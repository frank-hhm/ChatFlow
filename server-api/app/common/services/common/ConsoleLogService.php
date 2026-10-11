<?php
/**
 * @Date: 2025/6/23 16:27
 */
declare(strict_types=1);
namespace app\common\services\common;

use app\common\constants\StringConstant;
use think\facade\Log;

class ConsoleLogService
{
    public function create(string $msg = '',$isEcho = false,$category = 'market'): void
    {
//        if($isEcho){
//            $time =  date("Y-m-d H:i:s",time());
//            echo "[".$time."]".StringConstant::CONSOLE_LOG_SEPARATOR.":".$msg."\n";
//        }else{
//            Log::record($msg,$category);
//        }
        Log::write($msg,$category);
    }

}