<?php
namespace app\model\api\v1;

use app\model\BaseModel;
use app\common\library\helper\ImageHelper;

class Column extends BaseModel
{
    // 获取专栏列表
    public static function getColumnListByPage($where, $uid, $sort = 'new', $page = 1,$per_page = 8)
    {
        $cache_key = md5("column-list-{$sort}-{$page}");
        if ($cache_list_time = get_setting('cache_list_time')) {
            if ($list = cache($cache_key)) return $list;
        }

        $order = [];
        $where[] = ['verify', '=', 1];
        switch ($sort) {
            case 'new':
                $order['create_time'] = 'DESC';
                break;
            case 'hot':
                $order['view_count'] = 'DESC';
                break;
            case 'recommend':
                $order['view_count'] = 'DESC';
                $where[]=['recommend','=',1];
                break;
        }
        $list =  db('column')->where($where)->order($order)->page($page, $per_page)->select()->toArray();
        foreach ($list as &$value) {
            $value['description'] = str_cut(strip_tags(htmlspecialchars_decode($value['description'])),0,50);
            $value['has_focus'] = 0 ;
            if (db('column_focus')->where(['uid' => $uid, 'column_id' => $value['id']])->value('id')) {
                $value['has_focus'] = 1 ;
            }
            $value['cover'] = ImageHelper::replaceImageUrl($value['cover']);
            $value['user_info'] = Users::getUserInfoByUid($value['uid'],'user_name,nick_name,uid,avatar');
        }

        if ($cache_list_time) {
            cache($cache_key, $list, ['expire' => $cache_list_time * 60]);
        }

        return $list ?: [];
    }

    /**
     * 获取我的专栏列表
     * @param $uid
     * @param string $sort
     * @param int $verify
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public static function getMyColumnList($uid,$sort='new',$verify=1,$page=1,$per_page=10): array
    {
        $order = array();
        $where[] = ['verify','=',$verify];
        $where[] = ['uid','=',$uid];
        switch ($sort)
        {
            case 'new':
                $order['create_time'] = 'DESC';
                break;
            case 'hot':
                $order['view_count'] = 'DESC';
                break;
        }
        $list = db('column')->where($where)->order($order)->page($page,$per_page)->select()->toArray();
        $cover = request()->domain().'/static/common/image/default-cover.svg';
        foreach ($list as $key => $value) {
            $list[$key]['description'] = str_cut(strip_tags(htmlspecialchars_decode($value['description'])),0,50) ;
            $list[$key]['has_focus'] = 0 ;
            $list[$key]['item_type'] = 'column' ;
            if(db('column_focus')->where(['uid'=>intval($uid),'column_id'=>$value['id']])->value('id'))
            {
                $list[$key]['has_focus'] = 1 ;
            }
            $list[$key]['cover'] = $value['cover'] ? ImageHelper::replaceImageUrl($value['cover']) : $cover;
        }

        return $list;
    }

    public static function checkFocus($uid, $column_id)
    {
        return (int) db('column_focus')->where(['uid' => $uid, 'column_id' => $column_id])->value('id');
    }

    // 用户专栏数据
    public static function userColumns($uid)
    {
        $columns = db('column')->where(['verify'=>1,'uid'=>$uid])->field('id,name,cover,post_count,focus_count')->select()->toArray();
        if (empty($columns)) return [];
        $pic = request()->domain().'/static/common/image/topic.svg';
        foreach ($columns as &$val) {
            $val['cover'] = $val['cover'] ? ImageHelper::replaceImageUrl($val['cover']) : $pic;
        }

        return $columns;
    }
}