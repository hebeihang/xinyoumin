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
use app\model\Users as UserModel;

class UsersInbox extends BaseModel
{
    /**
     * 根据当前用户获取对话列表
     * @param $uid
     * @param string $where
     * @param int $page
     * @param int $per_page
     * @return array|false
     */
	public static function getDialogListByUid($uid, string $where='', $page=1, $per_page=10)
	{
        $where = $where ?: '(sender_uid = ' . intval($uid) . ' AND sender_count > 0) OR (recipient_uid = ' . intval($uid) . ' AND recipient_count > 0)';
		$list = db('users_inbox_dialog')
			->whereRaw($where)
			->order('update_time','DESC')
			->paginate([
				'list_rows'=> $per_page,
				'page' => $page
			]);

		$list = $list->toArray()['data'];

		$dialogIds = [];
        $dialogUidArr = [$uid];
		foreach ($list as $key => $val) {
			$dialogIds[] = $val['id'];
			$dialogUidArr[] = $uid == $val['recipient_uid'] ? $val['sender_uid'] : $val['recipient_uid'];
		}

		$dialogUidArr = array_unique($dialogUidArr);

		if (!$dialogUidArr || !$dialogIds) return false;

		$userInfos = Users::getUserInfoByIds($dialogUidArr, 'uid,nick_name,avatar');
		$lastMessageInfo = self::getLastDialogMessage($dialogIds);
		$data = array();
		foreach ($list as $key => $value) {
			if ($value['recipient_uid'] == $uid AND $value['recipient_count']) {
			    // 当前是接收用户
			    $data[$key]['type'] = 'recipient';
				$data[$key]['user'] = $userInfos[$value['sender_uid']];
				$data[$key]['unread'] = $value['recipient_unread'];
				$data[$key]['count'] = $value['recipient_count'];
				$data[$key]['uid'] = $value['sender_uid'];
			} else if ($value['sender_uid'] == $uid AND $value['sender_count']) {
                // 当前处于发送用户
			    $data[$key]['type'] = 'sender';
				$data[$key]['user'] = $userInfos[$value['recipient_uid']];
				$data[$key]['unread'] = $value['sender_unread'];
				$data[$key]['count'] = $value['sender_count'];
				$data[$key]['uid'] = $value['recipient_uid'];
			}
			$data[$key]['last_message'] = $lastMessageInfo[$value['id']]['message'];
            $data[$key]['last_message_uid'] = $lastMessageInfo[$value['id']]['uid'];
			$data[$key]['update_time'] = date_friendly($value['update_time']);
			$data[$key]['id'] = $value['id'];
			$data[$key]['last_user'] = $userInfos[$data[$key]['last_message_uid']];
		}
		return $data;
	}

    /**
     * 获取对话最后信息
     * @param $dialog_ids
     * @return array|false
     */
	public static function getLastDialogMessage($dialog_ids)
	{
		if (!is_array($dialog_ids)) return false;

		$last_message = [];
		foreach ($dialog_ids as $dialog_id) {
			$dialog_message = db('users_inbox')->where(['dialog_id'=>$dialog_id])->order('id','DESC')->field('message,uid')->find();
            $dialog_message['message']=str_cut($dialog_message['message'], 0, 60, 'UTF-8', '...');
            $last_message[$dialog_id] = $dialog_message;
		}

		return $last_message;
	}

