<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:32
 */
declare(strict_types=1);

namespace app\client\middleware;

use app\Request;
use app\common\middleware\MiddlewareInterface;

/**
 * Class BaseMiddleware
 * @package app\client\middleware
 */
class BaseMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next, bool $force = true)
    {
        if (!Request::hasMacro('adminId')) {
            Request::macro('adminId', function(){ return 0; });
        }
        return $next($request);
    }
}

