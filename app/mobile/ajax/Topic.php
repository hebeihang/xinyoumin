<?php
namespace app\mobile\ajax;
use app\common\controller\Frontend;
use app\model\Topic as TopicModel;

class Topic extends Frontend
{
    protected $needLogin=[
        'merge_topic',
        'remove_topic'
    ];

    /**
     * 删除话题
     */
    public function remove_topic()
    {
        $id = $this->request->param('id');
        if($this->user_id && ($this->user_info['group_id']===1 || $this->user_info['group_id']===2))
        {
            TopicModel::removeTopic((int)$id);
            $this->success('删除成功');
        }
        $this->error('您没有删除话题的权限');
    }

    /**
     * 获取话题日志
     * @return mixed
     */
    public function logs()
    {
        $id = $this->request->param('id',0,'intval');
        $data=TopicModel::getLogs($id,$this->user_id,$this->request->get('page',1,'intval'),10);
        $this->assign($data);
        return $this->fetch();
    }
}