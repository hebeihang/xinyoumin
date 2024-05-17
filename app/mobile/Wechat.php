<?php
namespace app\mobile;

use app\common\controller\Frontend;
use app\common\library\helper\WeChatHelper;

class Wechat extends Frontend
{
    public function index()
    {
        $id = $this->request->param('id');
        $response = WeChatHelper::instance()->getOfficialAccount($id)->server->serve();
        $response->send();
    }
}