    /**
     * 获取最后一条私信
     */
    public static function getLastMessage($uid)
    {
        $dialog_inbox_message = db('users_inbox_dialog')->whereRaw('sender_uid='.$uid.' OR recipient_uid='.$uid)->order(['create_time'=>'DESC'])->find();
        if($dialog_inbox_message)
        {
            $dialog_message = db('users_inbox')->where(['dialog_id'=>$dialog_inbox_message['id']])->find();
            if($dialog_message)
            {
                $dialog_message['message']=str_cut($dialog_message['message'], 0, 60, 'UTF-8', '...');
                $dialog_message['sender_user_info']=Users::getUserInfoByUid($dialog_inbox_message['sender_uid'],'nick_name,uid');
                $dialog_message['user_info']=Users::getUserInfoByUid($dialog_message['uid'],'nick_name,uid');
                $dialog_message['recipient_user_info']=Users::getUserInfoByUid($dialog_inbox_message['recipient_uid'],'nick_name,uid');
                $dialog_message['send_time'] = date_friendly($dialog_message['send_time']);
                $dialog_message['recipient_unread'] = $dialog_inbox_message['recipient_unread'];
                $dialog_message['sender_unread'] = $dialog_inbox_message['sender_unread'];
                $dialog_message['sender_uid'] = $dialog_inbox_message['sender_uid'];
                $dialog_message['recipient_uid'] = $dialog_inbox_message['recipient_uid'];
                return $dialog_message;
            }
        }
        return '';
    }

    /**
     * @param $dialog_id
     * @return mixed
     */
	public static function getDialogById($dialog_id)
	{
		return  db('users_inbox_dialog')->where(['id'=> (int)$dialog_id])->find();
	}

    /**
     * 获取对话消息
     * @param $dialog_id
     * @param $uid
     * @param int $page
     * @param int $per_page
     * @return array|false
     */
	public static function getMessageByDialogId($dialog_id, $uid, $page=1, $per_page=5)
	{
		if (!$dialog = self::getDialogById($dialog_id)) return false;

		$inbox =db('users_inbox')
            ->where(['dialog_id' => intval($dialog_id)])
            ->order('id','ASC')
            ->paginate([
                'list_rows'=> $per_page,
                'page' => $page
            ]);
        $inbox = $inbox->toArray();
		if (!$inbox) return false;
        $uidArr = array_unique([$uid, $dialog['sender_uid'], $dialog['recipient_uid']]);
        $userInfos = Users::getUserInfoByIds($uidArr, 'uid,nick_name,avatar');
		foreach ($inbox['data'] as $key => $val) {
            if (($dialog['sender_uid'] == $uid && $val['sender_remove']) || ($dialog['sender_uid'] != $uid && $val['recipient_remove'])) {
                unset($inbox['data'][$key]);
            } else {
                $inbox['data'][$key]['user'] = $val['uid'] == $dialog['sender_uid'] ? $userInfos[$dialog['sender_uid']] : $userInfos[$dialog['recipient_uid']];
            }
            $inbox['data'][$key]['unread'] = $val['read_time'] ? 0 : 1;
            $inbox['data'][$key]['send_time'] = date_friendly($val['send_time']);
            $inbox['data'][$key]['self'] = intval($uid == $val['uid']);
		}

        return $inbox['data'];
	}

    /**
     * @param $sender_uid
     * @param $recipient_uid
     * @return mixed
     */
	public static function getDialogByUser($sender_uid, $recipient_uid)
	{
		return db('users_inbox_dialog')
			->whereRaw("(`sender_uid` = " . (int)$sender_uid . " AND `recipient_uid` = " . (int)$recipient_uid . ") OR (`recipient_uid` = " . (int)$sender_uid . " AND `sender_uid` = " . (int)$recipient_uid . ")")
			->find();
	}

