<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\common\library\helper\LogHelper;
use app\common\library\helper\StringHelper;
use app\model\Attach;
use app\model\Common;
use app\model\Config;
use app\model\Report;
use app\model\Topic;
use app\model\Users;
use app\model\Vote;
use plugins\reward\model\Reward as RewardModel;
use plugins\reward\model\RewardAnswer as Answer;

class Reward extends Api
{
    protected $pay_config ;
    protected $reward_config ;

    public function initialize()
    {
        parent::initialize();
        $this->pay_config = get_plugins_config('pay');
        $this->reward_config = get_plugins_config('reward');

        if($this->reward_config['reward']['enable']!='Y')
        {
            $this->apiError('悬赏功能暂未启用');
        }
    }

    //悬赏详情
    public function detail()
    {
        $reward_id = $this->request->param('id',0,'intval');
        //更新悬赏浏览
        RewardModel::updateRewardViews($reward_id,$this->user_id);
        $reward_info = RewardModel::getRewardInfo($reward_id);

        if (!$reward_info || $reward_info['status']==0) {
            $this->error('悬赏不存在或已被删除');
        }

        //悬赏用户信息
        $reward_info['user_info'] = Users::getUserInfoByUid($reward_info['uid'], 'user_name,nick_name,uid,avatar,signature,verified');
        $reward_info['user_focus'] = (bool)Users::checkFocus($this->user_id, $reward_info['uid']);

        // 获取话题
        $reward_info['topics'] = Topic::getTopicByItemType('reward',$reward_info['id'])??[];

        $focus_type = $reward_info['look_enable']?'look':'focus';
        $reward_info['has_focus'] = RewardModel::checkUserIsFocus($this->user_id, $focus_type, $reward_info['id']);
        $reward_text= [
            0=>'未开始',
            1=>'进行中',
            2=>'最佳答案评定中',
            3=>'最佳答案评定中',
            4=>'公示中',
            5=>'已结束'
        ];
        $reward_info['reward_label'] = $reward_text[$reward_info['reward_status']];
        $reward_info['left_time'] = ($reward_info['reward_time']-time())*1000;
        $reward_info['create_time'] = date_friendly($reward_info['create_time']);

        //是否有回答权限
        $reward_info['answer_enable'] = true;

        //是否举报
        $reward_info['is_report'] = Report::getReportInfo($reward_id,'reward',$this->user_id)?1:0;

        //是否点赞
        $reward_info['vote_value'] = Vote::getVoteByType($reward_id,'reward',$this->user_id);

        //是否收藏
        $favoriteInfo = Common::checkFavorite(['uid'=>$this->user_id,'item_id'=>$reward_id,'item_type'=>'reward']);
        $reward_info['is_favorite'] = $favoriteInfo ? $favoriteInfo['id'] : 0;

        $this->apiResult($reward_info);
    }

