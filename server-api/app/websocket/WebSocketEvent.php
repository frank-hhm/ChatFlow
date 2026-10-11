<?php
/**
 * WebSocket 事件处理器
 * 优化版：Redis维护客服多会话、Table行同步、消除重复代码、完善异常处理
 */
declare(strict_types=1);

namespace app\websocket;

use app\common\constants\StringConstant;
use app\common\services\im\ConversationCacheService;
use app\websocket\services\ConversationService;
use app\websocket\services\MessageService;
use app\websocket\services\PushMessageService;
use app\common\services\common\CacheService;
use EasySwoole\Component\TableManager;
use EasySwoole\EasySwoole\ServerManager;
use think\facade\Db;
use think\facade\Log;

class WebSocketEvent
{


    // ==================== 鉴权 ====================

    /**
     * 模拟鉴权（替代 MemberAuthService）
     * 开发/测试环境使用，生产环境替换为真实鉴权
     */
    private static function mockAuth(
        int $tenantId,
        int $accountId = 0,
        int $uid = 0,
        string $visitorId = ''
    ): array {
        if ($tenantId <= 0) {
            return ['valid' => false, 'message' => 'tenant_id 必填'];
        }

        if ($accountId > 0) {
            return [
                'valid'      => true,
                'role'       => 'client',
                'tenant_id'  => $tenantId,
                'account_id'   => $accountId,
                'uid'        => 0,
                'visitor_id' => '',
            ];
        }

        if ($uid <= 0 && $visitorId === '') {
            return ['valid' => false, 'message' => 'visitor_id 或 uid 必填'];
        }

        return [
            'valid'      => true,
            'role'       => 'visitor',
            'tenant_id'  => $tenantId,
            'account_id'   => 0,
            'uid'        => $uid,
            'visitor_id' => $visitorId,
        ];
    }


    // ==================== 连接建立 ====================

