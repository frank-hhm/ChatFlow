<?php
/**
 * @Author: Frank
 * @Email: frank_hhm@163.com
 * @Date: 2026/10/11 13:26
 */
declare(strict_types=1);


namespace app\common\services\im;

use app\common\constants\CacheKeyConstant;
use app\common\dao\im\ConversationDao;

use app\common\enum\im\ConversationStatusEnum;
use app\common\services\common\CacheService;
use think\facade\Db;
use think\facade\Log;

/**
 * IM会话
 * Class ConversationService
 */
class ConversationService extends \app\common\services\BaseService
{

    /**
     * ConversationService constructor.
     * @param ConversationDao $dao
     */
    public function __construct(ConversationDao $dao)
    {
        $this->dao = $dao;
    }


    /**
     * 根据ID查询会话
     */
    public function getDetailById(int $id)
    {
        return $this->dao->model->where('id', $id)
            ->find();
    }


    /**
     * 创建新会话
     * 无客服分配时默认为 pending 状态，有客服分配时为 open
     */
    private function createConversation(
        int $tenantId,
        string $visitorId,
        int $uid,
        int $accountId,
        string $channel
    ): ?array {
        $now = time();

        $data = [
            'tenant_id'     => $tenantId,
            'visitor_id'    => $visitorId,
            'uid'           => $uid,
            'account_id'      => $accountId,
            'channel'       => $channel,
            'status'        => $accountId > 0 ? ConversationStatusEnum::STATUS_OPEN : ConversationStatusEnum::STATUS_PENDING,
            'last_message'  => '',
            'message_count' => 0,
            'open_time'     => $now,
            'close_time'     => 0,
            'create_time'    => $now,
            'update_time'    => $now,
        ];
        $id = $this->dao->model->insertGetId($data);

        if ($id) {
            $data['id'] = (int) $id;
            return $data;
        }
        return null;
    }



    /**
     * 更新最后消息
     */
    public function updateLastMessage(int $conversationId, string $lastMessage): void
    {
       $this->dao->model->where('id', $conversationId)
            ->inc('message_count')
            ->update([
                'last_message' => $lastMessage,
                'update_time'   => time(),
            ]);
    }

