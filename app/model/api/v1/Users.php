<?php
namespace app\model\api\v1;
use app\common\library\helper\ImageHelper;
use app\common\library\helper\RandomHelper;
use app\logic\common\FocusLogic;
use app\model\Answer;
use app\model\BaseModel;
use app\model\Topic;
use Overtrue\Pinyin\Pinyin;

class Users extends BaseModel
{
    /**
     * 根据用户id获取用户列表
     * @param $ids
     * @param string $field
     * @param int $status
     * @return array|false
     */
    public static function getUserInfoByIds($ids,string $field='',int $status=1)
    {
        if (!is_array($ids) || count($ids) == 0) {
            return false;
        }
        $ids = array_unique($ids);
        $where = [];

        if($status!=99)
        {
            $where['status'] = $status;
        }

        $user_info = db('users')->where($where)->whereIn('uid',implode(',', $ids))->column($field);

        $data = array();
        $default_avatar = request()->domain().'/static/common/image/default-avatar.svg';
        if ($user_info)
        {
            foreach ($user_info as $key => $val)
            {
                $data[$val['uid']] = $val;
                if(isset($val['avatar']) && $val['avatar'])
                {
                    $data[$val['uid']]['avatar'] = ImageHelper::replaceImageUrl($val['avatar']);
                } else {
                    $data[$val['uid']]['avatar'] = $default_avatar;
                }

                if(isset($val['verified'])){
                    $data[$val['uid']]['verified_icon'] = $val['verified'] ? db('users_verify_type')->where(['name'=>$val['verified'],'status'=>1])->value('icon') : '';
                    $data[$val['uid']]['verified_icon'] = ImageHelper::replaceImageUrl($data[$val['uid']]['verified_icon']);
                }
            }
        }
        return $data;
    }

    /**
     * 获取用户信息，根据字段
     * @param $uid
     * @param mixed $field
     * @param bool $extend
     * @param mixed $extend_field
     * @return false|array
     */

    public static function getUserInfoByUid($uid, $field = '*', bool $extend = false, $extend_field = '*')
    {
        $user_info = db('users')->where(['uid'=>$uid])->field($field)->find();
        if(!$user_info)
        {
            return false;
        }

        if (isset($user_info['avatar']) && $user_info['avatar']) {
            $user_info['avatar'] = ImageHelper::replaceImageUrl($user_info['avatar']);
        } else {
            $user_info['avatar'] = request()->domain().'/static/common/image/default-avatar.svg';
        }

        if(isset($user_info['verified'])){
            $user_info['verified_icon'] = $user_info['verified'] ? db('users_verify_type')->where(['name'=>$user_info['verified'],'status'=>1])->value('icon') : '';
            $user_info['verified_icon'] = ImageHelper::replaceImageUrl($user_info['verified_icon']);
        }

        $user_extend_info = $extend ? db('users_extends')->withoutField('id')->where('uid',$uid)->field($field)->find() : [];

        if($user_extend_info)
        {
            $user_info = array_merge($user_info,$user_extend_info);
        }

        return $user_info;
    }

    /**
     * 获取用户的动态概况
     * @param $action
     * @param $uid
     * @param $current_uid
     * @param int $page
     * @param int $per_page
     * @param int $words_count
     * @return array
     */
    public static function getUserDynamic($action, $uid, $current_uid, $page = 1, $per_page = 10, $words_count = 100)
    {
        $action = is_array($action) ? $action : explode(',', $action);
        $action_ids = [];
        if ($action) {
            $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
        }
        $where[] = ['status', '=', 1];
        $where[] = ['uid', '=', $uid];

        if (!empty($action_ids)) {
            $where[] = ['action_id', 'IN', $action_ids];
        }
        if ($current_uid!=$uid) {
            $where[] = ['anonymous', '=', 0];
        }

        $action_log_list = db('action_log')
            ->where($where)
            ->distinct(true)
            ->field('uid,record_type,record_id,relation_id,relation_type,anonymous,create_time,action_id')
            ->group('uid,record_type,record_id')
            ->order('create_time','DESC')
            ->page($page,$per_page)
            ->select()
            ->toArray();

        return self::parseActionLog($current_uid, $action_log_list, $words_count);
    }

