<?php
/**
 * 通话服务
 * 处理 WebRTC 信令(offer/answer/ice_candidate/hangup)
 * 管理通话状态、在线用户映射
 */
declare(strict_types=1);

namespace app\websocket\services;

use think\facade\Db;
use app\common\services\common\CacheService;

class CallService
{
    /**
     * Redis Key 前缀
     */
    private const KEY_PEER_FD       = 'call:peer:fd:';
    private const KEY_PEER_CALL     = 'call:peer:call:';
    private const KEY_CALL_ACTIVE   = 'call:active:';
    private const KEY_CALL_RING     = 'call:ring:';
    private const KEY_AGENT_ONLINE  = 'call:agent:online:';
    private const KEY_ICE_CAND      = 'call:ice:';

    /**
     * 注册客服 FD 映射(客服连接成功时调用)
     */
    public function registerAgent(int $tenantId, int $agentId, int $fd): void
    {
        try {
            CacheService::instance()->redisHandler()->setex(self::KEY_PEER_FD . $agentId, 3600, (string) $fd);
            CacheService::instance()->redisHandler()->sadd(self::KEY_AGENT_ONLINE . $tenantId, (string) $agentId);
            CacheService::instance()->redisHandler()->expire(self::KEY_AGENT_ONLINE . $tenantId, 3600);
        } catch (\Throwable $e) {
            error_log('[CallService] registerAgent error: ' . $e->getMessage());
        }
    }

    /**
     * 注销客服 FD
     */
    public function unregisterAgent(int $tenantId, int $agentId): void
    {
        try {
            $fd = CacheService::instance()->redisHandler()->get(self::KEY_PEER_FD . $agentId);
            if ($fd) {
                $callId = CacheService::instance()->redisHandler()->get(self::KEY_PEER_CALL . $fd);
                if ($callId) {
                    $this->endCall($callId, 'agent_offline');
                }
                CacheService::instance()->redisHandler()->del(self::KEY_PEER_FD . $agentId);
                CacheService::instance()->redisHandler()->del(self::KEY_PEER_CALL . $fd);
            }
            CacheService::instance()->redisHandler()->srem(self::KEY_AGENT_ONLINE . $tenantId, (string) $agentId);
        } catch (\Throwable $e) {
            error_log('[CallService] unregisterAgent error: ' . $e->getMessage());
        }
    }

