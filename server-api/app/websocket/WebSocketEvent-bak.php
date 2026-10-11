<?php
/**
 * WebSocket 事件处理器
 * 优化版：Redis维护客服多会话、Table行同步、消除重复代码、完善异常处理
 */
declare(strict_types=1);

namespace app\websocket;

use app\common\constants\StringConstant;
use app\websocket\services\ConversationService;
use app\websocket\services\MessageService;
use app\websocket\services\PushMessageService;
use app\common\services\common\CacheService;
use EasySwoole\Component\TableManager;
use EasySwoole\EasySwoole\ServerManager;
use think\facade\Db;

class WebSocketEventBak
{
    /**
     * Redis Key 前缀：客服会话集合
     * key 格式: agent_conversations:{tenant_id}:{agent_id}
     * 值类型: SET (conversation_id 集合)
     */
    private const AGENT_CONVERSATIONS_KEY = 'agent_conversations';

    /**
     * Redis Key 过期时间（秒）- 客服离线30分钟后自动清理
     */
    private const AGENT_TTL = 1800;

    // ==================== Redis 辅助方法 ====================

    /**
     * 生成 Redis Key
     */
    private static function getAgentConversationKey(int $tenantId, int $agentId): string
    {
        return sprintf('%s:%d:%d', self::AGENT_CONVERSATIONS_KEY, $tenantId, $agentId);
    }

    /**
     * 从 Redis 获取客服的所有会话ID列表
     *
     * @return int[] 会话ID数组
     */
    private static function getAgentConversationIds(int $tenantId, int $agentId): array
    {
        try {
            $key = self::getAgentConversationKey($tenantId, $agentId);
            $ids = CacheService::instance()->redisHandler()->smembers($key);
            return array_map('intval', $ids);
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] Redis getAgentConversationIds error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 将会话ID添加到客服的 Redis 集合中
     */
    public static function addAgentConversation(int $tenantId, int $agentId, int $conversationId): void
    {
        try {
            $key = self::getAgentConversationKey($tenantId, $agentId);
            CacheService::instance()->redisHandler()->sadd($key, $conversationId);
            // 设置过期时间，防止客服永久离线导致脏数据
            CacheService::instance()->redisHandler()->expire($key, self::AGENT_TTL);
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] Redis addAgentConversation error: ' . $e->getMessage());
        }
    }

    /**
     * 从客服的 Redis 集合中移除会话ID
     */
    public static function removeAgentConversation(int $tenantId, int $agentId, int $conversationId): void
    {
        try {
            $key = self::getAgentConversationKey($tenantId, $agentId);
            CacheService::instance()->redisHandler()->srem($key, $conversationId);
        } catch (\Throwable $e) {
            error_log('[WEBSOCKET] Redis removeAgentConversation error: ' . $e->getMessage());
        }
    }

