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

namespace app\api\v1;
use app\common\controller\Api;
use app\model\api\v1\Users;
use app\model\Topic;

/**
 * 通用小部件
 * Class Sidebar
 */
class Widget extends Api
{
    public function explore()
    {
        $type = $this->request->param('type','');
        $hot_topic = Topic::getHotTopics($this->user_id,[],[],3,1,true)['data'];
        $data = [
            'focus_topic'=>$type =='focus' || !$type ? Topic::getFocusTopicByRand($this->user_id) : [],
            'hot_topic'=>$type =='topic' || !$type ? $hot_topic : [],
            'hot_user'=> $type =='user' || !$type ?Users::getHotUsers($this->user_id,[],[],3) : [],
            'hot_question'=> !$type ? \app\model\api\v1\Question::getQuestionList($this->user_id,'hot',0,1,3) : [],
            'announce'=>db('announce')
                ->where(['status'=>1])
                ->order(['create_time'=>'DESC','sort'=>'DESC'])
                ->find(),
            'maybe_like'=> $type =='like' || !$type ? [] : []
        ];
        $this->apiResult($data);
    }
}
