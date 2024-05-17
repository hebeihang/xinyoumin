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
use app\common\library\helper\ImageHelper;
use app\logic\common\FocusLogic;
use app\model\Answer;
use app\model\Article;
use app\model\BaseModel;
use app\model\Category;
use app\model\Column;
use app\model\Question;
use app\model\Report;
use app\model\Topic;
use app\model\Topic as TopicModel;
use app\model\Vote;
use plugins\reward\model\Reward;
use tools\Tree;

class Common extends BaseModel
{
    /**
     * 获取用户推荐内容,管理员推荐的内容会优先显示
     * @param null $uid
     * @param null $item_type
     * @param null $category_id
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     * @param int $word_count
     */
    public static function getRecommendPost($uid=null,$item_type=null,$category_id = null,$page=1, $per_page=0,$relation_uid=0,$word_count=100)
    {
        $per_page = $per_page ?:intval(get_setting('contents_per_page'));
        $list = [];
        $key = $category_id.'-'.$item_type.'-'.$page.'-'.$per_page.'-'.$relation_uid;
        $cache_key = 'cache_explore_data_'.$key;
        if($cache_explore_time = get_setting('cache_explore_time'))
        {
            $list = cache($cache_key);
        }

        if($list) return $list;

        $where = [];

        $relation = config('aws.relation');

        if(!$item_type)
        {
            $where[] = ['item_type','IN', ['question','article',implode(',',$relation)]];
        }

        if ($item_type)
        {
            $where[] = ['item_type','=', $item_type];
        }

        if($uid)
        {
            $topic_ids = db('topic_focus')->where(['uid'=>intval($uid)])->orderRaw('RAND()')->limit(10)->column('topic_id');
            $relationTopic = db('topic_relation')->where(['uid'=>$uid,'status'=>1])->column('topic_id');
            $topic_ids = $relationTopic ? array_merge($topic_ids,$relationTopic) : $topic_ids;
            //根据关注的话题和自己发布内容所在的话题排除自己发布的推荐内容
            $topic_where = $item_type ? 'item_type="'.$item_type.'" AND status=1 AND uid<>'.$uid : 'status=1 AND uid<>'.$uid;
            $topicIdsWhere = $topic_ids ? ' AND topic_id IN('.implode(',',array_unique($topic_ids)).')' : '';
            $relationInfo = db('topic_relation')
                ->whereRaw($topic_where.$topicIdsWhere)
                ->column('item_id,item_type');
            $item_ids = array_column($relationInfo,'item_id');
            $where[] = ['item_id','in',implode(',', $item_ids)];
        }

        if ($category_id)
        {
            $category_ids = Category::getCategoryWithChildIds($category_id,true);
            if($category_ids)
            {
                $where[] = ['category_id','in', implode(',',$category_ids )];
            }else{
                $where[] = ['category_id','=', $category_id];
            }
        }

        $list = db('post_relation')->where($where)->orderRaw('is_recommend DESC,RAND()')->field('id,item_id,item_type,uid')->paginate(
            [
                'list_rows'=> $per_page,
                'page' => $page,
            ]
        );
        $list = self::processPostList($list,$uid);
        if($cache_explore_time)
        {
            cache($cache_key,$list,['expire'=>$cache_explore_time*60]);
        }

        return $list;
    }

    /**
     * 获取聚合数据列表
     * @param null $uid
     * @param null $item_type
     * @param null $sort
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     */
    public static function getPostRelationList($uid=null,$item_type=null, $sort = null, $page=1, $per_page=0,$relation_uid=0,$words_count=100,$category_id=0,$topic_ids=null)
    {
        $per_page = $per_page ?:intval(get_setting('contents_per_page'));
        $result_list = [];
        $key = $sort.'-'.$item_type.'-'.$page.'-'.$per_page.'-'.$relation_uid;
        $cache_key = 'cache_api_explore_data_'.$key;
        if($cache_explore_time = get_setting('cache_explore_time'))
        {
            $result_list = cache($cache_key);
        }
        if($result_list) return $result_list;

        $order = $where = array();
        $order['set_top_time'] = 'DESC';
        $where[] = ['status','=',1];
        if($relation_uid)
        {
            $where[] = ['uid','=',$relation_uid];
        }

        if($category_id)
        {
            $where[] = ['category_id','=',$category_id];
        }

        $item_types = ['question','article',implode(',',config('aws.relation'))];

        if($item_type && !in_array($item_type,$item_types))
        {
            return false;
        }

        //关注单独处理
        if($sort == 'focus') {
            return Users::parseActionLog($uid,self::getUserFocusLogList($uid, 'all', $page, $per_page), $words_count);
        }

        //推荐内容
        if($sort=='recommend')
        {
            return self::getRecommendPost($uid,$item_type, null,intval($page), intval($per_page),intval($relation_uid),$words_count);
        }

        if($sort=='unresponsive')
        {
            $where[] = ['answer_count','=',0];
            $item_type = 'question';
        }

        if($sort=='hot')
        {
            $order['popular_value'] = 'desc';
            $where[] = ['popular_value','>',0];
        }

        if($sort=='new'){
            $order['set_top_time'] = 'DESC';
            $order['update_time'] = 'DESC';
        }

        if ($topic_ids)
        {
            $topic_ids = is_array($topic_ids) ? $topic_ids : explode(',',$topic_ids);
            $topic_where = $item_type ? 'item_type="'.$item_type.'" AND status=1' : 'status=1';
            $topicIdsWhere = ' AND topic_id IN('.implode(',',array_unique($topic_ids)).')';
            $relationInfo = db('topic_relation')
                ->whereRaw($topic_where.$topicIdsWhere)
                ->column('item_id,item_type');
            $item_ids = array_column($relationInfo,'item_id');
            $item_types = array_column($relationInfo,'item_type');
            $where[] = ['item_id','in',implode(',', $item_ids)];
            if(!$item_type)
            {
                $where[] = ['item_type','in',implode(',', $item_types)];
            }
        }

        $order['create_time'] = 'DESC';

        if(!$item_type)
        {
            $where[] = ['item_type','IN', $item_types];
        }

        if ($item_type)
        {
            $where[] = ['item_type','=', $item_type];
        }

        $list = db('post_relation')
            ->where($where)
            ->order($order)
            ->field('id,item_id,item_type,uid')
            ->page($page,$per_page)
            ->select()
            ->toArray();

        $list = self::processPostList($list,$uid,$words_count);

        if($cache_explore_time)
        {
            cache($cache_key,$list,['expire'=>$cache_explore_time*60]);
        }

        return $list ?: [];
    }

