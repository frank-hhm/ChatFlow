<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */

declare(strict_types=1);

namespace app\common\dao\im;

use app\common\model\im\ConversationModel;

/**
 * Class ConversationDao
 * @package app\common\dao\im
 */
class ConversationDao extends \app\common\dao\BaseDao
{

    protected function setModel(): string
    {
        return ConversationModel::class;
    }
}