    /**
     * 获取客服的 FD
     */
    public function getAgentFd(int $agentId): ?int
    {
        try {
            $fd = CacheService::instance()->redisHandler()->get(self::KEY_PEER_FD . $agentId);
            return $fd ? (int) $fd : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 发起通话请求(A呼叫B)
     * 保存振铃状态,返回 call info 供调用方推送振铃通知
     */
    public function startCall(
        string $callId,
        int $fromAgentId,
        int $toAgentId,
        string $conversationId,
        string $callType,
        int $tenantId
    ): array {
        $callInfo = [
            'call_id'         => $callId,
            'from_agent_id'   => $fromAgentId,
            'to_agent_id'     => $toAgentId,
            'conversation_id' => $conversationId,
            'call_type'       => $callType,
            'tenant_id'       => $tenantId,
            'status'          => 'ringing',
            'created_at'      => time(),
        ];

        try {
            CacheService::instance()->redisHandler()->setex(
                self::KEY_CALL_RING . $callId,
                180,
                json_encode($callInfo, JSON_UNESCAPED_UNICODE)
            );

            $toFd = $this->getAgentFd($toAgentId);
            if ($toFd) {
                CacheService::instance()->redisHandler()->setex(self::KEY_PEER_CALL . $toFd, 3600, $callId);
            }
        } catch (\Throwable $e) {
            error_log('[CallService] startCall redis error: ' . $e->getMessage());
        }

        return $callInfo;
    }

    /**
     * 接听通话(B回复 offer)
     * 切换状态为 connected,记录开始时间
     */
    public function answerCall(string $callId, int $agentId, string $conversationId): array
    {
        $callInfo = [
            'call_id'         => $callId,
            'agent_id'        => $agentId,
            'conversation_id' => $conversationId,
            'status'          => 'connected',
            'started_at'      => time(),
            'updated_at'      => time(),
        ];

        try {
            $ringData = CacheService::instance()->redisHandler()->get(self::KEY_CALL_RING . $callId);
            if ($ringData) {
                $callInfo = array_merge(
                    json_decode($ringData, true),
                    $callInfo
                );
                CacheService::instance()->redisHandler()->del(self::KEY_CALL_RING . $callId);
            }

            CacheService::instance()->redisHandler()->setex(self::KEY_CALL_ACTIVE . $callId, 7200, json_encode($callInfo, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            error_log('[CallService] answerCall error: ' . $e->getMessage());
        }

        return $callInfo;
    }

    /**
     * 交换 ICE 候选(中继转发)
     */
    public function relayIceCandidate(string $callId, int $fromAgentId, array $candidate): bool
    {
        try {
            $key = self::KEY_ICE_CAND . $callId . ':' . $fromAgentId;
            $existing = CacheService::instance()->redisHandler()->get($key);
            $candidates = $existing ? json_decode($existing, true) : [];
            $candidates[] = $candidate;
            CacheService::instance()->redisHandler()->setex($key, 3600, json_encode($candidates, JSON_UNESCAPED_UNICODE));
            return true;
        } catch (\Throwable $e) {
            error_log('[CallService] relayIceCandidate error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 结束通话
     */
    public function endCall(string $callId, string $reason = 'normal'): void
    {
        try {
            $callData = CacheService::instance()->redisHandler()->get(self::KEY_CALL_ACTIVE . $callId);
            if ($callData) {
                $callInfo = json_decode($callData, true);
                $this->saveCallLog(array_merge($callInfo, [
                    'ended_at'   => time(),
                    'duration'   => time() - ($callInfo['started_at'] ?? time()),
                    'reason'     => $reason,
                ]));
                CacheService::instance()->redisHandler()->del(self::KEY_CALL_ACTIVE . $callId);
            }

            CacheService::instance()->redisHandler()->del(self::KEY_CALL_RING . $callId);
            CacheService::instance()->redisHandler()->del(self::KEY_ICE_CAND . $callId . ':from_agent');
            CacheService::instance()->redisHandler()->del(self::KEY_ICE_CAND . $callId . ':to_agent');
        } catch (\Throwable $e) {
            error_log('[CallService] endCall error: ' . $e->getMessage());
        }
    }

    /**
     * 保存通话记录到数据库
     */
    private function saveCallLog(array $log): void
    {
        try {
            Db::name('im_call_log')->insert([
                'call_id'         => $log['call_id'],
                'from_agent_id'   => $log['from_agent_id'] ?? 0,
                'to_agent_id'     => $log['to_agent_id'] ?? 0,
                'conversation_id' => $log['conversation_id'] ?? '',
                'call_type'       => $log['call_type'] ?? 'voice',
                'tenant_id'       => $log['tenant_id'] ?? 0,
                'duration'        => $log['duration'] ?? 0,
                'status'          => 'ended',
                'end_reason'      => $log['reason'] ?? 'normal',
                'started_at'      => $log['started_at'] ?? time(),
                'ended_at'        => $log['ended_at'] ?? time(),
                'created_at'      => time(),
                'updated_at'      => time(),
            ]);
        } catch (\Throwable $e) {
            error_log('[CallService] saveCallLog error: ' . $e->getMessage());
        }
    }

    /**
     * 获取租户在线客服列表
     */
    public function getOnlineAgents(int $tenantId): array
    {
        try {
            $agents = CacheService::instance()->redisHandler()->smembers(self::KEY_AGENT_ONLINE . $tenantId);
            return array_map('intval', $agents);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 检查客服是否在线
     */
    public function isAgentOnline(int $agentId): bool
    {
        try {
            return CacheService::instance()->redisHandler()->exists(self::KEY_PEER_FD . $agentId) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查客服是否正在通话中
     */
    public function isAgentBusy(int $agentId): bool
    {
        $fd = $this->getAgentFd($agentId);
        if (!$fd) {
            return true;
        }
        $callId = CacheService::instance()->redisHandler()->get(self::KEY_PEER_CALL . $fd);
        return $callId !== false;
    }

    /**
     * 生成唯一通话ID
     */
    public function generateCallId(): string
    {
        return 'call_' . bin2hex(random_bytes(16));
    }

    /**
     * 获取活跃通话信息
     */
    public function getActiveCall(string $callId): ?array
    {
        try {
            $data = CacheService::instance()->redisHandler()->get(self::KEY_CALL_ACTIVE . $callId);
            return $data ? json_decode($data, true) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取客服当前通话ID
     */
    public function getAgentCurrentCall(int $agentId): ?string
    {
        $fd = $this->getAgentFd($agentId);
        if (!$fd) {
            return null;
        }
        $callId = CacheService::instance()->redisHandler()->get(self::KEY_PEER_CALL . $fd);
        return $callId ?: null;
    }
}