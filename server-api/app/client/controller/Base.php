<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:32
 */
declare(strict_types=1);

namespace app\client\controller;
use app\BaseController;
/**
 * 控制器基础类
 */
class Base extends BaseController
{

    /**
     * 当前租户账号信息
     */
    protected mixed $accountInfo;

    /**
     * 当前租户ID
     */
    protected int $tenantId;
    /**
     * 当前租户账号ID
     */
    protected int $accountId;

    // 初始化
    protected function initialize(): void
    {
        $this->tenantId = $this->request->tenantId() ?? 0;
        $this->accountId = $this->request->accountId() ?? 0;
        $this->accountInfo = $this->request->accountInfo() ?? [];
    }
}
