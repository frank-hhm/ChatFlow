<?php
/**
 * @Date: 2025/6/25 17:00
 */
declare(strict_types=1);


namespace app\common\services\common;

use app\common\constants\CacheKeyConstant;
use app\common\exception\CommonException;
use app\common\services\BaseService;
use app\websocket\utility\RedisQueueUtility;
use EasySwoole\EasySwoole\Config;
use EasySwoole\Queue\Job;
use think\Validate;
use app\common\constants\StatusCode;

/**
 * 生产socket任务
 * Class PushWebSockQueueService
 */
class PushWebSockQueueService  extends BaseService
{

    // 生产普通任务
    public function create($data = []): void
    {
        $action = 'push_common_message';
        $this->push($action,$data);
    }
    public function createCommonMessage($data = []): void
    {
        $action = 'push_common_message';
        $this->push($action,$data);
    }
    private function push($action,$data = []){
        $cacheService = app(CacheService::class)->setRedisName(CacheKeyConstant::QUEUE_REDIS_DRIVER);
        $data['time'] = time();
        $data['action'] = $action;
        $job = new Job();
        $job->setJobData($data);
        $cacheService->handler()->rPush('websocket_message',serialize($job));
    }
}