<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */
declare(strict_types=1);

namespace app\common\services\tenant;

use app\common\dao\tenant\TenantAccountDao;
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
 * tenantAccount授权service
 * Class TenantAccountAuthService
 * @package app\common\services\tenant
 */
class TenantAccountAuthService extends BaseService
{
    /**
     * 构造方法
     * TenantAccountAuthService constructor.
     * @param App $app
     * @param TenantAccountDao $dao
     */
    public function __construct(App $app, TenantAccountDao $dao)
    {
        parent::__construct($app);
        $this->dao = $dao;
    }

    /**
     * 获取授权信息
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

        // 5. 验证 token
        try {
            JwtAuthService::instance()->verifyToken($token);
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

        // 7. 获取信息
        $info = $this->dao->model->where('id', $id)->find();

        if (!$info || !$info->id) {
            if (!request()->isCli()) {
                $cacheService->delete($md5Token);
            }

            $this->authFailAfter($id, $type);
            throw new CommonException(StatusCode::ERR_LOGIN);
        }

        $info->type = $type;
        $res = [];
        $res['info'] = $info->toArray();
        return $res;
    }

    /**
     * token验证失败后事件
     */
    protected function authFailAfter($id, $type): void
    {
    }
}