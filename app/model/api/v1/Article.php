<?php
namespace app\model\api\v1;

use app\common\library\helper\ImageHelper;
use app\logic\common\FocusLogic;
use app\model\BaseModel;
use app\model\Category;
use app\model\Topic;
use app\model\Vote;
use tools\Tree;
use WordAnalysis\Analysis;

class Article extends BaseModel
{
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
    public static function getArticleList($uid=null,$sort = null, $category_id = null,int $page=1, int $per_page=0,int $relation_uid=0,$words_count=100): array
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

        if($sort=='unresponsive')
        {
            $where[] = ['answer_count','=',0];
        }

        if($sort=='hot')
        {
            $order['popular_value'] = 'desc';
            $where[] = ['popular_value','>',1];
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
        $users_info = Users::getUserInfoByIds(array_column($list,'uid'),'user_name,avatar,nick_name,uid');
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

    //获取相关文章
    public static function getRelationArticleList($article_id,$page=1,$limit=10)
    {
        if(!intval($article_id)) {
            return false;
        }

        $article_id = intval($article_id);
        $cache_key = 'cache_api_relation_article_list_time_'.md5(intval($article_id));

        $list = cache($cache_key)?:[];
        $cache_relation_list_time = get_setting('cache_relation_list_time');
        if($cache_relation_list_time && $list)
        {
            return $list;
        }

        $article_title = db('article')->where(['id'=>$article_id,'status'=>1])->value('title');
        $keywords = Analysis::getKeywords($article_title);
        $keywords = $keywords ? explode(',', $keywords) : [];
        $where =$keywords ? "status=1 and (title regexp'".implode('|',$keywords)."')" : 'status=1';
        $list = db('article')
            ->whereRaw($where)
            ->where([['id','<>',$article_id]])
            ->order('view_count','DESC')
            ->page($page,$limit)
            ->select()
            ->toArray();
        if($list)
        {
            foreach ($list AS $key => $val)
            {
                $val['title'] = htmlspecialchars_decode($val['title']);
            }

            cache($cache_key,$list,$cache_relation_list_time*60);
        }
        return $list ?: [];
    }

    /**
     * 获取文章评论列表
     * @param $article_id
     * @param null $order
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public static function getArticleCommentList($article_id,$order=null,int $page=1,int $per_page=10): array
    {
        $map = ['article_id'=>intval($article_id),'status'=>1];
        $sort = [];
        if($order)
        {
            switch ($order)
            {
                case 'hot':
                    $sort['agree_count'] = 'DESC';
                    break;

                default :
                    $sort['create_time'] = 'DESC';
            }
        }

        $comments = db('article_comment')
            ->where($map)
            ->order($sort)
            ->page($page,$per_page)
            ->select()
            ->toArray();

        foreach ($comments as $key => $val)
        {
            $comments[$key]['user_info'] = \app\model\Users::getUserInfoByUid($val['uid'],'user_name,nick_name,avatar,uid');
            $comments[$key]['create_time'] = date_friendly($val['create_time']);
            $comments[$key]['message'] = strip_tags($val['message']);
        }

        Tree::config([
            'child' => 'comments',
        ]);
        return Tree::toTree($comments);
    }
}