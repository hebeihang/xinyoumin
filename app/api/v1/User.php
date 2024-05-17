<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\common\library\helper\ImageHelper;
use app\common\library\helper\IpLocation;
use app\common\library\helper\LogHelper;
use app\common\library\helper\NotifyHelper;
use app\common\library\helper\RandomHelper;
use app\logic\common\FocusLogic;
use app\model\api\v1\Users;
use app\model\Draft as DraftModel;
use app\model\Users as UserModel;
use app\model\Users as UsersModel;
use app\model\Verify;
use app\validate\User as UserValidate;
use think\exception\ValidateException;

class User extends Api
{
    // 大咖
    public function lists()
    {
        $params = $this->request->get();
        $params['sort'] = $params['sort'] ?? 'integral';
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        if ($params['page'] > 5) $this->apiResult([]);
        $order = [];
        $where[] = ['status', '=', 1];
        switch ($params['sort']) {
            //好学榜
            case 'question':
                $order['question_count']='DESC';
                $where[] = ['question_count','>',1];
                break;
            //热心帮
            case 'answer':
                $order['answer_count']='DESC';
                $where[] = ['answer_count','>',1];
                break;
            default :
                $order['reputation']='DESC';
                $where[] = ['reputation','>',0];
                break;
        }
        $data = Users::getUserList($where, $order, $params['page'], $params['page_size'], $this->user_id);
        $this->apiResult($data);
    }

