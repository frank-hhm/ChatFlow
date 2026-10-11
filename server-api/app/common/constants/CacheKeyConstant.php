<?php
/**
 * @Date: 2025/6/21 7:34
 */
declare(strict_types=1);
namespace app\common\constants;

/**
 * @Constants
 */
class CacheKeyConstant
{
    //JWT redis驱动名称
    public const JWT_REDIS_DRIVER = 'redis_jwt';
    //用户 redis驱动名称
    public const MEMBER_REDIS_DRIVER = 'redis_member';
    //产品redis驱动名称
    public const QUEUE_REDIS_DRIVER = 'redis_queue';
    //Api提交重复检测
    const API_SUBMIT_LOCK = 'api:submit:lock';


    /**
     * Redis Key 前缀：客服会话集合
     * key 格式: conversations:{tenant_id}:{account_id}
     * 值类型: SET (conversation_id 集合)
     */
    public const CONVERSATIONS_KEY = 'conversations';

    /**
     * Redis Key 过期时间（秒）- 客服离线30分钟后自动清理
     */
    public const CONVERSATIONS_TTL = 1800;

}