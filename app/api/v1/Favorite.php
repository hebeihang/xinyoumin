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
use app\logic\common\FocusLogic;
use app\model\api\v1\UsersFavorite as FavoriteModel;
use app\model\UsersFavorite;

class Favorite extends Api
{
	// 收藏标签
	public function index()
	{
		$page = $this->request->param('page',1);
		$data = FavoriteModel::getFavoriteTags($this->user_id,$page);
		$this->apiResult($data);
	}

	// 收藏详情
	public function detail()
	{
		$id = $this->request->get('id',0);
		$page = $this->request->get('page',1);
		$info = db('users_favorite_tag')->where(['id' => $id])->find();
		$info['focus'] = FocusLogic::checkUserIsFocus($this->user_id,'favorite', $info['id']);
        $info['create_time'] = date_friendly($info['create_time']);
		$fav_item = FavoriteModel::getFavoriteListByTagId($this->user_id, $info['id'], $page);
        $data = [
            'items' => $fav_item,
            'info' => $info
        ];
        $this->apiResult($data);
	}

	// 删除标签
	public function delete()
	{
		$id = $this->request->post('id',0);
		if (db('users_favorite')->where('tag_id', $id)->delete()) {
            db('users_favorite_tag')->where(['id' => $id])->delete();
			$this->apiSuccess('删除成功');
		} else {
		    $this->apiError('删除失败');
        }
	}
	public function save_favorite_tag()
    {
        if($this->request->isPost())
        {
            $is_public = $this->request->post('is_public',0);
            $title = $this->request->post('title','');
            $description = $this->request->post('description','');
            if(!FavoriteModel::saveFavoriteTag($this->user_id, $title,$is_public,$description))
            {
                $this->apiError(FavoriteModel::getError());
            }
            $this->apiSuccess('创建成功');
        }
    }

    public function get_fav_tags()
    {
        $item_type = $this->request->param('item_type','','trim');
        $item_id = $this->request->param('item_id',0,'intval');
        $favorite_list = UsersFavorite::getFavoriteTags($this->user_id)['list'];
        foreach ($favorite_list as $key => $value) {
            $favorite_list[$key]['is_favorite'] = UsersFavorite::where(['item_type' => $item_type, 'item_id' => (int) $item_id, 'tag_id' => $value['id'], 'uid' => $this->user_id])->value('id') ?1:0;
        }

        $this->apiResult($favorite_list);
    }

    public function save_favorite()
    {
        if ($this->request->isPost()) {
            $tag_id = $this->request->param('tag_id');
            $item_id = $this->request->param('item_id');
            $item_type = $this->request->param('item_type');
            if ($return = UsersFavorite::saveFavorite($this->user_id, $tag_id, $item_id, $item_type)) {
                $this->apiResult($return,1,'收藏成功');
            }
            $this->apiError(UsersFavorite::getError());
        }
    }
}