    //解析行为
    public static function parseActionLog($current_uid, $data, $words_count = 100): array
    {
        $question_ids = $article_ids = $answer_ids = $article_comment_ids = $answer_comment_ids = $data_list_uid = $topic_infos = [];

        foreach ($data as $val) {
            if ((isset($val['record_type']) && $val['record_type'] == 'question') || (isset($val['relation_type']) && $val['relation_type'] == 'question')) {
                if($val['record_type']=='question' && $val['record_id'])
                {
                    $question_ids[] = $val['record_id'];
                }
                if($val['relation_type']=='question' && $val['relation_id'])
                {
                    $question_ids[] = $val['relation_id'];
                }
            }

            if((isset($val['record_type']) && $val['record_type']=='article') || (isset($val['relation_type']) && $val['relation_type']=='article'))
            {
                if($val['record_type']=='article' && $val['record_id'])
                {
                    $article_ids[] = $val['record_id'];
                }
                if($val['relation_type']=='article' && $val['relation_id'])
                {
                    $article_ids[] = $val['relation_id'];
                }
            }

            if((isset($val['record_type']) && $val['record_type']=='answer') || (isset($val['relation_type']) && $val['relation_type']=='answer'))
            {
                if($val['record_type']=='answer' && $val['record_id'])
                {
                    $answer_ids[] = $val['record_id'];
                }
                if($val['relation_type']=='answer' && $val['relation_id'])
                {
                    $answer_ids[] = $val['relation_id'];
                }
            }

            if(isset($val['uid']))
            {
                $data_list_uid[$val['uid']] = $val['uid'] ?? 0;
            }
        }

        $question_infos = $article_infos = $answer_infos = [];

        if (array_unique($question_ids)) {
            if ($last_answers = Answer::getLastAnswerByIds($question_ids))
            {
                foreach ($last_answers as $val)
                {
                    $data_list_uid[$val['uid']] = $val['uid'];
                }
            }
            $topic_infos['question'] = Topic::getTopicByItemIds($question_ids, 'question');
            $question_infos = \app\model\Question::getQuestionByIds($question_ids);
        }

        if (array_unique($article_ids)) {
            $topic_infos['article'] = Topic::getTopicByItemIds($article_ids, 'article');
            $article_infos = \app\model\Article::getArticleByIds($article_ids);
        }

        if (array_unique($answer_ids)) {
            $answer_infos = Answer::getAnswerInfoByIds($answer_ids);
        }

        $users_info = Users::getUserInfoByIds($data_list_uid,'user_name,avatar,nick_name,uid');
        $result_list = [];

        // 行为数据
        $actions = db('action')->where(['status' => 1])->select()->toArray();
        $actions = array_column($actions, null, 'id');

        foreach ($data as $key => $val) {
            // 回答操作记录
            if (isset($val['record_type']) && $val['record_type'] == 'answer' && isset($val['relation_type']) && $val['relation_type'] == 'question' && isset($answer_infos[$val['record_id']]) && isset($question_infos[$answer_infos[$val['record_id']]['question_id']])) {

                if ($answer_infos && $answer_infos[$val['record_id']]) {
                    $result_list[$key]['item_label'] = '回答';
                    $result_list[$key]['item_type'] = 'answer';
                    $question_id = $answer_infos[$val['record_id']]['question_id'];
                    $result_list[$key]['id'] = $question_id;
                    $result_list[$key]['title'] = $question_infos[$question_id]['title'];
                    $result_list[$key]['content'] =  str_cut(strip_tags(htmlspecialchars_decode($question_infos[$question_id]['detail'])),0,$words_count);
                    $result_list[$key]['topics'] = $topic_infos['question'][$question_id] ?? [];
                    $result_list[$key]['images'] = ImageHelper::replaceImageUrl(ImageHelper::srcList(htmlspecialchars_decode($question_infos[$question_id]['detail']))) ?: [];

                    $result_list[$key]['user_info'] = $users_info[$val['uid']] ?? ['url'=>'javascript:;','uid'=>0,'name'=>'未知用户'];
                    $result_list[$key]['view_count'] = $question_infos[$question_id]['view_count'];
                    $result_list[$key]['agree_count'] = $question_infos[$question_id]['agree_count'];
                    $result_list[$key]['answer_count'] = $question_infos[$question_id]['answer_count'];
                    $result_list[$key]['update_time'] = (isset($question_infos[$question_id]['update_time']) &&$question_infos[$question_id]['update_time']) ? date_friendly($question_infos[$question_id]['update_time']):(isset($question_infos[$question_id]['create_time']) ? date_friendly($question_infos[$question_id]['create_time']) : '');

                    $result_list[$key]['answer_info']['comment_count'] = $answer_infos[$val['record_id']]['comment_count'];
                    $result_list[$key]['answer_info']['agree_count'] = $answer_infos[$val['record_id']]['agree_count'];
                    $result_list[$key]['answer_info']['user_info'] = $users_info[$answer_infos[$val['record_id']]['uid']];
                    $result_list[$key]['answer_info']['content'] = str_cut(strip_tags(htmlspecialchars_decode($answer_infos[$val['record_id']]['content'])),0,$words_count);
                }
            }

            // 问题类型操作记录
            elseif(((isset($val['record_type']) && $val['record_type']=='question') || (isset($val['relation_type']) && $val['relation_type']=='question')) && $val['record_type']!='answer')
            {
                if($question_infos && (isset($question_infos[$val['record_id']]) || isset($question_infos[$val['relation_id']])))
                {
                    $result_list[$key]['item_label'] = '提问';
                    $result_list[$key]['item_type'] = 'question';
                    $question = $question_infos[$val['record_id']] ?? $question_infos[$val['relation_id']];
                    $result_list[$key]['id'] = $question['id'];
                    $result_list[$key]['title'] = $question['title'];
                    $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($question['detail'])),0,$words_count);
                    $result_list[$key]['topics'] = $topic_infos['question'][$question['id']] ?? [];
                    $result_list[$key]['images'] = ImageHelper::replaceImageUrl(ImageHelper::srcList(htmlspecialchars_decode($question['detail']))) ?: [];

                    $result_list[$key]['user_info'] = $users_info[$question['uid']] ?? ['url'=>'javascript:;','uid'=>0,'name'=>'未知用户'];
                    $result_list[$key]['update_time'] = date_friendly($question['update_time']?:$question['create_time']);

                    $result_list[$key]['view_count'] = $question['view_count'];
                    $result_list[$key]['agree_count'] = $question['agree_count'];
                    $result_list[$key]['answer_count'] = $question['answer_count'];
                    $result_list[$key]['answer_info'] = [];
                }
            }

