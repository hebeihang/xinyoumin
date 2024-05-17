<?php
// +----------------------------------------------------------------------
// | WeCenter社交化问答系统
// +----------------------------------------------------------------------
// | Copyright (c) 2020-2022 https://wecenter.isimpo.com
// +----------------------------------------------------------------------
// | WeCenter一款基于TP6开发的社交化知识付费问答系统、企业内部知识库系统，打造私有社交化问答、内部知识存储
// +----------------------------------------------------------------------
// | Author: WeCenter团队 <devteam@WeCenter.com>
// +----------------------------------------------------------------------
namespace app\api\v1;

use app\common\controller\Api;
use app\model\api\v1\UsersInbox as InboxModel;
use app\model\Notify as NotifyModel;

class Inbox extends Api
{
	public function index()
	{
	    $params = $this->request->get();
	    $page = isset($params['page']) ? intval($params['page']) : 1;
        $pageSize = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $dialogList = InboxModel::getDialogListByUid($this->user_id,'', $page, $pageSize);
        $this->apiResult($dialogList);
	}

	// 私信详情
    public function detail()
    {
        $user_name = $this->request->get('recipient_uid','');
        $page = $this->request->get('page',1, 'intval');
        $recipient_uid = db('users')->where('nick_name', $user_name)->value('uid');
        $dialog_info = InboxModel::getDialogByUser($this->user_id, $recipient_uid);
        if ($this->user_info['inbox_unread'] && $dialog_info) {
            InboxModel::updateRead($dialog_info['id'], $this->user_id);
        }
        $list = $dialog_info ? InboxModel::getMessageByDialogId($dialog_info['id'], $this->user_id, $page) : [];

        $this->apiResult($list);
    }

	// 删除
    public function delete()
    {
        if (NotifyModel::removeNotify($this->request->post('id'), $this->user_id)) {
            $this->apiSuccess('已删除');
        } else {
            $this->apiError('删除失败');
        }
    }

    /**
     * 发送私信
     */
	public function send()
	{
        $postData = $this->request->post();
        if ($data = InboxModel::sendMessage($this->user_id, $postData['recipient_uid'], remove_xss($postData['message']))) {
            $data['user'] = [
                'uid' => $this->user_id,
                'avatar' => $this->user_info['avatar'],
                'nick_name' => $this->user_info['nick_name']
            ];
            $this->apiSuccess('发送成功', $data);
        } else {
            $this->apiError(InboxModel::getError());
        }
	}


    /**
     * 发送私信
     */
    public function sendGpt()
    {
        $postData = $this->request->post();
        $postData['uid'] = $this->user_id;
        $postData['create_time'] = time();
        $postData = $this->request->post();
        $chat_id = db('chatgpt')->insertGetId($postData);
        $this->apiResult(['id'=>$chat_id]);
    }

    public function getGpt()
    {
        $result = db('chatgpt')->select()->toArray();
        foreach ($result as $k=>$v)
        {
            $result[$k]['create_time']=date_friendly($v['create_time']);
        }
        $this->apiResult($result);
    }
}