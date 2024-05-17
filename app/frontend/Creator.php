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

namespace app\frontend;
use app\common\controller\Frontend;
use app\common\library\helper\LogHelper;
use app\logic\common\FocusLogic;
use app\model\Users;

/**
 * 创作者中心
 * Class Creator
 * @package app\member\frontend
 */
class Creator extends Frontend
{
    protected $needLogin = ['index'];

    public function index()
    {
        $this->assign('question_count',LogHelper::getActionLogCount('publish_question',$this->user_id,$this->user_id));
        $this->assign('answer_count',LogHelper::getActionLogCount('publish_answer',$this->user_id,$this->user_id));
        $this->assign('article_count',LogHelper::getActionLogCount('publish_article',$this->user_id,$this->user_id));
        return $this->fetch();
    }
}