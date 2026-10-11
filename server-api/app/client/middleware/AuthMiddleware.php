<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:32
 */
declare(strict_types=1);

namespace app\client\middleware;

use app\common\constants\StatusCode;
use app\common\exception\CommonException;
use app\common\middleware\MiddlewareInterface;
use app\Request;
use think\facade\Config;
use app\common\services\tenant\TenantAccountAuthService;

/**
 * 登陆验证中间件
 * Class AuthMiddleware
 * @package app\client\middleware
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $AuthoriZation = $request->header(Config::get('cookie.token_name', 'Authori-zation'));

        if (empty($AuthoriZation)) {
            throw new CommonException(StatusCode::ERR_LOGIN);
        }

        $token = trim(ltrim($request->header(Config::get('cookie.token_name', 'Authori-zation')), 'Bearer'));
        $authData = TenantAccountAuthService::instance()->parseToken($token);
        $info = $authData['info'] ?? [];
        Request::macro('isLogin', function () use (&$info) {
            return !is_null($info);
        });
        Request::macro('tenantId', function () use (&$info) {
            return $info['tenant_id'] ?? 0;
        });
        Request::macro('accountId', function () use (&$info) {
            return $info['id'] ?? 0;
        });

        Request::macro('accountInfo', function () use (&$info) {
            return $info;
        });

        return $next($request);
    }
}


