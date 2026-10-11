<?php
/**
 * 消息服务
 * 支持文本/图片/语音/视频/文件等多种消息类型
 */
declare(strict_types=1);

namespace app\websocket\services;

use app\common\constants\StringConstant;
use EasySwoole\Component\TableManager;
use think\facade\Db;
use think\facade\Redis;

class MessageServiceBak
{
    /**
     * 消息类型常量
     */
    public const TYPE_TEXT      = 'text';
    public const TYPE_IMAGE     = 'image';
    public const TYPE_VOICE     = 'voice';
    public const TYPE_VIDEO     = 'video';
    public const TYPE_FILE      = 'file';
    public const TYPE_LOCATION  = 'location';
    public const TYPE_RECALL    = 'recall';

    /**
     * 消息状态常量
     */
    public const STATUS_NORMAL  = 0;
    public const STATUS_RECALLED = 1;

    /**
     * 保存消息(支持多种类型)
     *
     * @param string $senderType 发送者类型: visitor/agent
     * @param string $messageType 消息类型: text/image/voice/video/file
     * @param mixed  $content 消息内容(文本直接存字符串,媒体存URL数组)
     * @param array  $extra 额外数据(语音时长/视频信息/位置等)
     */
    public function saveMessage(
        int $tenantId,
        string $visitorId,
        int $uid,
        string $conversationId,
        string $senderType,
        int $agentId,
        string $messageType,
        $content,
        array $extra = []
    ): ?array {
        try {
            $now = time();

            // 处理内容
            $messageContent = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : (string) $content;

            // 根据消息类型设置 sender_id
            $senderId = 0;
            if ($senderType === 'visitor') {
                $senderId = $uid > 0 ? $uid : crc32($visitorId) & 0xFFFFFFFF;
            } elseif ($senderType === 'agent') {
                $senderId = $agentId;
            }

            $data = [
                'tenant_id'       => $tenantId,
                'visitor_id'      => $visitorId,
                'uid'             => $uid,
                'conversation_id' => $conversationId,
                'sender_type'     => $senderType,
                'sender_id'       => $senderId,
                'agent_id'        => $agentId,
                'message_type'    => $messageType,
                'media_type'      => $extra['media_type'] ?? '',
                'content'         => $messageContent,
                'file_url'        => $extra['file_url'] ?? '',
                'duration'        => $extra['duration'] ?? 0,
                'width'           => $extra['width'] ?? 0,
                'height'          => $extra['height'] ?? 0,
                'thumbnail_url'   => $extra['thumbnail_url'] ?? '',
                'status'          => self::STATUS_NORMAL,
                'is_read'         => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            $id = Db::name('im_message')->insertGetId($data);

            if ($id) {
                $data['id'] = (int) $id;
                return $data;
            }

            return null;
        } catch (\Throwable $e) {
            error_log('[MessageService] saveMessage error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 保存语音消息
     */
    public function saveVoiceMessage(
        int $tenantId,
        string $visitorId,
        int $uid,
        string $conversationId,
        string $senderType,
        int $agentId,
        string $fileUrl,
        int $duration,
        string $fileFormat,
        int $fileSize
    ): ?array {
        return $this->saveMessage(
            $tenantId, $visitorId, $uid, $conversationId,
            $senderType, $agentId,
            self::TYPE_VOICE,
            ['file_url' => $fileUrl, 'format' => $fileFormat, 'size' => $fileSize],
            [
                'media_type'  => 'voice',
                'file_url'    => $fileUrl,
                'duration'    => $duration,
            ]
        );
    }

    /**
     * 保存视频消息
     */
    public function saveVideoMessage(
        int $tenantId,
        string $visitorId,
        int $uid,
        string $conversationId,
        string $senderType,
        int $agentId,
        string $fileUrl,
        int $duration,
        int $width,
        int $height,
        ?string $thumbnailUrl = null
    ): ?array {
        return $this->saveMessage(
            $tenantId, $visitorId, $uid, $conversationId,
            $senderType, $agentId,
            self::TYPE_VIDEO,
            ['file_url' => $fileUrl],
            [
                'media_type'    => 'video',
                'file_url'      => $fileUrl,
                'duration'      => $duration,
                'width'         => $width,
                'height'        => $height,
                'thumbnail_url' => $thumbnailUrl ?? '',
            ]
        );
    }

    /**
     * 保存图片消息
     */
    public function saveImageMessage(
        int $tenantId,
        string $visitorId,
        int $uid,
        string $conversationId,
        string $senderType,
        int $agentId,
        string $fileUrl,
        int $width,
        int $height,
        int $fileSize
    ): ?array {
        return $this->saveMessage(
            $tenantId, $visitorId, $uid, $conversationId,
            $senderType, $agentId,
            self::TYPE_IMAGE,
            ['file_url' => $fileUrl],
            [
                'media_type' => 'image',
                'file_url'   => $fileUrl,
                'width'      => $width,
                'height'     => $height,
            ]
        );
    }

    /**
     * 撤回消息
     */
    public function recallMessage(int $messageId, int $tenantId, string $conversationId): bool
    {
        try {
            $affected = Db::name('im_message')
                ->where('id', $messageId)
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->update([
                    'message_type' => self::TYPE_RECALL,
                    'content'      => '["recall"]',
                    'status'       => self::STATUS_RECALLED,
                    'updated_at'   => time(),
                ]);

            return $affected > 0;
        } catch (\Throwable $e) {
            error_log('[MessageService] recallMessage error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 标记消息已读
     */
    public function markAsRead(string $conversationId, int $agentId = 0): void
    {
        try {
            $where = ['conversation_id' => $conversationId, 'is_read' => 0];
            if ($agentId > 0) {
                $where['sender_type'] = 'visitor'; // 只标记访客发给客服的消息
            }

            Db::name('im_message')
                ->where($where)
                ->update(['is_read' => 1, 'updated_at' => time()]);
        } catch (\Throwable $e) {
            error_log('[MessageService] markAsRead error: ' . $e->getMessage());
        }
    }

    /**
     * 获取会话消息列表(分页)
     */
    public function getConversationMessages(
        string $conversationId,
        int $limit = 50,
        int $beforeId = 0
    ): array {
        try {
            $query = Db::name('im_message')
                ->where('conversation_id', $conversationId)
                ->where('status', self::STATUS_NORMAL)
                ->order('id', 'desc');

            if ($beforeId > 0) {
                $query->where('id', '<', $beforeId);
            }

            $query->limit($limit);

            return $query->select()->toArray();
        } catch (\Throwable $e) {
            error_log('[MessageService] getConversationMessages error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取客服未读消息统计(优化版 - 消除N+1查询)
     */
    public function getAgentUnreadSummary(int $tenantId, array $conversationIds): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        try {
            $result = Db::name('im_message')
                ->field('conversation_id, COUNT(*) as unread_count')
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', 'in', $conversationIds)
                ->where('sender_type', 'visitor')
                ->where('is_read', 0)
                ->group('conversation_id')
                ->select()
                ->toArray();

            $summary = [];
            foreach ($result as $row) {
                $summary[$row['conversation_id']] = (int) $row['unread_count'];
            }

            return $summary;
        } catch (\Throwable $e) {
            error_log('[MessageService] getAgentUnreadSummary error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取会话未读数
     */
    public function getUnreadCount(string $conversationId, int $agentId): int
    {
        try {
            $count = Db::name('im_message')
                ->where('conversation_id', $conversationId)
                ->where('sender_type', 'visitor')
                ->where('is_read', 0)
                ->where('agent_id', $agentId)
                ->count();

            return (int) $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 删除消息(软删除)
     */
    public function deleteMessage(int $messageId, int $tenantId): bool
    {
        try {
            $affected = Db::name('im_message')
                ->where('id', $messageId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'status'     => self::STATUS_RECALLED,
                    'content'    => '["deleted"]',
                    'updated_at' => time(),
                ]);

            return $affected > 0;
        } catch (\Throwable $e) {
            error_log('[MessageService] deleteMessage error: ' . $e->getMessage());
            return false;
        }
    }
}