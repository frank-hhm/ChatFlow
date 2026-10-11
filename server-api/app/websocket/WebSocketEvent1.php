<?php
/**
 * WebSocket 事件处理
 * 整合: 消息收发 / 语音视频消息 / 实时语音视频通话 / 会话管理
 */
declare(strict_types=1);

namespace app\websocket;

use app\common\constants\StringConstant;
use app\websocket\services\CallService;
use app\websocket\services\ConversationService;
use app\websocket\services\MessageFileService;
use app\websocket\services\MessageService;
use app\websocket\services\PushMessageService;
use app\websocket\handler\CallMessageHandler;

use EasySwoole\Component\TableManager;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use think\facade\Db;

class WebSocketEvent1
{
    use CallMessageHandler; // 引入通话处理方法

    /**
     * 服务实例
     */
    private $server;

    /**
     * 构造函数
     */
    public function __construct($server)
    {
        $this->server = $server;
    }

    /**
     * 连接建立
     */
    public static function onOpen($server, $request): void
    {
        $fd = $request->fd;
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        $table->set((string) $fd, [
            'fd'              => $fd,
            'tenant_id'       => 0,
            'agent_id'        => 0,
            'visitor_id'      => '',
            'uid'             => 0,
            'role'            => 'visitor',
            'conversation_id' => 0,
            'conversation_ids'=> [],
            'call_id'         => 0,
            'connected_at'    => time(),
        ]);

        self::push($server, $fd, 'connected', [
            'message' => '连接成功',
            'fd'      => $fd,
        ]);
    }

    /**
     * 接收消息
     */
    public static function onMessage($server, Frame $frame): void
    {
        $data = json_decode($frame->data, true);
        if (!$data || !isset($data['type'])) {
            return;
        }

        $fd = $frame->fd;
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        
        if (!$table || !$table->exist((string) $fd)) {
            return;
        }

        $row = $table->get((string) $fd);
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $agentId = (int) ($row['agent_id'] ?? 0);

        // 路由处理
        try {
            switch ($data['type']) {
                // ===== 消息相关 =====
                case 'visitor_send':
                    self::handleVisitorMessage($server, $frame, $tenantId, $agentId, $data);
                    break;

                case 'agent_reply':
                    self::handleAgentReply($server, $frame, $tenantId, $agentId, $data);
                    break;

                case 'voice_upload':
                    self::handleVoiceUpload($server, $frame, $tenantId, $agentId, $data);
                    break;

                case 'video_upload':
                    self::handleVideoUpload($server, $frame, $tenantId, $agentId, $data);
                    break;

                case 'image_upload':
                    self::handleImageUpload($server, $frame, $tenantId, $agentId, $data);
                    break;

                // ===== 通话相关 =====
                case 'call_start':
                    self::handleCallStart($server, $frame, $tenantId, $agentId);
                    break;

                case 'call_answer':
                    self::handleCallAnswer($server, $frame, $tenantId, $agentId);
                    break;

                case 'call_offer':
                    self::handleCallOffer($server, $frame, $tenantId, $agentId);
                    break;

                case 'call_answer_offer':
                    self::handleCallAnswerOffer($server, $frame, $tenantId, $agentId);
                    break;

                case 'call_ice_candidate':
                    self::handleCallIceCandidate($server, $frame, $tenantId, $agentId);
                    break;

                case 'call_hangup':
                    self::handleCallHangup($server, $frame, $tenantId, $agentId);
                    break;

                // ===== 会话相关 =====
                case 'agent_login_sync':
                    self::handleAgentLoginSync($server, $frame, $tenantId, $agentId);
                    break;

                case 'mark_read':
                    self::handleMarkRead($server, $frame, $tenantId, $agentId, $data);
                    break;

                case 'recall_message':
                    self::handleRecallMessage($server, $frame, $tenantId, $agentId, $data);
                    break;

                default:
                    self::push($server, $fd, 'error', ['message' => '未知消息类型: ' . $data['type']]);
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] onMessage error: ' . $e->getMessage());
            self::push($server, $fd, 'error', ['message' => '处理消息失败']);
        }
    }

