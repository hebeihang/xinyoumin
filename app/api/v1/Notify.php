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
use app\model\api\v1\Notify as NotifyModel;
use app\model\api\v1\UsersInbox;

/**
 * 通知模块控制器
 * Class Notify
 * @package app\ask\controller
 */
class Notify extends Api
{
	public function index()
	{
        $group = NotifyModel::getNotifyGroupInfo($this->user_id);
        $last_inbox = UsersInbox::getLastMessage($this->user_id);
        $result = [
            'group'=>$group,
            'list'=>NotifyModel::getNotifyListByGroup($this->user_id,1,10,2),
            'inbox'=>$last_inbox
        ];
        $this->apiResult($result);
	}

    //通知列表
    public function lists()
    {
        $type = $this->request->param('type');
        $page = $this->request->param('page',1,'intval');

        $notifyType = get_dict('notify_group');
        $title = $notifyType[$type];

        $data = [
            'title'=>$title,
            'list'=>NotifyModel::getNotifyListByGroup($this->user_id,$page,10,0,$type)
        ];
        $this->apiResult($data);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $info = NotifyModel::getNotifyInfo($id);
        $this->apiResult($info);
    }

    /**
     * 删除通知
     */
	public function delete()
    {
        $id = $this->request->param('id');
        if(NotifyModel::removeNotify($id,$this->user_id))
        {
            $this->apiSuccess();
        }
        $this->apiSuccess();
    }

    /**
     * 标记已读
     */
    public function read()
    {
        $id = $this->request->param('id');
        if(NotifyModel::setNotifyRead($id,$this->user_id))
        {
            $this->apiSuccess();
        }
        $this->apiError();
    }

    public function read_all()
    {
        if(!NotifyModel::setNotifyReadAll($this->user_id))
        {
            $this->apiSuccess('操作成功');
        }
        $this->apiSuccess('操作成功');
    }
}