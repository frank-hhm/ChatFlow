<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2023/12/16 13:26
 */
declare(strict_types=1);

namespace app\common\services\system;

use app\common\dao\system\AdminDao;
use app\common\services\BaseService;
use app\common\services\common\CacheService;
use app\common\services\common\JwtAuthService;
use app\common\constants\StatusCode;
use app\common\exception\CommonException;
use Firebase\JWT\ExpiredException;

use think\App;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Env;

/**
 * admin授权service
 * Class AdminAuthService
 * @package app\common\services\system
 */
class AdminAuthService extends BaseService
{
    /**
     * 构造方法
     * AdminAuthService constructor.
     * @param App $app
     * @param AdminDao $dao
     */
    public function __construct(App $app, AdminDao $dao)
    {
        parent::__construct($app);
        $this->dao = $dao;
    }

    /**
     * 获取Admin授权信息
     * @param string $token
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function parseToken(string $token): array
    {
        $cacheService = CacheService::instance();

        // 1. token 为空或非法
        if (!$token || $token === 'undefined') {
            throw new CommonException(StatusCode::ERR_LOGIN);
        }

        // 2. 解析 token，获取 id / type
        [$id, $type] = JwtAuthService::instance()->parseToken($token);

        // 3. 检测 token 缓存是否存在
        $md5Token = Env::get('redis.jwt') . md5($token);

        if (!$cacheService->has($md5Token) || !($cacheToken = $cacheService->get($md5Token))) {
            $this->authFailAfter($id, $type);
            throw new CommonException(StatusCode::ERR_LOGIN);
        }

        // 4. 是否超出有效次数（登录异常次数过多）
        // if (isset($cacheToken['invalidNum']) && $cacheToken['invalidNum'] >= 3) {
        //     if (!request()->isCli()) {
        //         $cacheService->delete($md5Token);
        //     }

        //     $this->authFailAfter($id, $type);
        //     throw new CommonException(StatusCode::ERR_LOGIN_TOO_MANY);
        // }

        // 5. 验证 token
        try {
            JwtAuthService::instance()->verifyToken($token);
            // $cacheService->set($md5Token, $cacheToken, $cacheToken['exp']);
        } catch (ExpiredException $e) {
            $cacheToken['invalidNum'] = isset($cacheToken['invalidNum'])
                ? $cacheToken['invalidNum'] + 1
                : 1;
        
            $cacheService->set($md5Token, $cacheToken, $cacheToken['exp']);
            throw new CommonException(StatusCode::ERR_LOGIN);
        } catch (\Exception $e) {
            if (!request()->isCli()) {
                $cacheService->delete($md5Token);
            }
            $this->authFailAfter($id, $type);
            throw new CommonException(StatusCode::ERR_TOKEN_INVALID);
        }

        // 6. 单点登录判断：从当前 token 中解析 iat，与 Redis 中最新签发时间比较
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            $this->authFailAfter($id, $type);
            throw new CommonException(StatusCode::ERR_TOKEN_INVALID);
        }

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            false
        );

        $iat = $payload->iat ?? 0;

        $latestIssueTime = JwtAuthService::instance()->getLatestIssueTime((int) $id);

        if ($latestIssueTime === null || $iat < (int) $latestIssueTime) {
            // 被新登录挤下线
            if (!request()->isCli()) {
                $cacheService->delete($md5Token);
            }

            $this->authFailAfter($id, $type);
            throw new CommonException(StatusCode::ERR_LOGIN_CONFLICT);
        }

        // 7. 获取管理员信息
        $adminInfo = $this->dao->model->where('id', $id)->find();

        if (!$adminInfo || !$adminInfo->id) {
            if (!request()->isCli()) {
                $cacheService->delete($md5Token);
            }

            $this->authFailAfter($id, $type);
            throw new CommonException(StatusCode::ERR_LOGIN);
        }

        $adminInfo->type = $type;
        $roles = CacheService::instance()->get('system:admin:roles:' . $adminInfo->id);

        $res = [];
        $res['roles'] = $roles;
        $res['info'] = $adminInfo->toArray();

        return $res;
    }

    /**
     * token验证失败后事件
     */
    protected function authFailAfter($id, $type): void
    {
    }
}