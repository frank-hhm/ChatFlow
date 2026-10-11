<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */
declare(strict_types=1);


namespace app\common\services\im;

use app\common\constants\CacheKeyConstant;
use app\common\dao\im\ConversationDao;

use app\common\services\common\CacheService;
use think\facade\Log;

/**
 * IM会话 缓存
 * Class ConversationCacheService
 */
class ConversationCacheService extends \app\common\services\BaseService
{

    public mixed $cacheService = null;

    /**
     * ConversationCacheService constructor.
     * @param ConversationDao $dao
     */
    public function __construct(ConversationDao $dao)
    {
        $this->cacheService = app(CacheService::class)->redisHandler();
    }

    // ==================== Redis 辅助方法 ====================

    /**
     * 生成 Redis Key
     */
    public function getTenantAccountConversationKey(int $tenantId, int $accountId): string
    {
        return sprintf('%s:%d:%d', CacheKeyConstant::CONVERSATIONS_KEY, $tenantId, $accountId);
    }

    /**
     * 从 Redis 获取客服的所有会话ID列表
     * @return int[] 会话ID数组
     */
    public function getTenantAccountConversationIds(int $tenantId, int $accountId): array
    {
        try {
            $key = $this->getTenantAccountConversationKey($tenantId, $accountId);
            $ids = $this->cacheService->smembers($key);
            return array_map('intval', $ids);
        } catch (\Throwable $e) {
            Log::error('[WEBSOCKET] 从 Redis 获取客服的所有会话ID列表 错误: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 将会话ID添加到客服的 Redis 集合中
     */
    public function addTenantAccountConversation(int $tenantId, int $accountId, int $conversationId): void
    {
        try {
            $key = $this->getTenantAccountConversationKey($tenantId, $accountId);
            $this->cacheService->sadd($key, $conversationId);
            // 设置过期时间，防止客服永久离线导致脏数据
            $this->cacheService->expire($key, CacheKeyConstant::CONVERSATIONS_TTL);
        } catch (\Throwable $e) {
            Log::error('[WEBSOCKET] 将会话ID添加到客服的 Redis 集合中错误: ' . $e->getMessage());
        }
    }



    /**
     * 从客服的 Redis 集合中移除会话ID
     */
    public function removeTenantAccountConversation(int $tenantId, int $accountId, int $conversationId): void
    {
        try {
            $key = $this->getTenantAccountConversationKey($tenantId, $accountId);
            $this->cacheService->srem($key, $conversationId);
        } catch (\Throwable $e) {
            Log::error('[WEBSOCKET] 从客服的 Redis 集合中移除会话ID 错误: ' . $e->getMessage());
        }
    }

    /**
     * 刷新客服 Redis Key 的过期时间（每次活跃时调用）
     */
    public function refreshTenantAccountTTL(int $tenantId, int $accountId): void
    {
        try {
            $key = $this->getTenantAccountConversationKey($tenantId, $accountId);
            $this->cacheService->expire($key, CacheKeyConstant::CONVERSATIONS_TTL);
        } catch (\Throwable $e) {
            // 忽略
            Log::error('[WEBSOCKET] 刷新客服 Redis Key 的过期时间（每次活跃时调用）: ' . $e->getMessage());
        }
    }
}

