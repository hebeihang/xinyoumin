<?php
namespace app\api\v1;
use app\common\controller\Api;
use app\common\library\helper\FormatHelper;
use app\common\library\helper\HtmlHelper;
use app\common\library\helper\ImageHelper;
use app\common\library\helper\IpHelper;
use app\common\library\helper\IpLocation;
use app\common\library\helper\LogHelper;
use app\common\library\helper\PopularHelper;
use app\logic\common\FocusLogic;
use app\model\Answer;
use app\model\Approval;
use app\model\Attach;
use app\model\Common;
use app\model\Draft;
use app\model\api\v1\Question as QuestionModel;
use app\model\Report;
use app\model\Topic;
use app\model\Users;
use app\model\Vote;
use think\exception\ValidateException;

class Question extends Api
{
    //问题列表
    public function index()
    {
        $category_id = $this->request->param('category_id', 0, 'intval');
        $sort = $this->request->param('sort', 'new', 'trim');
        $page = $this->request->param('page', 1, 'intval');
        $page_size = $this->request->param('page_size', 10, 'intval');
        $words_count = $this->request->param('words_count', 100, 'intval');

        //用于展示个人问答列表使用
        $uid = $this->request->param('uid', 0, 'intval');

        $question_list = \app\model\api\v1\Question::getQuestionList($this->user_id, $sort, $category_id, $page, $page_size, $uid, $words_count);
        $this->apiResult($question_list);
    }

