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
use app\common\dao\tenant\TenantDao;

use think\facade\Db;
/**
 * 租户
 * Class TenantService
 */
class TenantService extends \app\common\services\BaseService
{

    /**
     * TenantService constructor.
     * @param TenantDao $dao
     */
    public function __construct(TenantDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取列表
     */
    public function getList(array $param = []): array
    {
        [$page, $limit] = $this->dao->getPageValue();
        $list = $this->dao->model->where([])->order(['create_time DESC'])->page($page)->paginate($limit)->toArray();
        return $list;
    }

    /**
     * 新增租户
     */
    public function createTenant($data = []){
        Db::startTrans();
        try {
            $tenant["company_name"] = $data["company_name"];
            $res[] = $tenantId = $this->dao->model->insertGetId($tenant);
            $data["is_bind"] = 1;
            $data["tenant_id"] = $tenantId;
            $data['create_time'] = time();
            //修改密码
            if ($data['pwd']) {
                $data['pwd'] = $this->dao->passwordHash($data['pwd']);
            }else{
                unset($data['pwd']);
            }
            $res[] = app(TenantAccountService::class)->dao->model->save($data);
            if(!ArrayHelper::checkArr($res)){
                Db::collback();
                return false;
            }
            Db::commit();
            return true;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            Db::collback();
            return false;
        }
    }
}

