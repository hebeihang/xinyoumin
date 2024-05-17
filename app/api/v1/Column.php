<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\common\library\helper\ImageHelper;

use app\common\library\helper\LogHelper;
use app\logic\common\FocusLogic;
use app\model\api\v1\Users;
use app\model\Article as ArticleModel;
use app\model\Topic as TopicModel;
use app\model\api\v1\Column as ColumnModel;

class Column extends Api
{
    // 专栏列表
    public function index()
    {
        $where = [];
        $sort =  $this->request->param('sort','new','trim');
        $page = $this->request->param('page',1,'intval');
        $page_size = $this->request->param('page_size',10,'intval');
        if ($uid = $this->request->get('uid', 0, 'intval')) $where = [['uid', '=', $uid]];
        $data = ColumnModel::getColumnListByPage($where, $this->user_id, $sort, $page, $page_size);

        foreach ($data as $key=>$val)
        {
            $pic = $this->request->domain().'/static/common/image/topic.svg';
            $data[$key]['cover'] = $val['cover'] ? ImageHelper::replaceImageUrl($val['cover']) : $pic;
        }
        $this->apiResult(array_values($data));
    }

    // 专栏详情
    public function detail()
    {
        $column_info = db('column')->where(['id' => $this->request->get('id', 0, 'intval')])->find();
        $user_info = Users::getUserInfoByUid($column_info['uid'], 'uid,nick_name,avatar,signature');
        $user_info['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'user', $column_info['uid']) ? 1 : 0;
        $column_info['has_focus'] = ColumnModel::checkFocus($this->user_id, $column_info['id']);
        $column_info['cover'] = ImageHelper::replaceImageUrl($column_info['cover']);

        $data = [
            'info'=>$column_info,
            'user_info'=>$user_info
        ];
        $this->apiResult($data);
    }
    
    // 专栏文章
    public function articles()
    {
        $params = $this->request->get();
        $params['sort'] = $params['sort'] ?? 'column';
        $params['column_id'] = (int) $params['column_id'];
        $params['page'] = isset($params['page']) ? intval($params['page']) : 1;
        $params['page_size'] = isset($params['page_size']) ? intval($params['page_size']) : 10;
        $params['words_count'] = isset($params['words_count']) ? intval($params['words_count']) : 120;
        $order = $where = [];
        $where[] = ['status', '=', 1];

        $order['set_top_time'] = 'DESC';
        $order['update_time'] = 'DESC';
        $order['create_time'] = 'DESC';
        
        if ($params['sort'] == 'column') {
            $where[] = ['column_id', '=', $params['column_id']];
        } else {
            $where[] = ['column_id', '<>', $params['column_id']];
            $where[] = ['uid', '=', db('column')->where(['id' => $params['column_id']])->value('uid')];
        }
        
        $list = db('article')->where($where)->order($order)->page($params['page'], $params['page_size'])->select()->toArray() ?: [];

        if (!empty($list)) {
            $topic_infos = TopicModel::getTopicByItemIds(array_column($list,'id'), 'article');
            // 用户数据
            $users = Users::getUserInfoByIds(array_unique(array_column($list, 'uid')), 'uid,nick_name,avatar');
            $users = array_column($users, null, 'uid');
            foreach ($list as &$val) {
                $val['user_info'] = $users[$val['uid']];
                $val['content'] = str_cut(strip_tags(htmlspecialchars_decode($val['message'])), 0, $params['words_count']);
                $val['images'] = ImageHelper::srcList(ImageHelper::replaceImageUrl(htmlspecialchars_decode($val['message'])));
                if (empty($val['images'])) {
                    $val['images'] = $val['cover'] ? [ImageHelper::replaceImageUrl($val['cover'])] : [];
                }
                $val['topics'] = $topic_infos ? $topic_infos[$val['id']] : [];
                $val['create_time'] = date_friendly($val['create_time']);
            }
        }

        $this->apiResult($list);
    }

    //我的专栏
    public function my()
    {
        $page = $this->request->param('page',1);
        $sort = $this->request->param('sort','new');
        $verify =  $this->request->param('verify',1);
        $data = ColumnModel::getMyColumnList($this->user_id,$sort,$verify,$page);
        $this->apiResult($data);
    }

    public function apply()
    {
        if($this->request->isPost())
        {
            $name = $this->request->post('name');
            $description = $this->request->post('description');
            $cover = $this->request->post('cover');
            $id = $this->request->post('id',0,'intval');
            if(!$name){
                $this->apiResult([],0,'专栏标题不能为空');
            }

            if(!$description){
                $this->apiResult([],0,'专栏描述不能为空');
            }

            if(!$id && db('column')->where(['name'=>$name])->value('id'))
            {
                $this->apiResult([],0,'专栏已存在');
            }

            $verify = (isSuperAdmin() || isNormalAdmin()) ? 1 :0;
            \app\model\Column::applyColumn($this->user_id,$name,$description,$cover,intval($id),$verify);
            $this->apiResult([],0,$verify ? '申请成功' : '申请成功,请等待管理员审核');
        }
        $id = $this->request->param('id',0,'intval');
        $column_info = db('column')->where(['uid'=>$this->user_id,'id'=>$id])->find() ?:[];
        $this->apiResult($column_info);
    }

    // 收录文章至专栏
    public function collect()
    {
        $article_id = $this->request->post('article_id',0,'intval');
        $column_id  = $this->request->post('column_id',0,'intval');
        if (!$article_id || !$column_id) $this->apiError('请求参数不正确');

        $article_uid = db('article')->where(['id' => $article_id])->value('uid');

        if ($this->user_id != $article_uid && $this->user_info['group_id'] >= 3) $this->apiError('没有操作权限');

        if (db('article')->where(['id' => $article_id])->update(['column_id' => $column_id])) {
            $column_post_count = ArticleModel::where(['column_id' => $column_id, 'status' => 1])->count();
            \app\model\Column::update(['post_count' => $column_post_count], ['id' => $column_id]);

            // 添加行为日志
            LogHelper::addActionLog('create_column_article','column', $column_id, $this->user_id,'0',0,'article',$article_id);
            $this->apiSuccess('操作成功');
        } else {
            $this->apiError('操作失败');
        }
    }
}