    //问题详情
    public function detail()
    {
        $id = $this->request->param('id', 0, 'intval');
        $answer_id = $this->request->param('answer_id', 0, 'intval');

        if (!$id) {
            $this->apiResult([], 0, '请求参数错误');
        }

        $question_info = QuestionModel::getQuestionInfo($id, 'title,detail,id,update_time,answer_count,status,uid,is_anonymous,category_id,focus_count,comment_count,is_recommend,set_top,agree_count');

        if (!$question_info || $question_info['status'] === 0) {
            $this->apiResult([], 0, '问题不存在或已被删除');
        }

        //更新问题浏览
        QuestionModel::updateQuestionViews($id, $this->user_id);

        //更新问题热度值
        PopularHelper::calcQuestionPopularValue($id);

        $question_info['update_time'] = date_friendly($question_info['update_time']);

        //问题用户信息
        if ($question_info['is_anonymous']) {
            $question_info['user_info'] = [
                'nick_name' => '匿名用户',
                'avatar' => $this->request->domain() . '/static/common/image/default-avatar.svg'
            ];
        } else {
            $question_info['user_info'] = Users::getUserInfoByUid($question_info['uid'], 'user_name,nick_name,uid,avatar,signature,verified');

        }

        if (isset($question_info['user_info']['signature']) && !$question_info['user_info']['signature']) {
            $question_info['user_info']['signature'] = '暂无个人签名...';
        }

        if (!$question_info['user_info']['avatar']) {
            $question_info['user_info']['avatar'] = $this->request->domain() . '/static/common/image/default-avatar.svg';
        }

        $question_info['user_focus'] = (bool)Users::checkFocus($this->user_id, $question_info['uid']);

        // 获取话题
        $question_info['topics'] = Topic::getTopicByItemType('question', $question_info['id']);
        $question_info['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'question', $question_info['id']) ? 1 : 0;
        $question_info['user_info']['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'user', $question_info['uid']) ? 1 : 0;

        //是否举报
        $question_info['is_report'] = Report::getReportInfo($id, 'question', $this->user_id) ? 1 : 0;

        //是否点赞
        $question_info['vote_value'] = Vote::getVoteByType($id, 'question', $this->user_id);

        $shareData = [
            'url'=>(string)url('question/detail',['id'=>$question_info['id']],true,true),
            'image'=>ImageHelper::replaceImageUrl(ImageHelper::src(htmlspecialchars_decode($question_info['detail']))),
            'description'=>str_cut(strip_tags(htmlspecialchars_decode($question_info['detail'])),0,100)
        ];

        $question_info['share_data'] = $shareData;

        //是否收藏
        $favoriteInfo = Common::checkFavorite(['uid' => $this->user_id, 'item_id' => $id, 'item_type' => 'question']);
        $question_info['is_favorite'] = $favoriteInfo ? $favoriteInfo['id'] : 0;

        $question_info['detail'] = HtmlHelper::replaceVideoUrl(HtmlHelper::parseImgUrl($question_info['detail']));
        $question_info['attach_list'] = Attach::getAttach('question_attach', $question_info['id']);
        foreach ($question_info['attach_list'] as &$attach) {
            $attach['size'] = formatBytes($attach['size']);
            $attach['url'] = $this->request->domain() . $attach['url'];
        }
        $answer = [];
        if($answer_id)
        {
            $answer = QuestionModel::getAnswerByQuestionId($id,$answer_id,1,1,[],$this->user_id);
        }

        $data = [
            'info'=>$question_info,
            'answer'=>$answer
        ];
        $this->apiResult($data);
    }

    // 发布或修改问题
    public function publish()
    {
        $postData = $this->request->post();

        if ($postData['id'] = intval($postData['id'])) {
            $question_info = QuestionModel::getQuestionInfo(intval($postData['id']), 'uid,id');
            if (!$question_info || ($question_info['uid'] != $this->user_id && get_user_permission('modify_question') != 'Y')) $this->apiError('您没有修改问题的权限');
        } else {
            if ($this->user_info['permission']['publish_question_enable'] != 'Y') $this->apiError('您没有发布问题的权限');

            // 用户今天是否还可以发送问题
            if ($this->user_info['permission']['publish_question_num'] and ($question_num = Users::getUserPublishNum($this->user_id, 'question')) >= $this->user_info['permission']['publish_question_num']) {
                $this->apiError('你所在的用户组当天只允许发布' . $this->user_info['permission']['publish_question_num'] . '篇问题帖,您已发布' . $question_num . '篇问题帖');
            }

            // 验证用户积分是否满足积分操作条件
            if (!LogHelper::checkUserIntegral('NEW_QUESTION', $this->user_id)) $this->apiError('您的积分不足,无法发起问题');
        }

        try {
            validate(\app\validate\Question::class)->check($postData);
        } catch (ValidateException $e) {
            $this->apiError($e->getError());
        }

        /*问题提交前钩子*/
        hook('question_publish_post_before', $postData);
        if (htmlspecialchars_decode($postData['title']) == '' || removeEmpty($postData['title']) == '') $this->apiError('请填写问题标题');
        if ($this->settings['enable_category'] && !intval($postData['category_id'])) $this->apiError('请选择问题分类');

        if (get_setting('topic_enable') == 'Y' && (!isset($postData['topics'])) || empty($postData['topics'])) $this->apiError('请至少选择一个话题');


        if (get_setting('topic_enable') == 'Y' && get_setting('max_topic_select') < count($postData['topics'])) {
            $this->apiError('您最多只可设置' . get_setting('max_topic_select') . '个话题');
        }

        if ($this->user_info['permission']['publish_url'] == 'N' && FormatHelper::outsideUrlExists($postData['detail'])) $this->apiError('你所在的用户组不允许发布站外链接');

        // 微信小程序内容安全检测
        if (ENTRANCE == 'wechat') $this->wxminiCheckText([$postData['title'], $postData['detail']], '标题或内容不符合微信小程序安全检测');

        unset($postData['__token__'], $postData['access_key']);
        $postData['form'] = 1;
        $postData['uid'] = $postData['uid'] ?? $this->user_id;
        $postData['is_anonymous'] = $postData['is_anonymous'] ?? 0;
        $postData['question_type'] = $postData['question_type'] ?? 'normal';
        $access_key = $postData['access_key'] ?? md5($this->user_id . time());
        $postData['detail'] = htmlspecialchars_decode($postData['detail']);
        $postData['topics'] = array_unique(array_column($postData['topics'], 'id'));

        // 发起需要审核
        if ($postData['question_type'] == 'normal' && !$postData['id'] && $this->publish_approval_valid($postData['detail'])) {
            Approval::saveApproval('question', $postData, $this->user_id, $access_key);
            $this->apiSuccess('发表成功,请等待管理员审核', ['id' => $postData['id']]);
        }

        // 修改需要审核
        if ($postData['question_type'] == 'normal' && $this->publish_approval_valid($postData['detail'], 'modify_question_approval') && $postData['id']) {
            Approval::saveApproval('modify_question', $postData, $question_info['uid'] ?? $this->user_id, $access_key);
            $this->apiError('修改成功,请等待管理员审核');
        }

        if ($id = \app\model\api\v1\Question::saveQuestion($postData['uid'], $postData, $access_key)) {
            // 问题提交后钩子
            hook('question_publish_post_after', $id);
            $this->apiSuccess('发布成功', compact('id'));
        } else {
            $this->apiError('发布失败');
        }
    }

    //回答列表
    public function answers()
    {
        $question_id = $this->request->param('question_id', 0, 'intval');
        $sort = $this->request->param('sort', 'new', 'trim');
        $page = $this->request->param('page', 1, 'intval');
        $per_page = $this->request->param('per_page', 10, 'intval');
        $export_answer = $this->request->param('export_answer', 0, 'intval');
        if ($sort == 'new') {
            $order = ['is_best' => 'DESC', 'create_time' => 'DESC'];
        } else {
            $order = ['is_best' => 'DESC', 'agree_count' => 'DESC', 'comment_count' => 'DESC'];
        }

        $answer = QuestionModel::getAnswerByQuestionId($question_id,0,$page, $per_page, $order,$this->user_id,$export_answer);
        $this->apiResult($answer);
    }

    //相关问题
    public function relation()
    {
        $question_id = $this->request->param('question_id', 0, 'intval');

        if (!$question_id)
            $this->apiResult([], -1, '请求参数错误');

        $page = $this->request->param('page', 1, 'intval');
        $per_page = $this->request->param('page_size', 10, 'intval');

        $relation_question = \app\model\api\v1\Question::getRelationQuestion($question_id, $page, $per_page);
        $this->apiResult($relation_question ? array_values($relation_question) : []);
    }

    //回答详情
    public function answer()
    {
        $id = $this->request->param('id', 0, 'intval');

        if (!$id)
            $this->apiResult([], -1, '请求参数错误');

        $answer_info = Answer::getAnswerInfoById($id);
        if (!$answer_info) {
            $this->apiResult([], -1, '回答不存在');
        }

        $answer_info['vote_value'] = Vote::getVoteByType($id, 'answer', $this->user_id);
        $answer_info['question_info'] = QuestionModel::getQuestionInfo($answer_info['question_id']);
        $answer_info['question_info']['update_time'] = date_friendly($answer_info['question_info']['update_time']);
        $answer_info['user_info'] = Users::getUserInfoByUid($answer_info['uid'], 'user_name,nick_name,uid,avatar,signature');
        $answer_info['user_info']['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'user', $answer_info['uid']) ? 1 : 0;
        $answer_info['content'] = HtmlHelper::parseImgUrl($answer_info['content']);
        $answer_info['is_favorite'] = Common::checkFavorite(['uid' => $this->user_id, 'item_id' => $answer_info['id'], 'item_type' => 'answer']) ? 1 : 0;
        //$answer_info['comments'] = QuestionModel::getAnswerComments($id, 1, 3);

        $shareData = [
            'url'=>(string)url('question/detail',['id'=>$answer_info['question_id']],true,true),
            'image'=>ImageHelper::replaceImageUrl(ImageHelper::src(htmlspecialchars_decode($answer_info['content']))),
            'description'=>str_cut(strip_tags(htmlspecialchars_decode($answer_info['content'])),0,100)
        ];
        $Ip = new IpLocation();
        $answer_info['answer_user_local'] = $Ip->getLocation($answer_info['answer_user_ip'])['country'];
        $answer_info['share_data'] = $shareData;

        $this->apiResult($answer_info);
    }

    // 删除回答
    public function remove_answer()
    {
        $answer_id = $this->request->post('id', 0);

        if (!$answer_info = Answer::getAnswerInfoById($answer_id)) $this->apiError('回答不存在');
        if (!$answer_info['status']) $this->apiError('回答已被删除');
        if ($answer_info['uid'] != $this->user_id && $this->user_info['group_id'] > 2) $this->apiError('您没有删除回答的权限');

        if (Answer::deleteAnswer($answer_id)) {
            $this->apiSuccess('删除成功');
        } else {
            $this->apiError(Answer::getError());
        }
    }

    //删除问题
    public function remove_question()
    {
        $id = $this->request->param('id');
        $question_info = QuestionModel::getQuestionInfo($id, 'uid');

        if ($this->user_id !== $question_info['uid'] && get_user_permission('remove_question') != 'Y' && !isSuperAdmin() && !isNormalAdmin()) {
            $this->apiError('您没有删除问题的权限');
        }

        if(($question_info['answer_count'] || $question_info['focus_count']) && !isSuperAdmin() && !isNormalAdmin())
        {
            $this->apiError('已经有回答或有用户关注的问题，无法被删除');
        }

        if (!QuestionModel::removeQuestion($id)) {
            $this->apiError('删除问题失败');
        }
        $this->apiSuccess('删除问题成功');
    }

    //问题操作
    public function manager()
    {
        $question_id = $this->request->param('id');
        $type = $this->request->param('type');
        $value = $this->request->param('value',0,'intval');

        if (!$question_id && !$type) {
            $this->apiError('请求参数不正确');
        }

        if (QuestionModel::manger($question_id, $type,$value)) {
            $this->apiSuccess('操作成功');
        }

        $this->apiError(QuestionModel::getError());
    }

    //获取已邀请用户
    public function get_invite_users()
    {
        $question_id = $this->request->param('question_id', 0, 'intval');
        $uid_list = db('question_invite')->where(['question_id' => intval($question_id)])->column('recipient_uid');
        if (!$uid_list) $this->apiResult([]);;
        $users = Users::getUserInfoByIds($uid_list, 'nick_name,user_name,avatar,reputation,answer_count,fans_count,uid');
        $avatar = request()->domain() . '/static/common/image/default-avatar.svg';
        if (!$users) {
            $this->apiResult([]);
        }

        foreach ($users as $key => $val) {
            $users[$key]['has_invite'] = 1;
            $users[$key]['avatar'] = $val['avatar'] ? ImageHelper::replaceImageUrl($val['avatar']) : $avatar;
        }
        $this->apiResult(array_values($users));
    }

    //提交邀请搜索
    public function search_invite()
    {
        $question_id = $this->request->param('question_id');
        $name = $this->request->param('name', '');
        $page = $this->request->param('page', 1, 'intval');
        $where[] = ['uid', '<>', $this->user_id];

        $where[] = ['nick_name', 'like', "%" . $name . "%"];
        $where[] = ['status', '=', 1];
        $data = db('users')
            ->where($where)
            ->order(['reputation' => 'DESC'])
            ->field('nick_name,user_name,avatar,reputation,answer_count,fans_count,uid')
            ->page($page, 10)
            ->select()
            ->toArray();
        $avatar = request()->domain() . '/static/common/image/default-avatar.svg';
        foreach ($data as $key => $val) {
            $data[$key]['has_invite'] = 0;
            $data[$key]['avatar'] = $val['avatar'] ? ImageHelper::replaceImageUrl($val['avatar']) : $avatar;
            if (db('question_invite')->where(['sender_uid' => $this->user_id, 'recipient_uid' => $val['uid'], 'question_id' => intval($question_id)])->value('id')) {
                $data[$key]['has_invite'] = 1;
            }
        }

        $this->apiResult($data);
    }

    //保存邀请结果
    public function save_question_invite()
    {
        $invite_uid = $this->request->post('uid', 0, 'intval');
        $question_id = $this->request->post('question_id', 0, 'intval');

        if ($invite_uid == $this->user_id) {
            $this->apiError('不可以邀请自己回答问题');
        }

        if (db('answer')->where(['question_id' => $question_id, 'uid' => $invite_uid])->value('id')) {
            $this->apiError('该用户已回答过该问题,不能继续邀请');
        }

        //验证用户积分是否满足积分操作条件
        if (!LogHelper::checkUserIntegral('INVITE_ANSWER', $this->user_id)) {
            $this->apiError('您的积分不足,无法邀请用户回答问题');
        }

        if (!$question_info = QuestionModel::getQuestionInfo($question_id, 'id,uid,title')) {
            $this->apiError('问题不存在或已被删除');
        }
        if (!QuestionModel::saveQuestionInvite($question_info, $this->user_id, $invite_uid)) {
            $this->apiError('该用户已邀请过啦', '', []);
        }
        $this->apiSuccess('操作成功');
    }

    // 回答编辑器
    public function answer_editor()
    {
        $question_id = $this->request->param('question_id',0,'intval');
        $answer_id = $this->request->param('answer_id',0,'intval');
        $answer_info =[];
        if($answer_id)
        {
            $answer_info = Answer::getAnswerInfoById($answer_id);
            $question_id = $answer_info['question_id'];
        }else{
            // 判断是否已回复过问题
            if ((get_setting('answer_unique') == 'Y') && db('answer')->where(['uid'=>$this->user_id,'question_id'=>intval($question_id),'status'=>1])->count())
            {
                $error = '一个问题只能回复一次，你可以编辑回复过的回复';
            }

        }
        $draft_info = Draft::getDraftByItemID($this->user_id,'answer');
        if(isset($draft_info['data']))
        {
            $answer_info['content'] = htmlspecialchars_decode($draft_info['data']['content']);
        }
        $answer_info['access_key'] = md5($this->user_id.time());
        $this->apiResult($answer_info);
    }

    // 保存回答
    public function save_answer()
    {
        if($this->request->isPost())
        {
            $data = $this->request->post();
            $access_key = $data['access_key'];

            // 判断是否已回复过问题
            if ((get_setting('answer_unique') == 'Y') && db('answer')->where(['uid'=>$this->user_id,'question_id'=>intval($data['question_id'])])->count() && !$data['id'])
            {
                $this->apiError('一个问题只能回复一次，你可以编辑回复过的回复');
            }

            if(!$data['id'] && $this->user_info['permission']['publish_answer_enable']=='N')
            {
                $this->apiError('您没有发布回答的权限');
            }

            if(htmlspecialchars_decode($data['content'])=='' || removeEmpty($data['content'])=='')
            {
                $this->apiError('请输入回答内容');
            }

            if ($this->user_info['permission']['publish_url']=='N' && FormatHelper::outsideUrlExists($data['content'])) {
                $this->apiError('你所在的用户组不允许发布站外链接');
            }

            $answer_info = [];

            if($data['id'])
            {
                $answer_info = Answer::getAnswerInfoById($data['id']);
                if(!$answer_info)
                {
                    $this->apiError('回答不存在');
                }
            }

            unset($data['__token__'],$data['access_key']);

            $uid = $data['uid']??$this->user_id;

            //验证用户积分是否满足积分操作条件
            if(!LogHelper::checkUserIntegral('ANSWER_QUESTION',$uid) && !$data['id'])
            {
                $this->apiError('您的积分不足,无法回答问题');
            }

            $question_info = QuestionModel::getQuestionInfo(intval($data['question_id']));
            if(!$question_info)
            {
                $this->apiError('问题不存在', '/');
            }

            $Ip = new IpLocation(); // 实例化类 参数表示IP地址库文件
            $data['uid'] = $data['id'] ? $answer_info['uid'] : $uid;
            $data['answer_user_ip'] = IpHelper::getRealIp();
            $data['answer_user_local'] = $Ip->getLocation(IpHelper::getRealIp())['country'];

            //发起回答审核
            if($this->publish_approval_valid(htmlspecialchars_decode($data['content']),'publish_answer_approval') && !$data['id'])
            {
                Approval::saveApproval('answer',$data,$uid,$access_key);
                $this->apiError('发起成功,请等待管理员审核', 'question/detail?id=' . $data['question_id']);
            }

            //修改回答审核
            if($this->publish_approval_valid(htmlspecialchars_decode($data['content']),'modify_answer_approval') && $data['id'])
            {
                Approval::saveApproval('modify_answer',$data,$uid,$access_key);
                $this->apiError('修改成功,请等待管理员审核', 'question/detail?id=' . $data['question_id']);
            }

            $data['is_anonymous'] = $data['is_anonymous'] ?? 0;

            if (Answer::saveAnswer($data,$access_key))
            {
                $this->apiSuccess('回答成功');
            }
            $this->apiError(Answer::getError());
        }
    }

    // 设置最佳回复
    public function set_best_answer()
    {
        if(get_user_permission('set_best_answer')!='Y')
        {
            $this->apiError('您没有操作权限');
        }

        $answer_id = $this->request->param('id',0);
        $answer_info = Answer::getAnswerInfoById($answer_id);

        if(!$answer_info)
        {
            $this->apiError('回答不存在');
        }

        if($answer_info['uid']==$this->user_id &&  $this->user_info['group_id']!=1 && $this->user_info['group_id']!=2)
        {
            $this->apiError('不可设置自己的回答为最佳答案');
        }

        if(db('answer')->where(['question_id'=>$answer_info['question_id'],'is_best'=>1])->count())
        {
            $this->apiError('最多只可设置一个最佳答案');
        }

        if(db('question')->where(['id'=>$answer_info['question_id']])->update(['best_answer'=>$answer_info['id']]))
        {
            db('answer')->where(['id'=>$answer_info['id']])->update(['is_best'=>1,'best_uid'=>$this->user_id,'best_time'=>time()]);
            //添加积分记录
            LogHelper::addIntegralLog('BEST_ANSWER',$answer_id,'answer',$answer_info['uid']);

            //$question_info = QuestionModel::getQuestionInfo($answer_info['question_id'],'title');

            //系统通知用户
            send_notify(0,$answer_info['uid'],'BEST_ANSWER','question',$answer_info['question_id']);

            $this->apiSuccess('设置最佳答案成功');
        }
        $this->apiError('设置最佳答案失败');
    }

    // 删除回答评论
    public function remove_answer_comment()
    {
        $comment_id = $this->request->post('id');
        $ret = Answer::deleteComment($comment_id, $this->user_info);
        if ($ret) {
            $this->apiSuccess('删除成功');
        } else {
            $this->apiError(Answer::getError());
        }
    }

    // 获取搜索问题
    public function search_question()
    {
        $keyword = $this->request->param('keyword','','trim');
        $keyword=preg_replace('/[\'.,:;*?~`!@#$%^&+=)(<>{}]|\]|\[|\/|\\\|\"|\|/', '', trim($keyword));
        $keyword = sqlFilter($keyword);

        $sql1 = "title regexp '".$keyword."'";
        $data = db('question')
            ->whereRaw('status=1 AND '.$sql1)
            ->field('title,id,answer_count,focus_count')
            ->page(1,15)
            ->select()
            ->toArray();

        $this->apiResult($data);
    }

    // 回答评论列表
    public function answer_comments()
    {
        $data = $this->request->get();
        $page = isset($data['page']) ? intval($data['page']) : 1;
        $pageSize = isset($data['page_size']) ? intval($data['page_size']) : 10;
        $this->apiResult(\app\model\api\v1\Question::getAnswerComments($data['id'], $page, $pageSize));
    }
}