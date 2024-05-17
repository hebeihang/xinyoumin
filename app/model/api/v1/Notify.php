<?php
// +----------------------------------------------------------------------
// | WeCenter 简称 WC
// +----------------------------------------------------------------------
// | Copyright (c) 2020-2021 https://wecenter.isimpo.com
// +----------------------------------------------------------------------
// | WeCenter团队一款基于TP6开发的社交化知识付费问答系统、企业内部知识库系统，打造私有社交化问答、内部知识存储
// +----------------------------------------------------------------------
// | Author: WeCenter团队 <devteam@wecenter.com>
// +----------------------------------------------------------------------
namespace app\model\api\v1;
use app\model\BaseModel;
use think\helper\Str;

/**
 * 系统通知模型
 * Class Notify
 */
class Notify extends BaseModel
{
    protected $name = 'users_notify';

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * 发送通知
     * @param int $sender_uid 发送用户id
     * @param int $recipient_uid 接受用户id
     * @param string $action_type 通知类型
     * @param string $subject 通知标题
     * @param int $item_id 通知内容id
     * @param string $content 通知详细数据
     * @return mixed
     */
    public static function send($sender_uid = 0, $recipient_uid = 0, $action_type = '', $subject = '', int $item_id = 0,$item_type='', $content='',$anonymous=0)
    {
        if (!$recipient_uid || !$action_type) return false;

        $model_type = db('users_notify_setting')->where(['status'=>1,'name'=>$action_type])->value('group');
        $insertData = array(
            'sender_uid' => $sender_uid,
            'recipient_uid' => $recipient_uid,
            'action_type' => $action_type,
            'model_type'=>$model_type,
            'subject' => $subject,
            'content' => $content,
            'item_id' => $item_id,
            'item_type'=>$item_type,
            'create_time' => time(),
            'anonymous'=>$anonymous,
            'read_flag' => 0
        );
        $notification_id = db('users_notify')->insertGetId($insertData);
        if (!$notification_id) {
            return false;
        }
        Users::updateNotifyUnread($recipient_uid);
        return $notification_id;
    }

    /**
     * 发送自定义通知
     * @param int $sender_uid 发送用户id
     * @param int $recipient_uid 接受用户id
     * @param string $subject 通知标题
     * @param string $content 通知详细数据
     * @return mixed
     */
    public static function sendNotify($sender_uid = 0, $recipient_uid = 0,$subject = '',$content='',$anonymous=0)
    {
        if (!$recipient_uid) return false;
        $insertData = array(
            'sender_uid' => $sender_uid,
            'recipient_uid' => $recipient_uid,
            'action_type' => 'DIY_NOTIFY',
            'model_type'=>'TYPE_SYSTEM_NOTIFY',
            'subject' => $subject,
            'content' => $content,
            'create_time' => time(),
            'anonymous'=>$anonymous,
            'read_flag' => 0
        );
        $notification_id = db('users_notify')->insertGetId($insertData);
        if (!$notification_id) {
            return false;
        }
        Users::updateNotifyUnread($recipient_uid);
        return $notification_id;
    }

    /**
     * 获得通知列表
     * @param $recipient_uid
     * @param int $page
     * @param int $per_page
     * @param null $read_status
     * @param null $action_type
     * @param string $pjax
     * @return array|false
     */
    public static function getNotifyList($recipient_uid, int $page = 1, int $per_page = 10, $read_status = null, $action_type = null, string $pjax = '')
    {
        if (!$recipient_uid) {
            return false;
        }

        $map = array();
        $map[] = ['recipient_uid', '=', $recipient_uid];
        $map[] = ['status', '=', 1];
        if ($action_type) {
            $map[] = ['action_type', '=', $action_type];
        }

        if ($read_status) {
            $read_status = $read_status == 2 ? 0 : $read_status;
            $map[] = ['read_flag', '=', $read_status];
        }
        $result = db('users_notify')->where($map)->order('create_time', 'DESC')->paginate([
            'list_rows' => $per_page,
            'page' => $page,
            'query' => request()->param(),
            'pjax' => $pjax
        ]);
        $pageVar = $result->render();
        $list = $result->all();
        $senderUidArr = array_column($list, 'sender_uid');
        $recipientUidArr = array_column($list, 'recipient_uid');
        $sender_user_list = Users::getUserInfoByIds($senderUidArr, 'avatar,uid,nick_name,user_name');
        $recipient_user_list = Users::getUserInfoByIds($recipientUidArr, 'avatar,uid,nick_name,user_name');

        foreach ($list as $key => &$val) {
            $content = json_decode($val['content'], true);
            $list[$key]['content'] = $content;
            $list[$key]['sender_user'] = $val['sender_uid'] ? $sender_user_list[$val['sender_uid']] : '系统';
            $list[$key]['recipient_user'] = $recipient_user_list[$val['recipient_uid']];
            $list[$key]['message'] = $content['message'] ?? '';
        }
        return ['list' => $list, 'page' => $pageVar];
    }