    /**
     * 通用解析聚合数据列表
     */
    public static function processPostList($contents,$uid,$words_count=100)
    {
        if (!$contents) {
            return false;
        }
        $result_list = [];
        $last_answers = $topic_infos = $question_ids = $article_ids = $data_list_uid = $question_infos = $article_infos =  array();
        foreach ($contents as $data)
        {
            if($data['item_type']=='question')
            {
                $question_ids[] = $data['item_id'];
            }elseif($data['item_type']=='article')
            {
                $article_ids[] = $data['item_id'];
            }
            $data_list_uid[$data['uid']] = $data['uid'];
        }

        if ($question_ids)
        {
            if ($last_answers = Answer::getLastAnswerByIds($question_ids,'uid,content,agree_count,is_anonymous,question_id,comment_count'))
            {
                foreach ($last_answers as $val)
                {
                    $data_list_uid[$val['uid']] = $val['uid'];
                }
            }
            $topic_infos['question'] = Topic::getTopicByItemIds($question_ids, 'question');
            $question_infos = Question::getQuestionByIds($question_ids);
        }

        if ($article_ids)
        {
            $topic_infos['article'] = Topic::getTopicByItemIds($article_ids, 'article');
            $article_infos = Article::getArticleByIds($article_ids);
        }
        $users_info = Users::getUserInfoByIds($data_list_uid,'user_name,avatar,nick_name,uid,verified');

        foreach ($contents as $key => $data)
        {
            if ($data['item_type']=='question') {
                if ($question_infos && isset($question_infos[$data['item_id']])) {
                    $result_list[$key] = $question_infos[$data['item_id']];
                    $result_list[$key]['has_focus'] = 0;
                    $result_list[$key]['item_id'] = intval($data['item_id']);
                    $result_list[$key]['item_type'] = $data['item_type'];
                    $result_list[$key]['post_id'] = intval($data['id']);
                    //是否已回答
                    $result_list[$key]['is_answer'] = $uid ? db('answer')->where(['uid'=>$uid,'question_id'=>$data['item_id'],'status'=>1])->value('id') : 0;

                    //回答用户
                    $answerUidLists = db('answer')->where(['question_id'=>$data['item_id'],'status'=>1])->order('agree_count','DESC')->limit(3)->column('uid');
                    $result_list[$key]['answer_users'] = $answerUidLists ? array_values(Users::getUserInfoByIds($answerUidLists,'user_name,avatar,nick_name,uid',99)):[];

                    $result_list[$key]['answer_info'] = $last_answers[$data['item_id']] ?? [];
                    $result_list[$key]['content'] = str_cut(strip_tags($result_list[$key]['detail']),0,$words_count);

                    if ($result_list[$key]['answer_info']) {
                        $result_list[$key]['item_type'] = 'answer';
                        $result_list[$key]['answer_info']['user_info'] = $users_info[$last_answers[$data['item_id']]['uid']];
                        $result_list[$key]['answer_info']['content'] = str_cut(strip_tags($last_answers[$data['item_id']]['content']),0,$words_count);
                    } else{
                        $result_list[$key]['item_type'] = 'question';
                    }

                    $result_list[$key]['vote_value'] = Vote::getVoteByType($data['item_id'],'question', $uid);
                    $result_list[$key]['topics'] = $topic_infos['question'][$data['item_id']] ?? [];
                    $result_list[$key]['user_info'] = $users_info[$data['uid']] ?? ['url'=>'javascript:;','uid'=>0,'name'=>'未知用户'];
                    $result_list[$key]['images'] = ImageHelper::replaceImageUrl(ImageHelper::srcList($result_list[$key]['detail'])) ?: [];

                    //是否举报
                    $result_list[$key]['is_report'] = Report::getReportInfo($data['item_id'], 'question', $uid) ? 1 : 0;

                    //是否收藏
                    $result_list[$key]['is_favorite'] = \app\model\Common::checkFavorite(['uid' => $uid, 'item_id' => $data['item_id'], 'item_type' => 'question']) ? 1 : 0;
                    $result_list[$key]['update_time'] = (isset($result_list[$key]['update_time']) && $result_list[$key]['update_time']) ? date_friendly($result_list[$key]['update_time']):(isset($result_list[$key]['create_time']) ? date_friendly($result_list[$key]['create_time']) : '');

                }
            }

            if ($data['item_type']=='article') {
                if($article_infos && isset($article_infos[$data['item_id']]))
                {
                    $result_list[$key]['has_focus'] = 0;
                    $result_list[$key]['item_id'] = intval($data['item_id']);
                    $result_list[$key]['item_type'] = $data['item_type'];
                    $result_list[$key]['post_id'] = intval($data['id']);

                    $result_list[$key] = $article_infos[$data['item_id']];
                    $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($result_list[$key]['message'])),0,$words_count);
                    $result_list[$key]['item_type'] = 'article';
                    $result_list[$key]['cover'] = ImageHelper::replaceImageUrl($article_infos[$data['item_id']]['cover']);

                    if ($result_list[$key]['cover']) {
                        $result_list[$key]['images'] = [$result_list[$key]['cover']];
                    } else {
                        $result_list[$key]['images'] = ImageHelper::replaceImageUrl(ImageHelper::srcList(htmlspecialchars_decode($result_list[$key]['message']))) ?: [];
                    }

                    //是否举报
                    $result_list[$key]['is_report'] = Report::getReportInfo($data['item_id'], 'article', $uid) ? 1 : 0;

                    //是否收藏
                    $result_list[$key]['is_favorite'] = \app\model\Common::checkFavorite(['uid' => $uid, 'item_id' => $data['item_id'], 'item_type' => 'article']) ? 1 : 0;

                    $result_list[$key]['topics'] = $topic_infos['article'][$data['item_id']] ?? [];
                    $result_list[$key]['user_info'] = $users_info[$data['uid']] ?? ['url' => 'javascript:;', 'uid' => 0, 'name' => '未知用户'];

                    $result_list[$key]['update_time'] = (isset($result_list[$key]['update_time']) && $result_list[$key]['update_time']) ? date_friendly($result_list[$key]['update_time']) : (isset($result_list[$key]['create_time']) ? date_friendly($result_list[$key]['create_time']) : '');
                    $result_list[$key]['vote_value'] = Vote::getVoteByType($data['item_id'],'article', $uid);
                }

            }
        }
        return $result_list;
    }

    /**
     * 获取用户关注内容动态
     * 关注的人，关注的问题，关注的话题，关注的专栏，关注的收藏夹
     */
    public static function getUserFocusLogList($uid,$focus_type='all',$page=1,$per_page=10)
    {
        if(!in_array($focus_type,['all','user','column','topic','question']))
        {
            return false;
        }

        //关注聚合
        if($focus_type=='all')
        {
            //关注的用户
            $friend_uid = db('users_follow')->where(['status'=>1,'fans_uid'=>intval($uid)])->column('friend_uid');

            //关注的话题
            $topic_ids = db('topic_focus')->where(['uid'=>intval($uid)])->column('topic_id');

            //关注的问题
            $question_ids = db('question_focus')->where(['uid'=>intval($uid)])->column('question_id');

            //关注的专栏
            $column_ids = db('column_focus')->where(['uid'=>intval($uid)])->column('column_id');

            $sqlWhere = [];

            if($friend_uid)
            {
                $action = [
                    'publish_question',
                    'publish_article',
                    'publish_answer',
                    'agree_question',
                    'agree_article',
                    'agree_answer',
                    'focus_question',
                ];
                $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                $sqlWhere[] = '(uid IN('.implode(',',$friend_uid).') AND action_id IN('.implode(',',$action_ids).') AND anonymous=0)';
            }

            if($question_ids)
            {
                $sql[] = '(action_id=6 AND record_id IN('.implode(',',$question_ids).') AND record_type="question")';
                $sql[] = '(relation_id IN('.implode(',',$question_ids).') AND record_type="answer" AND action_id IN(4,8))';
                $sqlWhere[] = '('.implode(' OR ',$sql).')';
            }

            if($column_ids)
            {
                $action = [
                    'create_column_article',
                    'modify_column_article',
                ];
                $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                $sqlWhere[] = 'record_id IN('.implode(',',$column_ids).') AND record_type="column" AND action_id IN('.implode(',',$action_ids).')';
            }

            if($topic_ids)
            {
                $action = [
                    'modify_question_topic',
                    'modify_article_topic',
                ];
                $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                $sqlWhere[] = 'record_type="topic" AND record_id IN('.implode(',',$topic_ids).') AND action_id IN('.implode(',',$action_ids).')';
            }

            if(!$sqlWhere)
            {
                return ['list'=>[],'page'=>'','total'=>0];
            }

            return db('action_log')
                ->whereRaw(implode(' OR ',$sqlWhere))
                ->distinct(true)
                ->field('uid,record_type,record_id,relation_id,relation_type,anonymous,create_time,action_id')
                ->group('uid,record_type,record_id')
                ->order('create_time','DESC')
                ->page($page,$per_page)
                ->select()
                ->toArray();
        }

        //关注的用户
        if($focus_type=='user')
        {
            $friend_uid = db('users_follow')->where(['status'=>1,'fans_uid'=>intval($uid)])->column('friend_uid');
            if($friend_uid)
            {
                $action = [
                    'publish_question',
                    'publish_article',
                    'publish_answer',
                    'agree_question',
                    'agree_article',
                    'agree_answer',
                    'focus_question',
                ];
                $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                $where[] = ['status', '=', 1];
                $where[] = ['uid', 'IN', $friend_uid];
                if (!empty($action_ids)) {
                    $where[] = ['action_id', 'IN', $action_ids];
                }
                $where[] = ['anonymous', '=', 0];
                return db('action_log')
                    ->where($where)
                    ->distinct(true)
                    ->field('uid,record_type,record_id,relation_id,relation_type,anonymous,create_time,action_id')
                    ->group('uid,record_type,record_id')
                    ->order('create_time','DESC')
                    ->page($page,$per_page)
                    ->select()
                    ->toArray();
            }
        }

        //关注的话题
        if($focus_type=='topic')
        {
            $topic_ids = db('topic_focus')->where(['uid'=>intval($uid)])->column('topic_id');
            if($topic_ids)
            {
                $action = [
                    'modify_question_topic',
                    'modify_article_topic',
                ];
                $action_ids = [];
                if ($action) {
                    $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                }
                if (!empty($action_ids)) {
                    $where3[] = ['action_id', 'IN', $action_ids];
                }

                $where3[] = ['status', '=', 1];
                $where3[] = ['record_id', 'IN', $topic_ids];
                $where3[] = ['record_type', '=', 'topic'];
                return db('action_log')
                    ->where($where3)
                    ->distinct(true)
                    ->field('uid,record_type,record_id,relation_id,relation_type,anonymous,create_time,action_id')
                    ->group('uid,record_type,record_id')
                    ->order('create_time','DESC')
                    ->page($page,$per_page)
                    ->select()
                    ->toArray();
            }
        }

        //关注的问题
        if($focus_type=='question')
        {
            $question_ids = db('question_focus')->where(['uid'=>intval($uid)])->column('question_id');
            if($question_ids)
            {
                $action = [
                    'publish_answer',
                    'agree_question',
                    'agree_answer',
                    'publish_answer'
                ];
                $action_ids = [];
                if ($action) {
                    $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                }
                if (!empty($action_ids)) {
                    $where1[] = ['action_id', 'IN', $action_ids];
                }

                $where1[] = ['status', '=', 1];
                $where1[] = ['record_id', 'IN', $question_ids];
                $where1[] = ['record_type', 'IN', ['question','answer']];
                return db('action_log')
                    ->where($where1)
                    ->distinct(true)
                    ->field('uid,record_type,record_id,relation_id,relation_type,anonymous,create_time,action_id')
                    ->group('uid,record_type,record_id')
                    ->order('create_time','DESC')
                    ->page($page,$per_page)
                    ->select()
                    ->toArray();
            }
        }

        //关注的专栏
        if($focus_type=='column')
        {
            $column_ids = db('column_focus')->where(['uid'=>intval($uid)])->column('column_id');
            if($column_ids)
            {
                $article_ids = db('article')->whereIN('column_id',$column_ids)->where(['status'=>1])->column('id');
                $action = [
                    'create_column_article',
                    'modify_column_article',
                ];
                $action_ids = [];
                if ($action) {
                    $action_ids = db('action')->whereIn('name', $action)->where(['status' => 1])->column('id');
                }
                if (!empty($action_ids)) {
                    $where2[] = ['action_id', 'IN', $action_ids];
                }

                $where2[] = ['status', '=', 1];
                $where2[] = ['relation_id', 'IN', $article_ids];
                $where2[] = ['relation_type', '=', 'article'];
                return db('action_log')
                    ->where($where2)
                    ->distinct(true)
                    ->field('uid,record_type,record_id,relation_id,relation_type,anonymous,create_time,action_id')
                    ->group('uid,record_type,record_id')
                    ->order('create_time','DESC')
                    ->page($page,$per_page)
                    ->select()
                    ->toArray();
            }

        }
    }

    /**
     * 获取用户关注
     * @param $uid
     * @param $type
     * @param $page
     * @param $per_page
     * @return false
     */
    public static function getUserFocus($uid, $type, $page = 1, $per_page = 10)
    {
        if(!$uid || !$type) return false;
        $dbName = 'question_focus';
        $where = [];
        switch ($type)
        {
            case 'question':
                $where['uid'] = $uid;
                break;

            case 'friend':
                $dbName = 'users_follow';
                $where = ['fans_uid'=>$uid];
                break;

            case 'fans':
                $dbName = 'users_follow';
                $where = ['friend_uid'=>$uid];
                break;

            case 'column':
                $dbName = 'column_focus';
                $where = ['uid'=>$uid];
                break;

            case 'topic':
                $dbName = 'topic_focus';
                $where = ['uid'=>$uid];
                break;

            case 'favorite':
                $dbName = 'favorite_focus';
                $where = ['uid'=>$uid];
                break;
        }
        $result = db($dbName)
            ->where($where)
            ->page($page,$per_page)
            ->select()
            ->toArray();
        if($result)
        {
            foreach ($result as $key=>$val)
            {
                switch ($type)
                {
                    case 'question':
                        $question_ids = array_column($result,'question_id');
                        $question_infos = Question::getQuestionByIds($question_ids);
                        if(!empty($question_infos) && isset($question_infos[$val['question_id']]))
                        {
                            $result[$key] = $question_infos[$val['question_id']];
                            $result[$key]['user_info'] = Users::getUserInfoByUid($question_infos[$val['question_id']]['uid'],'nick_name,avatar,uid');
                            $result[$key]['detail'] = str_cut(strip_tags(htmlspecialchars_decode($question_infos[$val['question_id']]['detail'])),0,150);
                            $result[$key]['topics'] = Topic::getTopicByItemType('question',$val['question_id']);
                            $result[$key]['vote_value'] =  Vote::getVoteByType($val['question_id'],'question',$uid);
                            $result[$key]['item_id'] =  $val['question_id'];
                        }else{
                            unset($result[$key]);
                        }
                        break;

                    case 'friend':
                        $uid_s = array_column($result,'friend_uid');
                        $user_infos = Users::getUserInfoByIds($uid_s);
                        if(!empty($user_infos))
                        {
                            $result[$key]['user_info'] = $user_infos[$val['friend_uid']];
                            $result[$key]['item_id'] =  $val['friend_uid'];
                        }
                        break;

                    case 'fans':
                        $uid_s = array_column($result,'fans_uid');
                        $user_infos = Users::getUserInfoByIds($uid_s,'',99);
                        $result[$key]['user_info'] = $user_infos[$val['fans_uid']] ?? [];
                        $result[$key]['item_id'] =  $val['fans_uid'];
                        break;

                    case 'column':
                        $column_ids = array_column($result,'column_id');
                        $column_infos = Column::getColumnByIds($column_ids);
                        $result[$key] = $column_infos[$val['column_id']];
                        $result[$key]['item_id'] =  $val['column_id'];
                        break;

                    case 'topic':
                        $topic_ids = array_column($result,'topic_id');
                        $topic_infos = Topic::getTopicByIds($topic_ids);
                        $result[$key] = $topic_infos[$val['topic_id']];
                        $result[$key]['uid'] = $val['uid'];
                        $result[$key]['item_id'] = $val['topic_id'];
                        break;
                }
            }
        }

        return $result;
    }

    //获取聚合数据表数据据
    public static function getRelationContent($uid=null,$item_type=null,$sort = null, $page=1, $per_page=10,$relation_uid=0,$words_count=100,$category_id=0,$topic_ids=null)
    {
        $per_page = $per_page ?:intval(get_setting('contents_per_page'));
        /*$result_list = [];
        $key = $sort.'-'.$page.'-'.$per_page.'-'.$relation_uid;
        $cache_key = 'cache_api_explore_data_'.$key;
        if($cache_explore_time = get_setting('cache_explore_time'))
        {
            $result_list = cache($cache_key);
        }
        if($result_list) return $result_list;*/

        $order = $where = array();
        $order['set_top_time'] = 'DESC';
        $where[] = ['status','=',1];
        if($relation_uid)
        {
            $where[] = ['uid','=',$relation_uid];
        }

        if($category_id)
        {
            $where[] = ['category_id','=',$category_id];
        }

        $item_types = ['question','article',implode(',',config('aws.relation'))];

        //关注单独处理
        if($sort == 'focus') {
            return Users::parseActionLog($uid,self::getUserFocusLogList($uid, 'all', $page, $per_page), $words_count);
        }

        //推荐内容
        if($sort=='recommend')
        {
            return self::getRecommendPost($uid,null, null,intval($page), intval($per_page),intval($relation_uid),$words_count);
        }

        if($sort=='hot')
        {
            $order['popular_value'] = 'desc';
            $where[] = ['popular_value','>',0];
        }

        if($sort=='new'){
            $order['set_top_time'] = 'DESC';
            $order['update_time'] = 'DESC';
        }

        if($sort=='unresponsive')
        {
            $where[] = ['answer_count','=',0];
            $item_type = 'question';
        }

        if(!$item_type)
        {
            $where[] = ['item_type','IN', $item_types];
        }

        if ($item_type)
        {
            $where[] = ['item_type','=', $item_type];
        }

        if ($topic_ids)
        {
            $topic_ids = is_array($topic_ids) ? $topic_ids : explode(',',$topic_ids);
            $topic_where = $item_type ? 'item_type="'.$item_type.'" AND status=1' : 'status=1';
            $topicIdsWhere = ' AND topic_id IN('.implode(',',array_unique($topic_ids)).')';
            $relationInfo = db('topic_relation')
                ->whereRaw($topic_where.$topicIdsWhere)
                ->column('item_id,item_type');

            if(!$relationInfo)
            {
                $result_list['list'] = [];
                $result_list['page'] = '';
                $result_list['total'] = 1;
                return $result_list;
            }

            $post_ids = $post_id_where = [];
            $whereRaw = '';

            foreach ($relationInfo as $val)
            {
                $post_ids[$val['item_type']][$val['item_id']] = $val['item_id'];
            }

            foreach ($post_ids AS $key => $val)
            {
                $post_id_where[] = "(item_id IN (" . implode(',', $val) . ") AND item_type = '" . $key . "')";
            }

            if ($post_id_where)
            {
                $whereRaw = '(' . implode(' OR ', $post_id_where) . ')';
            }

            if($whereRaw)
            {
                $list = db('post_relation')
                    ->where($where)
                    ->whereRaw($whereRaw)
                    ->order($order)
                    ->field('id,item_id,item_type,uid')
                    ->page($page,$per_page)
                    ->select()
                    ->toArray();
                return self::processPostList($list,$uid,$sort);
                /*$result_list['list'] = $list;
               $result_list['page'] = $pageVar;
               $result_list['total'] = $allList['last_page'];
               /*if($cache_explore_time)
               {
                   cache($cache_key,$result_list,['expire'=>$cache_explore_time*60]);
               }*/
            }
        }

        $order['create_time'] = 'DESC';
        $where[] = ['item_type','IN', $item_types];
        $list = db('post_relation')
            ->where($where)
            ->order($order)
            ->field('id,item_id,item_type,uid')
            ->page($page,$per_page)
            ->select()
            ->toArray();
        $list = self::processPostList($list,$uid,$words_count);
        /*if($cache_explore_time && $list)
        {
            cache($cache_key,$list,['expire'=>$cache_explore_time*60]);
        }*/
        return $list ?: [];
    }

    //获取聚合数据
    public static function getMixedList($uid=null,$item_type=null, $sort = null, $page=1, $per_page=10,$relation_uid=0,$words_count=100,$category_id=0,$topic_ids=null)
    {
        if(!$item_type || $topic_ids)
        {
            return self::getRelationContent($uid,$item_type, $sort, $page, $per_page,$relation_uid,$words_count,$category_id,$topic_ids);
        }

        if($item_type=='question')
        {
            return self::getQuestionList($uid,$sort, $category_id,$page,$per_page,$relation_uid,$words_count);
        }

        if($item_type=='reward')
        {
            return self::getRewardList($uid,$sort,$topic_ids, $category_id,$page,$per_page,$relation_uid);
        }

        if($item_type=='article')
        {
            return self::getArticleList($uid,$sort, $category_id,$page,$per_page,$relation_uid,$words_count);
        }

        if($item_type=='topic')
        {
            return self::getTopicList($uid,$sort, $category_id,$page,$per_page,$relation_uid);
        }

        if($item_type=='column')
        {
            return self::getColumnList($uid,$sort,$page, $per_page,$relation_uid);
        }
        return [];
    }

    /**
     * 获取悬赏列表
     * @param null $uid
     * @param null $sort
     * @param null $topic_ids
     * @param null $category_id
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     * @return array
     */
    public static function getRewardList($uid=null,$sort = null, $topic_ids = null, $category_id = null,$page=1, $per_page=10,$relation_uid=0): array
    {
        $order = $where = array();
        $order['set_top_time'] = 'DESC';
        $where[] = ['status','=',1];
        if($relation_uid)
        {
            $where[] = ['uid','=',$relation_uid];
        }
        switch ($sort)
        {
            //等待回答
            case 'unresponsive':
                $where[] = ['answer_count','=',0];
                break;

            //最新
            case 'new' :
                $order['reward_time'] = 'ASC';
                //$order['update_time'] = 'DESC';
                break;

            //推荐
            case 'recommend':
                $where[] = ['is_recommend','=', 1];

                // TODO 可以根据用户关注的内容和发起过的内容推荐相关内容

                break;

            //热门
            case 'hot':
                $order['popular_value'] = 'desc';
                //$where[] = ['popular_value','>',0];
                break;
        }

        //$order['create_time'] = 'DESC';

        if ($topic_ids)
        {
            $topic_ids = is_array($topic_ids) ? $topic_ids : explode(',',$topic_ids);
            $topic_where =  'item_type="question" AND status=1' ;
            $topicIdsWhere = ' AND topic_id IN('.implode(',',array_unique($topic_ids)).')';
            $relationInfo = db('topic_relation')
                ->whereRaw($topic_where.$topicIdsWhere)
                ->column('item_id,item_type');
            $item_ids = array_column($relationInfo,'item_id');
            $where[] = ['id','in',implode(',', $item_ids)];
        }

        if ($category_id)
        {
            $category_ids = Category::getCategoryWithChildIds($category_id,true);
            if($category_ids)
            {
                $where[] = ['category_id','in', implode(',',$category_ids )];
            }else{
                $where[] = ['category_id','=', $category_id];
            }
        }

        $list = db('reward')
            ->where($where)
            ->order($order)
            ->page($page,$per_page)
            ->select()
            ->toArray();

        if(!$list) return [];
        $users_info = Users::getUserInfoByIds(array_column($list,'uid'),'user_name,avatar,nick_name,uid',99);
        $topic_infos = Topic::getTopicByItemIds(array_column($list,'id'), 'reward');
        $result_list = [];
        $reward_text= [
            0=>'未开始',
            1=>'进行中',
            2=>'最佳答案评定中',
            3=>'最佳答案评定中',
            4=>'公示中',
            5=>'已结束'
        ];
        foreach ($list as $key=>$data)
        {
            $result_list[$key] = $data;
            $result_list[$key]['title'] = htmlspecialchars_decode($result_list[$key]['title']);
            $result_list[$key]['has_focus'] = Reward::checkUserIsFocus($uid,'look',$data['id']);
            $result_list[$key]['reward_label'] = $reward_text[$data['reward_status']];
            $result_list[$key]['left_time'] = ($data['reward_time']-time())*1000;
            $detail = $result_list[$key]['detail'];
            $result_list[$key]['vote_value'] = Vote::getVoteByType($data['id'],'reward',$uid);
            $result_list[$key]['content']=str_cut(strip_tags(htmlspecialchars_decode($result_list[$key]['detail'])),0,150);
            $cover  = ImageHelper::srcList(htmlspecialchars_decode($detail));
            $result_list[$key]['img_list'] = $cover;
            $result_list[$key]['topics'] = $topic_infos[$data['id']] ?? [];
            $result_list[$key]['user_info'] = $users_info[$data['uid']]??[];
            $result_list[$key]['create_time'] = date_friendly($data['create_time']);
        }

        return $result_list;
    }

    /**
     * 获取问题列表
     * @param null $uid
     * @param null $sort
     * @param null $category_id
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     * @param int $words_count
     * @return array|mixed|object
     */
    public static function getQuestionList($uid=null,$sort = null, $category_id = null,int $page=1, int $per_page=10,int $relation_uid=0,int $words_count=150)
    {
        $order = $where = array();
        $order['set_top_time'] = 'DESC';
        $where[] = ['status','=',1];

        if ($relation_uid) {
            $where[] = ['uid','=',$relation_uid];
            $where[] = ['is_anonymous', '=', 0];
        }

        // 推荐内容
        if ($sort=='recommend') {
            return self::getRecommendPost($uid,'question',null, $category_id,$page, $per_page,$relation_uid);
        }

        if ($sort=='unresponsive') {
            $where[] = ['answer_count','=',0];
        }

        if ($sort=='hot') {
            $order['popular_value'] = 'desc';
            $where[] = ['popular_value','>',0];
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
        $data_list_uid = [];
        $list = db('question')->where($where)->order($order)->page($page,$per_page)->column('id,uid,is_anonymous,title,detail,set_top,create_time,update_time,view_count,agree_count,answer_count,comment_count');
        if(!$list) return [];
        foreach ($list as $val)
        {
            $data_list_uid[$val['uid']] = $val['uid'];
        }
        $last_answers = Answer::getLastAnswerByIds(array_column($list,'id'),'content,id,question_id,against_count,agree_count,uid,comment_count,is_anonymous,create_time,thanks_count');
        foreach ($last_answers as $val)
        {
            $data_list_uid[$val['uid']] = $val['uid'];
        }
        $users_info = $data_list_uid ? Users::getUserInfoByIds(array_unique($data_list_uid),'user_name,avatar,nick_name,uid',99):[];
        $topic_infos = Topic::getTopicByItemIds(array_column($list,'id'), 'question');
        $result_list = [];
        $anonymous_user = [
            'nick_name' => '匿名用户',
            'avatar' => request()->domain().'/static/common/image/default-avatar.svg'
        ];

        foreach ($list as $key=>$data)
        {
            $result_list[$key] = $data;
            $result_list[$key]['title'] = htmlspecialchars_decode($result_list[$key]['title']);

            //是否已回答
            $result_list[$key]['is_answer'] = $uid ? db('answer')->where(['uid'=>$uid,'question_id'=>$data['id'],'status'=>1])->value('id') : 0;

            //回答用户
            $answerUidLists = db('answer')->where(['question_id'=>$data['id'],'status'=>1])->order('agree_count','DESC')->limit(3)->column('uid');
            $result_list[$key]['answer_users'] = $answerUidLists ? array_values(Users::getUserInfoByIds($answerUidLists,'user_name,avatar,nick_name,uid')):[];

            //最后回答
            $result_list[$key]['answer_info'] = $last_answers[$data['id']] ?? false;
            if($result_list[$key]['answer_info']){
                $result_list[$key]['answer_info']['user_info'] = $users_info[$last_answers[$data['id']]['uid']]??['uid'=>0,'nick_name'=>'未知用户','avatar'=>'/static/common/image/default-avatar.svg'];
                if ($result_list[$key]['answer_info']['is_anonymous']) {
                    $result_list[$key]['answer_info']['user_info'] = $anonymous_user;
                } else {
                    $result_list[$key]['answer_info']['user_info'] = $users_info[$last_answers[$data['id']]['uid']]??[
                            'nick_name' => '未知用户',
                            'avatar' => request()->domain().'/static/common/image/default-avatar.svg'
                        ];
                }
                $result_list[$key]['answer_info']['vote_value'] = isset($last_answers[$data['id']])?Vote::getVoteByType($last_answers[$data['id']]['id'],'answer',$uid):0;
                $result_list[$key]['answer_info']['content'] = str_cut(strip_tags($result_list[$key]['answer_info']['content']),0,150);
                $result_list[$key]['answer_info']['img_list'] = ImageHelper::srcList($result_list[$key]['answer_info']['content']);
            }

            $result_list[$key]['has_focus'] = FocusLogic::checkUserIsFocus($uid,'question',$data['id']);

            $result_list[$key]['vote_value'] = Vote::getVoteByType($data['id'],'question',$uid);

            $result_list[$key]['img_list'] = ImageHelper::srcList($result_list[$key]['detail']);

            $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($result_list[$key]['detail'])),0,150);

            if($result_list[$key]['answer_info'] && !isset($users_info[$last_answers[$data['id']]['uid']]))
            {
                $result_list[$key]['answer_info'] = [];
            }

            //问题话题
            $result_list[$key]['topics'] = $topic_infos[$data['id']] ?? [];

            if(!isset($users_info[$data['uid']]))
            {
                unset($result_list[$key]);
                continue;
            }
            $result_list[$key]['user_info'] = $data['is_anonymous'] ? $anonymous_user : ($users_info[$data['uid']]??[
                    'nick_name' => '未知用户',
                    'avatar' => request()->domain().'/static/common/image/default-avatar.svg'
                ]);
        }
        return $result_list;
    }

    /**
     * 获取文章列表
     * @param null $uid
     * @param null $sort
     * @param null $category_id
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     * @param int $words_count
     * @return array
     */
    public static function getArticleList($uid=null,$sort = null, $category_id = null,int $page=1, int $per_page=10,int $relation_uid=0,int $words_count=150): array
    {
        $data_list = [];
        $key = md5($sort.'-'.$category_id.'-'.$page.'-'.$per_page.'-'.$relation_uid);
        $cache_key = 'cache_api_list_article_data_'.$key;

        if($cache_list_time = get_setting('cache_list_time'))
        {
            $data_list = cache($cache_key);
        }
        if($data_list) return $data_list;

        $order = $where = array();
        $order['set_top_time'] = 'DESC';
        $where[] = ['status','=',1];
        if($relation_uid)
        {
            $where[] = ['uid','=',$relation_uid];
        }

        //推荐内容
        if($sort=='recommend')
        {
            return Common::getRecommendPost($uid,'article',null, $category_id,$page, $per_page,$relation_uid);
        }

        if($sort=='hot')
        {
            $order['popular_value'] = 'desc';
            $where[] = ['popular_value','>',0];
        }

        if($sort=='new'){
            $order['set_top_time'] = 'DESC';
            $order['update_time'] = 'DESC';
        }

        $order['create_time'] = 'DESC';

        if ($category_id)
        {
            $category_ids = Category::getCategoryWithChildIds($category_id,true);
            if($category_ids)
            {
                $where[] = ['category_id','in', implode(',',$category_ids )];
            }else{
                $where[] = ['category_id','=', $category_id];
            }
        }

        $list = db('article')->where($where)->order($order)->page($page,$per_page)->column('id,uid,message,title,is_recommend,column_id,cover,set_top,create_time,update_time,view_count,agree_count,comment_count,cover');
        $users_info = Users::getUserInfoByIds(array_column($list,'uid'),'user_name,avatar,nick_name,uid',99);
        $topic_infos = Topic::getTopicByItemIds(array_column($list,'id'), 'article');
        $result_list = [];
        foreach ($list as $key=>$data)
        {
            $result_list[$key] = $data;
            $result_list[$key]['item_type'] = 'article';
            $result_list[$key]['action_label'] = '发布了文章';
            $result_list[$key]['title'] = strip_tags(htmlspecialchars_decode($data['title']));
            $result_list[$key]['content'] = str_cut(strip_tags(htmlspecialchars_decode($data['message'])),0,$words_count);
            $result_list[$key]['vote_value'] = Vote::getVoteByType($data['id'],'article', $uid);
            if ($data['cover']) {
                $result_list[$key]['images'] = [ImageHelper::replaceImageUrl($data['cover'])];
            } else {
                $result_list[$key]['images'] = ImageHelper::replaceImageUrl(ImageHelper::srcList(htmlspecialchars_decode($data['message']))) ?: [];
            }
            $result_list[$key]['images'] = $result_list[$key]['images']??[];
            $result_list[$key]['topics'] = $topic_infos[$data['id']] ?? [];
            $result_list[$key]['user_info'] = $users_info[$data['uid']];

            // 格式化时间戳
            $result_list[$key]['create_time'] = date_friendly($data['create_time']);
            $result_list[$key]['update_time'] = date_friendly($data['update_time']);
        }
        if($cache_list_time)
        {
            cache($cache_key,$result_list,['expire'=>$cache_list_time*60]);
        }

        return $result_list;
    }

    /**
     * 获取话题列表
     * @param $uid
     * @param $sort
     * @param $category_id
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     * @return array|false
     */
    public static function getTopicList($uid=null,$sort = null,$category_id = null,int $page=1, int $per_page=10,int $relation_uid=0)
    {
        $order = [];
        if($sort=='hot')
        {
            $order['discuss'] ='desc';
        }

        if($sort=='focus'&& $relation_uid){
            $list = self::getUserFocus($relation_uid, 'topic', $page,$per_page);
            if (!empty($list)) {
                $pic = request()->domain().'/static/common/image/topic.svg';
                foreach ($list as &$value) {
                    $value['has_focus'] = FocusLogic::checkUserIsFocus($uid, 'topic', $value['id']) ? 1 : 0;
                    $value['pic'] = $value['pic'] ? ImageHelper::replaceImageUrl($value['pic']) : $pic;
                }
            }
          return $list;
        }

        if($sort=='new'){
            $order['discuss_update'] ='desc';
        }

        $where[] = ['status','=',1];

        if($category_id){
            $child_ids = TopicModel::getTopicWithChildIds($category_id);
            $where[] = ['id','IN',$child_ids];
        }

        $list = db('topic')->where($where)->order($order)->page($page, $per_page)->select()->toArray() ?: [];

        if (!empty($list)) {
            $pic = request()->domain().'/static/common/image/topic.svg';
            foreach ($list as &$value) {
                $value['has_focus'] = FocusLogic::checkUserIsFocus($uid, 'topic', $value['id']) ? 1 : 0;
                $value['pic'] = $value['pic'] ? ImageHelper::replaceImageUrl($value['pic']) : $pic;
            }
            return $list;
        }

        return [];
    }

    /**
     * 获取专栏列表
     * @param $uid
     * @param $sort
     * @param int $page
     * @param int $per_page
     * @param int $relation_uid
     * @return array
     */
    public static function getColumnList($uid=null,$sort = null,int $page=1, int $per_page=10,int $relation_uid=0): array
    {
        $where = [];
        if ($relation_uid) $where = [['uid', '=', $relation_uid]];

        $order = [];
        $where[] = ['verify', '=', 1];
        switch ($sort) {
            case 'new':
                $order['create_time'] = 'DESC';
                break;
            case 'hot':
                $order['view_count'] = 'DESC';
                break;
            case 'recommend':
                $order['view_count'] = 'DESC';
                $where[]=['recommend','=',1];
                break;
        }
        $list =  db('column')->where($where)->order($order)->page($page, $per_page)->select()->toArray();
        foreach ($list as &$value) {
            $value['description'] = str_cut(strip_tags(htmlspecialchars_decode($value['description'])),0,50);
            $value['has_focus'] = 0 ;
            if (db('column_focus')->where(['uid' => $uid, 'column_id' => $value['id']])->value('id')) {
                $value['has_focus'] = 1 ;
            }
            $value['cover'] = ImageHelper::replaceImageUrl($value['cover']);
            $value['user_info'] = Users::getUserInfoByUid($value['uid'],'user_name,nick_name,uid,avatar',99);
        }
        return $list ?: [];
    }

    public static function getCategoryListByType(string $type='common',bool $only=false,$sort=['sort'=>'DESC']): array
    {
        $where = $only ? [$type] :  ['common',$type];
        $res = db('category')->where(['status'=>1])->whereIn('type',$where)->order($sort)->column('id,title,icon,pid,url_token');
        $tree = new Tree(['child'=>'children']);
        return $tree->toTree($res);
    }
}