<?php
/**
 * 消息服务
 * 优化版：修复 sender_id 缺失、消除 dump 调试代码、优化 N+1 查询
 */
declare(strict_types=1);

namespace app\websocket\services;

use think\facade\Db;

class MessageService
{
    /**
     * 消息状态常量
     */
    public const STATUS_NORMAL  = 0;
    public const STATUS_RECALLED = 1;

    /**
     * 消息类型常量
     */
    public const TYPE_TEXT      = 'text';
    public const TYPE_IMAGE     = 'image';
    public const TYPE_VOICE     = 'voice';
    public const TYPE_FILE      = 'file';
    public const TYPE_SYSTEM    = 'system';

    /**
     * 保存聊天消息（同步落库 + 异步Task兜底）
     *
     * @param int    $tenantId       租户ID
     * @param int    $conversationId 会话ID
     * @param string $senderType     发送者类型: visitor/agent/system
     * @param int|null $uid          用户ID（访客登录时）
     * @param string|null $visitorId  访客ID（未登录时）
     * @param string $msgType        消息类型: text/image/voice/file
     * @param string $content        消息内容
     * @param int|null $accountId      客服ID（客服回复时）
     * @param array  $extra          扩展字段
     * @return array|null 返回写入的消息数据，失败返回null
     */
    public function saveMessage(
        int $tenantId,
        int $conversationId,
        string $senderType,
        ?int $uid,
        ?string $visitorId,
        string $msgType = self::TYPE_TEXT,
        string $content = '',
        ?int $accountId = null,
        array $extra = []
    ): ?array {
        try {
            $now = time();

            // 根据 senderType 设置 sender_id
            $senderId = 0;
            if ($senderType === 'visitor') {
                $senderId = !empty($visitorId) ? (int) crc32($visitorId) : 0;
            } elseif ($senderType === 'client' && $accountId) {
                $senderId = $accountId;
            }

            $data = [
                'tenant_id'        => $tenantId,
                'conversation_id'  => $conversationId,
                'sender_type'      => $senderType,
                'sender_id'        => $senderId,
                'uid'              => $uid ?? 0,
                'visitor_id'       => $visitorId ?? '',
                'account_id'         => $accountId ?? 0,
                'msg_type'         => $msgType,
                'content'          => $content,
                'status'           => self::STATUS_NORMAL,
                'is_read'          => 0,
                'extra'            => !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : '',
                'create_time'       => $now,
                'update_time'       => $now,
            ];

            $id = Db::name('im_message')->insertGetId($data);

            if (!$id) {
                // 同步写入失败，异步兜底
                $this->asyncSaveMessage($data);
                return null;
            }

            $data['id'] = (int) $id;

            // 更新会话最后消息和消息数
            app(\app\common\services\im\ConversationService::class)->updateLastMessage($conversationId, $content);

            return $data;
        } catch (\Throwable $e) {
            // 异常时异步兜底（移除 dump 调试代码）
            $this->asyncSaveMessage([
                'tenant_id'        => $tenantId,
                'conversation_id'  => $conversationId,
                'sender_type'      => $senderType,
                'sender_id'        => 0,
                'uid'              => $uid ?? 0,
                'visitor_id'       => $visitorId ?? '',
                'account_id'         => $accountId ?? 0,
                'msg_type'         => $msgType,
                'content'          => $content,
                'status'           => self::STATUS_NORMAL,
                'is_read'          => 0,
                'extra'            => '',
                'create_time'       => time(),
                'update_time'       => time(),
            ]);
            error_log('MessageService::saveMessage error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 异步保存消息（Task消费端调用）
     */
    public function handleTaskMessageSave(array $data): bool
    {
        try {
            $data['created_at'] = $data['created_at'] ?? time();
            $data['updated_at'] = $data['updated_at'] ?? time();

            $id = Db::name('im_message')->insertGetId($data);

            if ($id) {
                if (isset($data['conversation_id'])) {
                    app(\app\common\services\im\ConversationService::class)->updateLastMessage(
                        (int) $data['conversation_id'],
                        $data['content'] ?? ''
                    );
                }
                return true;
            }
        } catch (\Throwable $e) {
            error_log('MessageService::handleTaskMessageSave error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * 异步保存消息（定时任务兜底）
     */
    private function asyncSaveMessage(array $data): void
    {
        try {
            $data['retry_at'] = time() + 60;
            Db::name('im_message_retry')->insert($data);
        } catch (\Throwable $e) {
            error_log('MessageService::asyncSaveMessage error: ' . $e->getMessage());
            // 重试表也不存在时降级写日志
            file_put_contents(
                runtime_path() . 'message_retry_' . date('Y-m-d') . '.log',
                json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    /**
     * 获取会话消息列表（分页 + 增量拉取）
     */
    public function getMessages(
        int $tenantId,
        int $conversationId,
        int $lastMessageId = 0,
        int $limit = 50
    ): array {
        $query = Db::name('im_message')
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->where('status', self::STATUS_NORMAL)
            ->order('id', 'asc');

        if ($lastMessageId > 0) {
            $query->where('id', '>', $lastMessageId);
        }

        $messages = $query->limit($limit)->select()->toArray();

        // 标记已读
        if (!empty($messages)) {
            $messageIds = array_column($messages, 'id');
            Db::name('im_message')
                ->where('id', 'in', $messageIds)
                ->where('is_read', 0)
                ->update(['is_read' => 1, 'updated_at' => time()]);
        }

        return $messages;
    }

    /**
     * 撤回消息
     */
    public function recallMessage(int $messageId, int $tenantId, int $agentId): bool
    {
        $affected = Db::name('im_message')
            ->where('id', $messageId)
            ->where('tenant_id', $tenantId)
            ->where('sender_type', 'agent')
            ->where('status', self::STATUS_NORMAL)
            ->update([
                'status'     => self::STATUS_RECALLED,
                'updated_at' => time(),
            ]);

        return $affected > 0;
    }

    /**
     * 删除消息（软删除）
     */
    public function deleteMessage(int $messageId, int $tenantId): bool
    {
        $affected = Db::name('im_message')
            ->where('id', $messageId)
            ->where('tenant_id', $tenantId)
            ->update([
                'status'     => self::STATUS_RECALLED,
                'updated_at' => time(),
            ]);

        return $affected > 0;
    }

    /**
     * 获取会话未读消息数
     */
    public function getUnreadCount(int $conversationId, int $tenantId): int
    {
        return (int) Db::name('im_message')
            ->where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('is_read', 0)
            ->where('status', self::STATUS_NORMAL)
            ->count();
    }

    /**
     * 获取客服待处理会话的未读消息汇总（优化版：一次性查询避免 N+1）
     *
     * @param int $tenantId 租户ID
     * @param int $agentId  客服ID
     * @return array ['total'=>int, 'conversations'=>[...] ]
     */
    public function getAgentUnreadSummary(int $tenantId, int $agentId): array
    {
        // 一次性查询所有会话的未读数（避免 N+1）
        $sql = "
            SELECT c.id AS conversation_id,
                   c.visitor_id,
                   c.last_message,
                   c.message_count,
                   c.updated_at,
                   COUNT(m.id) AS unread_count
            FROM m_im_conversation c
            LEFT JOIN m_im_message m ON m.conversation_id = c.id
                AND m.tenant_id = ?
                AND m.is_read = 0
                AND m.status = 0
            WHERE c.tenant_id = ?
              AND c.agent_id = ?
              AND c.status IN ('open', 'pending')
            GROUP BY c.id, c.visitor_id, c.last_message, c.message_count, c.updated_at
            ORDER BY c.updated_at DESC
        ";

        $conversations = Db::query($sql, [$tenantId, $tenantId, $agentId]);

        $unreadList = [];
        $totalCount = 0;

        foreach ($conversations as $conv) {
            if ((int) $conv['unread_count'] > 0) {
                $unreadList[] = [
                    'conversation_id'  => (int) $conv['conversation_id'],
                    'visitor_id'       => $conv['visitor_id'],
                    'last_message'     => $conv['last_message'],
                    'unread_count'     => (int) $conv['unread_count'],
                    'updated_at'       => (int) $conv['updated_at'],
                ];
                $totalCount += (int) $conv['unread_count'];
            }
        }

        return [
            'total'         => $totalCount,
            'conversations' => $unreadList,
        ];
    }
}