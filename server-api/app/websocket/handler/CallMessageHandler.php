<?php
/**
 * WebSocketEvent 通话相关处理方法(补充到 WebSocketEvent.php 中)
 * 
 * 使用方法: 将以下方法添加到 WebSocketEvent 类中,
 * 并在 onMessage 的 type 路由中添加对应的 case 分支
 */
declare(strict_types=1);

namespace app\websocket\handler;

use app\websocket\services\CallService;
use app\websocket\services\ConversationService;
use EasySwoole\Component\TableManager;
use think\facade\Db;

/**
 * 处理通话相关消息类型
 * 
 * 需要在 onMessage 的 switch/case 中添加:
 * 
 * case 'call_start':
 *     $this->handleCallStart($server, $frame, $tenantId, $agentId);
 *     break;
 * case 'call_answer':
 *     $this->handleCallAnswer($server, $frame, $tenantId, $agentId);
 *     break;
 * case 'call_offer':
 *     $this->handleCallOffer($server, $frame, $tenantId, $agentId);
 *     break;
 * case 'call_answer_offer':
 *     $this->handleCallAnswerOffer($server, $frame, $tenantId, $agentId);
 *     break;
 * case 'call_ice_candidate':
 *     $this->handleCallIceCandidate($server, $frame, $tenantId, $agentId);
 *     break;
 * case 'call_hangup':
 *     $this->handleCallHangup($server, $frame, $tenantId, $agentId);
 *     break;
 */
trait CallMessageHandler
{
    /**
     * 处理通话开始请求(A请求呼叫B)
     * 前端发送: {type: 'call_start', to_agent_id: 2, conversation_id: 'xxx', call_type: 'video'}
     */
    private function handleCallStart($server, $frame, int $tenantId, int $agentId): void
    {
        $data = json_decode($frame->data, true);
        $toAgentId = (int) ($data['to_agent_id'] ?? 0);
        $conversationId = (string) ($data['conversation_id'] ?? '');
        $callType = $data['call_type'] ?? 'voice';

        if ($toAgentId <= 0 || $conversationId === '') {
            $this->push($server, $frame->fd, 'call_error', [
                'message' => '参数错误',
            ]);
            return;
        }

        $callService = new CallService();

        // 检查被叫是否在线
        if (!$callService->isAgentOnline($toAgentId)) {
            $this->push($server, $frame->fd, 'call_error', [
                'message' => '对方不在线',
            ]);
            return;
        }

        // 检查被叫是否正在通话中
        if ($callService->isAgentBusy($toAgentId)) {
            $this->push($server, $frame->fd, 'call_error', [
                'message' => '对方正在通话中',
            ]);
            return;
        }

        // 检查呼叫方是否已有活跃通话
        $myCallId = $callService->getAgentCurrentCall($agentId);
        if ($myCallId) {
            $this->push($server, $frame->fd, 'call_error', [
                'message' => '你正在通话中',
            ]);
            return;
        }

        // 生成通话ID
        $callId = $callService->generateCallId();

        // 保存振铃状态
        $callInfo = $callService->startCall(
            $callId, $agentId, $toAgentId, $conversationId, $callType, $tenantId
        );

        // 获取被叫的 FD 并推送振铃通知
        $toFd = $callService->getAgentFd($toAgentId);
        if ($toFd) {
            $this->push($server, $toFd, 'call_ring', [
                'call_id'         => $callId,
                'from_agent_id'   => $agentId,
                'from_agent_name' => $this->getAgentName($agentId),
                'conversation_id' => $conversationId,
                'call_type'       => $callType,
            ]);
        }

        // 记录呼叫方当前通话
        $fdKey = (string) $frame->fd;
        $table = TableManager::getInstance()->get(\app\common\constants\StringConstant::WEBSOCKET_CLIENT);
        if ($table && $table->exist($fdKey)) {
            $row = $table->get($fdKey);
            $row['call_id'] = $callId;
            $table->set($fdKey, $row);
        }

        $this->push($server, $frame->fd, 'call_starting', [
            'call_id'         => $callId,
            'to_agent_id'     => $toAgentId,
            'call_type'       => $callType,
            'message'         => '正在呼叫...',
        ]);
    }

    /**
     * 处理通话接听(A发送 SDP offer 给 B,B回复 answer)
     * 前端发送: {type: 'call_answer', call_id: 'xxx', conversation_id: 'xxx', sdp: {...}}
     */
    private function handleCallAnswer($server, $frame, int $tenantId, int $agentId): void
    {
        $data = json_decode($frame->data, true);
        $callId = (string) ($data['call_id'] ?? '');
        $conversationId = (string) ($data['conversation_id'] ?? '');
        $sdp = $data['sdp'] ?? null;

        if ($callId === '' || $conversationId === '') {
            $this->push($server, $frame->fd, 'call_error', ['message' => '参数错误']);
            return;
        }

        $callService = new CallService();
        $callInfo = $callService->answerCall($callId, $agentId, $conversationId);

        if (!$callInfo) {
            $this->push($server, $frame->fd, 'call_error', ['message' => '通话不存在']);
            return;
        }

        // 获取呼叫方 FD 并推送 answer
        $fromFd = $callService->getAgentFd($callInfo['from_agent_id'] ?? 0);
        if ($fromFd && $sdp) {
            $this->push($server, $fromFd, 'call_answer', [
                'call_id'         => $callId,
                'from_agent_id'   => $agentId,
                'conversation_id' => $conversationId,
                'sdp'             => $sdp,
            ]);
        }

        // 通知接听方通话已建立
        $this->push($server, $frame->fd, 'call_connected', [
            'call_id'         => $callId,
            'conversation_id' => $conversationId,
            'call_type'       => $callInfo['call_type'] ?? 'voice',
        ]);

        // 更新 Table 中的 call_id
        $fdKey = (string) $frame->fd;
        $table = TableManager::getInstance()->get(\app\common\constants\StringConstant::WEBSOCKET_CLIENT);
        if ($table && $table->exist($fdKey)) {
            $row = $table->get($fdKey);
            $row['call_id'] = $callId;
            $table->set($fdKey, $row);
        }
    }