    // 我的页面
    public function my()
    {
        $user = [
            'uid' => $this->user_id,
            'sex' => $this->user_info['sex'],
            'url_token' => $this->user_info['url_token'],
            'nick_name' => $this->user_info['nick_name'],
            'signature' => $this->user_info['signature'] ?: '这家伙没有什么简介',
            'group_id' => $this->user_info['group_id'],
            'group_name' => $this->user_info['group_name'],
            'integral' => $this->user_info['integral'],
            'reputation' => $this->user_info['reputation'],
            'agree_count' => $this->user_info['agree_count'],
            'fans_count' => $this->user_info['fans_count'],
            'friend_count' => $this->user_info['friend_count'],
            'mobile' => $this->user_info['mobile'],
            'email' => $this->user_info['email'],
            'birthday' => $this->user_info['birthday'] ? date('Y-m-d', $this->user_info['birthday']) : '',
            'avatar' => $this->user_info['avatar'] ? ImageHelper::replaceImageUrl($this->user_info['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
        ];
        $this->apiResult($user);
    }
    // 保存个人资料
    public function save_profile()
    {
        // 保存用户资料钩子
        hook('userSaveProfile');

        if ($this->request->isPost()) {
            $postData = $this->request->post();
            if (!$postData['uid'] || $postData['uid'] != $this->user_id) $this->apiResult([], 0, '当前用户信息不正确');
            // 一些非字段数据
            unset($postData['uid']);
            unset($postData['group_name']);
            unset($postData['oldMobile']);
            unset($postData['token']);
            unset($postData['permission']);

            if (isset($postData['birthday'])) $postData['birthday'] = strtotime($postData['birthday']);

            if (isset($postData['url_token']) && $postData['url_token'] != $this->user_info['url_token'] && db('users')->where('url_token', trim($postData['url_token']))->value('uid')) {
                $this->apiResult([], 0, '该自定义链接已存在！');
            }

            if (ENTRANCE == 'wechat') $this->wxminiCheckText([$postData['nick_name'], $postData['signature'], $postData['url_token']]);

            if (UsersModel::updateUserFiled($this->user_id, $postData)) {
                if (isset($postData['signature']) && $postData['signature'] != '' && $postData['signature'] != $this->user_info['signature']) {
                    LogHelper::addIntegralLog('UPDATE_SIGNATURE', $this->user_id,'users', $this->user_id);
                }

                if ($postData['avatar'] && $postData['avatar'] != $this->user_info['avatar'] && $postData['avatar'] != '/static/common/image/default-cover.svg') {
                    LogHelper::addIntegralLog('UPLOAD_AVATAR', $this->user_id,'users', $this->user_id);
                }

                $this->apiResult([], 1, '资料更新成功');
            }

            $this->apiResult([], 1, '资料更新成功');
        }
    }

    // 个人主页
    public function homepage()
    {
        $name = $this->request->get('name');
        // 防止sql注入报错
        if (!$name = str_replace(['"','"'],'', $name)) $this->apiResult([], 0, '访问页面不存在');

        $uid = db('users')->whereRaw("user_name='{$name}' OR nick_name='{$name}' OR url_token='{$name}'")->value('uid');
        if (!$uid) $this->apiResult([], 0, '用户不存在');

        $user = UsersModel::getUserInfo($uid);

        if (!$user || $user['status'] == 2) $this->apiResult([], 0, '用户不存在');

        UsersModel::updateUsersViews($uid, $this->user_id);
        $ip = new IpLocation();
        $data = [
            'uid' => $user['uid'],
            'name' => $user['name'],
            'nick_name' => $user['nick_name'],
            'signature' => $user['signature'] ?: '这家伙没有什么简介',
            'group_id' => $user['group_id'],
            'group_name' => $user['group_name'],
            'integral' => $user['integral'],
            'reputation' => $user['reputation'],
            'agree_count' => $user['agree_count'],
            'fans_count' => $user['fans_count'],
            'friend_count' => $user['friend_count'],
            'ip' =>$ip->getLocation($user['last_login_ip'])['country'],
            'has_focus' => $user['uid'] == $this->user_id ? 1 : FocusLogic::checkUserIsFocus($this->user_id,'user', $user['uid']),
            'avatar' => $user['avatar'] ? ImageHelper::replaceImageUrl($user['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
        ];

        $this->apiResult($data);
    }
    
    // 个人动态
    public function dynamic()
    {
        $params = $this->request->get();
        $params['uid'] = isset($params['uid']) ? intval($params['uid']) : 0;
        if (!$params['uid']) $this->apiResult([], 0, '参数不正确');
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $params['words_count'] = isset($params['words_count']) ? intval($params['words_count']) : 100;
        $type = $params['type'] ?? 'dynamic';

        $action = 'publish_'.$type;
        if($type=='dynamic')
        {
            $action=[
                'publish_question',
                'publish_article',
                'publish_answer',
                'agree_question',
                'agree_article',
                'agree_answer',
                'focus_question',
            ];
        }

        $data = Users::getUserDynamic($action,$params['uid'],$this->user_id,$params['page'],$params['page_size'], $params['words_count']);
        $this->apiResult($data);
    }

    // 关注的人
    public function friend()
    {
        $params = $this->request->get();
        $params['uid'] = isset($params['uid']) ? intval($params['uid']) : 0;
        if (!$params['uid']) $this->apiResult([], 0, '参数不正确');
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $data = Users::getUserFocus($params['uid'], $this->user_id,'friend', $params['page'], $params['page_size']);
        $this->apiResult($data);
    }

    // 粉丝
    public function fans()
    {
        $params = $this->request->get();
        $params['uid'] = isset($params['uid']) ? intval($params['uid']) : 0;
        if (!$params['uid']) $this->apiResult([], 0, '参数不正确');
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $data = Users::getUserFocus($params['uid'], $this->user_id,'fans', $params['page'], $params['page_size']);
        $this->apiResult($data);
    }

    // 关注的专栏
    public function focus_column()
    {
        $params = $this->request->get();
        $params['uid'] = isset($params['uid']) ? intval($params['uid']) : 0;
        if (!$params['uid']) $this->apiResult([], 0, '参数不正确');
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $data = Users::getUserFocus($params['uid'], $this->user_id, 'column', $params['page'], $params['page_size']);
        $this->apiResult($data);
    }

    // 关注的话题
    public function focus_topic()
    {
        $params = $this->request->get();
        $params['uid'] = isset($params['uid']) ? intval($params['uid']) : 0;
        if (!$params['uid']) $this->apiResult([], 0, '参数不正确');
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $data = Users::getUserFocus($params['uid'], $this->user_id,'topic', $params['page'], $params['page_size']);
        $this->apiResult($data);
    }

    // 通知配置
    public function get_notify_config()
    {
        if ($this->request->isPost()) {
            $postData = $this->request->post();
            $setting_id = db('users_extends')->where('uid', $this->user_id)->value('id');
            $updateData = $notifySetting = [];
            foreach ($postData['notify_setting'] as $s) {
                if ($s['type'] == 'inbox') {
                    $updateData['inbox_setting'] = $s['user_setting'];
                } else {
                    $notifySetting[$s['type']] = $s['user_setting'] ?: [];
                }
            }
            $updateData['notify_setting'] = json_encode($notifySetting, JSON_UNESCAPED_UNICODE);
            if ($setting_id) {
                $res = db('users_extends')->where(['id' => $setting_id])->update($updateData);
            } else {
                $updateData['uid'] = $this->user_id;
                $res = db('users_extends')->insert($updateData);
            }

            if ($res) $this->apiResult([], 1, '操作成功');

            $this->apiResult([], 0, '操作失败');
        }

        $userSetting = db('users_extends')->where('uid',$this->user_id)->find();
        if ($userSetting) {
            $userSetting['notify_setting'] = json_decode($userSetting['notify_setting'],true);
        } else {
            $userSetting = [];
        }
        $types = json_decode(db('config')->where(['name'=>'notify_type'])->value('option'),true);

        $notifySetting = NotifyHelper::getNotifyConfigItem();
        $data = [
            [
                'type' => 'inbox',
                'label' => '私信设置',
                'title' => '谁可以给我发私信',
                'settings' => [
                    [
                        'value' => 'all',
                        'text' => '所有人'
                    ],
                    [
                        'value' => 'focus',
                        'text' => '我关注的人'
                    ]
                ],
                'multiple' => false,
                'user_setting' => $userSetting['inbox_setting']
            ]
        ];
        // 数据处理
        foreach ($notifySetting as $key => $val) {
            $settings = [];
            foreach ($val['config'] as $config) {
                if ($config['user_setting']) {
                    $settings[] = [
                        'value' => $config['name'],
                        'text' => $config['title']
                    ];
                }
            }

            $data[] = [
                'type' => $key,
                'label' => $types[$key],
                'title' => "哪些情况可以给我发{$types[$key]}",
                'settings' => $settings,
                'multiple' => true,
                'user_setting' => (!empty($userSetting) && isset($userSetting['notify_setting'][$key])) ? $userSetting['notify_setting'][$key] : []
            ];
        }

        $this->apiResult($data);
    }

    public function logout()
    {
        \app\model\Users::logout();
        $this->apiResult([],1,'退出成功');
    }

    // 修改密码
    public function modify_password()
    {
        $postData = $this->request->post();

        $info = db('users')->where('uid', $this->user_id)->field('password,deal_password,salt')->find();
        // 账号密码修改
        if ('account' == $postData['type']) {
            if (compile_password($postData['old_password'], $info['salt']) != $info['password']) $this->error('账号密码不正确');

            // 密码验证
            try {
                validate(UserValidate::class)->scene('password')->check($postData);
            } catch (ValidateException $e) {
                $this->error($e->getError());
            }

            if (UsersModel::updateUserFiled($this->user_id, ['salt' => RandomHelper::alnum(), 'password' => $postData['password']])) {
                $this->apiSuccess('修改成功');
            } else {
                $this->apiError('提交失败或数据无变化');
            }
        }

        // 交易密码修改
        if ($info['deal_password'] && !password_verify($postData['old_password'], $info['deal_password'])) $this->apiError('原交易密码错误');
        if ($postData['password'] != $postData['re_password']) $this->apiError('两次密码输入不一致');
        if (UsersModel::updateUserFiled($this->user_id, ['deal_password' => password_hash($postData['password'],1)])) {
            $this->apiSuccess('修改成功');
        } else {
            $this->apiError('提交失败或数据无变化');
        }
    }

    //我的积分记录
    public function integral()
    {
        $page = $this->request->param('page',1,'intval');
        $page_size = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $where=['uid'=>$this->user_id];
        $data = Users::getScoreList($where,$page,$page_size);
        $this->apiResult($data,1,'获取成功');
    }

    //我的草稿
    public function draft()
    {
        $item_type = $this->request->param('type','question');
        $page = $this->request->param('page',1,'intval');
        $page_size = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $data = Users::getDraftByType($this->user_id,$item_type,$page,$page_size);
        $this->apiResult($data,1,'获取成功');
    }

    //删除草稿
    public function remove_draft()
    {
        $item_type = $this->request->param('type','question');
        $item_id = $this->request->param('item_id',0);
        if(DraftModel::deleteDraftByItemID($this->user_id,$item_type,$item_id))
        {
            $this->apiResult([],1,'删除成功');
        }

        $this->apiResult([],0,'删除失败');
    }

    public function get_verify_type()
    {
        $verify_type = db('users_verify_type')->where(['status'=>1])->column('title,name');
        $this->apiResult($verify_type,1,'获取成功');
    }
    public function verified()
    {
        if($this->request->isPost()) {
            $params = $this->request->post();
            $id = $params['id']??0;
            if(!isset($params['type']))
            {
                $this->apiError('请选择认证类型!');
            }
            unset($params['data']);
            $type = $params['type'];
            unset($params['id'],$params['type']);
            $field = db('verify_field')->where(['verify_type'=>$type])->column('title,name');
            foreach ($field as $k=>$v)
            {
                if(removeEmpty($params[$v['name']])=='')
                {
                    $this->apiError($v['title'].'不能为空!');
                }
            }

            if(!$id)
            {
                db('users_verify')->insert(['create_time'=>time(),'type'=>$type,'data'=>json_encode($params),'status'=>1,'uid'=>intval($this->user_id),'reason'=>'']);
            }else{
                db('users_verify')->where('id',$id)->update(['type'=>$type,'data'=>json_encode($params),'status'=>1]);
            }

            $this->apiSuccess('提交成功');
        }

        $type = $this->request->param('type');
        $where = ['verify_type'=>$type];
        $info = db('users_verify')->where(['uid'=>intval($this->user_id)])->find();
        if($info)
        {
            $verifyData =json_decode($info['data'],true);
            $info = array_merge($info,$verifyData);
            unset($info['data']);
        }

        $data = array(
            'keyList' => Verify::getConfigList($where),
            'info'=>$info
        );

        $this->apiResult($data,1,'获取成功');
    }

    //注销账户
    public function removeUser()
    {
        if($this->user_id)
        {
            UserModel::removeUser($this->user_id);
            $this->apiSuccess('注销成功');
        }

        $this->apiError('请求参数不正确');
    }
}