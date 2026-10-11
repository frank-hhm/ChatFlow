<?php
/**
 * @Date: 2025/6/25 17:50
 */
declare(strict_types=1);
namespace app\websocket\tasks;

use app\common\constants\CacheKeyConstant;
use app\common\constants\StringConstant;
use app\common\services\common\CacheService;
use app\common\services\common\ConsoleLogService;
use app\websocket\services\PushMessageService;
use EasySwoole\Component\Process\AbstractProcess;
use EasySwoole\Component\TableManager;
use Swoole\Coroutine;

class ListenRedisPublishTask extends AbstractProcess
{
    public mixed $cacheService = null;

    public mixed $logService = null;
    public function run($arg)
    {
        $this->cacheService = app(CacheService::class)->setRedisName(CacheKeyConstant::QUEUE_REDIS_DRIVER);
        $this->logService = app(ConsoleLogService::class);
        // TODO: Implement run() method.
        $this->logService->create(StringConstant::WEBSOCKET_CLIENT . "【Redis消息队列】检测程序开始", true);
       
         $this->addTick(5000, function () {
            go(function () {
                
                $this->cacheService->subscribe(['push_broadcast_message'], function ($redis, $channel, $result) {
                    $result = json_decode($result, true);
    //                echo "接收到Redis消息：{$channel} \n";
                    $type = $result['type'] ?? 'pong';
                    $data = $result['data'] ?? [];
                    app(PushMessageService::class)->push($type,$data);
                });
            });
        });
    }
}