    /**
     * 访客发送消息
     */
    private static function handleVisitorMessage($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        $content = $data['content'] ?? '';
        $messageType = $data['message_type'] ?? 'text';
        $conversationId = (string) ($data['conversation_id'] ?? '');

        if ($conversationId === '') {
            self::push($server, $frame->fd, 'error', ['message' => '会话ID不能为空']);
            return;
        }

        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        $row = $table->get((string) $frame->fd);
        $visitorId = $row['visitor_id'] ?? '';
        $uid = (int) ($row['uid'] ?? 0);

        $messageService = new MessageService();
        $message = $messageService->saveMessage(
            $tenantId, $visitorId, $uid, $conversationId,
            'visitor', 0,
            $messageType, $content,
            [
                'file_url' => $data['file_url'] ?? '',
                'duration' => $data['duration'] ?? 0,
                'width'    => $data['width'] ?? 0,
                'height'   => $data['height'] ?? 0,
                'thumbnail_url' => $data['thumbnail_url'] ?? '',
            ]
        );

        if (!$message) {
            self::push($server, $frame->fd, 'send_failed', ['message' => '消息发送失败']);
            return;
        }

        // 推送给客服
        $pushService = new PushMessageService();
        $pushService->pushToAgent(
            $tenantId, $agentId, 'new_message',
            ['message' => $message]
        );

        // 更新会话最后消息
        $conversationService = new ConversationService();
        $conversationService->updateLastMessage(
            (int) $conversationId,
            $messageType === 'text' ? $content : '[' . $messageType . ']'
        );

        self::push($server, $frame->fd, 'send_success', ['message' => $message]);
    }

    /**
     * 客服回复消息
     */
    private static function handleAgentReply($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        $content = $data['content'] ?? '';
        $messageType = $data['message_type'] ?? 'text';
        $conversationId = (string) ($data['conversation_id'] ?? '');

        if ($conversationId === '') {
            self::push($server, $frame->fd, 'error', ['message' => '会话ID不能为空']);
            return;
        }

        $messageService = new MessageService();
        $message = $messageService->saveMessage(
            $tenantId, '', 0, $conversationId,
            'agent', $agentId,
            $messageType, $content,
            [
                'file_url' => $data['file_url'] ?? '',
                'duration' => $data['duration'] ?? 0,
                'width'    => $data['width'] ?? 0,
                'height'   => $data['height'] ?? 0,
                'thumbnail_url' => $data['thumbnail_url'] ?? '',
            ]
        );

        if (!$message) {
            self::push($server, $frame->fd, 'send_failed', ['message' => '消息发送失败']);
            return;
        }

        // 推送给访客
        $pushService = new PushMessageService();
        $pushService->pushToVisitor(
            $tenantId, $conversationId, 'new_message',
            ['message' => $message]
        );

        self::push($server, $frame->fd, 'send_success', ['message' => $message]);
    }

    /**
     * 处理语音上传
     */
    private static function handleVoiceUpload($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        // 语音消息: 前端先上传音频文件到HTTP接口, 然后发送 WebSocket 消息携带 file_url
        // 这里处理的是已上传完成的语音消息发送
        $fileUrl = $data['file_url'] ?? '';
        $duration = (int) ($data['duration'] ?? 0);
        $conversationId = (string) ($data['conversation_id'] ?? '');

        if ($fileUrl === '' || $conversationId === '') {
            self::push($server, $frame->fd, 'voice_upload_failed', ['message' => '参数错误']);
            return;
        }

        $messageService = new MessageService();
        $message = $messageService->saveVoiceMessage(
            $tenantId, '', 0, $conversationId,
            'agent', $agentId,
            $fileUrl, $duration, 'mp3', 0
        );

        if ($message) {
            $pushService = new PushMessageService();
            $pushService->pushToVisitor(
                $tenantId, $conversationId, 'new_message',
                ['message' => $message]
            );
            self::push($server, $frame->fd, 'voice_upload_success', ['message' => $message]);
        }
    }

    /**
     * 处理视频上传
     */
    private static function handleVideoUpload($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        $fileUrl = $data['file_url'] ?? '';
        $duration = (int) ($data['duration'] ?? 0);
        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        $thumbnailUrl = $data['thumbnail_url'] ?? '';
        $conversationId = (string) ($data['conversation_id'] ?? '');

        if ($fileUrl === '' || $conversationId === '') {
            self::push($server, $frame->fd, 'video_upload_failed', ['message' => '参数错误']);
            return;
        }

        $messageService = new MessageService();
        $message = $messageService->saveVideoMessage(
            $tenantId, '', 0, $conversationId,
            'agent', $agentId,
            $fileUrl, $duration, $width, $height, $thumbnailUrl
        );

        if ($message) {
            $pushService = new PushMessageService();
            $pushService->pushToVisitor(
                $tenantId, $conversationId, 'new_message',
                ['message' => $message]
            );
            self::push($server, $frame->fd, 'video_upload_success', ['message' => $message]);
        }
    }

