<?php
namespace app\api\v1;
use app\common\controller\Api;
use app\common\library\helper\FormatHelper;
use app\common\library\helper\HtmlHelper;
use app\common\library\helper\ImageHelper;
use app\common\library\helper\LogHelper;
use app\common\library\helper\PopularHelper;
use app\logic\common\FocusLogic;
use app\model\Approval;
use app\model\Article as ArticleModel;
use app\model\Attach;
use app\model\PostRelation;
use app\model\Report;
use app\model\Topic;
use app\model\Users;
use app\model\UsersFavorite;
use app\model\Vote;
use app\model\api\v1\Column;

class Article extends Api
{
    //文章列表
    public function index()
    {
        $category_id = $this->request->param('category_id',0,'intval');
        $sort =  $this->request->param('sort','new','trim');
        $page = $this->request->param('page',1,'intval');
        $page_size = $this->request->param('page_size',10,'intval');
        $words_count = $this->request->param('words_count',100,'intval');
        //用于展示个人文章列表使用
        $uid = $this->request->param('uid',0,'intval');

        $article_list = \app\model\api\v1\Article::getArticleList($this->user_id,$sort,$category_id,$page,$page_size,$uid,$words_count);
        $this->apiResult($article_list);
    }

    //文章详情
    public function detail()
    {
        $id = $this->request->param('id',0,'intval');
        if(!$id)
        {
            $this->apiResult([],-1,'请求参数错误');
        }

        $article_info = ArticleModel::getArticleInfo($id);
        if (!$article_info || !$article_info['status']) {
            $this->apiResult([],-1,'文章不存在或已被删除');
        }

        //更新文章浏览量
        ArticleModel::updateArticleViews($id, $this->user_id);

        //更新文章热度值
        PopularHelper::calcArticlePopularValue($id);

        $article_info['title'] = htmlspecialchars_decode($article_info['title']);

        $article_info['update_time'] = date_friendly($article_info['update_time']);

        //举报状态
        $article_info['is_report'] = Report::getReportInfo($article_info['id'], 'article', $this->user_id);

        //投票状态
        $article_info['vote_value'] = Vote::getVoteByType($article_info['id'], 'article', $this->user_id);

        //收藏状态
        $article_info['is_favorite'] = UsersFavorite::checkFavorite($this->user_id, 'article', $article_info['id']);

        // 用户信息
        $user_info = Users::getUserInfoByUid($article_info['uid'],'user_name,nick_name,uid,avatar,signature,verified');

        $user_info['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'user', $article_info['uid']) ? 1 : 0;

        $article_info['topics'] = Topic::getTopicByItemType('article', $article_info['id']);

        if ($article_info['column_id']) {
            $article_info['columns'] = [];
            $article_info['column_info'] = db('column')->where(['id'=>$article_info['column_id'],'verify'=>1])->field('name,description,cover,focus_count,view_count,post_count')->find();
            $article_info['column_info']['cover'] = ImageHelper::replaceImageUrl($article_info['column_info']['cover']);
            $article_info['column_info']['has_focus'] = Column::checkFocus($this->user_id, $article_info['column_id']);
        } else {
            $article_info['columns'] = Column::userColumns($article_info['uid']);
        }

        $shareData = [
            'url'=>(string)url('article/detail',['id'=>$article_info['id']],true,true),
            'image'=>$article_info['cover']?ImageHelper::replaceImageUrl($article_info['cover']):ImageHelper::replaceImageUrl(ImageHelper::src(htmlspecialchars_decode($article_info['message']))),
            'description'=>str_cut(strip_tags(htmlspecialchars_decode($article_info['message'])),0,100)
        ];

        $article_info['share_data'] = $shareData;
        $article_info['message'] = HtmlHelper::replaceVideoUrl(HtmlHelper::parseImgUrl(htmlspecialchars_decode($article_info['message'])));
        $article_info['cover'] = $article_info['cover'] ? ImageHelper::replaceImageUrl($article_info['cover']) : '';
        $article_info['create_time'] = date_friendly($article_info['create_time']);
        $article_info['attach_list'] = Attach::getAttach('article_attach', $article_info['id']);
        foreach ($article_info['attach_list'] as &$attach) {
            $attach['size'] = formatBytes($attach['size']);
            $attach['url'] = $this->request->domain().$attach['url'];
        }
        $article_info['user_info'] = $user_info;

        $this->apiResult($article_info,);
    }
    
    // 发布文章
    public function publish()
    {
        $postData = $this->request->post();
        if ($postData['id'] = intval($postData['id'])) {
            $article_info = ArticleModel::getArticleInfo($postData['id']);
            if (!$article_info || ($article_info['uid'] != $this->user_id && get_user_permission('modify_article') != 'Y')) $this->apiError('您没有修改文章的权限');
        } else {
            if ($this->user_info['permission']['publish_article_enable'] != 'Y') $this->apiError('您没有发布文章的权限');


            if ($this->user_info['permission']['publish_article_num'] && ($question_num = Users::getUserPublishNum($this->user_id,'article')) >= intval($this->user_info['permission']['publish_article_num'])) {
                $this->apiError('你所在的用户组当天只允许发布' . $this->user_info['permission']['publish_article_num'] . '篇文章,您已发布' . $question_num . '篇文章');
            }
            // 验证用户积分是否满足积分操作条件
            if (!LogHelper::checkUserIntegral('publish_article',$this->user_id)) $this->apiError('您的积分不足,无法发表文章');
        }

        /*文章提交前钩子*/
        hook('article_publish_post_before', $postData);

        if (htmlspecialchars_decode($postData['title'])=='' || removeEmpty($postData['title']) == '') $this->apiError('请填写文章标题');
        if ($this->settings['enable_category'] && isset($postData['category_id']) && !$postData['category_id']) $this->apiError('请选择文章分类');

        if (get_setting('topic_enable') == 'Y' && (!isset($postData['topics']) || empty($postData['topics']))) $this->apiError('请至少选择一个话题');

        if (get_setting('topic_enable') == 'Y' && get_setting('max_topic_select') < count($postData['topics'])) {
            $this->apiError('您最多只可设置'.get_setting('max_topic_select').'个话题');
        }

        if (htmlspecialchars_decode($postData['message']) == '' || removeEmpty($postData['message']) == '') $this->apiError('请填写文章正文');

        if ($this->user_info['permission']['publish_url'] == 'N' && FormatHelper::outsideUrlExists($postData['message'])) $this->apiError('你所在的用户组不允许发布站外链接');

        // 微信小程序内容安全检测
        if (ENTRANCE == 'wechat') $this->wxminiCheckText([$postData['title'], $postData['message']], '标题或内容不符合微信小程序安全检测');

        $access_key = $postData['access_key'];
        $uid = $postData['uid'] ?? $this->user_id;
        unset($postData['__token__'], $postData['access_key']);
        $postData['message'] = htmlspecialchars_decode($postData['message']);
        $postData['topics'] = array_unique(array_column($postData['topics'], 'id'));

        // 需要审核
        if ($this->publish_approval_valid($postData['message'],'publish_article_approval') && !$postData['id']) {
            Approval::saveApproval('article', $postData, $uid, $access_key);
            $this->apiError('发表成功,请等待管理员审核');
        }

        // 修改需要审核
        if ($this->publish_approval_valid($postData['message'],'modify_article_approval') && $postData['id']) {
            $article_uid = db('article')->where('id', $postData['id'])->value('uid');
            Approval::saveApproval('modify_article',$postData,$article_uid,$access_key);
            $this->apiSuccess('修改成功,请等待管理员审核', ['id' => $postData['id']]);
        }

        $id = $postData['id'] ?  ArticleModel::updateArticle($uid, $postData, $access_key) : ArticleModel::saveArticle($uid, $postData, $access_key);

        /*文章提交后钩子*/
        hook('article_publish_post_after',$id);

        if ($id) {
            $this->apiSuccess('发布成功', compact('id'));
        } else {
            $this->apiError('发布失败');
        }
    }

    //相关文章
    public function relation()
    {
        $id = $this->request->param('id',0,'intval');
        if(!$id)
        {
            $this->apiResult([],-1,'请求参数错误');
        }

        if (!db('article')->where(['id'=>$id,'status'=>1])->value('id')) {
            $this->apiResult([],-1,'文章不存在或已被删除');
        }

        $page = $this->request->param('page', 1,'intval');
        $per_page = $this->request->param('page_size', 10,'intval');

        $relation_article = \app\model\api\v1\Article::getRelationArticleList($id,$page,$per_page);
        $this->apiResult($relation_article);
    }

    //文章评论
    public function comments()
    {
        $article_id = $this->request->param('article_id',0,'intval');
        $page = $this->request->param('page', 1,'intval');
        $per_page = $this->request->param('page_size', 10,'intval');
        $sort = $this->request->param('sort','new','trim');

        if (!db('article')->where(['id'=>$article_id,'status'=>1])->value('id')) {
            $this->apiResult([],-1,'文章不存在或已被删除');
        }

        $comment_list = \app\model\api\v1\Article::getArticleCommentList($article_id, $sort, intval($page),$per_page);

        foreach ($comment_list as $key => $val)
        {
            $comment_list[$key]['vote_value'] = Vote::getVoteByType($val['id'], 'article_comment', $this->user_id);
        }

        $this->apiResult(array_values($comment_list));
    }

    public function manager()
    {
        $action = input('type');
        $article_id = input('id',0,'intval');
        if ($action == 'recommend') {
            $is_recommend = input('is_recommend');
            $msg=$is_recommend ? '取消成功' : '推荐成功';
            $ret = db('article')->where('id',$article_id)->update(['is_recommend'=>$is_recommend ? 0 : 1]);
            if($ret){
                PostRelation::updatePostRelation($article_id,'article',['is_recommend'=>$is_recommend?0:1]);
                $this->apiError($msg);
            }
        }

        if($action=='set_top')
        {
            $set_top = input('set_top');
            $msg=$set_top ?  '取消成功' : '置顶成功';
            $ret= db('article')->where(['id'=>$article_id])->update(['set_top'=>$set_top ? 0 : 1,'set_top_time'=>time()]);
            if($ret){
                PostRelation::updatePostRelation($article_id,'article',['set_top'=>$set_top ? 0 : 1,'set_top_time'=>$set_top ? 0 : time()]);
                $this->apiError($msg);
            }
        }
    }

    /*删除文章*/
    public function remove_article()
    {
        $id = $this->request->param('id');
        $article_info = ArticleModel::getArticleInfo($id);

        if ($this->user_id != $article_info['uid'] && get_user_permission('remove_article')!='Y') {
            $this->apiError('您没有删除文章的权限');
        }

        if (!ArticleModel::removeArticle($id)) {
            $this->apiError('删除文章失败');
        }

        $this->apiSuccess('删除文章成功');
    }
}