    /**
     * 处理 SDP Offer 转发(A->B)
     * 前端发送: {type: 'call_offer', call_id: 'xxx', sdp: {...}}
     */
    private function handleCallOffer($server, $frame, int $tenantId, int $agentId): void
    {
        $data = json_decode($frame->data, true);
        $callId = (string) ($data['call_id'] ?? '');
        $sdp = $data['sdp'] ?? null;

        if ($callId === '' || !$sdp) {
            return;
        }

        $callService = new CallService();
        $callInfo = $callService->getActiveCall($callId);

        if (!$callInfo) {
            return;
        }

        // 转发给被叫方
        $toFd = $callService->getAgentFd($callInfo['to_agent_id'] ?? 0);
        if ($toFd) {
            $this->push($server, $toFd, 'call_offer', [
                'call_id'         => $callId,
                'from_agent_id'   => $agentId,
                'conversation_id' => $callInfo['conversation_id'],
                'sdp'             => $sdp,
            ]);
        }
    }

    /**
     * 处理 SDP Answer 转发(B->A)
     */
    private function handleCallAnswerOffer($server, $frame, int $tenantId, int $agentId): void
    {
        $data = json_decode($frame->data, true);
        $callId = (string) ($data['call_id'] ?? '');
        $sdp = $data['sdp'] ?? null;

        if ($callId === '' || !$sdp) {
            return;
        }

        $callService = new CallService();
        $callInfo = $callService->getActiveCall($callId);

        if (!$callInfo) {
            return;
        }

        // 转发给呼叫方
        $fromFd = $callService->getAgentFd($callInfo['from_agent_id'] ?? 0);
        if ($fromFd) {
            $this->push($server, $fromFd, 'call_answer', [
                'call_id'         => $callId,
                'from_agent_id'   => $agentId,
                'conversation_id' => $callInfo['conversation_id'],
                'sdp'             => $sdp,
            ]);
        }
    }

    /**
     * 处理 ICE Candidate 转发
     */
    private function handleCallIceCandidate($server, $frame, int $tenantId, int $agentId): void
    {
        $data = json_decode($frame->data, true);
        $callId = (string) ($data['call_id'] ?? '');
        $candidate = $data['candidate'] ?? null;

        if ($callId === '' || !$candidate) {
            return;
        }

        $callService = new CallService();
        $callInfo = $callService->getActiveCall($callId);

        if (!$callInfo) {
            return;
        }

        // 中继转发 ICE 候选
        $toAgentId = ($callInfo['to_agent_id'] ?? 0) === $agentId
            ? $callInfo['from_agent_id']
            : $callInfo['to_agent_id'];

        $toFd = $callService->getAgentFd($toAgentId);
        if ($toFd) {
            $this->push($server, $toFd, 'call_ice_candidate', [
                'call_id'     => $callId,
                'from_agent_id'=> $agentId,
                'candidate'   => $candidate,
            ]);
        }

        $callService->relayIceCandidate($callId, $agentId, $candidate);
    }

    /**
     * 处理挂断通话
     */
    private function handleCallHangup($server, $frame, int $tenantId, int $agentId): void
    {
        $data = json_decode($frame->data, true);
        $callId = (string) ($data['call_id'] ?? '');

        if ($callId === '') {
            return;
        }

        $callService = new CallService();
        $callInfo = $callService->getActiveCall($callId);

        if ($callInfo) {
            // 通知对方通话结束
            $otherAgentId = ($callInfo['to_agent_id'] ?? 0) === $agentId
                ? $callInfo['from_agent_id']
                : $callInfo['to_agent_id'];

            $otherFd = $callService->getAgentFd($otherAgentId);
            if ($otherFd) {
                $this->push($server, $otherFd, 'call_hangup', [
                    'call_id'         => $callId,
                    'from_agent_id'   => $agentId,
                    'conversation_id' => $callInfo['conversation_id'],
                    'reason'          => 'hangup',
                ]);
            }

            // 结束通话记录
            $callService->endCall($callId, 'normal');

            // 清理 Table 中的 call_id
            $fdKey = (string) $frame->fd;
            $table = TableManager::getInstance()->get(\app\common\constants\StringConstant::WEBSOCKET_CLIENT);
            if ($table && $table->exist($fdKey)) {
                $row = $table->get($fdKey);
                $row['call_id'] = 0;
                $table->set($fdKey, $row);
            }
        }

        $this->push($server, $frame->fd, 'call_hangup', [
            'call_id'         => $callId,
            'conversation_id' => $callInfo['conversation_id'] ?? '',
            'reason'          => 'hangup',
        ]);
    }

    /**
     * 获取客服名称(辅助方法)
     */
    private function getAgentName(int $agentId): string
    {
        try {
            $agent = Db::name('agent')->where('id', $agentId)->value('name');
            return $agent ?: '客服' . $agentId;
        } catch (\Throwable $e) {
            return '客服' . $agentId;
        }
    }
}