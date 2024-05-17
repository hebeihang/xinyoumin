<?php
namespace app\model\api\v1;

use app\common\library\helper\HtmlHelper;
use app\common\library\helper\ImageHelper;
use app\common\library\helper\IpLocation;
use app\common\library\helper\LogHelper;
use app\logic\common\FocusLogic;
use app\model\Answer;
use app\model\Attach;
use app\model\BaseModel;
use app\model\Category;
use app\model\Common;
use app\model\PostRelation;
use app\model\Report;
use app\model\Topic;
use app\model\Vote;
use tools\Tree;

class Question extends BaseModel
{
    //根据问题id获取问题详情
    public static function getQuestionInfo($question_id,$field="*",$status=1)
    {
        $where['status'] = $status;
        $where['id'] = intval($question_id);
        $question_info = db('question')->field($field)->where($where)->find();
        if(isset($question_info['detail']))
        {
            $question_info['detail'] = htmlspecialchars_decode($question_info['detail']);
        }
        return $question_info;
    }

    /**
     * 保存更新邀请用户
     * @param $question_info
     * @param $sender_uid
     * @param $recipient_uid
     * @return false
     */
    public static function saveQuestionInvite($question_info,$sender_uid,$recipient_uid)
    {
        $question_id=$question_info['id'];
        if(!$question_id || !$sender_uid || !$recipient_uid) return false;
        $where[] = ['question_id','=', (int)$question_id];
        $where[] = ['sender_uid','=', (int)$sender_uid];
        $where[] = ['recipient_uid','=', (int)$recipient_uid];

        $invite_id = db('question_invite')->where($where)->value('id');

        if($invite_id)
        {
            return false;
        }

        $insert_data = array(
            'question_id'=> (int)$question_id,
            'sender_uid'=> (int)$sender_uid,
            'recipient_uid'=> (int)$recipient_uid,
            'create_time'=>time()
        );
        $result = db('question_invite')->insert($insert_data);
        if($result)
        {
            LogHelper::addIntegralLog('INVITE_ANSWER',$question_id,'question',$sender_uid);
        }
        send_notify($sender_uid,$recipient_uid,'INVITE_ANSWER','question',$question_id);
        return $result;
    }

    /**
     * 获取回答列表
     * @param $question_id
     * @param int $answer_id
     * @param int $page
     * @param int $per_page
     * @param array $sort
     * @param int $uid
     * @return array
     */
    public static function getAnswerByQuestionId($question_id,int $answer_id=0,int $page=1,int $per_page=10,array $sort=[],int $uid=0,$export_answer=0): array
    {
        if ($answer_id) {
            $where = [['question_id','=', $question_id], ['id' ,'=',$answer_id],['status','=',1]];
        } else {
            $where = [['question_id','=', $question_id],['status','=',1]];
        }
        if($export_answer)
        {
            $where[] = ['id','<>',$export_answer];
        }

        $list =db('answer')
            ->where($where)
            ->order($sort)
            ->page($page,$per_page)
            ->select()
            ->toArray();
        $ip = new IpLocation();
        foreach ($list as $key=>$val)
        {
            $list[$key]['user_info'] = Users::getUserInfoByUid($val['uid'],'nick_name,uid,avatar,signature');
            $list[$key]['content'] = HtmlHelper::parseImgUrl(htmlspecialchars_decode($val['content']));
            $list[$key]['checkFavorite']=Common::checkFavorite(['uid'=>$uid,'item_id'=>$val['id'],'item_type'=>'answer'])?1:0;
            $list[$key]['checkReport']=Report::getReportInfo($val['id'],'answer',$uid)?1:0;
            $list[$key]['comments'] = $val['comment_count'] ? self::getAnswerComments($val['id']) :[];
            $list[$key]['update_time'] = $val['update_time'] ? date_friendly($val['update_time']) :date_friendly($val['create_time']);
            if(get_setting('show_answer_user_ip')=='Y' && $val['answer_user_ip'] && !$val['answer_user_local'])
            {
                $list[$key]['answer_user_local'] = $ip->getLocation($val['answer_user_ip'])['country'];
            }
        }
        if($answer_id)
        {
            $list = end($list);
        }
        return $list;
    }