    /**
     * 获得通知列表
     * @param $recipient_uid
     * @param int $page
     * @param int $per_page
     * @param null $read_status
     * @param null $model_type
     * @return array|false
     */
    public static function getNotifyListByGroup($recipient_uid, int $page = 1, int $per_page = 10, $read_status = null, $model_type = null)
    {
        if (!$recipient_uid) {
            return false;
        }

        $map = array();
        $map[] = ['recipient_uid', '=', $recipient_uid];
        $map[] = ['status', '=', 1];
        if ($model_type) {
            $map[] = ['model_type', '=', $model_type];
        }
        if ($read_status) {
            $read_status = $read_status == 2 ? 0 : $read_status;
            $map[] = ['read_flag', '=', $read_status];
        }
        $list = db('users_notify')->where($map)->order(['read_flag'=>'ASC','create_time'=> 'DESC'])->page($page,$per_page)->select()->toArray();
        $senderUidArr = array_column($list, 'sender_uid');
        $recipientUidArr = array_column($list, 'recipient_uid');
        $sender_user_list = Users::getUserInfoByIds($senderUidArr, 'avatar,uid,nick_name,user_name');
        $recipient_user_list = Users::getUserInfoByIds($recipientUidArr, 'avatar,uid,nick_name,user_name');

        foreach ($list as $key => $val)
        {
            /*自定义解析不同类型通知方法*/
            if ($val['item_type']) hook('parseSiteNotifyByType'.Str::title($val['item_type']),$val);

            $title = '';
            $user_name = get_link_username($recipient_user_list[$val['recipient_uid']]);
            $from_name = $val['anonymous'] ? '匿名用户' :($val['sender_uid'] ? $sender_user_list[$val['sender_uid']]['nick_name'] : '系统');

            if($val['item_type'] && $val['item_id'])
            {
                if($val['item_type']=='question' || $val['item_type']=='article' || $val['item_type']=='reward') {
                    $title = db($val['item_type'])->where(['id' => $val['item_id']])->value('title');
                }

                if($val['item_type']=='column') {
                    $title = db('column')->where(['id' => $val['item_id']])->value('name');
                }

                if($val['item_type']=='users')
                {
                    $title = db('users')->where(['uid'=>$val['item_id']])->value('nick_name');
                }
            }

            $search = [
                '[#site_name#]',
                '[#title#]',
                '[#time#]',
                '[#user_name#]',
                '[#from_username#]',
            ];

            $replace = [
                get_setting('site_name'),
                $title,
                date('Y-m-d H:i',time()),
                $user_name,
                $from_name,
            ];

            if(isset($val['subject']) && $val['subject'])
            {
                $val['subject'] = str_replace($search,$replace,(string)$val['subject']);
            }

            if($val['content'])
            {
                $val['content'] = str_replace($search,$replace,$val['content']);
                $val['content'] = str_replace('[#subject#]',$val['subject'],$val['content']);
            }
            $list[$key]['subject'] = $val['subject'];
            $list[$key]['create_time'] = date_friendly($val['create_time']);
            $list[$key]['content'] = htmlspecialchars_decode($val['content']);
            $list[$key]['sender_user'] = $val['anonymous'] ? '匿名用户' : ($val['sender_uid'] ? $sender_user_list[$val['sender_uid']] : '系统');
            $list[$key]['recipient_user'] = $recipient_user_list[$val['recipient_uid']];
        }
        return $list;
    }