    /**
     * 发送私信
     * @param $sender_uid
     * @param $recipient_uid
     * @param $message
     * @return mixed
     */
	public static function sendMessage($sender_uid, $recipient_uid, $message)
	{
		if (!$sender_uid || !$recipient_uid || !$message) {
            self::setError('请求参数不正确');
			return false;
		}

		if (is_string($recipient_uid)) $recipient_uid = db('users')->where('user_name', $recipient_uid)->value('uid');


		if (trim($message) == '') {
			self::setError('请输入私信内容');
            return false;
		}

		if (!$recipient_user = UserModel::getUserInfo($recipient_uid)) {
            self::setError('接收私信的用户不存在');
            return false;
		}

        if ($recipient_user['inbox_setting'] != 'all' && !db('users_follow')->where(['fans_uid' => $recipient_uid, 'friend_uid' => $sender_uid])->value('id')) {
            self::setError('对方已拒绝陌生人发送的私信');
            return false;
        }

		if ($recipient_user['uid'] == $sender_uid) {
			self::setError('不能给自己发私信');
            return false;
		}

		$time = time();
		$message = htmlspecialchars($message);
		if (!$users_inbox_dialog = self::getDialogByUser(intval($sender_uid), intval($recipient_uid))) {
			$users_inbox_dialog_id = db('users_inbox_dialog')->insertGetId(array(
				'sender_uid' => intval($sender_uid),
				'sender_unread' => 0,
				'recipient_uid' => intval($recipient_uid),
				'recipient_unread' => 0,
				'create_time' => $time,
				'update_time' => $time,
				'sender_count' => 0,
				'recipient_count' => 0
			));
		} else {
			$users_inbox_dialog_id = $users_inbox_dialog['id'];
		}

		$message_id = db('users_inbox')->insertGetId(array(
			'dialog_id' => $users_inbox_dialog_id,
			'message' => $message,
			'send_time' => $time,
			'uid' => $sender_uid
		));

		//更新私信对话数量
		self::updateDialogCount($users_inbox_dialog_id, $sender_uid);
        $recipient_unread = db('users_inbox_dialog')->whereRaw('recipient_uid = ' . intval($recipient_uid))->sum('recipient_unread');
        $sender_unread = db('users_inbox_dialog')->where('sender_uid = ' . intval($recipient_uid))->sum('sender_unread');
		UserModel::updateUserFiled($recipient_uid, ['inbox_unread' => $recipient_unread + $sender_unread]);

		return [
		    'self' => 1,
		    'id' => $message_id,
            'uid' => $sender_uid,
            'dialog_id' => $users_inbox_dialog_id,
            'message' => $message,
            'send_time' => date_friendly($time),
            'unread' => 1
        ];
	}

    /**
     * 更新对话数量
     * @param $dialog_id
     * @param $uid
     * @return false
     */
	public static function updateDialogCount($dialog_id, $uid)
    {
		if (!$users_inbox_dialog = self::getDialogById($dialog_id)) return false;

		db('users_inbox_dialog')->where(['id' => $dialog_id])->update(
		    [
                'sender_count' =>db('users_inbox')->whereRaw( 'uid IN(' . $users_inbox_dialog['sender_uid'] .','.$users_inbox_dialog['recipient_uid'].') AND sender_remove = 0 AND dialog_id = ' . intval($dialog_id))->count(),
                'recipient_count' => db('users_inbox')->whereRaw( 'uid IN(' . $users_inbox_dialog['sender_uid'] .','.$users_inbox_dialog['recipient_uid'].') AND recipient_remove = 0 AND dialog_id = ' . intval($dialog_id))->count(),
                'update_time' => time()
            ]);
		$updateField = $users_inbox_dialog['sender_uid'] == $uid ? 'recipient_unread' : 'sender_unread';
		return db('users_inbox_dialog')->where(['id' => $dialog_id])->inc($updateField)->update();
	}

    /**
     * 更新消息状态
     * @param $dialog_id
     * @param $uid
     * @return false
     */
	public static function updateRead($dialog_id,$uid)
    {
        if (!$dialog = self::getDialogById($dialog_id)) return false;

        if ($uid == $dialog['sender_uid'] && $uid == $dialog['recipient_uid']) {
            $update_data = ['sender_unread' => 0,'recipient_unread' => 0];
        } else {
            $update_data = $uid == $dialog['sender_uid'] ? ['sender_unread' => 0] : ['recipient_unread' => 0];
        }
        db('users_inbox_dialog')->where(['id' => $dialog['id']])->update($update_data);
        return UserModel::updateInboxUnread($uid);
    }
}