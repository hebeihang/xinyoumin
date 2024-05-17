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

class Page extends Api
{
    public function index()
    {
        $url_name = $this->request->get('name','');
        $where[]=['status','=',1];
        if($url_name)
        {
            $where[]=['url_name','=',$url_name];
        }

        $info = db('page')->where($where)->find();
        if($info)
        {
            $info['contents'] = htmlspecialchars_decode($info['contents']);
        }
        $this->apiResult($info);
    }

    /**
     * 积分规则页面
     */
    public function score()
    {
        $list = db('integral_rule')->where([['integral','<>','0']])->select()->toArray();

        $this->assign([
            'list'=>$list
        ]);
        return $this->fetch();
    }
}