    public function answers()
    {
        $reward_id = $this->request->param('reward_id',0);
        $reward_info = RewardModel::getRewardInfo($reward_id);
        $sort = $this->request->param('sort', 'new', 'trim');
        $page = $this->request->param('page', 1, 'intval');
        $per_page = $this->request->param('per_page', 10, 'intval');
        $export_answer = $this->request->param('export_answer', 0, 'intval');
        if ($sort == 'new') {
            $order = ['is_best' => 'DESC', 'create_time' => 'DESC'];
        } else {
            $order = ['is_best' => 'DESC', 'agree_count' => 'DESC', 'comment_count' => 'DESC'];
        }
        //是否围观问题
        $is_look_question = db('reward_focus')->where(['uid'=>$this->user_id,'type'=>'look','reward_id'=>$reward_info['id']])->value('id');

        $answer = [];
        //判断用户查看问题回答权限
        //0原始状态 1发起悬赏 2发起者最佳答案选择期间 3 管理员选择期间 4 悬赏公示期 5 赏金分配完成 6 悬赏结束
        //公示期之前参与回答的只能看到自己的
        if($reward_info['reward_status']<4 && !isSuperAdmin() && !isNormalAdmin() && $this->user_id!=$reward_info['uid'])
        {
            $where['reward_id'] = $reward_info['id'];
            $where['uid'] = $this->user_id;
            $answer = Answer::getAnswersByWhere($where,$order,$page,10);
        }

        //公示期 在悬赏内参与回答的用户可看全部悬赏期内的回答
        if($reward_info['reward_status']==4)
        {
            $answer_user_ids = db('reward_answer')->where(['is_reward'=>1,'status'=>1])->column('uid');
            $where1[] = ['reward_id','=',$reward_info['id']];
            $where1[] = ['uid','IN',$answer_user_ids];
            $answer = Answer::getAnswersByWhere($where1,$order,$page,10);
        }

        //悬赏结束 未开放回答 围观用户可见
        $look_enable_answer = $reward_info['reward_status']>=5 && $reward_info['is_open']==0 && $is_look_question;

        if($look_enable_answer || isNormalAdmin() || isSuperAdmin() || $this->user_id==$reward_info['uid'])
        {
            $answer = Answer::getAnswerByRewardId($reward_info['id'],0,$page,10,$order);
        }

        //悬赏结束 并且开放查看所有人可看
        if($reward_info['reward_status']>=5 && $reward_info['is_open']==1)
        {
            $answer = Answer::getAnswerByRewardId($reward_info['id'],0,$page,10,$order);
        }

        if($answer)
        {
            foreach ($answer['data'] as $key=>$val)
            {
                $answer['data'][$key]['has_appeal'] = 0;
                $answer['data'][$key]['vote_value'] = RewardModel::getVoteByType($val['id'],'answer',$this->user_id);
                $answer['data'][$key]['has_thanks'] = db('reward_answer_thanks')->where(['answer_id'=>$val['id'],'uid'=>$this->user_id])->value('id') ? 1 : 0;
                if($val['is_best'])
                {
                    $answer['data'][$key]['has_appeal'] = db('reward_appeal')->where(['uid'=>$this->user_id,'reward_id'=>$reward_id])->value('id');
                }
            }
        }

        $this->apiResult($answer['data']);
    }

    //围观支付
    public function looker_pay()
    {
        if($this->request->isPost())
        {
            $postData = $this->request->post();
            $reward_info = RewardModel::getRewardInfo($postData['id']);
            if(!$reward_info)
            {
                $this->apiError('无该悬赏信息');
            }

            //积分悬赏围观
            if ($reward_info['pay_type'] == 'integral') {
                if ($this->user_info['integral'] < $postData['amount']) {
                    $this->apiError(L(sprintf('你的%s不够', Config::getConfigs('score_unit'))));
                }

                //扣减用户积分
                if (!LogHelper::addIntegralLog('reward_looker', $reward_info['id'], 'reward', $this->user_id, -$postData['amount']))
                {
                    $this->apiError('付费失败:'.LogHelper::getError());
                }
            }
            //付费悬赏围观
            if($reward_info['pay_type'] == 'money')
            {

            }
            //添加围观记录
            if(!db('reward_focus')->insert([
                'reward_id'=>$reward_info['id'],
                'uid'=>$this->user_id,
                'type'=>'look',
                'create_time'=>time()
            ]))
            {
                $this->apiError('更新围观记录失败');
            }

            if(!db('reward')->where(['id'=>$postData['relation_id']])->inc('look_money',$postData['amount'])->inc('looker_count',1)->update())
            {
                $this->apiError('更新悬赏数据失败');
            }

            $this->apiSuccess('付费成功');
        }
    }

    //关注悬赏
    public function focus()
    {
        if($this->request->isPost()) {
            $postData = $this->request->post();
            RewardModel::saveFocus($this->user_id,$postData['id']);
            $this->success('关注成功');
        }
    }

    /*检查围观费用*/
    public function checkLook()
    {
        $reward_id = $this->request->param('id',0);
        $config = get_plugins_config('reward','look');
        if($config['look_type']=='fixed')
        {
            $options = [
                'title'=>'围观悬赏',
                'relation_type'=>'reward',
                'relation_title'=>'围观悬赏',
                'order_type'=>'reward_look',
                'amount'=>$config['circuses_money'],
                'out_trade_no'=>StringHelper::getOrderSn('AWS'),
                'relation_id'=>$reward_id,
                'return_url'=>(string)url('reward/detail',['id'=>$reward_id])
            ];
        }else{
            $options = [
                'title'=>'围观悬赏',
                'relation_type'=>'reward',
                'relation_title'=>'围观悬赏',
                'order_type'=>'reward_look',
                'amount'=> RewardModel::getRewardLookMoney($reward_id),
                'out_trade_no'=>StringHelper::getOrderSn('AWS'),
                'relation_id'=>$reward_id,
                'return_url'=>(string)url('reward/detail',['id'=>$reward_id])
            ];
        }
        $this->apiResult($options);
    }
}