    /**
     * 连接建立
     */
    public static function onOpen($server, $request): void
    {
        $fd = (string) $request->fd;

        $params     = $request->get ?? [];
        $tenantId   = (int) ($params['tenant_id'] ?? 0);
        $accountId    = (int) ($params['account_id'] ?? 0);
        $uid        = (int) ($params['uid'] ?? 0);
        $visitorId  = (string) ($params['visitor_id'] ?? '');
        $channel    = (string) ($params['channel'] ?? 'web');

        $auth = self::mockAuth($tenantId, $accountId, $uid, $visitorId);

        if (!$auth['valid']) {
            self::closeFd($server, (int) $fd, 4001, $auth['message']);
            return;
        }


        $imConversationService = app(\app\common\services\im\ConversationService::class);
        $imConversationCacheService = app(\app\common\services\im\ConversationCacheService::class);
        $conversationService = app(ConversationService::class);
        $conversation = null;
        $conversationId = 0;
        $conversationIds = []; // [新增] 客服的所有会话ID列表
        // === 访客连接：创建/获取会话 ===
        
        if ($auth['role'] === 'visitor') {
            $conversation = $imConversationService->createOrUpdate(
                $auth['tenant_id'],
                $auth['visitor_id'],
                $auth['uid'],
                $auth['account_id'],
                $channel
            );

            if ($conversation && (int) ($conversation['account_id'] ?? 0) <= 0) {
                $assignedAgentId =$conversationService->autoAssignTenantAccount($auth['tenant_id'], $auth['visitor_id']);
                if ($assignedAgentId > 0) {
                    $imConversationService->dao->model->where('id', $conversation['id'])
                        ->update([
                            'account_id'   => $assignedAgentId,
                            'status'   => "open",
                            'updated_at' => time(),
                        ]);
                    $conversation['account_id'] = $assignedAgentId;

                    // [新增] 将会话ID加入客服的 Redis 集合
                    $imConversationCacheService->addTenantAccountConversation($auth['tenant_id'], $assignedAgentId, (int) $conversation['id']);

                    // [新增] 同步更新客服 Table 行的 conversation_ids
                    $conversationService->syncTenantAccountTableConversationIds(
                        $auth['tenant_id'],
                        $assignedAgentId,
                        $imConversationCacheService->getTenantAccountConversationIds($auth['tenant_id'], $assignedAgentId)
                    );

                    // 通知客服有新访客
                    try {
                        app(PushMessageService::class)->pushToAgent(
                            $auth['tenant_id'],
                            $assignedAgentId,
                            'new_visitor',
                            [
                                'conversation_id' => (int) $conversation['id'],
                                'visitor_id'      => $auth['visitor_id'],
                                'uid'             => $auth['uid'],
                                'channel'         => $channel,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::error('[WEBSOCKET] pushToAgent 错误: ' . $e->getMessage());
                    }
                }
            }
        }

        // === 客服连接：加载所有活跃会话（不再创建空会话）===
        if ($auth['role'] === 'client') {
            // [新增] 从 Redis 加载该客服的所有会话ID
            $conversationIds = $imConversationCacheService->getTenantAccountConversationIds($tenantId, $accountId);

            // 同时获取完整的会话详情用于同步响应
            $allConversations = $conversationService->getTenantAccountAllConversations($tenantId, $accountId);

            // 取最新的会话ID作为当前会话（保持向后兼容）
            $result = $conversationService->assignTenantAccountConversation($tenantId, $accountId, $channel);
            $conversationId = $result['conversationId'];
            $conversation = $result['conversation'];

            if ($conversationId > 0) {
                echo "[AGENT] fd={$fd} 分配会话 conversation_id={$conversationId}\n";
            } else {
                echo "[AGENT] fd={$fd} 无可用会话, Redis会话数=" . count($conversationIds) . "\n";
            }

            // 客服登录同步 - [修改] 返回所有会话列表和未读汇总
            try {
                $unreadSummary = app(MessageService::class)->getAgentUnreadSummary($tenantId, $accountId);
                $pendingCount = count($imConversationCacheService->getPendingConversations($tenantId));

                // [新增] 获取所有会话的未读数
                $unreadByConversation = [];
                foreach ($unreadSummary['conversations'] as $item) {
                    $unreadByConversation[$item['conversation_id']] = $item['unread_count'];
                }

                app(PushMessageService::class)->push(
                    (int) $fd,
                    'agent_sync',
                    [
                        'unassigned_count'       => $pendingCount,
                        'unread_summary'         => $unreadSummary,
                        'conversation_ids'       => $conversationIds, // [新增] 所有会话ID列表
                        'conversations'          => $allConversations, // [新增] 所有会话详情
                        'unread_by_conversation' => $unreadByConversation, // [新增] 按会话ID的未读数
                        'conversation_id'        => $conversationId,
                    ],
                    $tenantId
                );
            } catch (\Throwable $e) {
                Log::error('[WEBSOCKET] agent_sync 错误: ' . $e->getMessage());
            }

            // [新增] 刷新客服活跃 TTL
            $imConversationCacheService->refreshTenantAccountTTL($tenantId, $accountId);
        }

        // 写入连接表
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (!empty($table)) {
            $effectiveAgentId = $auth['account_id'];
            if ($auth['role'] === 'visitor' && $conversation && (int) ($conversation['account_id'] ?? 0) > 0) {
                $effectiveAgentId = (int) $conversation['account_id'];
            }

            $table->set($fd, [
                'fd'              => (int) $fd,
                'tenant_id'       => $auth['tenant_id'],
                'visitor_id'      => $auth['visitor_id'],
                'uid'             => $auth['uid'],
                'account_id'        => $effectiveAgentId,
                'role'            => $auth['role'],
                'conversation_id' => (int) ($conversation['id'] ?? 0),
                'conversation_ids' => $conversationIds, // [新增] 客服的所有会话ID列表
                'channel'         => $channel,
                'last_time'       => microtime(true),
                'status'          => 'connected',
            ]);
        }

        // 推送连接成功响应
        $responseData = [
            'conversation_id' => (int) ($conversation['id'] ?? 0),
            'account_id'        => $auth['role'] === 'visitor'
                ? (int) ($conversation['account_id'] ?? 0)
                : $auth['account_id'],
            'visitor_id'      => $auth['visitor_id'],
            'role'            => $auth['role'],
        ];

        $server->push(
            (int) $fd,
            json_encode([
                'type' => 'connected',
                'data' => $responseData,
            ], JSON_UNESCAPED_UNICODE)
        );

        // 访客连接成功且已分配客服时，通知客服
        if ($auth['role'] === 'visitor') {
            $newAgentId = (int) ($conversation['account_id'] ?? 0);
            if ($newAgentId > 0) {
                try {
                    app(PushMessageService::class)->pushToAgent(
                        $auth['tenant_id'],
                        $newAgentId,
                        'new_visitor',
                        [
                            'conversation_id' => (int) $conversation['id'],
                            'visitor_id'      => $auth['visitor_id'],
                            'uid'             => $auth['uid'],
                            'channel'         => $channel,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('[WEBSOCKET] pushToAgent(onOpen) 错误: ' . $e->getMessage());
                }
            }
        }

        // 统一日志
        $logAgentId = $auth['role'] === 'visitor'
            ? (int) ($conversation['account_id'] ?? 0)
            : $auth['account_id'];
        $logConversationId = (int) ($conversation['id'] ?? 0);

        echo sprintf(
            "fd=%s tenant=%d role=%s visitor=%s uid=%d agent=%d conversation=%d connected\n",
            $fd,
            $auth['tenant_id'],
            $auth['role'],
            $auth['visitor_id'],
            $auth['uid'],
            $logAgentId,
            $logConversationId
        );
    }

    // ==================== 消息处理 ====================

    /**
     * 消息处理
     */
    public static function onMessage($server, $frame): void
    {
        try {
            $fdKey = (string) $frame->fd;
            $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);

            if (empty($table) || !$table->exist($fdKey)) {
                return;
            }

            $row = $table->get($fdKey);
            $row['last_time'] = microtime(true);

            $role = $row['role'] ?? 'visitor';
            $data = json_decode($frame->data, true);
            dump($row);
            dump($data);
            if (!is_array($data)) {
                $table->set($fdKey, $row);
                return;
            }
            $msgType = $data['type'] ?? '';
            // 心跳
            if ($msgType === 'ping') {
                $server->push(
                    (int) $fdKey,
                    json_encode(['type' => 'pong', 'data' => []], JSON_UNESCAPED_UNICODE)
                );
                $table->set($fdKey, $row);
                return;
            }

            $imConversationService = app(\app\common\services\im\ConversationService::class);
            $imConversationCacheService = app(\app\common\services\im\ConversationCacheService::class);
            $conversationService = app(ConversationService::class);

            // 客服登录同步请求
            if ($msgType === 'agent_login_sync') {
                $tenantId = (int) ($row['tenant_id'] ?? 0);
                $accountId  = (int) ($row['account'] ?? 0);

                if ($accountId > 0 && $tenantId > 0) {
                    // [新增] 从 Redis 加载所有会话ID
                    $conversationIds = $imConversationCacheService->getTenantAccountConversationIds($tenantId, $accountId);
                    $allConversations = $conversationService->getTenantAccountAllConversations($tenantId, $accountId);

                    // 同时尝试分配一个会话作为当前会话
                    $result = $conversationService->assignTenantAccountConversation($tenantId, $accountId);
                    $conversationId = $result['conversationId'];

                    if ($conversationId > 0) {
                        $row['conversation_id'] = $conversationId;
                    }

                    // [新增] 同步 Table 行
                    $conversationService->syncTenantAccountTableConversationIds($tenantId, $accountId, $conversationIds);

                    // 获取未读汇总
                    $unreadSummary = app(MessageService::class)->getAgentUnreadSummary($tenantId, $accountId);
                    $pendingCount = count($imConversationService->getPendingConversations($tenantId));

                    $unreadByConversation = [];
                    foreach ($unreadSummary['conversations'] as $item) {
                        $unreadByConversation[$item['conversation_id']] = $item['unread_count'];
                    }

                    $server->push(
                        (int) $fdKey,
                        json_encode([
                            'type' => 'agent_sync',
                            'data' => [
                                'unassigned_count'       => $pendingCount,
                                'unread_summary'         => $unreadSummary,
                                'conversation_ids'       => $conversationIds,
                                'conversations'          => $allConversations,
                                'unread_by_conversation' => $unreadByConversation,
                                'conversation_id'        => $conversationId,
                            ],
                        ], JSON_UNESCAPED_UNICODE)
                    );

                    // [新增] 刷新 TTL
                    $imConversationCacheService->refreshTenantAccountTTL($tenantId, $accountId);
                }

                $table->set($fdKey, $row);
                return;
            }

            $tenantId       = (int) ($row['tenant_id'] ?? 0);
            $visitorId      = (string) ($row['visitor_id'] ?? '');
            $uid            = (int) ($row['uid'] ?? 0);
            $accountId        = (int) ($row['account_id'] ?? 0);
            //这里不用去查，而是发消息时带上
            // $conversationId = (int) $data['conversation_id'] ?? 0;
            $conversationId = (int) ($row['conversation_id'] ?? 0);
            
            // 客服发消息时反查会话（兜底）
            
            if ($role === 'agent' && $conversationId <= 0 && $accountId > 0 && $tenantId > 0) {
                try {
                    $activeConversation = $imConversationService->getConversationByStatus($tenantId, $accountId);

                    if ($activeConversation) {
                        $conversationId = (int) $activeConversation['id'];
                        $row['conversation_id'] = $conversationId;
                        $table->set($fdKey, $row);
                    } else {
                        $server->push((int)$fdKey, json_encode([
                            'type'    => 'error',
                            'code'    => 4002,
                            'message' => '当前没有活跃的会话可发送消息',
                        ], JSON_UNESCAPED_UNICODE));
                        return;
                    }
                } catch (\Throwable $e) {
                    Log::error('[WEBSOCKET] conversation lookup 错误: ' . $e->getMessage());
                    $server->push((int)$fdKey, json_encode([
                        'type'    => 'error',
                        'code'    => 500,
                        'message' => '系统异常',
                    ], JSON_UNESCAPED_UNICODE));
                    return;
                }
            }

            if ($tenantId <= 0 || $conversationId <= 0) {
                return;
            }

            $row['conversation_id'] = $conversationId;

            // 访客发送消息
            if ($role === 'visitor' && $msgType === 'chat') {
                $content = trim((string) ($data['content'] ?? ''));
                if ($content === '') {
                    return;
                }

                try {
                    $messageService = app(MessageService::class);
                    $message = $messageService->saveMessage(
                        $tenantId,
                        $conversationId,
                        'visitor',
                        $uid > 0 ? $uid : null,
                        $visitorId,
                        'text',
                        $content
                    );

                    // 获取最新会话信息（agent_id 可能刚被更新）
                    $latestConv = $imConversationService->getDetailById($conversationId);
                    $currentAgentId = (int) ($latestConv['account_id'] ?? 0);

                    $onlineAgentCount = $conversationService->getOnlineTenantAccountCount($tenantId);

                    if ($currentAgentId > 0 && $onlineAgentCount > 0) {
                        app(PushMessageService::class)->pushToAgent(
                            $tenantId,
                            $currentAgentId,
                            'new_message',
                            $message
                        );
                    } else {
                        app(PushMessageService::class)->push(
                            (int) $fdKey,
                            'system',
                            [
                                'code'    => 503,
                                'message' => '当前暂无客服在线，您的消息已保存，客服上线后会尽快回复',
                            ],
                            $tenantId
                        );

                        if ((int) ($row['agent_id'] ?? 0) <= 0) {
                            $assignedAgentId = $conversationService->autoAssignTenantAccount($tenantId, $visitorId);
                            if ($assignedAgentId > 0) {
                                Db::name('im_conversation')
                                    ->where('id', $conversationId)
                                    ->update([
                                        'account_id'   => $assignedAgentId,
                                        'updated_at' => time(),
                                    ]);
                                $row['account_id'] = $assignedAgentId;
                                $table->set($fdKey, $row);

                                // [新增] 将会话ID加入客服的 Redis 集合
                                $imConversationCacheService->addTenantAccountConversation($tenantId, $assignedAgentId, $conversationId);

                                // [新增] 同步更新客服 Table 行
                                $conversationService->syncTenantAccountTableConversationIds(
                                    $tenantId,
                                    $assignedAgentId,
                                    $imConversationCacheService->getTenantAccountConversationIds($tenantId, $assignedAgentId)
                                );

                                try {
                                    app(PushMessageService::class)->pushToAgent(
                                        $tenantId,
                                        $assignedAgentId,
                                        'new_visitor',
                                        [
                                            'conversation_id' => $conversationId,
                                            'visitor_id'      => $visitorId,
                                            'uid'             => $uid,
                                            'content'         => $content,
                                        ]
                                    );
                                } catch (\Throwable $e) {
                                    error_log('[WEBSOCKET] auto-assign push error: ' . $e->getMessage());
                                }
                            }
                        }
                    }

                    $server->push(
                        (int) $fdKey,
                        json_encode([
                            'type' => 'message_ack',
                            'data' => $message,
                        ], JSON_UNESCAPED_UNICODE)
                    );
                } catch (\Throwable $e) {
                    error_log("[WEBSOCKET] error: " . $e->getMessage());
                    error_log('[WEBSOCKET] visitor message save error: ' . $e->getMessage());
                    $server->push((int)$fdKey, json_encode([
                        'type'    => 'error',
                        'code'    => 500,
                        'message' => '消息发送失败',
                    ], JSON_UNESCAPED_UNICODE));
                }
            }

            // 客服回复消息
            // [修改] 支持从消息数据中读取 conversation_id（客服可同时接待多个会话）
            if ($role === 'client' && $msgType === 'agent_reply') {
                $fromAgentId = (int) ($data['account_id'] ?? 0);
                $content     = trim((string) ($data['content'] ?? ''));
                // [新增] 从消息数据中获取 conversation_id
                $convIdFromData = (int) ($data['conversation_id'] ?? 0);
dump($fromAgentId);
dump($content);
                if ($fromAgentId <= 0 || $content === '') {
                    return;
                }

                // [新增] 优先使用消息中携带的 conversation_id，兜底使用 table row 中的
                $conversationId = $convIdFromData > 0 ? $convIdFromData : $conversationId;

                if ($conversationId <= 0) {
                    $server->push((int)$fdKey, json_encode([
                        'type'    => 'error',
                        'code'    => 4002,
                        'message' => '请指定会话ID',
                    ], JSON_UNESCAPED_UNICODE));
                    return;
                }

                try {
                    $messageService = app(MessageService::class);
                    $message = $messageService->saveMessage(
                        $tenantId,
                        $conversationId,
                        'client',
                        null,
                        null,
                        'text',
                        $content,
                        $fromAgentId
                    );

                    app(PushMessageService::class)->pushToVisitor(
                        $tenantId,
                        $conversationId,
                        'client_message',
                        $message
                    );
                } catch (\Throwable $e) {
                    error_log("[WEBSOCKET] error: " . $e->getMessage());
                    error_log('[WEBSOCKET] agent message save error: ' . $e->getMessage());
                    $server->push((int)$fdKey, json_encode([
                        'type'    => 'error',
                        'code'    => 500,
                        'message' => '消息发送失败',
                    ], JSON_UNESCAPED_UNICODE));
                }
            }

            $table->set($fdKey, $row);
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] onMessage error: ' . $e->getMessage());
        }
    }

    // ==================== 连接关闭 ====================

    /**
     * 连接关闭
     */
    public static function onClose($server, int $fd, int $reactorId): void
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (!empty($table)) {
            $row = $table->get((string) $fd);
            $table->del((string) $fd);

            // [新增] 客服断开时，清理 Redis 中该客服的会话集合
            $accountId = (int) ($row['account_id'] ?? 0);
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($accountId > 0 && $tenantId > 0) {
                $key = app(\app\common\services\im\ConversationCacheService::class)->getTenantAccountConversationKey($tenantId, $accountId);
                try {
                   app(CacheService::class)->redisHandler()->del($key);
                } catch (\Throwable $e) {
                    error_log('[WEBSOCKET] onClose Redis del error: ' . $e->getMessage());
                }
            }
        }

        try {
            app(ConversationService::class)->onVisitorDisconnect($fd);
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] onClose error: ' . $e->getMessage());
        }
    }

