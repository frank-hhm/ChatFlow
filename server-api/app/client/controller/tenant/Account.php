<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:32
 */
declare(strict_types=1);

namespace app\client\controller\tenant;

use app\client\controller\Base;
use think\facade\{App};
use app\common\services\tenant\TenantAccountService;
/**
 * 账号
 * Class Account
 * @package app\client\controller\tenant
 */
class Account extends Base
{
    /**
     * Account constructor.
     */
    public function __construct(App $app , TenantAccountService $service)
    {
        parent::__construct($app);
        $this->service = $service;
    }

    /**
     * 账号详细
     * @noAuth(true)
     * @method(GET)
     */
    public function defaultDetail(): void
    {
        $this->success($this->accountInfo);
    }
}