    /**
     * 设置消息已读
     * @param $id
     * @param $uid
     * @return false
     */
    public static function setNotifyRead($id, $uid): bool
    {
        if (!$id || !$uid) return false;
        if (db('users_notify')->where(['id' => $id, 'recipient_uid' => $uid])->update(['read_flag' => 1])) {
            Users::updateNotifyUnread($uid);
            return true;
        }
        return false;
    }

    /**
     * 设置全部消息已读
     * @param $uid
     * @return bool
     */
    public static function setNotifyReadAll($uid): bool
    {
        if (!$uid) return false;
        if (db('users_notify')->where(['recipient_uid' => $uid])->update(['read_flag' => 1])) {
            Users::updateNotifyUnread($uid);
            return true;
        }
        return false;
    }

    /**
     * 删除通知消息
     * @param $id
     * @param $uid
     * @return bool
     */
    public static function removeNotify($id, $uid): bool
    {
        if (!$id) return false;
        if (db('users_notify')->where(['id' => $id, 'recipient_uid' => $uid])->update(['status' => 0])) {
            Users::updateNotifyUnread($uid);
            return true;
        }
        return false;
    }

    //获取通知分组信息
    public static function getNotifyGroupInfo($uid): array
    {
        $result = [];
        $notifyType = get_dict('notify_group');
        $icons = [
            'icon-guanliyuanrenzheng_o',
            'icon-at',
            'icon-dianzan1',
            'icon-huida',
            'icon-yaoqingyouli',
            'icon-tongzhi',
            'icon-airudiantubiaohuizhi-zhuanqu_zixundongtai'
        ];
        foreach ($notifyType as $key=>$val)
        {
            $result[$key]['iconSize'] = 60;
            $result[$key]['text'] =$val;
            $result[$key]['type'] = $key;
            $result[$key]['dot'] = (bool)db('users_notify')->where(['model_type' => $key, 'read_flag' => 0, 'recipient_uid' => $uid])->count();
        }
        $result = array_values($result);
        foreach ($result as $key=>$item) {
            $result[$key]['icon'] = $icons[$key];
        }
        return $result;
    }

    //获取通知详情
    public static function getNotifyInfo($id)
    {
        if (!$id) {
            return false;
        }
        $map = array();
        $map[] = ['id', '=', $id];
        $map[] = ['status', '=', 1];

        $notify_info = db('users_notify')->where($map)->find();
        $recipient_user = Users::getUserInfoByUid($notify_info['recipient_uid']);

        $anonymous = ['nick_name'=>'匿名用户','avatar'=>request()->domain().'/static/common/image/default-avatar.svg'];
        $send_user = !$notify_info['anonymous'] ? Users::getUserInfoByUid($notify_info['sender_uid']) : $anonymous;
        $title = '';
        $user_name = $recipient_user['nick_name'];

        if($notify_info['item_type'] && $notify_info['item_id'])
        {
            if($notify_info['item_type']=='question' || $notify_info['item_type']=='article' || $notify_info['item_type']=='reward') {
                $title = db($notify_info['item_type'])->where(['id' => $notify_info['item_id']])->value('title');
            }

            if($notify_info['item_type']=='column') {
                $title = db('column')->where(['id' => $notify_info['item_id']])->value('name');
            }

            if($notify_info['item_type']=='users')
            {
                $title = db('users')->where(['uid'=>$notify_info['item_id']])->value('nick_name');
            }
        }

        $search = [
            '[#site_name#]',
            '[#title#]',
            '[#time#]',
            '[#user_name#]',
            '[#from_username#]',
        ];

        $replace = [
            get_setting('site_name'),
            '',
            date('Y-m-d H:i',time()),
            $user_name,
            ''
        ];

        $notify_info['title'] = $title;

        if($notify_info['content'])
        {
            $notify_info['content'] = str_replace($search,$replace,$notify_info['content']);
        }
        $notify_info['create_time'] = date_friendly($notify_info['create_time']);
        $notify_info['content'] = htmlspecialchars_decode($notify_info['content']);
        $notify_info['sender_user'] = $notify_info['anonymous'] ? '匿名用户' : ($notify_info['sender_uid'] ? $send_user : '系统');
        $notify_info['recipient_user'] = $recipient_user;
        return $notify_info;
    }
}