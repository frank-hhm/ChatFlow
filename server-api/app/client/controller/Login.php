<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:32
 */
declare(strict_types=1);

namespace app\client\controller;
use app\BaseController;
use app\common\exception\CommonException;
use app\common\services\common\CacheService;
use app\common\services\common\CaptchaService;
use app\common\services\tenant\TenantAccountService;
use think\Cache;
use think\facade\Queue;

/**
 * 登陆
 * @NoMiddleware("app\client\middleware\AuthMiddleware")
 */
class Login extends BaseController
{
    /**
     * 登陆授权
     * @method(POST)
     */
    public function index()
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? $this->request->ip();
        $cacheService = app(CacheService::class);
        $cacheKey = 'client:login:'.$ip;
        $cacheCount = $cacheService->get($cacheKey);
        empty($cacheCount) && $cacheCount = 0;
        $cacheCount++;
        $cacheService->set($cacheKey, $cacheCount,15 * 60);
        if($cacheCount > 10){
            throw new CommonException('您登录次数过多，请稍后再试',703);
        }
        try {
            $data = $this->vali([
                'account.require' => '账号不能为空!',
                'account.min:3'   => '账号不能少于3位字符!',
                'password.require' => '登录密码不能为空!',
                'password.min:4'   => '登录密码不能少于4位字符!',
                'captcha_code.require'   => '图形验证码不能为空!',
                'captcha_uniqid.require'   => '图形验证标识不能为空!',
            ]);
            if(!CaptchaService::instance()->check($data['captcha_code'], $data['captcha_uniqid'])) {
                throw new CommonException('验证码错误，请重新输入',702);
            }
            $login = TenantAccountService::instance()->login($data['account'], $data['password'], 'client');
            throw new CommonException('',0);
        }catch (\Exception $e){

            if($e->getCode() === 0 && !empty($login)){
                $this->success($login);
            }else {
                $this->error($e->getMessage(),[],$e->getCode());
            }
        }
    }
}