    /**
     * 更新问题浏览量
     * @param $question_id
     * @param int $uid
     * @return bool
     */
    public static function updateQuestionViews($question_id,int $uid=0): bool
    {
        $cache_key = md5('cache_question_'.$question_id.'_'.$uid);
        $cache_result = cache($cache_key);
        if($cache_result) {
            return true;
        }
        cache($cache_key,$cache_key,['expire'=>60]);
        return db('question')->where(['id'=>$question_id])->inc('view_count')->update();
    }

    /**
     * 获取回答评论
     * @param $answer_id
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public static function getAnswerComments($answer_id,int $page=1,int $per_page=5): array
    {
        $list = db('answer_comment')
            ->where(['answer_id' => $answer_id, 'status' => 1])
            ->page($page,$per_page)
            ->select()
            ->toArray();

        foreach ($list as $key => &$value) {
            $list[$key]['user_info'] = Users::getUserInfoByUid($value['uid'],'nick_name,avatar,uid');
            $list[$key]['message'] = htmlspecialchars_decode($value['message']);
            $list[$key]['at_user_info'] =$value['at_uid']? Users::getUserInfoByUid($value['at_uid'],'nick_name,uid'):[];
            $list[$key]['create_time'] = date_friendly($value['create_time']);
        }
        Tree::config([
            'child' => 'comments',
        ]);
        return Tree::toTree($list);
    }

    /**
     * 获取问题列表
     * @param $uid
     * @param $sort
     * @param $category_id
     * @param $page
     * @param $per_page
     * @param $relation_uid
     * @param $words_count
     * @return array|mixed|object
     */
    public static function getQuestionList($uid=null,$sort = null, $category_id = null,$page=1, $per_page=0,$relation_uid=0,$words_count='150')
    {
        // 推荐内容
        if ($sort=='recommend') {
            return \app\model\api\v1\Common::getRecommendPost($uid,'question',null, $category_id,$page, $per_page,$relation_uid);
        }

        $key = md5($sort.'-'.$category_id.'-'.$page.'-'.$per_page.'-'.$relation_uid);

        $cache_key = 'cache_api_list_question_data_'.$key;

        $result_list = [];
        if($cache_list_time = get_setting('cache_list_time')) {
            $result_list = cache($cache_key);
        }

        if ($result_list) return $result_list;

        $order = $where = array();
        $order['set_top_time'] = 'DESC';
        $where[] = ['status','=',1];

        if ($relation_uid) {
            $where[] = ['uid','=',$relation_uid];
            $where[] = ['is_anonymous', '=', 0];
        }

        if ($sort=='unresponsive') {
            $where[] = ['answer_count','=',0];
        }

        if ($sort=='hot') {
            $order['popular_value'] = 'desc';
            $where[] = ['popular_value','>',1];
        }

        if ($sort=='new') {
            $order['set_top_time'] = 'DESC';
            $order['update_time'] = 'DESC';
        }

        $order['create_time'] = 'DESC';

        if ($category_id) {
            if ($category_ids = Category::getCategoryWithChildIds($category_id,true)) {
                $where[] = ['category_id', 'in', implode(',', $category_ids )];
            } else {
                $where[] = ['category_id', '=', $category_id];
            }
        }

        $list = db('question')->where($where)->order($order)->page($page,$per_page)->column('id,uid,is_anonymous,title,detail,set_top,create_time,update_time,view_count,agree_count,answer_count,comment_count');

        $last_answers = Answer::getLastAnswerByIds(array_column($list,'id'),'content,id,question_id,against_count,agree_count,uid,comment_count,is_anonymous,create_time,thanks_count');
        $users_info = Users::getUserInfoByIds(array_unique(array_column(array_merge($list ?: [], $last_answers ?: []),'uid')),'user_name,avatar,nick_name,uid');
        $topic_infos = Topic::getTopicByItemIds(array_column($list,'id'), 'question');
        $result_list = [];
        $anonymous_user = [
            'nick_name' => '匿名用户',
            'avatar' => request()->domain().'/static/common/image/default-avatar.svg'
        ];
        foreach ($list as $key => $data) {
            $result_list[$key] = $data;
            $result_list[$key]['has_focus'] = 0;
            $result_list[$key]['thanks_count'] = 0;
            $result_list[$key]['title'] = strip_tags(htmlspecialchars_decode($data['title']));
            $result_list[$key]['answer_info'] = $last_answers[$data['id']] ?? false;
            if ($result_list[$key]['answer_info']) {
                $result_list[$key]['item_type'] = 'answer';
                $result_list[$key]['action_label'] = '回答了问题';
                if ($result_list[$key]['answer_info']['is_anonymous']) {
                    $result_list[$key]['user_info'] = $anonymous_user;
                } else {
                    $result_list[$key]['user_info'] = $users_info[$last_answers[$data['id']]['uid']];
                }
                $data['create_time'] = $result_list[$key]['answer_info']['create_time'];
                $result_list[$key]['is_anonymous'] = $result_list[$key]['answer_info']['is_anonymous'];
                $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($result_list[$key]['answer_info']['content'])),0, $words_count);
                $result_list[$key]['vote_value'] = Vote::getVoteByType($result_list[$key]['answer_info']['id'],'answer', $uid);
                $result_list[$key]['thanks_count'] = $result_list[$key]['answer_info']['thanks_count'];
            } else {
                $result_list[$key]['item_type'] = 'question';
                $result_list[$key]['action_label'] = '发布了问题';
                $result_list[$key]['user_info'] = $data['is_anonymous'] ? $anonymous_user : $users_info[$data['uid']];
                $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($data['detail'])),0, $words_count);
                $result_list[$key]['vote_value'] = Vote::getVoteByType($data['id'],'question', $uid);
                $result_list[$key]['has_focus'] = FocusLogic::checkUserIsFocus($uid,'question', $data['id']);
            }

            // 格式化时间戳
            $result_list[$key]['create_time'] = date_friendly($data['create_time']);
            $result_list[$key]['update_time'] = date_friendly($data['update_time']);

            $cover = ImageHelper::srcList(htmlspecialchars_decode($result_list[$key]['answer_info'] ? $result_list[$key]['answer_info']['content'] : $data['detail']));
            $result_list[$key]['images'] = ImageHelper::replaceImageUrl($cover) ?: [];
            $result_list[$key]['topics'] = $topic_infos[$data['id']] ?? [];
        }

        if ($cache_list_time) {
            cache($cache_key, $result_list, ['expire' => $cache_list_time * 60]);
        }

        return $result_list;
    }

    //获取问题评论列表
    public static function getQuestionComments($question_id,$page,$sort=['create_time'=>'desc'],$per_page=10): array
    {
        $list = db('question_comment')
            ->where([['question_id','=', (int)$question_id], ['status','=', 1]])
            ->order($sort)
            ->page($page,$per_page)
            ->select()
            ->toArray();

        foreach ($list as $key => $value) {
            $list[$key]['user_info'] = Users::getUserInfoByUid($value['uid'],'nick_name,avatar,uid');
            $list[$key]['check'] = Common::checkVote([
                'uid'=>intval(session('login_uid')),
                'item_type'=>'question_comment',
                'item_id'=>$value['id'],
                'vote_value'=>1,
            ],'question_comment')?1:0;

            $list[$key]['message'] = htmlspecialchars_decode($value['message']);
            //$list[$key]['report'] = Report::getReportInfo($value['id'],'question_comment',intval(session('login_uid')));
            $list[$key]['vote_count'] = self::getQuestionCommentVoteCount($value['id']);
            $list[$key]['create_time'] = date_friendly($value['create_time']);
            $list[$key]['at_user_info'] =$value['at_uid']? Users::getUserInfoByUid($value['at_uid'],'nick_name,uid'):[];
        }

        Tree::config([
            'child' => 'comments',
        ]);
        return Tree::toTree($list);
    }

    /**
     * 获取问题评论列表
     * @param $item_id
     * @return mixed
     */
    public static function getQuestionCommentVoteCount($item_id){
        return db('question_vote')
            ->where([['item_id','=', (int)$item_id], ['item_type','=', 'question_comment'],['vote_value','=',1]])
            ->count();
    }

    //问题操作管理
    public static function manger($question_id,$type,$value=0): bool
    {
        if(!$question_info = self::getQuestionInfo($question_id))
        {
            self::setError('问题不存在');
            return false;
        }

        if(!$question_info['status'])
        {
            self::setError('问题已被删除');
            return false;
        }
        $value = !$value;
        switch ($type)
        {
            case 'recommend':
                self::update(['is_recommend'=>$value],['id'=>$question_id]);
                PostRelation::updatePostRelation($question_id,'question',['is_recommend'=>$value]);
                break;

            case 'set_top':
                self::update(['set_top'=>$value,'set_top_time'=>time()],['id'=>$question_id]);
                PostRelation::updatePostRelation($question_id,'question',['set_top'=>$value,'set_top_time'=>time()]);
                break;
        }

        return true;
    }

    /**
     * 删除问题
     * @param $id
     * @param bool $realMove
     * @return bool
     */
    public static function removeQuestion($id, bool $realMove=false): bool
    {
        $id = is_array($id) ? $id : explode(',',$id);
        $question_infos = db('question')->whereIn('id',$id)->column('id,status,uid');
        if(!$question_infos)
        {
            return false;
        }

        if($realMove)
        {
            db('question_comment')->whereIn('question_id',$id)->delete();
            db('answer')->whereIn('question_id',$id)->delete();
            db('question')->whereIn('id',$id)->delete();
            Attach::removeAttachByItemIds('question',$id);
            foreach ($question_infos as $question_info)
            {
                PostRelation::updatePostRelation($question_info['id'],'question',['status'=>0]);
                LogHelper::removeActionLog('question',$question_info['id'],null,true);
                db('question_focus')->where(['question_id'=>$question_info['id']])->delete();
            }
        }else{
            if(!db('question')->whereIn('id',$id)->update(['status'=>0]))
            {
                self::setError('删除失败');
                return false;
            }
            //更新首页表
            foreach ($question_infos as $question_info)
            {
                //逻辑删除行为记录
                LogHelper::removeActionLog('question',$question_info['id']);
                PostRelation::updatePostRelation($question_info['id'],'question',['status'=>0]);
                db('question_focus')->where(['question_id'=>$question_info['id']])->update(['status'=>0]);
            }
        }
        return true;
    }


    /**
     * 保存回答评论
     * @param $data
     * @return false
     */
    public static function saveAnswerComments($data)
    {
        $arr['answer_id'] = $data['answer_id'];
        $arr['uid'] = $data['uid'];
        $arr['message'] = htmlspecialchars($data['message']);

        if (isset($data['id']) and $data['id'] > 0) {
            $arr['id'] = $data['id'];
            $arr['update_time'] = time();
        } else {
            $arr['create_time'] = time();
        }

        $arr['at_uid'] = intval($data['at_uid']);
        $arr['pid'] = intval($data['pid']);
        $result = db('answer_comment')->insertGetId($arr);

        if(!$result)
        {
            self::setError('评论失败');
            return false;
        }

        $publish_info=db('answer')->field('uid,id')->find($data['answer_id']);

        self::updateCommentCount($data['answer_id']);

        //自己发表的评论就不通知了
        if($publish_info['uid']!=$data['uid'])
        {
            send_notify($data['uid'],$publish_info['uid'],'NEW_ANSWER_COMMENT','question',$data['question_info']['id']);
        }

        $arr['id'] = $result;
        $arr['create_time'] = date_friendly($arr['create_time']);
        return $arr;
    }

    /**
     * 更新回答评论数
     * @param $answer_id
     * @return mixed
     */
    public static function updateCommentCount($answer_id)
    {
        $count = db('answer_comment')->where(['answer_id' => $answer_id, 'status' => 1])->count();
        return db('answer')->where(['id' => $answer_id])->update(['comment_count' => $count]);
    }

}