            //文章类型操作记录
            elseif((isset($val['record_type']) && $val['record_type']=='article') || (isset($val['relation_type']) && $val['relation_type']=='article'))
            {
                if ($article_infos && (isset($article_infos[$val['record_id']]) || isset($article_infos[$val['relation_id']]))) {
                    $result_list[$key]['item_label'] = '文章';
                    $result_list[$key]['item_type'] = 'article';
                    $article = $article_infos[$val['record_id']] ?? $article_infos[$val['relation_id']];
                    $result_list[$key]['id'] = $article['id'];
                    $result_list[$key]['title'] = $article['title'];
                    $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($article['message'])),0,$words_count);
                    $result_list[$key]['topics'] = $topic_infos['article'][$article['id']] ?? [];
                    $result_list[$key]['images'] = ImageHelper::replaceImageUrl(ImageHelper::srcList(htmlspecialchars_decode($article['message']))) ?: [];

                    if (!$result_list[$key]['images']) {
                        $result_list[$key]['images'] = [ImageHelper::replaceImageUrl($article['cover'])];
                    }
                    $result_list[$key]['action_label'] = str_replace(['[user]', '[time]'], ['', date_friendly($val['create_time'])], $actions[$val['action_id']]['log_rule']);
                    $result_list[$key]['user_info'] = $users_info[$article['uid']] ?? ['url'=>'javascript:;','uid'=>0,'name'=>'未知用户'];
                    $result_list[$key]['update_time'] = date_friendly($article['update_time']?:$article['create_time']);
                    $result_list[$key]['view_count'] = $article['view_count'];
                    $result_list[$key]['agree_count'] = $article['agree_count'];
                    $result_list[$key]['comment_count'] = $article['comment_count'];
                }
            }
        }

        return array_values($result_list);
    }

    /**
     * 获取热门用户
     * @param int $uid
     * @param array $where
     * @param array $order
     * @param int $per_page
     * @param int $page
     * @return mixed
     */
    public static function getHotUsers(int $uid=0, array $where=[], array $order=[], int $per_page=5, int $page=1)
    {
        $where = !empty($where) ? $where : [['status','=',1],['reputation','>',0]];
        $order = !empty($order) ? $order : ['reputation'=>'DESC','answer_count'=>'DESC'];
        $list = db('users')
            ->where([['uid','<>',$uid]])
            ->where($where)
            ->orderRaw('RAND()')
            ->order($order)
            ->limit($per_page)
            ->select()
            ->toArray();
        $avatar = request()->domain().'/static/common/image/default-avatar.svg';
        foreach ($list as $key=>$val)
        {
            $list[$key]['avatar'] =$val['avatar'] ? ImageHelper::replaceImageUrl($val['avatar']) : $avatar;
            $list[$key]['url'] = get_user_url($val['uid']);
            $list[$key]['is_focus'] = db('users_follow')->where(['fans_uid'=> (int)$val['uid']])->value('id');
        }
        return $list;
    }

    // 用户列表-大咖
    public static function getUserList($where, $order = [], $current_page = 1, $per_page = 10, $uid = 0): array
    {
        $order = $order ?:['create_time'=>'desc'];

        $list = db('users')->where($where)->withoutField('password')->order($order)->page($current_page, $per_page)->select()->toArray();
        $data = [];
        if (empty($list)) return $data;
        $avatar = request()->domain().'/static/common/image/default-avatar.svg';
        foreach ($list as $user) {
            $data[] = [
                'uid' => $user['uid'],
                'nick_name' => $user['nick_name'],
                'avatar' => $user['avatar'] ? ImageHelper::replaceImageUrl($user['avatar']) : $avatar,
                'signature' => $user['signature'] ?: '这家伙没有留下自我介绍...',
                'integral' => $user['integral'],
                'reputation' => $user['reputation'],
                'agree_count' => $user['agree_count'],
                'fans_count' => $user['fans_count'],
                'has_focus' => \app\model\Users::checkFocus($uid, $user['uid']) ? 1 : 0
            ];
        }

        return $data;
    }

    //获取积分明细列表
    public static function getScoreList($where=[],$page=1,$per_page=10): array
    {
        $list = db('integral_log')->where($where)->order('create_time','DESC')->page($page,$per_page)->select()->toArray();

        foreach ($list as $key=>$val)
        {
            $list[$key]['create_time'] = date_friendly($val['create_time']);
            if ($val['action_type'] == 'LOGIN')
            {
                $list[$key]['extend'] = '';
            }
        }
        return $list;
    }

    //获取草稿列表
    public static function getDraftByType($uid,$item_type,$page=1,$per_page=10): array
    {
        $where=[
            ['uid','=',intval($uid)],
            ['item_type','=',$item_type],
        ];

        $draft_list = db('draft')->where($where)->page($page,$per_page)->select()->toArray();

        foreach ($draft_list as $key=>$val)
        {
            $data = json_decode($val['data'],true);
            if(isset($data['detail']))
            {
                $data['content'] = str_cut(strip_tags(htmlspecialchars_decode($data['detail'])),0,150);
            }

            if(isset($data['message']))
            {
                $data['content'] = str_cut(strip_tags(htmlspecialchars_decode($data['message'])),0,100);
            }

            if(isset($data['content']))
            {
                $data['content'] = str_cut(strip_tags(htmlspecialchars_decode($data['content'])),0,150);
            }

            $draft_list[$key]['data']=$data;

            if($item_type=='answer')
            {
                $question_info = Question::getQuestionInfo(intval($data['question_id']));
                if($question_info)
                {
                    $draft_list[$key] = array_merge($question_info,$val);
                    $draft_list[$key]['data']=$data;
                }
            }

            $draft_list[$key]['create_time']= date_friendly($val['create_time']);
        }

        return $draft_list;
    }


    /**
     * 获取用户关注
     * @param $uid
     * @param $current_uid
     * @param $type
     * @param $page
     * @param $per_page
     * @return array
     */
    public static function getUserFocus($uid, $current_uid, $type, $page = 1, $per_page = 10)
    {
        switch ($type) {
            case 'friend':
                $dbName = 'users_follow';
                $where = ['fans_uid' => $uid];
                break;
            case 'fans':
                $dbName = 'users_follow';
                $where = ['friend_uid' => $uid];
                break;
            case 'column':
                $dbName = 'column_focus';
                $where = ['uid' => $uid];
                break;
            default:
                $dbName = 'topic_focus';
                $where = ['uid' => $uid];
        }

        $result = db($dbName)->where($where)->page($page, $per_page)->select()->toArray();

        $data = [];
        if (empty($result)) return $data;

        if ('friend' == $type) {
            $data = self::getUserInfoByIds(array_column($result,'friend_uid'), 'avatar,uid,nick_name,signature,integral,reputation,fans_count,agree_count');
            foreach ($data as &$val) {
                $val['signature'] = $val['signature'] ?: '这家伙没有什么简介';
                $val['has_focus'] = FocusLogic::checkUserIsFocus($current_uid, 'user', $val['uid']) ? 1 : 0;
            }
        }
        if ('fans' == $type) {
            $data = self::getUserInfoByIds(array_column($result,'fans_uid'), 'avatar,uid,nick_name,signature,integral,reputation,fans_count,agree_count');
            foreach ($data as &$val) {
                $val['signature'] = $val['signature'] ?: '这家伙没有什么简介';
                $val['has_focus'] = FocusLogic::checkUserIsFocus($current_uid, 'user', $val['uid']) ? 1 : 0;
            }
        }

        if ('column' == $type) {
            $data = \app\model\Column::getColumnByIds(array_column($result,'column_id'));
            $cover = request()->domain().'/static/common/image/cover.svg';
            foreach ($data as &$val) {
                $val['has_focus'] = FocusLogic::checkUserIsFocus($current_uid, 'column', $val['id']) ? 1 : 0;
                $val['cover'] = $val['cover'] ? ImageHelper::replaceImageUrl($val['cover']) : $cover;
            }
        }

        if ('topic' == $type) {
            $data = Topic::getTopicByIds(array_column($result,'topic_id'));
            $pic = request()->domain().'/static/common/image/topic.svg';
            foreach ($data as &$val) {
                $val['has_focus'] = FocusLogic::checkUserIsFocus($current_uid, 'topic', $val['id']) ? 1 : 0;
                $val['pic'] = $val['pic'] ? ImageHelper::replaceImageUrl($val['pic']) : $pic;
            }
        }

        return array_values($data);
    }

    /**
     * 更新用户通知未读数
     * @param $recipient_uid
     * @return bool
     */
    public static function updateNotifyUnread($recipient_uid)
    {
        $unread_num = db('users_notify')->where(['recipient_uid'=>(int)$recipient_uid,'read_flag'=>0])->count();
        return self::updateUserFiled($recipient_uid,['notify_unread'=>$unread_num]);
    }

    /**
     * 更新用户字段
     * @param $uid
     * @param $data //主表用户信息
     * @param $extend //附加表用户信息
     * @return mixed
     */
    public static function updateUserFiled($uid,$data=null,$extend=null): bool
    {
        // 更新主表
        if ($data) {
            if (isset($data['password']) && $data['password']) {
                $data['salt'] = $data['salt'] ?? RandomHelper::alnum();
                $data['password'] = compile_password($data['password'], $data['salt']);
            }

            $pinyin = new Pinyin();
            if (isset($data['url_token']) && $data['url_token']) $data['url_token'] = $pinyin->permalink($data['url_token'],'');

            if (!db('users')->where(['uid'=>$uid])->update($data)) return false;
        }

        // 更新附属表用户信息
        if ($extend && !db('users_extends')->where(['uid'=>$uid])->update($extend)) return false;

        //更新缓存信息
        if ($uid === session('login_uid')) {
            $user =self::getUserInfoByUid($uid);
            session('login_user_info', $user);
        }

        return true;
    }
}