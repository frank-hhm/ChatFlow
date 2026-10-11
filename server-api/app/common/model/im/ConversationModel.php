<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2023/12/16 13:23
 */

declare(strict_types=1);

namespace app\common\model\im;

use  app\common\model\BaseModel;
use app\common\traits\ModelTrait;

/**
 * IM会话表模型
 * Class ConversationModel
 * @package app\common\model\im
 */
class ConversationModel extends BaseModel
{
    use ModelTrait;

    ## 数据表主键
    protected $pk = 'id';

    ## 模型名称
    protected $name = 'im_conversation';
}
