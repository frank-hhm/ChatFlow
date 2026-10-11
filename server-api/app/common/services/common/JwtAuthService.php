<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2023/12/16 13:29
 */
declare(strict_types=1);

namespace app\common\services\common;

use app\common\exception\CommonException;
use app\common\services\common\CacheService;
use app\common\services\BaseService;
use app\common\constants\StatusCode;
use Firebase\JWT\JWT;
use think\facade\Env;

/**
 * Jwt
 * Class JwtAuthService
 * @package app\common\services\common
 */
class JwtAuthService extends BaseService
{
    // token
    protected string $token = '';

    /**
     * Redis 缓存前缀
     */
    private static string $prefix = 'token:';

    /**
     * 获取token
     * @param int $id
     * @param string $type
     * @param array $params
     * @return array
     */
    public function getToken(int $id, string $type, array $params = []): array
    {
        $host = app()->request->host();
        $time = time();
        $expTime = 30 * 60 * 60 * 24;

        $params += [
            'iss' => $host,
            'aud' => $host,
            'iat' => $time,
            'nbf' => $time,
            'exp' => (int)($time + $expTime),
        ];

        $params['jti'] = compact('id', 'type');

        $token = JWT::encode($params, Env::get('app.app_key', 'default'), 'HS256');

        // 记录该用户最新签发时间，用于单点登录判断
        CacheService::instance()->set(
            Env::get('redis.jwt') . static::$prefix . $id,
            $time,
            $expTime
        );

        return compact('token', 'params');
    }

    /**
     * 解析token
     * @param string $jwt
     * @return array [id, type]
     */
    public function parseToken(string $jwt): array
    {
        $this->token = $jwt;

        [$headb64, $bodyb64, $cryptob64] = explode('.', $this->token);

        $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));

        return [
            $payload->jti->id,
            $payload->jti->type,
        ];
    }

    /**
     * 验证token
     */
    public function verifyToken(string $token = ''): void
    {
        if ($token !== '') {
            $this->token = $token;
        }
    
        if ($this->token === '') {
            throw new CommonException(StatusCode::ERR_TOKEN_INVALID);
        }
    
        JWT::$leeway = 60;
    
        JWT::decode(
            $this->token,
            Env::get('app.app_key', 'default'),
            ['HS256']
        );
    }

    /**
     * 获取token并放入令牌桶
     * @param int $id
     * @param string $type
     * @param array $params
     * @return array
     */
    public function createToken(int $id, string $type, array $params = []): array
    {
        $tokenInfo = $this->getToken($id, $type, $params);

        $expTime = $tokenInfo['params']['exp'] + 60;
        $exp =  $expTime - $tokenInfo['params']['iat'];
        $res = CacheService::instance()->set(
            Env::get('redis.jwt') . md5($tokenInfo['token']),
            [
                'uid'   => $id,
                'type'  => $type,
                'token' => $tokenInfo['token'],
                'exp'   => $expTime,
            ],
            (int)$exp
        );

        if (!$res) {
            throw new CommonException(StatusCode::ERROR);
        }

        return $tokenInfo;
    }

    /**
     * 获取用户最新签发时间
     * @param int $id
     * @return int|string|null
     */
    public function getLatestIssueTime(int $id)
    {
        return CacheService::instance()->get(Env::get('redis.jwt') . static::$prefix . $id);
    }

    /**
     * 清除用户登录状态
     * @param int $id
     */
    public function clearUser(int $id): void
    {
        CacheService::instance()->delete(Env::get('redis.jwt') . static::$prefix . $id);
    }
}