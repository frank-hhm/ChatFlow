<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */

declare(strict_types=1);

namespace app\common\dao\tenant;

use app\common\model\tenant\TenantModel;

/**
 * Class TenantDao
 * @package app\common\dao\tenant
 */
class TenantDao extends \app\common\dao\BaseDao
{
    protected array $field = ['*'];

    protected function setModel(): string
    {
        return TenantModel::class;
    }
}

