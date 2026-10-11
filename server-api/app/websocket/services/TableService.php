<?php
/**
 * @Date: 2025/6/23 17:11
 */
declare(strict_types=1);
namespace app\websocket\services;

use app\common\constants\StringConstant;
use app\common\services\BaseService;
use app\websocket\tasks\BroadcastTask;
use EasySwoole\Component\TableManager;

class TableService extends BaseService
{
    public static function broadcast(string $message)
    {


        $server = \EasySwoole\EasySwoole\ServerManager::getInstance()->getSwooleServer();
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if ($table){
            echo "当前在线人数：" . $table->count() . "\n";
            foreach ($table as $fd => $row) {
                if ($server->exist((int)$fd)) {
                    $server->push((int)$fd, $message);
                } else {
                    $table->del( (int)$fd); // 清理已经不存在的连接
                }
            }
        }else{
            echo "当前在线人数：0\n";
        }
    }


    /**
     * 广播给订阅
     */
    public static function broadcastSubscribe($pid,string $message)
    {
        $task = new BroadcastTask($message);
        $taskManager = \EasySwoole\EasySwoole\Task\TaskManager::getInstance();
        $taskManager->async($task);
//        $server = \EasySwoole\EasySwoole\ServerManager::getInstance()->getSwooleServer();
//        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
//        if (!$table) return;
//        foreach ($table as $fd => $row) {
//            if ($server->isEstablished((int)$fd)) {
////                    if (!empty($row['market_subscribe']) && $row['market_subscribe'] !== 'all' && $pid != $row['market_subscribe']){
////                        continue;
////                    }
//                $server->push((int)$fd, $message);
//            } else {
//                $table->del( (int)$fd); // 清理已经不存在的连接
//            }
//        }
    }



    /**
     * 广播给用户
     */
    public static function broadcastMember($uid,string $message)
    {
        $server = \EasySwoole\EasySwoole\ServerManager::getInstance()->getSwooleServer();
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if ($table){
            foreach ($table as $fd => $row) {
                if ($server->exist((int)$fd)) {
                    if (!empty($row['uid']) && $row['uid'] === (int)$uid){
                        $server->push((int)$fd, $message);
                    }
                } else {
                    $table->del( (int)$fd);
                }
            }
        }
    }

    public static function getOnlineCount(): int
    {
        $count = 0;
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        foreach ($table as $fd => $row) {
            if (isset($row['last_time'])) {
                $count++;
            }
        }
        return $count;
    }
}