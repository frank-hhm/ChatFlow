<?php
/**
 * @Date: 2025/6/25 17:04
 */
declare(strict_types=1);
namespace app\websocket\process;


use app\websocket\services\PushMessageService;
use app\websocket\services\TableService;
use app\websocket\utility\RedisQueueUtility;
use EasySwoole\Component\Process\AbstractProcess;
use EasySwoole\Queue\Job;
use app\common\services\common\ConsoleLogService;
use app\common\constants\StringConstant;

class QueueProcess extends AbstractProcess
{
    protected function run($arg)
    {
        // TODO: Implement run() method.
        
        $logService = app(ConsoleLogService::class);
        $logService->create(StringConstant::WEBSOCKET_CLIENT . "【QueueProcess】检测程序开始", true);
        go(function (){
            try {
                RedisQueueUtility::getInstance()->consumer('websocket_message')->listen(function (Job $job){
                    $res = $job->getJobData();
                    echo "【QueueProcess】----websocket_message2\n";
                    if(isset($res['action']) ){
                        $type = $res['type'] ?? 'pong';
                        $data = $res['data'] ?? [];
                        if( $res['action'] == 'push_member_message'){
                            $uid = $res['member_id'];
                            app(TableService::class)->broadcastMember($uid,json_encode(['type' =>$type, 'data' => $data],JSON_UNESCAPED_UNICODE));
                        }else{
                            app(PushMessageService::class)->push($type,$data);
                        }
                    }
                });
            }catch (\Exception $e){
                echo $e->getMessage();
            }
        });
    }
}