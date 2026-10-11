<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:32
 */
declare(strict_types=1);

namespace app\sys\validate\tenant;

use think\Validate;

class TenantValidate extends Validate
{
    protected $rule = [
        'nick_name' => 'require',
        'email' => 'require|email|unique:tenant_account,email',
        'pwd' => 'requireCallback:check_require|min:5',
        'conf_pwd' => 'requireCallback:check_require|confirm:pwd|min:5',
    ];
    protected $message  =   [
        'nick_name.require'     => '请输入昵称!',
        'email.require'     => '请输入邮箱!',
        'email.email'     => '邮箱格式错误!',
        'email.unique'     => '邮箱已存在!',
        'pwd.requireCallback'   => '请输入账号密码!',
        'conf_pwd.requireCallback'   => '请输入账号确定密码!',
        'pwd.min'   => '账号最小长度为6位!',
        'conf_pwd.min'   => '账号确定密码最小长度为6位!',
        'conf_pwd.confirm'   => '两次密码不一致!',
    ];

    protected function check_require($value, $data){
        if(empty($data['id']) || !empty($data['pwd'])|| !empty($data['conf_pwd'])){
            return true;
        }
    }

    protected function roles_check_require($value, $data){
        if(empty($data['id'])){
            return true;
        }
    }
}
