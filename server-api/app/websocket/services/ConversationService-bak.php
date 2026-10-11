<?php
/**
 * 会话服务
 * 优化版：修复 pending 状态复用问题、完善异常处理、Redis维护客服多会话
 */
declare(strict_types=1);

namespace app\websocket\services;

use app\common\constants\StringConstant;
use app\websocket\WebSocketEvent;
use EasySwoole\Component\TableManager;
use think\facade\Db;

class ConversationServiceBak
{
    /**
     * 会话状态常量
     */
    public const STATUS_OPEN    = 'open';
    public const STATUS_CLOSED  = 'closed';
    public const STATUS_PENDING = 'pending';

    /**
     * 访客可查的活跃状态（open + pending）
     * 访客应能找到自己的待分配会话，避免重连后创建重复会话
     */
    private const VISITOR_ACTIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_PENDING];

    /**
     * 默认渠道
     */
    private const DEFAULT_CHANNEL = 'web';

    /**
     * 创建或更新会话
     * 【修复】创建时默认为 pending 状态，分配客服后才变为 open
     */
    public function createOrUpdate(
        int $tenantId,
        string $visitorId,
        int $uid = 0,
        int $agentId = 0,
        string $channel = self::DEFAULT_CHANNEL
    ): ?array {
        if ($tenantId <= 0 || $visitorId === '') {
            return null;
        }

        $conversation = $this->findActiveConversation($tenantId, $visitorId, $uid, $channel);

        if ($conversation) {
            return $this->updateConversation($conversation, $uid, $agentId, $channel);
        }

        return $this->createConversation($tenantId, $visitorId, $uid, $agentId, $channel);
    }

    /**
     * 访客断开连接时处理
     */
    public function onVisitorDisconnect(int $fd): void
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);

        if (empty($table) || !$table->exist((string) $fd)) {
            return;
        }

        $row            = $table->get((string) $fd);
        $conversationId = (int) ($row['conversation_id'] ?? 0);
        $agentId        = (int) ($row['agent_id'] ?? 0);

        if ($conversationId <= 0) {
            return;
        }

        // 未分配客服时标记为待分配
        if ($agentId <= 0) {
            $this->markConversationPending($conversationId);
        }

        $row['conversation_id'] = 0;
        $table->set((string) $fd, $row);
    }

    /**
     * 查找活跃会话
     * 【修复】匹配 open + pending 状态，访客应能找到自己的待分配会话
     * 优先按 visitor_id 匹配，匹配不到再按 uid 匹配（访客登录后复用会话）
     * 注意：只匹配有 visitor_id 的会话（排除客服误创建的无效会话）
     */
    private function findActiveConversation(
        int $tenantId,
        string $visitorId,
        int $uid,
        string $channel
    ): ?array {
        $baseQuery = function ($query) use ($tenantId, $channel) {
            return $query
                ->where('tenant_id', $tenantId)
                ->where('channel', $channel)
                ->whereIn('status', self::VISITOR_ACTIVE_STATUSES)
                ->whereNotNull('visitor_id');
        };

        // 1. 优先按 visitor_id 精确匹配
        $conversation = Db::name('im_conversation')
            ->where($baseQuery)
            ->where('visitor_id', $visitorId)
            ->order('id', 'desc')
            ->find();

        if ($conversation) {
            return $conversation;
        }

        // 2. 按 uid 匹配（访客登录后复用）
        if ($uid > 0) {
            $conversation = Db::name('im_conversation')
                ->where($baseQuery)
                ->where('uid', $uid)
                ->order('id', 'desc')
                ->find();

            if ($conversation) {
                $this->refreshConversation($conversation['id'], [
                    'visitor_id' => $visitorId !== '' ? $visitorId : $conversation['visitor_id'],
                    'uid'        => $uid,
                ]);

                return $this->findById($conversation['id']);
            }
        }

        return null;
    }

    /**
     * 创建新会话
     * 【修复】无客服分配时默认为 pending 状态，有客服分配时为 open
     */
    private function createConversation(
        int $tenantId,
        string $visitorId,
        int $uid,
        int $agentId,
        string $channel
    ): ?array {
        $now = time();

        $data = [
            'tenant_id'     => $tenantId,
            'visitor_id'    => $visitorId,
            'uid'           => $uid,
            'agent_id'      => $agentId,
            'channel'       => $channel,
            'status'        => $agentId > 0 ? self::STATUS_OPEN : self::STATUS_PENDING,
            'last_message'  => '',
            'message_count' => 0,
            'opened_at'     => $now,
            'closed_at'     => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $id = Db::name('im_conversation')->insertGetId($data);

        if ($id) {
            $data['id'] = (int) $id;
            return $data;
        }

        return null;
    }

    /**
     * 更新已有会话
     * 【修复】分配客服时自动将状态改为 open
     */
    private function updateConversation(array $conversation, int $uid, int $agentId, string $channel): array
    {
        $updateData = ['updated_at' => time()];

        if (($conversation['channel'] ?? '') !== $channel) {
            $updateData['channel'] = $channel;
        }

        if (($conversation['uid'] ?? 0) <= 0 && $uid > 0) {
            $updateData['uid'] = $uid;
        }

        if (($conversation['agent_id'] ?? 0) <= 0 && $agentId > 0) {
            $updateData['agent_id'] = $agentId;
            $updateData['status'] = self::STATUS_OPEN;  // 分配客服后状态变为 open
        }

        if (count($updateData) > 1) {
            $this->refreshConversation($conversation['id'], $updateData);
        }

        return array_merge($conversation, $updateData);
    }

    /**
     * 标记会话为待分配
     */
    private function markConversationPending(int $conversationId): void
    {
        $this->refreshConversation($conversationId, [
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * 分配客服到会话
     * 【新增】同时更新 Redis 集合
     */
    public function assignAgent(int $conversationId, int $agentId): void
    {
        $this->refreshConversation($conversationId, [
            'agent_id' => $agentId,
            'status'   => self::STATUS_OPEN,
        ]);

        // [新增] 同步到 Redis
        try {
            $conv = $this->findById($conversationId);
            if ($conv) {
                $tenantId = (int) ($conv['tenant_id'] ?? 0);
                if ($tenantId > 0) {
                    WebSocketEvent::addAgentConversation($tenantId, $agentId, $conversationId);
                }
            }
        } catch (\Throwable $e) {
            error_log('ConversationService::assignAgent Redis error: ' . $e->getMessage());
        }
    }

    /**
     * 根据ID查询会话
     */
    public function findById(int $id): ?array
    {
        return Db::name('im_conversation')
            ->where('id', $id)
            ->find();
    }

    /**
     * 按会话ID获取会话数据
     */
    public function getConversation(int $conversationId): ?array
    {
        return $this->findById($conversationId);
    }

    /**
     * 关闭会话
     * 【修复】同时清理 Redis 集合
     */
    public function closeConversation(int $conversationId): void
    {
        // 先获取会话信息（tenant_id, agent_id）
        $conv = $this->findById($conversationId);
        $tenantId = (int) ($conv['tenant_id'] ?? 0);
        $agentId  = (int) ($conv['agent_id'] ?? 0);

        // 关闭会话
        $this->refreshConversation($conversationId, [
            'status'    => self::STATUS_CLOSED,
            'closed_at' => time(),
        ]);

        // [新增] 从 Redis 集合中移除
        if ($agentId > 0 && $tenantId > 0) {
            WebSocketEvent::removeAgentConversation($tenantId, $agentId, $conversationId);
        }
    }

    /**
     * 关闭会话（不清理 Redis，用于 WebSocketEvent 中已手动管理的场景）
     */
    public function closeConversationSilent(int $conversationId): void
    {
        $this->refreshConversation($conversationId, [
            'status'    => self::STATUS_CLOSED,
            'closed_at' => time(),
        ]);
    }

    /**
     * 更新最后消息
     */
    public function updateLastMessage(int $conversationId, string $lastMessage): void
    {
        Db::name('im_conversation')
            ->where('id', $conversationId)
            ->inc('message_count')
            ->update([
                'last_message' => $lastMessage,
                'updated_at'   => time(),
            ]);
    }

    /**
     * 获取某客服的活跃会话列表
     */
    public function getAgentConversations(int $tenantId, int $agentId, int $limit = 50): array
    {
        return Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PENDING])
            ->order('updated_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取待分配的会话列表
     */
    public function getPendingConversations(int $tenantId, string $channel = self::DEFAULT_CHANNEL, int $limit = 50): array
    {
        return Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('status', self::STATUS_PENDING)
            ->where('agent_id', 0)
            ->whereNotNull('visitor_id')
            ->order('created_at', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 根据客服ID获取其当前活跃会话
     */
    public function getAgentActiveConversation(int $tenantId, int $agentId): ?array
    {
        return Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PENDING])
            ->whereNotNull('visitor_id')
            ->order('id', 'desc')
            ->find();
    }

    /**
     * 封装更新，统一维护 updated_at
     */
    private function refreshConversation(int $id, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $data['updated_at'] = time();

        Db::name('im_conversation')
            ->where('id', $id)
            ->update($data);
    }
}