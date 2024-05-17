<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\model\Report;

class Permission extends Api
{
    //内容举报
    public function report()
    {
        if ($this->request->isPost()) {
            $reason = $this->request->post('reason');
            $report_type = $this->request->post('report_type');
            $item_id = $this->request->post('item_id',0,'intval');
            $item_type = $this->request->post('item_type');
            if (!$reason || removeEmpty($reason)=='') $this->apiError('请填写举报理由');

            // 微信小程序内容安全检测
            if (ENTRANCE == 'wechat') $this->wxminiCheckText($reason);

            $result = Report::saveReport($item_id, $item_type, $report_type, $reason, $this->user_id);
            $this->apiSuccess($result['msg']);
        }
    }
}