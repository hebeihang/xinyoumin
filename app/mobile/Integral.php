<?php
// +----------------------------------------------------------------------
// | WeCenter社交化问答系统
// +----------------------------------------------------------------------
// | Copyright (c) 2020-2022 https://wecenter.isimpo.com
// +----------------------------------------------------------------------
// | WeCenter一款基于TP6开发的社交化知识付费问答系统、企业内部知识库系统，打造私有社交化问答、内部知识存储
// +----------------------------------------------------------------------
// | Author: WeCenter团队 <devteam@WeCenter.com>
// +----------------------------------------------------------------------

namespace app\mobile;
use app\common\controller\Frontend;
use app\model\Score as ScoreModel;

class Integral extends Frontend
{
    protected $needLogin=['index'];

	public function index()
	{
        $type = $this->request->param('type','log');
		$this->assign('type',$type);
		return $this->fetch();
	}


    public function get_integral_log()
    {
        $page = $this->request->param('page',1,'intval');
        $where=['uid'=>$this->user_id];
        $data = ScoreModel::getScoreList($where,$page);
        $data['html'] = $this->fetch('',$data);
        return $this->apiResult($data);
    }
}