<?php
/**
 * 推送消息服务
 * 负责 WebSocket 推送、离线消息队列管理
 * 修复版：变量名完整、Table 迭代方法正确
 */
declare(strict_types=1);

namespace app\websocket\services;

use app\common\constants\StringConstant;
use EasySwoole\Component\TableManager;
use think\facade\Db;

class PushMessageService
{
    /**
     * 通用推送方法
     *
     * @param int    $fd         目标fd
     * @param string $msgType    消息类型
     * @param array  $data       消息数据
     * @param int    $tenantId   租户ID
     * @return bool
     */
    public function push(int $fd, string $msgType, array $data = [], int $tenantId = 0): bool
    {
        try {
            $server = \EasySwoole\EasySwoole\ServerManager::getInstance()->getSwooleServer();
            if (!$server) {
                return false;
            }

            $response = [
                'type'    => $msgType,
                'time'    => time(),
                'data'    => $data,
            ];

            if ($tenantId > 0) {
                $response['tenant_id'] = $tenantId;
            }

            $server->push($fd, json_encode($response, JSON_UNESCAPED_UNICODE));
            return true;
        } catch (\Throwable $e) {
            error_log('PushMessageService::push error fd=' . $fd . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 推送消息给访客（通过conversation_id查找访客fd）
     *
     * @param int    $tenantId       租户ID
     * @param int    $conversationId 会话ID
     * @param string $msgType        消息类型
     * @param array  $data           消息数据
     * @return bool
     */
    public function pushToVisitor(int $tenantId, int $conversationId, string $msgType, array $data = []): bool
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return false;
        }

        // 修复：使用 foreach($table as $fd => $row) 替代 $table->rows()
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['conversation_id'] ?? 0) == $conversationId
                && ($row['role'] ?? '') === 'visitor'
            ) {
                return $this->push((int) $fd, $msgType, $data, $tenantId);
            }
        }

        // 未找到在线访客，消息落库等待下次连接时推送
        app(MessageService::class)->saveMessage(
            $tenantId,
            $conversationId,
            'system',
            null,
            '',
            'system',
            'visitor_offline: ' . ($data['content'] ?? '')
        );

        return false;
    }

    /**
     * 推送消息给指定客服
     *
     * @param int    $tenantId  租户ID
     * @param int    $accountId   客服ID
     * @param string $msgType   消息类型
     * @param array  $data      消息数据
     * @return bool
     */
    public function pushToAgent(int $tenantId, int $accountId, string $msgType, array $data = []): bool
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return false;
        }

        // 修复：使用 foreach($table as $fd => $row)
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['account_id'] ?? 0) == $accountId
                && ($row['role'] ?? '') === 'client'
            ) {
                return $this->push((int) $fd, $msgType, $data, $tenantId);
            }
        }

        // 客服不在线，消息入队等待上线后推送
        $this->queueMessage($tenantId, $accountId, $msgType, $data);

        return false;
    }

    /**
     * 广播消息给租户下所有在线客服
     *
     * @param int    $tenantId  租户ID
     * @param string $msgType   消息类型
     * @param array  $data      消息数据
     * @return int 成功推送的客服数
     */
    public function pushToAgents(int $tenantId, string $msgType, array $data = []): int
    {
        $table = TableManager::getInstance()->get(StringConstant::WEBSOCKET_CLIENT);
        if (empty($table)) {
            return 0;
        }

        $count = 0;
        // 修复：使用 foreach($table as $fd => $row)
        foreach ($table as $fd => $row) {
            if (($row['tenant_id'] ?? 0) == $tenantId
                && ($row['role'] ?? '') === 'client'
            ) {
                if ($this->push((int) $fd, $msgType, $data, $tenantId)) {
                    $count++;
                }
            }
        }

        // 如果没有在线客服，消息入队
        if ($count === 0) {
            $this->queueMessage($tenantId, 0, $msgType, $data);
        }

        return $count;
    }

    /**
     * 推送新访客提醒给所有在线客服（无人分配时）
     *
     * @param int    $tenantId  租户ID
     * @param array  $data      访客信息
     * @return int 成功推送数
     */
    public function notifyNewVisitor(int $tenantId, array $data = []): int
    {
        return $this->pushToAgents($tenantId, 'new_visitor', $data);
    }

    /**
     * 将离线消息入队（数据库暂存）
     *
     * @param int    $tenantId  租户ID
     * @param int    $accountId   客服ID（0表示广播）
     * @param string $msgType   消息类型
     * @param array  $data      消息数据
     * @return void
     */
    private function queueMessage(int $tenantId, int $accountId, string $msgType, array $data): void
    {
        try {
            $queueData = [
                'tenant_id'    => $tenantId,
                'account_id'     => $accountId,
                'msg_type'     => $msgType,
                'data'         => json_encode($data, JSON_UNESCAPED_UNICODE),
                'create_time'   => time(),
                'retry_count'  => 0,
            ];

            Db::name('im_message_queue')->insert($queueData);
        } catch (\Throwable $e) {
            error_log('PushMessageService::queueMessage error: ' . $e->getMessage());
        }
    }

    /**
     * 消费离线消息队列（定时任务调用）
     * 客服上线后检查是否有待推送的离线消息
     *
     * @param int $tenantId 租户ID
     * @param int $accountId  客服ID
     * @return array ['success'=>int, 'failed'=>int]
     */
    public function flushOfflineMessages(int $tenantId, int $accountId = 0): array
    {
        $query = Db::name('im_message_queue')
            ->where('tenant_id', $tenantId)
            ->where('retry_count', '<', 3)
            ->order('create_time', 'asc');

        if ($accountId > 0) {
            $query->where('account_id', $accountId);
        }

        $messages = $query->limit(50)->select()->toArray();
        $success = 0;
        $failed = 0;

        foreach ($messages as $msg) {
            $pushed = $this->pushToAgent(
                $msg['tenant_id'],
                $msg['account_id'],
                $msg['msg_type'],
                json_decode($msg['data'], true) ?? []
            );

            if ($pushed) {
                Db::name('im_message_queue')->where('id', $msg['id'])->delete();
                $success++;
            } else {
                Db::name('im_message_queue')
                    ->where('id', $msg['id'])
                    ->update(['retry_count' => $msg['retry_count'] + 1]);
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }
}