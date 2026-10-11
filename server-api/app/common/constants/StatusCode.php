<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2023/12/16 12:37
 */
declare(strict_types=1);

namespace app\common\constants;

/**
 * @Constants
 */
class StatusCode
{
    const SUCCESS = [1, '请求成功'];

    const ERROR = [0, '失败'];

    const CAPTCHA_ERROR = [3, '验证码校验失败'];

    // 通用登录失效：token 不存在、过期、签名错误、用户不存在等
    const ERR_LOGIN = [700, '登录已失效，请重新登录'];

    // 被挤下线 / 单点登录冲突：账号在其它地方登录，当前 token 被作废
    const ERR_LOGIN_CONFLICT = [705, '您的账号已在其他地方登录，您已被迫下线'];

    // token 格式异常 / 无法解析
    const ERR_TOKEN_INVALID = [704, '无效的登录凭证'];

    // 登录失败次数过多，强制下线
    const ERR_LOGIN_TOO_MANY = [703, '登录异常次数过多，请重新登录'];
}