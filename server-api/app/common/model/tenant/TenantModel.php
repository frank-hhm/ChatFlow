<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2023/12/16 13:23
 */

declare(strict_types=1);

namespace app\common\model\tenant;

use app\common\enum\EnumFactory;
use app\common\helper\ArrayHelper;
use  app\common\model\BaseModel;
use app\common\traits\ModelTrait;
use think\model\concern\SoftDelete;

/**
 * 租户模型
 * Class TenantModel
 * @package app\common\model\tenant
 */
class TenantModel extends BaseModel
{
    use ModelTrait;
    use SoftDelete;

    ## 数据表主键
    protected $pk = 'id';

    ## 模型名称
    protected $name = 'tenant';
    protected string $deleteTime = 'delete_time';
    protected $defaultSoftDelete = 0;
    /**
     * 权限获取器
     */
    public function getRolesAttr($value)
    {
        return ArrayHelper::arrayItemToInt(!empty($value)?explode(',', $value):[]);
    }

    /**
     * 状态获取器
     */
    public function getStatusAttr($value)
    {
        return EnumFactory::instance('status')->getItem($value);
    }
}
