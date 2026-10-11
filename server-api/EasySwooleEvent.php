<?php


namespace EasySwoole\EasySwoole;


use app\websocket\process\QueueProcess;
use app\websocket\utility\RedisQueueUtility;
use app\websocket\WebSocketEvent;
use app\websocket\WebSocketParser;
use app\websocket\tasks\ListenRedisPublishTask;
use EasySwoole\Component\TableManager;
use EasySwoole\Component\Timer;
use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\Queue\Job;
use EasySwoole\Redis\Redis;
use EasySwoole\RedisPool\RedisPool;
use EasySwoole\Socket\Dispatcher;
use think\App;
use app\common\constants\StringConstant;

class EasySwooleEvent implements Event
{
    public static function initialize()
    {
        date_default_timezone_set('Asia/Shanghai');
        TableManager::getInstance()->add(
            StringConstant::WEBSOCKET_CLIENT,
            [
                'fd'                 => ['type' => \Swoole\Table::TYPE_INT,    'size' => 8],
                'role'               => ['type' => \Swoole\Table::TYPE_STRING, 'size' => 16],   // visitor / client
                'tenant_id'          => ['type' => \Swoole\Table::TYPE_INT,    'size' => 8],
                'account_id'         => ['type' => \Swoole\Table::TYPE_INT,    'size' => 8],
                'visitor_id'         => ['type' => \Swoole\Table::TYPE_STRING, 'size' => 64],
                'uid'                => ['type' => \Swoole\Table::TYPE_INT,    'size' => 8],
                'conversation_id'    => ['type' => \Swoole\Table::TYPE_INT,    'size' => 8],
                'last_time'          => ['type' => \Swoole\Table::TYPE_FLOAT,  'size' => 8],
            ],
            10240   // 容量，按并发连接数调
        );
    }
    public static function mainServerCreate(EventRegister $register)
    {
//        ServerManager::getInstance()->getSwooleServer()->set([
//            // 基础性能
//            'worker_num' => 8,                          // 工作进程数（建议 CPU 核数）
//            'task_worker_num' => 8,                     // Task 进程数
//            'reactor_num' => 4,                         // Reactor 线程数
//
//            // ⚡ 关键：启用多线程发送提升 push 性能
//            'enable_reuse_port' => true,
//            'socket_buffer_size' => 1024 * 1024 * 1024,  // 128MB 缓冲区
//            'max_wait_time' => 5,
//
//            // 消息长度限制（用于 WebSocket 大消息）
//            'package_max_length' => 1024 * 1024 * 1024,   // 8MB
//            'buffer_output_size' => 1024 * 1024 * 1024,   // 输出缓冲 2MB
//
//            // Task 配置（如果用了 Task）
//            'task_enable_coroutine' => true,
//        ]);
        //ServerManager::getInstance()->getSwooleServer()->addProcess((new ListenRedisPublishTask('ListenRedisPublishTask'))->getProcess());

        // 初始化分发器
        $conf = new \EasySwoole\Socket\Config();
        $conf->setType($conf::WEB_SOCKET);
        $conf->setParser(WebSocketParser::class);
        // 注册WebSocket事件
//        $register->set($register::onHandShake, [$websocketEvent, 'onHandShake']);
        $register->add($register::onMessage, [WebSocketEvent::class, 'onMessage']);
        $register->add($register::onOpen, [WebSocketEvent::class, 'onOpen']);
        $register->add($register::onClose, [WebSocketEvent::class, 'onClose']);


        $config = Config::getInstance()->getConf('REDIS');

        $driver = new \EasySwoole\Queue\Driver\RedisQueue(new \EasySwoole\Redis\Config($config));

        RedisQueueUtility::getInstance($driver);
        // 注册一个消费进程
        $processConfig = new \EasySwoole\Component\Process\Config([
            'processName' => 'QueueProcess', // 设置 自定义进程名称
            'processGroup' => 'Queue', // 设置 自定义进程组名称
            'enableCoroutine' => true, // 设置 自定义进程自动开启协程
        ]);
        \EasySwoole\Component\Process\Manager::getInstance()->addProcess(new QueueProcess($processConfig));

        // 应用初始化
        (new App())->initialize();
    }
}