<?php

namespace app\api\v1;

use app\common\library\helper\ImageHelper;
use think\facade\Log;
use app\model\Users;
use app\validate\User as UserValidate;
use think\exception\ValidateException;
use app\common\library\helper\MailHelper;
use app\common\library\helper\WeChatHelper;

use app\common\controller\Api;

class Account extends Api
{
    protected string $wxPlatform = 'wxMiniApp';

    public function login()
    {
        if (!$this->request->isPost()) $this->apiError('错误的请求');

        $data = $this->request->post();

        if (!isset($data['account'])) $this->apiError('账号不能为空');

        $remember = intval($this->settings['remember_login_time'])*ONE_DAY;

        if (isset($data['code'])) {
            if (($this->settings['register_type']=='mobile' || $this->settings['register_type']=='all') && get_plugins_config('sms','enable') != 'N' && !$data['code']) {
                $this->apiError('请输入手机验证码');
            }

            if (cache('sms_'.$data['account']) != $data['code']) $this->apiError('手机验证码不正确');

            $user = Users::loginByMobile($data['account'], $data['code'], $data, $remember, true, 'h5');
        } else {
            if (!isset($data['password'])) $this->apiError('密码不能为空');
            $user = Users::getLogin($data['account'], $data['password'], $remember, 'h5');
        }

        if (!$user) $this->apiError(Users::getError());
        $uid = is_array($user) ? $user['uid'] : $user;
        $userInfo = Users::getUserInfo($uid);
        $res = [
            'uid' => $uid,
            'sex' => $userInfo['sex'],
            'url_token' => $userInfo['url_token'],
            'nick_name' => $userInfo['nick_name'],
            'signature' => $userInfo['signature'] ?: '这家伙没有什么简介',
            'group_id' => $userInfo['group_id'],
            'group_name' => $userInfo['group_name'],
            'integral' => $userInfo['integral'],
            'reputation' => $userInfo['reputation'],
            'agree_count' => $userInfo['agree_count'],
            'fans_count' => $userInfo['fans_count'],
            'friend_count' => $userInfo['friend_count'],
            'mobile' => $userInfo['mobile'],
            'email' => $userInfo['email'],
            'permission' => $userInfo['permission'],
            'token' => session('login_token'),
            'birthday' => $userInfo['birthday'] ? date('Y-m-d', $userInfo['birthday']) : '',
            'avatar' => $userInfo['avatar'] ? ImageHelper::replaceImageUrl($userInfo['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
        ];
        
        $this->apiSuccess('登录成功', $res);
    }

    // 注册 兼容注册和微信小程序注册+绑定
    public function register()
    {
        $data = $this->request->post();
        $data['register_type'] = $data['register_type'] ?? 'h5';
        $checkPassword = wcCheckPassword($data['password']);
        if ($checkPassword !== true) $this->apiError($checkPassword);
        if (isset($data['code']) || isset($data['mobile'])) {
            // 手机号绑定 兼容直接绑定和注册绑定
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) $this->apiError('错误的手机号');
            if (!(get_plugins_config('sms','base') && get_plugins_config('sms','base')!='N')) $this->apiError('网站尚未安装或配置短信插件');

            if(!$data['code'] || cache('sms_'.$data['mobile']) != $data['code']) $this->apiError('手机验证码不正确');
            $uid = Users::loginByMobile($data['mobile'], $data['code'], $data, 0, true, $data['register_type']);
        } else {
            $data['email'] =  trim($data['email']);
            $data['username'] = trim($data['username']);
            $data['password'] = trim($data['password']);
            // 微信输入内容安全检测
            $this->wxminiCheckText($data['username'], '用户名不符合微信小程序安全检测');
            if (Users::checkUserExist($data['username'])) {
                if ($data['register_type'] == $this->wxPlatform) {
                    $user = Users::getLogin($data['username'], $data['password'], 0, $this->wxPlatform);
                    $uid = $user ? $user['uid'] : 0;
                } else {
                    $uid = 0;
                    $this->apiError('账号已存在');
                }
            } else {
                try {
                    validate(UserValidate::class)->scene('register')->check($data);
                } catch (ValidateException $e) {
                    $this->apiError($e->getError());
                }

                if (!$data['email'] || !MailHelper::isEmail($data['email'])) $this->apiError('请填写正确的邮箱地址');
                if (Users::checkUserExist($data['email'])) $this->apiError('该邮箱已被使用请更换邮箱');

                $uid = Users::registerUser($data['username'], $data['password'], ['email' => $data['email']], true, true, $data['register_type']);
            }
        }

        if (!$uid) $this->apiError(Users::getError());
        // 微信小程序和用户绑定
        if ($uid && $data['register_type'] == $this->wxPlatform) {
            db('third')->insert([
                'uid' => $uid,
                'openid' => $data['openid'],
                'platform' => $this->wxPlatform
            ]);
        }

        $this->apiSuccess('注册成功', $this->getUserInfo($uid));
    }


    // 微信小程序登录
    public function wxminiapp_login()
    {
       try {
            $app = WeChatHelper::instance()->getMiniProgram();
            $data = $app->auth->session($this->request->get('code'));
            if (isset($data['errcode']) && !$data['errcode']) {
                $wxMinAppUser = db('third')->where('platform', $this->wxPlatform)->where('openid', $data['openid'])->find(); // 微信小程序用户
                $data['is_bind'] = $wxMinAppUser ? 1 : 0;
                // 已绑定-直接登录响应登录数据
                if ($data['is_bind']) {
                    // 服务器登录
                    $userInfo = Users::getUserInfo($wxMinAppUser['uid']);
                    $userInfo['client'] = $this->wxPlatform;
                    if (Users::extracted($userInfo, 0)) {
                        $this->apiError(Users::getError());
                    }
                    $data = [
                        'uid' => $userInfo['uid'],
                        'sex' => $userInfo['sex'],
                        'url_token' => $userInfo['url_token'],
                        'nick_name' => $userInfo['nick_name'],
                        'signature' => $userInfo['signature'] ?: '这家伙没有什么简介',
                        'group_id' => $userInfo['group_id'],
                        'group_name' => $userInfo['group_name'],
                        'integral' => $userInfo['integral'],
                        'reputation' => $userInfo['reputation'],
                        'agree_count' => $userInfo['agree_count'],
                        'fans_count' => $userInfo['fans_count'],
                        'friend_count' => $userInfo['friend_count'],
                        'mobile' => $userInfo['mobile'],
                        'email' => $userInfo['email'],
                        'permission' => $userInfo['permission'],
                        'token' => session('login_token'),
                        'birthday' => $userInfo['birthday'] ? date('Y-m-d', $userInfo['birthday']) : '',
                        'avatar' => $userInfo['avatar'] ? ImageHelper::replaceImageUrl($userInfo['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
                    ];
                }

                $this->apiSuccess('登录成功', $data);
            } else {
                $this->apiError("{$data['errmsg']}");
            }
       } catch (\Exception $e) {
            Log::error("微信小程序登录错误：{$e->getFile()} {$e->getLine()} {$e->getCode()} {$e->getMessage()}");
            $this->apiError($e->getMessage());
       }
    }
    
    // 微信小程序用户直接绑定/注册+绑定
    public function wxminiapp_bind()
    {
        $data = $this->request->post();

        if (isset($data['code']) || isset($data['mobile'])) {
            // 手机号绑定 兼容直接绑定和注册绑定
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) $this->apiError('错误的手机号');
            if (!(get_plugins_config('sms','base') && get_plugins_config('sms','base')!='N')) $this->apiError('网站尚未安装或配置短信插件');

            if(!$data['code'] || cache('sms_'.$data['mobile']) != $data['code']) $this->apiError('手机验证码不正确');

            $uid = Users::loginByMobile($data['mobile'], $data['code'], $data, 0, true, $this->wxPlatform);
        } else {
            $data['email'] =  trim($data['trim']);
            $data['username'] = trim($data['username']);
            $data['password'] = trim($data['password']);
            // 微信输入内容安全检测
            $this->wxminiCheckText($data['username'], '用户名不符合微信小程序安全检测');
            if (Users::checkUserExist($data['username'])) {
                $user = Users::getLogin($data['username'], $data['password'], 0, $this->wxPlatform);
                $uid = $user ? $user['uid'] : 0;
            } else {
                try {
                    validate(UserValidate::class)->scene('register')->check($data);
                } catch (ValidateException $e) {
                    $this->apiError($e->getError());
                }

                if (!$data['email'] || !MailHelper::isEmail($data['email'])) $this->apiError('请填写正确的邮箱地址');
                if (Users::checkUserExist($data['email'])) $this->apiError('该邮箱已被使用请更换邮箱');

                $uid = Users::registerUser($data['username'], $data['password'], ['email' => $data['email']], true, true, $this->wxPlatform);
            }
        }

        if (!$uid) $this->apiError(Users::getError());
        // 微信小程序和用户绑定
        if ($uid) {
            db('third')->insert([
                'uid' => $uid,
                'openid' => $data['openid'],
                'platform' => $this->wxPlatform
            ]);
        }

        $userInfo = Users::getUserInfo($uid);

        $this->apiSuccess('绑定成功', [
            'uid' => $uid,
            'sex' => $userInfo['sex'],
            'url_token' => $userInfo['url_token'],
            'nick_name' => $userInfo['nick_name'],
            'signature' => $userInfo['signature'] ?: '这家伙没有什么简介',
            'group_id' => $userInfo['group_id'],
            'group_name' => $userInfo['group_name'],
            'integral' => $userInfo['integral'],
            'reputation' => $userInfo['reputation'],
            'agree_count' => $userInfo['agree_count'],
            'fans_count' => $userInfo['fans_count'],
            'friend_count' => $userInfo['friend_count'],
            'mobile' => $userInfo['mobile'],
            'email' => $userInfo['email'],
            'permission' => $userInfo['permission'],
            'token' => session('login_token'),
            'birthday' => $userInfo['birthday'] ? date('Y-m-d', $userInfo['birthday']) : '',
            'avatar' => $userInfo['avatar'] ? ImageHelper::replaceImageUrl($userInfo['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
        ]);
    }


    // 我的信息
    public function my()
    {
        $this->apiResult($this->getUserInfo());
    }

    // 重设密码
    public function reset_password()
    {
        $data = $this->request->post();
        if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) $this->apiError('请输入正确的手机号');
        if (!(get_plugins_config('sms','base') && get_plugins_config('sms','base')!='N')) $this->apiError('网站尚未安装或配置短信插件');
        if(!$data['code'] || cache('sms_'.$data['mobile']) != $data['code']) $this->apiError('短信验证码不正确');
        $uid = Users::loginByMobile($data['mobile'], $data['code'], [], 0, true);
        if (!$uid) $this->apiError(Users::getError());
        if (Users::updateUserFiled($uid, ['password' => $data['password']])) {
            $this->apiSuccess('操作成功', $this->getUserInfo($uid));
        } else {
            $this->apiError('操作失败');
        }
    }

    protected function getUserInfo ($uid=0)
    {
        $uid = $uid ?:$this->user_id;
        $userInfo = Users::getUserInfo($uid);
        return [
            'uid' => $userInfo['uid'],
            'sex' => $userInfo['sex'],
            'url_token' => $userInfo['url_token'],
            'nick_name' => $userInfo['nick_name'],
            'signature' => $userInfo['signature'] ?: '这家伙没有什么简介',
            'group_id' => $userInfo['group_id'],
            'group_name' => $userInfo['group_name'],
            'integral' => $userInfo['integral'],
            'reputation' => $userInfo['reputation'],
            'agree_count' => $userInfo['agree_count'],
            'fans_count' => $userInfo['fans_count'],
            'friend_count' => $userInfo['friend_count'],
            'mobile' => $userInfo['mobile'],
            'email' => $userInfo['email'],
            'permission' => $userInfo['permission'],
            'token' => session('login_token'),
            'inbox_unread' => $userInfo['inbox_unread'],
            'notify_unread' => $userInfo['notify_unread'],
            'is_valid_email' => $userInfo['is_valid_email'],
            'birthday' => $userInfo['birthday'] ? date('Y-m-d', $userInfo['birthday']) : '',
            'avatar' => $userInfo['avatar'] ? ImageHelper::replaceImageUrl($userInfo['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
        ];
    }
}