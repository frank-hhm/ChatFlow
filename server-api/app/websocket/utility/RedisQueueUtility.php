<?php
/**
 * @Date: 2025/6/25 17:03
 */
declare(strict_types=1);
namespace app\websocket\utility;

use EasySwoole\Component\Singleton;
use EasySwoole\Queue\Queue;

class RedisQueueUtility extends Queue
{
    use Singleton;

}