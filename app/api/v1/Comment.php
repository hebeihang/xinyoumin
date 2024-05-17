<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\common\library\helper\ImageHelper;
use app\model\Answer;
use app\model\Article as ArticleModel;
use app\model\Question as QuestionModel;

class Comment extends Api
{
    //获取评论列表
    public function get_comments()
    {
        $item_id = $this->request->param('item_id',0,'intval');
        $item_type = $this->request->param('item_type','question','trim');
        $page = $this->request->param('page',1,'intval');
        $order = $this->request->param('sort', 'new');
        $pageSize = $this->request->get('page_size', 10, 'intval');
        $sort = ['create_time' => 'desc'];

        switch ($item_type)
        {
            case 'answer':
                $data = \app\model\api\v1\Question::getAnswerComments($item_id, $page, $pageSize);
                break;

            default :
                $data = \app\model\api\v1\Question::getQuestionComments($item_id, $page, $sort, $pageSize);
                break;
        }

        $this->apiSuccess('获取成功', $data);
    }

    //保存评论
    public function save_comment()
    {
        if(!$this->user_id) $this->apiError('请先登录');
        $item_id = $this->request->param('item_id',0,'intval');
        $item_type = $this->request->param('item_type','question','trim');
        if (!$message = $this->request->post('message','','sqlFilter')) $this->apiError('请填写评论内容');
        if (htmlspecialchars_decode($message) == '' || removeEmpty($message) == '') $this->apiError('请填写评论内容');
        // 微信小程序内容安全检测
        if (ENTRANCE == 'wechat') $this->wxminiCheckText($message);

        switch ($item_type)
        {
            case 'answer':
                $at_uid = $this->request->post('at_uid','','intval');
                $pid = $this->request->post('pid',0,'intval');

                $data['at_uid'] = $at_uid;
                $data['pid'] = $pid;
                $data['uid'] = $this->user_id;
                $data['answer_id'] = $item_id;
                $data['user_name'] = $this->user_info['user_name'];
                if (!$answer = Answer::getAnswerInfoById($item_id)) $this->apiError('回答不存在');

                $data['question_info'] = QuestionModel::getQuestionInfo($answer['question_id'],'id,uid,title');
                $data['message'] = $message;

                if ($ret = \app\model\api\v1\Question::saveAnswerComments($data)) {
                    $ret['user_info'] = [
                        'uid' => $this->user_id,
                        'nick_name' => $this->user_info['nick_name'],
                        'avatar' => $this->user_info['avatar'] ? ImageHelper::replaceImageUrl($this->user_info['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
                    ];
                    $this->apiSuccess('评论成功', $ret);
                } else {
                    $this->apiError('评论失败');
                }
                break;
            case 'article':
                if (!$article = ArticleModel::getArticleInfoField($item_id,'id,title,uid')) $this->apiError('文章不存在');
                $at_info = $this->request->post('at_uid','','intval');
                $pid = $this->request->post('pid',0,'intval');

                if (htmlspecialchars_decode($message) == '' || removeEmpty($message) == '') $this->apiError('请填写内容');

                if (!$result = ArticleModel::saveArticleComment($article, $message, $this->user_info, $at_info, $pid)) $this->apiError($pid ? '回复失败' : '评论失败');
                $result['user_info'] = [
                    'uid' => $this->user_id,
                    'nick_name' => $this->user_info['nick_name'],
                    'avatar' => $this->user_info['avatar'] ? ImageHelper::replaceImageUrl($this->user_info['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
                ];

                $this->apiSuccess($pid ? '回复成功' : '评论成功', $result);
                break;

            default :
                if (!QuestionModel::getQuestionInfo($item_id,'id,uid,title')) $this->apiError('问题不存在或已被删除');
                if ($ret = QuestionModel::saveComments($this->user_id, $item_id, $message)) {
                    $ret['user_info'] = [
                        'uid' => $this->user_id,
                        'nick_name' => $this->user_info['nick_name'],
                        'avatar' => $this->user_info['avatar'] ? ImageHelper::replaceImageUrl($this->user_info['avatar']) : $this->request->domain().'/static/common/image/default-avatar.svg'
                    ];
                    $this->apiSuccess('评论成功', $ret);
                } else {
                    $this->apiError('评论失败');
                }
        }

    }

    //删除评论
    public function remove_comment()
    {
        $item_type = $this->request->param('item_type','question','trim');
        $comment_id = $this->request->param('id',0,'intval');
        switch ($item_type) {
            case 'answer':
                if ($ret = Answer::deleteComment($comment_id, $this->user_info)) {
                    $this->apiSuccess('删除成功');
                } else {
                    $this->apiError(Answer::getError());
                }
            case 'article':
                if (!$comment_info = db('article_comment')->find($comment_id)) $this->apiError('评论已被删除');
                if ($this->user_id != $comment_info['uid'] && $this->user_info['group_id'] > 2) $this->apiError('您没有删除评论的权限');

                if (ArticleModel::deleteComment($comment_id)) {
                    $this->apiSuccess('删除成功');
                } else {
                    $this->apiError('删除失败');
                }
                break;
            default :
                $ret = QuestionModel::deleteComment($comment_id, $this->user_info);
                if ($ret) {
                    $this->apiSuccess('删除成功');
                } else {
                    $this->apiError(Answer::getError());
                }
        }
    }

}