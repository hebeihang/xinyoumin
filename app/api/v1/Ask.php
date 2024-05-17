<?php
namespace app\api\v1;
use app\common\controller\Api;

/**
 * 社区页面数据
 */
class Ask extends Api
{
    /**
     * 聚合数据
     * @return void
     */
    public function lists()
    {
        $sort =  $this->request->param('sort','new','trim');
        $page = $this->request->param('page',1,'intval');
        $page_size = $this->request->param('page_size',10,'intval');
        $words_count = $this->request->param('words_count',100,'intval');
        $item_type = $this->request->param('type','','trim');
        $category_id = $this->request->param('category_id',0,'intval');
        $item_type = $item_type==''? null : $item_type;
        $relation_uid = $this->request->param('relation_uid',0,'intval');
        $list = \app\model\api\v1\Common::getMixedList($this->user_id,$item_type,$sort,$page,$page_size,$relation_uid,$words_count,$category_id);
        $this->apiResult(array_values($list));
    }
}