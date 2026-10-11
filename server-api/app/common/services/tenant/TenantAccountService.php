<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */
declare(strict_types=1);


namespace app\common\services\tenant;

use app\common\helper\ArrayHelper;
use app\common\helper\StringHelper;
use app\common\exception\CommonException;
use app\common\dao\tenant\TenantAccountDao;
use app\common\services\common\CacheService;
use app\common\services\common\JwtAuthService;
use app\common\services\tenant\TenantService;

use think\facade\Db;
/**
 * 租户账号
 * Class TenantAccountService
 */
class TenantAccountService extends \app\common\services\BaseService
{

    /**
     * TenantAccountService constructor.
     * @param TenantAccountDao $dao
     */
    public function __construct(TenantAccountDao $dao)
    {
        $this->dao = $dao;
    }

    // 后台登陆获取菜单获取token
    public function login(string $account, string $password, string $type): array
    {
        $info = $this->verifyLogin($account, $password);

        $tokenInfo = JwtAuthService::instance()->createToken($info->id, $type);
        $exp =  $tokenInfo['params']['exp'] - $tokenInfo['params']['iat'];
        return [
            'token' => $tokenInfo['token'],
            'expires_time' => $exp,
            'info' => $info->hidden(['pwd'])
        ];
    }

    // 账号登陆
    public function verifyLogin(string $account, string $password)
    {
        $info = $this->dao->model->where(['phone|email' => $account, 'status' => 1])->find();

        if (!$info) {
            throw new CommonException('账号不存在!');
        }
        if (!$info->status) {
            throw new CommonException('您已被禁止登录!');
        }
        if (!password_verify($password, $info->pwd)) {
            throw new CommonException('账号或密码错误，请重新输入',701);
        }
        $info->save();

        return $info;
    }
}

