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
use app\model\Users;
use app\common\library\helper\MailHelper;
use app\model\api\v1\Invitation as InvitationModel;

class Invitation extends Api
{
    public function index()
    {
        $page = $this->request->param('page',1,'intval');
        $page_size =  $this->request->param('page_size',10,'intval');
        $where = ['uid' => $this->user_id];

        $data = [
            'records'=>InvitationModel::records($where, $page, $page_size),
            'quota'=>InvitationModel::availableCount($this->user_info),
            'hasInvite'=>InvitationModel::hadInvitationCount($this->user_id),
            'hasRegister'=>InvitationModel::whereRaw('active_uid IS NOT NULL AND uid='.$this->user_id.' AND active_status=2')->count()
        ];
        $this->apiResult($data,1,'获取成功');
    }

    // 邀请操作
    public function create()
    {
        if (InvitationModel::availableCount($this->user_info) <= 0)  $this->apiResult([],0,L('邀请名额已用完'));

        $type = $this->request->get('type', 'link', 'trim');
        $invitation_code = InvitationModel::generateCode($this->user_id);
        $invitation_email = $this->request->post('invitation_email');
        $data = [
            'invitation_code'=>$invitation_code,
            'active_status'=>1,
            'uid'=>$this->user_id,
            'create_time'=>time(),
            'add_ip'=>ip2long($this->request->ip()),
            'active_expire'=>3600 * $this->settings['invitation_expire_time'] + time()
        ];

        if ($type=='email') {
            if (!$invitation_email || !MailHelper::isEmail($invitation_email))  $this->apiResult([],0,L('请填写正确的邮箱地址'));
            if (Users::checkUserExist($invitation_email)) $ $this->apiResult([],0,L('该邮箱已被使用请更换邮箱'));
            if (InvitationModel::emailHadInvitation($this->user_id, $invitation_email))  $this->apiResult([],0,'该邮箱已在邀请注册中');
            $data['invitation_email'] = $invitation_email;
        }

        if (InvitationModel::add($data)) {
            // 发送邮件
            if ($type=='email') {
                $subject = $this->user_info['name'].L('邀请您注册').$this->settings['site_name'].L('用户');
                $message = L('注册链接将在').$this->settings['invitation_expire_time'].L('小时后过期').'。<a href="'.(string) url('Account/register', ['invitation_code' => $invitation_code], true, true).'">'.L('立即前往注册').'</a>';
                $res = MailHelper::sendEmail($invitation_email, $subject, $message);
                if ($res['code'] == 0)  $this->apiResult([],0,L('邮件发送失败'));
            }
            $this->apiResult([],1,L('记录成功'));
        } else {
            $this->apiResult([],0,L('记录失败'));
        }
    }

    public function invite_list()
    {
        $page = $this->request->param('page',1,'intval');
        $page_size =  $this->request->param('page_size',10,'intval');
        $invite_ids = InvitationModel::whereRaw('active_uid IS NOT NULL AND uid='.$this->user_id.' AND active_status=2')->column('active_uid');
        $data = $invite_ids ? \app\model\api\v1\Users::getUserList('uid IN ('.implode(',',$invite_ids).') AND status=1', '', $page,$page_size, $this->user_id) : [];
        $this->apiResult($data,1,'获取成功');
    }
}