    /**
     * 处理图片上传
     */
    private static function handleImageUpload($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        $fileUrl = $data['file_url'] ?? '';
        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        $conversationId = (string) ($data['conversation_id'] ?? '');

        if ($fileUrl === '' || $conversationId === '') {
            self::push($server, $frame->fd, 'image_upload_failed', ['message' => '参数错误']);
            return;
        }

        $messageService = new MessageService();
        $message = $messageService->saveImageMessage(
            $tenantId, '', 0, $conversationId,
            'agent', $agentId,
            $fileUrl, $width, $height, 0
        );

        if ($message) {
            $pushService = new PushMessageService();
            $pushService->pushToVisitor(
                $tenantId, $conversationId, 'new_message',
                ['message' => $message]
            );
            self::push($server, $frame->fd, 'image_upload_success', ['message' => $message]);
        }
    }

    /**
     * 客服登录同步
     */
    private static function handleAgentLoginSync($server, Frame $frame, int $tenantId, int $agentId): void
    {
        $callService = new CallService();
        $callService->registerAgent($tenantId, $agentId, $frame->fd);

        // 注册到 Table
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        $table->set((string) $frame->fd, [
            'fd'              => $frame->fd,
            'tenant_id'       => $tenantId,
            'agent_id'        => $agentId,
            'role'            => 'agent',
            'conversation_id' => 0,
            'conversation_ids'=> [],
            'call_id'         => 0,
            'connected_at'    => time(),
        ]);

        // 获取待分配会话和活跃会话
        $conversationService = new ConversationService();
        $conversations = $conversationService->getAgentConversations($tenantId, $agentId);
        
        // 收集会话ID
        $conversationIds = array_column($conversations, 'id');
        
        // 获取未读数
        $unreadSummary = [];
        if (!empty($conversationIds)) {
            $messageService = new MessageService();
            $unreadSummary = $messageService->getAgentUnreadSummary($tenantId, array_map('strval', $conversationIds));
        }

        self::push($server, $frame->fd, 'agent_sync', [
            'message'         => '登录成功',
            'agent_id'        => $agentId,
            'conversations'   => $conversations,
            'conversation_ids'=> $conversationIds,
            'unread_summary'  => $unreadSummary,
        ]);
    }

    /**
     * 标记已读
     */
    private function handleMarkRead($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        $conversationId = (string) ($data['conversation_id'] ?? '');
        if ($conversationId === '') {
            return;
        }

        $messageService = new MessageService();
        $messageService->markAsRead($conversationId, $agentId);

        self::push($server, $frame->fd, 'mark_read_success', ['conversation_id' => $conversationId]);
    }

    /**
     * 撤回消息
     */
    private static function handleRecallMessage($server, Frame $frame, int $tenantId, int $agentId, array $data): void
    {
        $messageId = (int) ($data['message_id'] ?? 0);
        $conversationId = (string) ($data['conversation_id'] ?? '');

        if ($messageId <= 0 || $conversationId === '') {
            return;
        }

        $messageService = new MessageService();
        $result = $messageService->recallMessage($messageId, $tenantId, $conversationId);

        if ($result) {
            // 推送撤回通知
            $pushService = new PushMessageService();
            $pushService->pushToVisitor($tenantId, $conversationId, 'message_recalled', [
                'message_id'     => $messageId,
                'conversation_id'=> $conversationId,
            ]);
        }

        self::push($server, $frame->fd, 'recall_success', ['success' => $result]);
    }

    /**
     * 连接关闭
     */
    public static function onClose($server, int $fd): void
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        
        if ($table && $table->exist((string) $fd)) {
            $row = $table->get((string) $fd);
            $role = $row['role'] ?? '';
            $agentId = (int) ($row['agent_id'] ?? 0);
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            $conversationId = (int) ($row['conversation_id'] ?? 0);

            // 客服断开: 清理通话和在线状态
            if ($role === 'agent' && $agentId > 0) {
                $callService = new CallService();
                $callService->unregisterAgent($tenantId, $agentId);

                // 如果有活跃通话,通知对方
                if (!empty($row['call_id'])) {
                    $callInfo = $callService->getActiveCall((string) $row['call_id']);
                    if ($callInfo) {
                        // 通知对方通话结束
                        // ... 实现略
                    }
                }
            }

            // 访客断开: 处理会话状态
            if ($role === 'visitor' && $conversationId > 0) {
                $conversationService = new ConversationService();
                $conversationService->onVisitorDisconnect($fd);
            }

            $table->del((string) $fd);
        }
    }

    /**
     * 统一推送方法
     */
    private static function push($server, int $fd, string $type, array $data = []): void
    {
        try {
            $message = json_encode([
                'type'    => $type,
                'data'    => $data,
                'timestamp' => time(),
            ], JSON_UNESCAPED_UNICODE);

            $server->push((int) $fd, $message);
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] push error: ' . $e->getMessage());
        }
    }
}