    // ==================== WebSocket 握手 ====================

    /**
     * WebSocket 握手
     */
    public function onHandShake($request, $response): bool
    {
        if ($this->customHandShake($request, $response)
            && $this->secWebsocketAccept($request, $response)
        ) {
            $response->status(101);
            $response->end();
            return true;
        }

        $response->end();
        return false;
    }

    protected function customHandShake($request, $response): bool
    {
        return true;
    }

    protected function secWebsocketAccept($request, $response): bool
    {
        if (!isset($request->header['sec-websocket-key'])) {
            return false;
        }

        if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $request->header['sec-websocket-key'])
            || 16 !== strlen(base64_decode($request->header['sec-websocket-key']))
        ) {
            return false;
        }

        $key = base64_encode(
            sha1(
                $request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
                true
            )
        );

        $headers = [
            'Upgrade'              => 'websocket',
            'Connection'           => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version'=> '13',
            'KeepAlive'            => 'off',
        ];

        if (isset($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }

        foreach ($headers as $k => $v) {
            $response->header($k, $v);
        }

        return true;
    }

    /**
     * 关闭连接并返回错误信息
     */
    private static function closeFd($server, int $fd, int $code, string $reason): void
    {
        try {
            $server->push(
                $fd,
                json_encode([
                    'type'    => 'error',
                    'code'    => $code,
                    'message' => $reason,
                ], JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            // push 失败忽略
        }

        try {
            $server->close($fd);
        } catch (\Throwable $e) {
            // close 失败忽略
        }
    }
}