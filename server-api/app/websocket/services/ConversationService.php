<?php
/**
 * 会话服务
 * 优化版：修复 pending 状态复用问题、完善异常处理、Redis维护客服多会话
 */
declare(strict_types=1);

namespace app\websocket\services;

use app\common\constants\CacheKeyConstant;
use app\common\constants\StringConstant;
use app\common\enum\im\ConversationStatusEnum;
use app\common\services\common\CacheService;
use app\websocket\WebSocketEvent;
use EasySwoole\Component\TableManager;
use EasySwoole\EasySwoole\ServerManager;

use think\facade\Db;
use think\facade\Log;
class ConversationService
{

    /**
     * 默认渠道
     */
    private const DEFAULT_CHANNEL = 'web';


    /**
     * 查找客服的 Table 行并更新 conversation_ids（用于实时同步）
     */
    public function syncTenantAccountTableConversationIds(int $tenantId, int $accountId, array $conversationIds): void
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return;
        }

        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['account_id'] ?? 0) == $accountId
                && ($row['role'] ?? '') === 'agent'
            ) {
                $row['conversation_ids'] = $conversationIds;
                $table->set($fd, $row);
            }
        }
    }

    /**
     * 向指定客服的所有连接推送 WebSocket 消息
     * @return bool 是否找到至少一个连接
     */
    public function pushToTenantAccountViaTable(int $tenantId, int $accountId, string $type, array $data): bool
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return false;
        }

        $server = ServerManager::getInstance()->getServer();
        $found = false;

        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['account_id'] ?? 0) == $accountId
                && ($row['role'] ?? '') === 'client'
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

    // ==================== 客服分配策略 ====================

    /**
     * 自动分配客服给访客会话
     * 策略优先级：
     * 1. 优先分配给上次接待过该访客的在线客服（老访客找回上次接待）
     * 2. 从当前在线客服中按会话数最少分配（负载均衡）
     * 3. 降级到配置列表（兜底）
     */
    public function autoAssignTenantAccount(int $tenantId, string $visitorId = ''): int
    {
        // 策略1: 优先分配给上次接待过该访客的在线客服
        if ($visitorId !== '') {
            $historyAgent = app(\app\common\services\im\ConversationService::class)->getHistoryIConversationsByIds($tenantId,$visitorId);
            if ($historyAgent && $this->isTenantAccountOnline($tenantId, (int) $historyAgent)) {
                return (int) $historyAgent;
            }
        }

        // 策略2: 从当前在线客服中按会话数最少分配（负载均衡）
        $onlineAgentIds = $this->getOnlineTenantAccountIds($tenantId);
        if (!empty($onlineAgentIds)) {
            $agentId = $this->pickLeastBusyTenantAccount($tenantId, $onlineAgentIds);
            if ($agentId > 0) {
                return $agentId;
            }
        }

        // 策略3: 降级到配置列表（兜底，兼容无在线客服时的开发环境）
        return empty($agentList) ? 0 : (int) $agentList[0];
    }

    /**
     * 检查客服是否在线
     * 修复：Swoole\Table 没有 rows() 方法，直接 foreach 迭代
     */
    public function isTenantAccountOnline(int $tenantId, int $accountId): bool
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return false;
        }
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['account_id'] ?? 0) == $accountId
                && ($row['role'] ?? '') === 'client'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否有在线客服
     */
    public function getOnlineTenantAccountCount(int $tenantId): int
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return 0;
        }

        $count = 0;
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId && ($row['role'] ?? '') === 'client') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取指定租户的在线客服ID列表
     * @return int[] 在线客服ID数组（去重）
     */
    public function getOnlineTenantAccountIds(int $tenantId): array
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return [];
        }

        $accountIds = [];
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['role'] ?? '') === 'client'
                && ($row['account_id'] ?? 0) > 0
            ) {
                $accountIds[] = (int) $row['account_id'];
            }
        }

        return array_unique($accountIds);
    }

    /**
     * 从在线客服中挑选当前会话数最少的客服
     * @return int 客服ID，无可用客服返回0
     */
    public function pickLeastBusyTenantAccount(int $tenantId, array $accountIds): int
    {
        if (empty($accountIds)) {
            return 0;
        }
        $minAccountId = 0;
        $minCount = PHP_INT_MAX;

        foreach ($accountIds as $accountId) {
            $count = app(\app\common\services\im\ConversationService::class)->getConversationCountByStatus($tenantId,$accountId);
            if ($count < $minCount) {
                $minCount = $count;
                $minAccountId = $accountId;
            }
        }

        return $minAccountId;
    }

    // ==================== 会话分配 ====================

    /**
     * 客服分配/获取活跃会话（统一方法）
     * 修复：客服连接时不再创建空会话，只查找已有的 pending/open 会话
     * @return array{conversationId: int, conversation: array|null}
     */
    public function assignTenantAccountConversation(int $tenantId, int $accountId, string $channel = 'web'): array
    {
        $conversationService = app(\app\common\services\im\ConversationService::class);
        // 1. 查找该客服已有的活跃会话（取最新的）
        $activeConv = $conversationService->getConversationByStatus($tenantId,$accountId);

        if ($activeConv) {
            return [
                'conversationId' => (int) $activeConv['id'],
                'conversation'   => $activeConv,
            ];
        }

        // 2. 从未分配池接管一个 pending 会话
        $pendingConv = $conversationService->getConversationByStatus($tenantId,0,["create_time"=>"asc"]);

        if ($pendingConv) {
            $conversationService->dao->model->where('id', $pendingConv['id'])
                ->update([
                    'account_id'   => $accountId,
                    'status'     => 'open',
                    'update_time' => time(),
                ]);

            return [
                'conversationId' => (int) $pendingConv['id'],
                'conversation'   => [
                    'id'         => (int) $pendingConv['id'],
                    'account_id'   => $accountId,
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
     * @return array 会话详情数组
     */
    public function getTenantAccountAllConversations(int $tenantId, int $accountId): array
    {
        $conversationCacheService = app(\app\common\services\im\ConversationCacheService::class);
        $conversationService = app(\app\common\services\im\ConversationService::class);
        // 先从 Redis 获取会话ID列表
        $conversationIds = $conversationCacheService->getTenantAccountConversationIds($tenantId, $accountId);

        if (!empty($conversationIds)) {
            // Redis 有数据，从数据库获取详情
            $conversations = $conversationService->getConversationsByIds($tenantId, $conversationIds);

            // 同步修复：把数据库中已分配给该客服但Redis中缺失的会话加回去
            $dbConvs = $conversationService->getConversationsByStatus($tenantId, $accountId,true);

            foreach ($dbConvs as $convId) {
                if (!in_array($convId, $conversationIds)) {
                    $conversationCacheService->addTenantAccountConversation($tenantId, $accountId, $convId);
                }
            }

            return $conversations;
        }

        // Redis 无数据，从数据库查询并回填 Redis
        $conversations =$conversationService->getConversationsByStatus($tenantId, $accountId);

        foreach ($conversations as $conv) {
            $conversationCacheService->addTenantAccountConversation($tenantId, $accountId, (int) $conv['id']);
        }

        return $conversations;
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
            app(\app\common\services\im\ConversationService::class)->markConversationPending($conversationId);
        }

        $row['conversation_id'] = 0;
        $table->set((string) $fd, $row);
    }

    /**
     * 分配客服到会话
     * 【新增】同时更新 Redis 集合
     */
    public function assignTenantAccount(int $conversationId, int $accountId): void
    {

        $conversationService = app(\app\common\services\im\ConversationService::class);
        $conversationCacheService = app(\app\common\services\im\ConversationCacheService::class);
        $conversationService->refreshConversation($conversationId, [
            'account_id' => $accountId,
            'status'   => ConversationStatusEnum::STATUS_OPEN,
        ]);

        // [新增] 同步到 Redis
        try {
            $conv = $conversationService->getDetailById($conversationId);
            if ($conv) {
                $tenantId = (int) ($conv['tenant_id'] ?? 0);
                if ($tenantId > 0) {
                    $conversationCacheService->addTenantAccountConversation($tenantId, $accountId, $conversationId);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[WEBSOCKET] 【新增】同时更新 Redis 集合 错误: ' . $e->getMessage());
        }
    }

    /**
     * 关闭会话
     * 【修复】同时清理 Redis 集合
     */
    public function closeConversation(int $conversationId): void
    {
        $conversationService = app(\app\common\services\im\ConversationService::class);
        $conversationCacheService = app(\app\common\services\im\ConversationCacheService::class);
        // 先获取会话信息（tenant_id, agent_id）
        $conv = $conversationService->getDetailById($conversationId);
        $tenantId = (int) ($conv['tenant_id'] ?? 0);
        $agentId  = (int) ($conv['agent_id'] ?? 0);

        // 关闭会话
        $conversationService->refreshConversation($conversationId, [
            'status'    => ConversationStatusEnum::STATUS_CLOSED,
            'closed_at' => time(),
        ]);

        // [新增] 从 Redis 集合中移除
        if ($agentId > 0 && $tenantId > 0) {
            $conversationCacheService->removeTenantAccountConversation($tenantId, $agentId, $conversationId);
        }
    }

    /**
     * 关闭会话（不清理 Redis，用于 WebSocketEvent 中已手动管理的场景）
     */
    public function closeConversationSilent(int $conversationId): void
    {
        $conversationService = app(\app\common\services\im\ConversationService::class);
        $conversationService->refreshConversation($conversationId, [
            'status'    => ConversationStatusEnum::STATUS_CLOSED,
            'close_time' => time(),
        ]);
    }

}