    /**
     * 刷新客服 Redis Key 的过期时间（每次活跃时调用）
     */
    private static function refreshAgentTTL(int $tenantId, int $agentId): void
    {
        try {
            $key = self::getAgentConversationKey($tenantId, $agentId);
            CacheService::instance()->redisHandler()->expire($key, self::AGENT_TTL);
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 根据会话ID列表获取完整的会话详情
     *
     * @return array 会话详情数组
     */
    private static function getConversationsByIds(int $tenantId, array $conversationIds): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        return Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $conversationIds)
            ->whereIn('status', ['open', 'pending'])
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 查找客服的 Table 行并更新 conversation_ids（用于实时同步）
     */
    private static function syncAgentTableConversationIds(int $tenantId, int $agentId, array $conversationIds): void
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return;
        }

        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['agent_id'] ?? 0) == $agentId
                && ($row['role'] ?? '') === 'agent'
            ) {
                $row['conversation_ids'] = $conversationIds;
                $table->set($fd, $row);
            }
        }
    }

    /**
     * 向指定客服的所有连接推送 WebSocket 消息
     *
     * @return bool 是否找到至少一个连接
     */
    private static function pushToAgentViaTable(int $tenantId, int $agentId, string $type, array $data): bool
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return false;
        }

        $server = ServerManager::getInstance()->getServer();
        $found = false;

        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['agent_id'] ?? 0) == $agentId
                && ($row['role'] ?? '') === 'agent'
            ) {
                $server->push(
                    (int) $fd,
                    json_encode([
                        'type' => $type,
                        'data' => $data,
                    ], JSON_UNESCAPED_UNICODE)
                );
                $found = true;
            }
        }

        return $found;
    }

    // ==================== 鉴权 ====================

    /**
     * 模拟鉴权（替代 MemberAuthService）
     * 开发/测试环境使用，生产环境替换为真实鉴权
     */
    private static function mockAuth(
        int $tenantId,
        int $agentId = 0,
        int $uid = 0,
        string $visitorId = ''
    ): array {
        if ($tenantId <= 0) {
            return ['valid' => false, 'message' => 'tenant_id 必填'];
        }

        if ($agentId > 0) {
            return [
                'valid'      => true,
                'role'       => 'agent',
                'tenant_id'  => $tenantId,
                'agent_id'   => $agentId,
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
            'agent_id'   => 0,
            'uid'        => $uid,
            'visitor_id' => $visitorId,
        ];
    }

    // ==================== 客服分配策略 ====================

    /**
     * 自动分配客服给访客会话
     * 策略优先级：
     * 1. 优先分配给上次接待过该访客的在线客服（老访客找回上次接待）
     * 2. 从当前在线客服中按会话数最少分配（负载均衡）
     * 3. 降级到配置列表（兜底）
     */
    private static function autoAssignAgent(int $tenantId, string $visitorId = ''): int
    {
        // 策略1: 优先分配给上次接待过该访客的在线客服
        if ($visitorId !== '') {
            $historyAgent = Db::name('im_conversation')
                ->where('tenant_id', $tenantId)
                ->where('visitor_id', $visitorId)
                ->where('agent_id', '>', 0)
                ->where('status', 'open')
                ->order('updated_at', 'desc')
                ->value('agent_id');

            if ($historyAgent && self::isAgentOnline($tenantId, (int) $historyAgent)) {
                return (int) $historyAgent;
            }
        }

        // 策略2: 从当前在线客服中按会话数最少分配（负载均衡）
        $onlineAgentIds = self::getOnlineAgentIds($tenantId);
        if (!empty($onlineAgentIds)) {
            $agentId = self::pickLeastBusyAgent($tenantId, $onlineAgentIds);
            if ($agentId > 0) {
                return $agentId;
            }
        }

        // 策略3: 降级到配置列表（兜底，兼容无在线客服时的开发环境）
        $agentList = config('websocket.assign_agents.' . $tenantId, []);
        if (empty($agentList)) {
            $agentList = config('websocket.assign_agents.default', []);
        }

        return empty($agentList) ? 0 : (int) $agentList[0];
    }

    /**
     * 检查客服是否在线
     * 修复：Swoole\Table 没有 rows() 方法，直接 foreach 迭代
     */
    private static function isAgentOnline(int $tenantId, int $agentId): bool
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return false;
        }

        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['agent_id'] ?? 0) == $agentId
                && ($row['role'] ?? '') === 'agent'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否有在线客服
     */
    private static function getOnlineAgentCount(int $tenantId): int
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return 0;
        }

        $count = 0;
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId && ($row['role'] ?? '') === 'agent') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取指定租户的在线客服ID列表
     *
     * @return int[] 在线客服ID数组（去重）
     */
    private static function getOnlineAgentIds(int $tenantId): array
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return [];
        }

        $agentIds = [];
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['role'] ?? '') === 'agent'
                && ($row['agent_id'] ?? 0) > 0
            ) {
                $agentIds[] = (int) $row['agent_id'];
            }
        }

        return array_unique($agentIds);
    }

    /**
     * 从在线客服中挑选当前会话数最少的客服
     *
     * @return int 客服ID，无可用客服返回0
     */
    private static function pickLeastBusyAgent(int $tenantId, array $agentIds): int
    {
        if (empty($agentIds)) {
            return 0;
        }

        $minAgentId = 0;
        $minCount = PHP_INT_MAX;

        foreach ($agentIds as $agentId) {
            $count = Db::name('im_conversation')
                ->where('tenant_id', $tenantId)
                ->where('agent_id', $agentId)
                ->whereIn('status', ['open', 'pending'])
                ->count();

            if ($count < $minCount) {
                $minCount = $count;
                $minAgentId = $agentId;
            }
        }

        return $minAgentId;
    }

    // ==================== 会话分配 ====================

    /**
     * 客服分配/获取活跃会话（统一方法）
     * 修复：客服连接时不再创建空会话，只查找已有的 pending/open 会话
     *
     * @return array{conversationId: int, conversation: array|null}
     */
    private static function assignAgentConversation(int $tenantId, int $agentId, string $channel = 'web'): array
    {
        // 1. 查找该客服已有的活跃会话（取最新的）
        $activeConv = Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('visitor_id')
            ->order('id', 'desc')
            ->find();

        if ($activeConv) {
            return [
                'conversationId' => (int) $activeConv['id'],
                'conversation'   => $activeConv,
            ];
        }

        // 2. 从未分配池接管一个 pending 会话
        $pendingConv = Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', 0)
            ->where('status', 'pending')
            ->whereNotNull('visitor_id')
            ->order('created_at', 'asc')
            ->find();

        if ($pendingConv) {
            Db::name('im_conversation')
                ->where('id', $pendingConv['id'])
                ->update([
                    'agent_id'   => $agentId,
                    'status'     => 'open',
                    'updated_at' => time(),
                ]);

            return [
                'conversationId' => (int) $pendingConv['id'],
                'conversation'   => [
                    'id'         => (int) $pendingConv['id'],
                    'agent_id'   => $agentId,
                    'tenant_id'  => $tenantId,
                    'status'     => 'open',
                    'visitor_id' => $pendingConv['visitor_id'],
                ],
            ];
        }

        // 3. 没有待分配会话，不创建空会话，返回 0
        return ['conversationId' => 0, 'conversation' => null];
    }

    /**
     * 获取客服的所有活跃会话详情（用于客服登录同步）
     * 同步逻辑：Redis 为第一数据源，DB 为兜底，不一致时自动修复 Redis
     *
     * @return array 会话详情数组
     */
    private static function getAgentAllConversations(int $tenantId, int $agentId): array
    {
        // 先从 Redis 获取会话ID列表
        $conversationIds = self::getAgentConversationIds($tenantId, $agentId);

        if (!empty($conversationIds)) {
            // Redis 有数据，从数据库获取详情
            $conversations = self::getConversationsByIds($tenantId, $conversationIds);

            // 同步修复：把数据库中已分配给该客服但Redis中缺失的会话加回去
            $dbConvs = Db::name('im_conversation')
                ->where('tenant_id', $tenantId)
                ->where('agent_id', $agentId)
                ->whereIn('status', ['open', 'pending'])
                ->whereNotNull('visitor_id')
                ->pluck('id')
                ->toArray();

            foreach ($dbConvs as $convId) {
                if (!in_array($convId, $conversationIds)) {
                    self::addAgentConversation($tenantId, $agentId, $convId);
                }
            }

            return $conversations;
        }

        // Redis 无数据，从数据库查询并回填 Redis
        $conversations = Db::name('im_conversation')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('visitor_id')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($conversations as $conv) {
            self::addAgentConversation($tenantId, $agentId, (int) $conv['id']);
        }

        return $conversations;
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
        $agentId    = (int) ($params['agent_id'] ?? 0);
        $uid        = (int) ($params['uid'] ?? 0);
        $visitorId  = (string) ($params['visitor_id'] ?? '');
        $channel    = (string) ($params['channel'] ?? 'web');

        $auth = self::mockAuth($tenantId, $agentId, $uid, $visitorId);

        if (!$auth['valid']) {
            self::closeFd($server, (int) $fd, 4001, $auth['message']);
            return;
        }

        $conversation = null;
        $conversationId = 0;
        $conversationIds = []; // [新增] 客服的所有会话ID列表

        // === 访客连接：创建/获取会话 ===
        
        if ($auth['role'] === 'visitor') {
            $conversationService = app(ConversationService::class);
            $conversation = $conversationService->createOrUpdate(
                $auth['tenant_id'],
                $auth['visitor_id'],
                $auth['uid'],
                $auth['agent_id'],
                $channel
            );

            if ($conversation && (int) ($conversation['agent_id'] ?? 0) <= 0) {
                $assignedAgentId = self::autoAssignAgent($auth['tenant_id'], $auth['visitor_id']);
                if ($assignedAgentId > 0) {
                    Db::name('im_conversation')
                        ->where('id', $conversation['id'])
                        ->update([
                            'agent_id'   => $assignedAgentId,
                            'status'   => "open",
                            'updated_at' => time(),
                        ]);
                    $conversation['agent_id'] = $assignedAgentId;

                    // [新增] 将会话ID加入客服的 Redis 集合
                    self::addAgentConversation($auth['tenant_id'], $assignedAgentId, (int) $conversation['id']);

                    // [新增] 同步更新客服 Table 行的 conversation_ids
                    self::syncAgentTableConversationIds(
                        $auth['tenant_id'],
                        $assignedAgentId,
                        self::getAgentConversationIds($auth['tenant_id'], $assignedAgentId)
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
                        error_log('[WEBSOCKET] pushToAgent error: ' . $e->getMessage());
                    }
                }
            }
        }

        // === 客服连接：加载所有活跃会话（不再创建空会话）===
        if ($auth['role'] === 'agent') {
            // [新增] 从 Redis 加载该客服的所有会话ID
            $conversationIds = self::getAgentConversationIds($tenantId, $agentId);

            // 同时获取完整的会话详情用于同步响应
            $allConversations = self::getAgentAllConversations($tenantId, $agentId);

            // 取最新的会话ID作为当前会话（保持向后兼容）
            $result = self::assignAgentConversation($tenantId, $agentId, $channel);
            $conversationId = $result['conversationId'];
            $conversation = $result['conversation'];

            if ($conversationId > 0) {
                echo "[AGENT] fd={$fd} 分配会话 conversation_id={$conversationId}\n";
            } else {
                echo "[AGENT] fd={$fd} 无可用会话, Redis会话数=" . count($conversationIds) . "\n";
            }

            // 客服登录同步 - [修改] 返回所有会话列表和未读汇总
            try {
                $unreadSummary = app(MessageService::class)->getAgentUnreadSummary($tenantId, $agentId);
                $pendingCount = count(app(ConversationService::class)->getPendingConversations($tenantId));

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
                error_log('[WEBSOCKET] agent_sync error: ' . $e->getMessage());
            }

            // [新增] 刷新客服活跃 TTL
            self::refreshAgentTTL($tenantId, $agentId);
        }

        // 写入连接表
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (!empty($table)) {
            $effectiveAgentId = $auth['agent_id'];
            if ($auth['role'] === 'visitor' && $conversation && (int) ($conversation['agent_id'] ?? 0) > 0) {
                $effectiveAgentId = (int) $conversation['agent_id'];
            }

            $table->set($fd, [
                'fd'              => (int) $fd,
                'tenant_id'       => $auth['tenant_id'],
                'visitor_id'      => $auth['visitor_id'],
                'uid'             => $auth['uid'],
                'agent_id'        => $effectiveAgentId,
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
            'agent_id'        => $auth['role'] === 'visitor'
                ? (int) ($conversation['agent_id'] ?? 0)
                : $auth['agent_id'],
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
            $newAgentId = (int) ($conversation['agent_id'] ?? 0);
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
                    error_log('[WEBSOCKET] pushToAgent(onOpen) error: ' . $e->getMessage());
                }
            }
        }

        // 统一日志
        $logAgentId = $auth['role'] === 'visitor'
            ? (int) ($conversation['agent_id'] ?? 0)
            : $auth['agent_id'];
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

            // 客服登录同步请求
            if ($msgType === 'agent_login_sync') {
                $tenantId = (int) ($row['tenant_id'] ?? 0);
                $agentId  = (int) ($row['agent_id'] ?? 0);

                if ($agentId > 0 && $tenantId > 0) {
                    // [新增] 从 Redis 加载所有会话ID
                    $conversationIds = self::getAgentConversationIds($tenantId, $agentId);
                    $allConversations = self::getAgentAllConversations($tenantId, $agentId);

                    // 同时尝试分配一个会话作为当前会话
                    $result = self::assignAgentConversation($tenantId, $agentId);
                    $conversationId = $result['conversationId'];

                    if ($conversationId > 0) {
                        $row['conversation_id'] = $conversationId;
                    }

                    // [新增] 同步 Table 行
                    self::syncAgentTableConversationIds($tenantId, $agentId, $conversationIds);

                    // 获取未读汇总
                    $unreadSummary = app(MessageService::class)->getAgentUnreadSummary($tenantId, $agentId);
                    $pendingCount = count(app(ConversationService::class)->getPendingConversations($tenantId));

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
                    self::refreshAgentTTL($tenantId, $agentId);
                }

                $table->set($fdKey, $row);
                return;
            }

            $tenantId       = (int) ($row['tenant_id'] ?? 0);
            $visitorId      = (string) ($row['visitor_id'] ?? '');
            $uid            = (int) ($row['uid'] ?? 0);
            $agentId        = (int) ($row['agent_id'] ?? 0);
            //这里不用去查，而是发消息时带上
            // $conversationId = (int) $data['conversation_id'] ?? 0;
            $conversationId = (int) ($row['conversation_id'] ?? 0);
            
            // 客服发消息时反查会话（兜底）
            
            if ($role === 'agent' && $conversationId <= 0 && $agentId > 0 && $tenantId > 0) {
                try {
                    $activeConversation = app(ConversationService::class)
                        ->getAgentActiveConversation($tenantId, $agentId);

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
                    error_log('[WEBSOCKET] agent conversation lookup error: ' . $e->getMessage());
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
                    $latestConv = Db::name('im_conversation')
                        ->where('id', $conversationId)
                        ->find();
                    $currentAgentId = (int) ($latestConv['agent_id'] ?? 0);

                    $onlineAgentCount = self::getOnlineAgentCount($tenantId);

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
                            $assignedAgentId = self::autoAssignAgent($tenantId, $visitorId);
                            if ($assignedAgentId > 0) {
                                Db::name('im_conversation')
                                    ->where('id', $conversationId)
                                    ->update([
                                        'agent_id'   => $assignedAgentId,
                                        'updated_at' => time(),
                                    ]);
                                $row['agent_id'] = $assignedAgentId;
                                $table->set($fdKey, $row);

                                // [新增] 将会话ID加入客服的 Redis 集合
                                self::addAgentConversation($tenantId, $assignedAgentId, $conversationId);

                                // [新增] 同步更新客服 Table 行
                                self::syncAgentTableConversationIds(
                                    $tenantId,
                                    $assignedAgentId,
                                    self::getAgentConversationIds($tenantId, $assignedAgentId)
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
            if ($role === 'agent' && $msgType === 'agent_reply') {
                $fromAgentId = (int) ($data['agent_id'] ?? 0);
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
                        'agent',
                        null,
                        null,
                        'text',
                        $content,
                        $fromAgentId
                    );

                    app(PushMessageService::class)->pushToVisitor(
                        $tenantId,
                        $conversationId,
                        'agent_message',
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
            $agentId = (int) ($row['agent_id'] ?? 0);
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($agentId > 0 && $tenantId > 0) {
                $key = self::getAgentConversationKey($tenantId, $agentId);
                try {
                    CacheService::instance()->redisHandler()->del($key);
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