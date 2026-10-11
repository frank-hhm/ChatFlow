<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */

declare(strict_types=1);

namespace app\common\enum\im;

use app\common\enum\BaseEnum;

/**
 * 枚举类
 * Class ConversationStatusEnum
 * @package app\common\enum\im
 */
class ConversationStatusEnum extends BaseEnum
{
    const STATUS_OPEN = 'open';

    const STATUS_CLOSED = 'closed';
    const STATUS_PENDING = 'pending';

    /**
     * 获取枚举数据
     * @return array
     */
    public static function data()
    {
        return [
            self::STATUS_OPEN => [
                'name' => '已连接',
                'value' => self::STATUS_OPEN,
            ],
            self::STATUS_CLOSED => [
                'name' => '已关闭',
                'value' => self::STATUS_CLOSED,
            ],
            self::STATUS_PENDING => [
                'name' => '代连接',
                'value' => self::STATUS_PENDING,
            ],
        ];
    }
}
