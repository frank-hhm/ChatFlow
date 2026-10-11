<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateTenantAccount extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change()
    {

        $table = $this->table('tenant_account');
        $table
            ->addColumn(Column::integer('tenant_id')->setLimit(11)->setDefault(0)->setComment('昵称'))
            ->addColumn(Column::string('nick_name')->setLimit(150)->setDefault('')->setComment('昵称'))
            ->addColumn(Column::string('email')->setLimit(150)->setDefault('')->setComment('邮箱'))
            ->addColumn(Column::string('phone')->setLimit(150)->setDefault('')->setComment('手机号码'))
            ->addColumn(Column::tinyInteger('is_bind')->setLimit(3)->setDefault(0)->setComment('是否管理员'))
            ->addColumn('pwd', 'string', array('limit' => 100, 'default' => '', 'comment' => '管理员密码'))
            ->addColumn(Column::tinyInteger('status')->setLimit(3)->setDefault(1)->setComment('状态，1启用，0禁用'))
            ->addColumn('create_time', 'integer', array('limit' => 11, 'default' => 0, 'comment' => '创建时间'))
            ->addColumn('update_time', 'integer', array('limit' => 11, 'default' => 0, 'comment' => '更新时间'))
            ->addColumn('delete_time', 'integer', array('limit' => 11, 'default' => 0, 'comment' => '删除时间'))
            ->setPrimaryKey('id')
            ->addIndex(['tenant_id','email','phone','status'])
            ->setComment('租户账号')
            ->create();
    }
}
