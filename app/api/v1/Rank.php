<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\model\api\v1\Users;
use think\facade\Db;

class Rank extends Api
{
    /**
     * 点赞榜
     * @return void
     */
    public function agree()
    {
        $sort = $this->request->param('sort','new','trim');
        $page = $this->request->param('page',1,'intval');
        $uid = $this->request->param('uid',0,'intval');
        $uid = $uid?:$this->user_id;
    }

    /**
     * 积分榜
     * @return void
     */
    public function score()
    {
        $sort = $this->request->param('sort','day','trim');
        $uid = $this->request->param('uid',0,'intval');
        $uid = $uid?:$this->user_id;
        $dayBeginTime = strtotime(date('Y-m-d'));
        $dayEndTime = strtotime(date('Y-m-d 23:59:59'));
        $monthBeginTime = $dayBeginTime-30*ONE_DAY;

        if($sort=='day')
        {
            $list =  Db::name('integral_log')
                ->whereBetweenTime('create_time',$dayBeginTime,$dayEndTime)
                ->group('uid')
                ->order('sum_integral','DESC')
                ->limit(50)
                ->column('SUM(integral) as sum_integral,uid');
        }else{
            $list = Db::name('integral_log')
                ->whereBetweenTime('create_time',$monthBeginTime,$dayEndTime)
                ->group('uid')
                ->order('sum_integral','DESC')
                ->limit(50)
                ->column('SUM(integral) as sum_integral,uid');
        }

        $rank = 0;

        foreach ($list as $key=>$value)
        {
            $user_info = Users::getUserInfoByUid($value['uid'],'nick_name,avatar,fans_count,agree_count');
            $list[$key] = array_merge($value,$user_info);
            if($uid == $value['uid'])
            {
                $rank = $key+1;
            }
        }

        $data = [
            'rank'=>$rank,
            'list'=>$list
        ];
        $this->apiResult($data);

    }

    /**
     * 威望榜
     * @return void
     */
    public function power()
    {
        $uid = $this->request->param('uid',0,'intval');
        $uid = $uid?:$this->user_id;
    }

    /**
     * 评论榜
     * @return void
     */
    public function comment()
    {

    }

    /**
     * 收藏榜
     * @return void
     */
    public function fav()
    {

    }
}