    /**
     * 获取某客服的活跃会话列表
     */
    public function getConversations(int $tenantId, int $accountId, int $limit = 50): array
    {
        return $this->dao->model
            ->where('tenant_id', $tenantId)
            ->where('account', $accountId)
            ->whereIn('status', [ConversationStatusEnum::STATUS_OPEN,ConversationStatusEnum::STATUS_PENDING])
            ->order('update_time', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }


    /**
     * 获取待分配的会话列表
     */
    public function getPendingConversations(int $tenantId, string $channel = "", int $limit = 50): array
    {
        return $this->dao->model
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('status', ConversationStatusEnum::STATUS_PENDING)
            ->where('account_id', 0)
            ->whereNotNull('visitor_id')
            ->order('create_time', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }


    /**
     * 根据会话ID列表获取完整的会话详情
     * @return array 会话详情数组
     */
    public function getConversationsByIds(int $tenantId, array $conversationIds,array $status = [ConversationStatusEnum::STATUS_OPEN,ConversationStatusEnum::STATUS_PENDING]): array
    {
        if (empty($conversationIds)) {
            return [];
        }
        return $this->dao->model
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $conversationIds)
            ->whereIn('status', $status)
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 获取上次接待过该访客的在线客服
     */
    public function getHistoryIConversationsByIds(int $tenantId, string $visitorId): array
    {
        // 上次接待过该访客的在线客服
        $historyIds = $this->dao->model
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->where('account_id', '>', 0)
            ->where('status', ConversationStatusEnum::STATUS_OPEN)
            ->order('update_time', 'desc')
            ->value('account_id');
        dump($historyIds);
        return $historyIds;
    }

    /**
     * 根据状态获取会话统计
     */
    public function getConversationCountByStatus(int $tenantId, int $accountId,array $status = [ConversationStatusEnum::STATUS_OPEN,ConversationStatusEnum::STATUS_PENDING]): int
    {
        return $this->dao->model
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->whereIn('status',$status)
            ->count();
    }

    /**
     * 查找该客服已有的活跃会话（取最新的）
     */
    public function getConversationByStatus(int $tenantId, int $accountId = 0,array $order = ["id"=>"desc"],array $status = [ConversationStatusEnum::STATUS_OPEN,ConversationStatusEnum::STATUS_PENDING]): array|null|Db|\think\Model
    {
        // 1. 查找该客服已有的活跃会话（取最新的）
        return $this->dao->model
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->whereIn('status', $status)
            ->whereNotNull('visitor_id')
            ->order($order)
            ->find();
    }



    /**
     * 查找该客服已有的活跃会话
     */
    public function getConversationsByStatus(int $tenantId, int $accountId = 0,bool $isPluck = false,array $order = ["id"=>"desc"],array $status = [ConversationStatusEnum::STATUS_OPEN,ConversationStatusEnum::STATUS_PENDING]): array|null|Db|\think\Model
    {
         $query = $this->dao->model
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->whereIn('status', $status)
            ->whereNotNull('visitor_id');
         if($isPluck){

             return $query->column('id');
         }
        return $query->order($order)
            ->select()
            ->toArray();
    }



    /**
     * 创建或更新会话
     * 【创建时默认为 pending 状态，分配客服后才变为 open
     */
    public function createOrUpdate(
        int $tenantId,
        string $visitorId,
        int $uid = 0,
        int $accountId = 0,
        string $channel = ""
    ): ?array {
        if ($tenantId <= 0 || $visitorId === '') {
            return null;
        }

        $conversation = $this->findActiveConversation($tenantId, $visitorId, $uid, $channel);

        if ($conversation) {
            return $this->updateConversation($conversation, $uid, $accountId, $channel);
        }

        return $this->createConversation($tenantId, $visitorId, $uid, $accountId, $channel);
    }

    /**
     * 查找活跃会话
     */
    public function findActiveConversation(
        int $tenantId,
        string $visitorId,
        int $uid,
        string $channel
    ) {
        $baseQuery = function ($query) use ($tenantId, $channel) {
            return $query
                ->where('tenant_id', $tenantId)
                ->where('channel', $channel)
                ->whereIn('status', [ConversationStatusEnum::STATUS_OPEN,ConversationStatusEnum::STATUS_PENDING])
                ->whereNotNull('visitor_id');
        };

        // 1. 优先按 visitor_id 精确匹配
        $conversation = $this->dao->model
            ->where($baseQuery)
            ->where('visitor_id', $visitorId)
            ->order('id', 'desc')
            ->find();

        if ($conversation) {
            return $conversation->toArray();
        }

        // 2. 按 uid 匹配（访客登录后复用）
        if ($uid > 0) {
            $conversation = $this->dao->model
                ->where($baseQuery)
                ->where('uid', $uid)
                ->order('id', 'desc')
                ->find();

            if ($conversation) {
                $this->refreshConversation($conversation['id'], [
                    'visitor_id' => $visitorId !== '' ? $visitorId : $conversation['visitor_id'],
                    'uid'        => $uid,
                ]);

                $detail = $this->getDetailById($conversation['id']);
                if ($detail) {
                    return $detail->toArray();
                }
            }
        }

        return [];
    }



    /**
     * 更新已有会话
     * 【修复】分配客服时自动将状态改为 open
     */
    public function updateConversation(array $conversation, int $uid, int $accountId, string $channel) :array
    {
        $updateData = ['update_time' => time()];
        if (($conversation['channel'] ?? '') !== $channel) {
            $updateData['channel'] = $channel;
        }

        if (($conversation['uid'] ?? 0) <= 0 && $uid > 0) {
            $updateData['uid'] = $uid;
        }

        if (($conversation['account_id'] ?? 0) <= 0 && $accountId > 0) {
            $updateData['account_id'] = $accountId;
            $updateData['status'] = ConversationStatusEnum::STATUS_OPEN;  // 分配客服后状态变为 open
        }

        if (count($updateData) > 1) {
            $this->refreshConversation($conversation['id'], $updateData);
        }

        return array_merge($conversation, $updateData);
    }



    /**
     * 标记会话为待分配
     */
    public function markConversationPending(int $conversationId): void
    {
        $this->refreshConversation($conversationId, [
            'status' => ConversationStatusEnum::STATUS_PENDING,
        ]);
    }

    /**
     * 封装更新，统一维护 update_time
     */
    public function refreshConversation(int $id, array $data): void
    {
        if (empty($data)) {
            return;
        }
        $data['update_time'] = time();
        $this->dao->model->where('id', $id)->update($data);
    }

}

