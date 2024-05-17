<?php
namespace app\api\v1;

use app\common\controller\Api;
use app\common\library\helper\ImageHelper;
use app\logic\common\FocusLogic;
use app\model\Topic as TopicModel;
use think\exception\ValidateException;

class Topic extends Api
{
    // 话题列表
    public function index()
    {
        $type = $this->request->param('type','','trim');
        $pid = $this->request->param('pid',0,'intval');
        $page = $this->request->param('page',0,'intval');
        $uid = $this->request->param('uid',0,'intval');
        if($type=='hot')
        {
            $order['discuss'] ='desc';
        }

        $uid = $uid?:$this->user_id;
        if($type=='focus'&& $uid){
            $list = \app\model\api\v1\Common::getUserFocus($uid, 'topic', $page);
            if (!empty($list)) {
                $pic = $this->request->domain().'/static/common/image/topic.svg';
                foreach ($list as &$value) {
                    $value['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'topic', $value['id']) ? 1 : 0;
                    $value['pic'] = $value['pic'] ? ImageHelper::replaceImageUrl($value['pic']) : $pic;
                }
            }
            $this->apiResult($list);
        }

        if($type=='new'){
            $order['discuss_update'] ='desc';
        }
        $where[] = ['status','=',1];

        if($pid){
            $child_ids = TopicModel::getTopicWithChildIds($pid);
            $where[] = ['id','IN',$child_ids];
        }

        $list = db('topic')->where($where)->order($order)->page($page, 10)->select()->toArray() ?: [];

        if (!empty($list)) {
            $pic = $this->request->domain().'/static/common/image/topic.svg';
            foreach ($list as &$value) {
                $value['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'topic', $value['id']) ? 1 : 0;
                $value['pic'] = $value['pic'] ? ImageHelper::replaceImageUrl($value['pic']) : $pic;
            }
        }

        $this->apiResult($list);
    }

    //父级话题
    public function parent_topic()
    {
        $parent_list=[
            [
                'title'=>'推荐',
                'id'=>'recommend'
            ],
            [
                'title'=>'热门',
                'id'=>'hot'
            ],
            [
                'title'=>'关注',
                'id'=>'focus'
            ]
        ];
        $parent_list1 = db('topic')->where(['status'=>1,'is_parent'=>1,'pid'=>0])->column('id,title');
        $parent_list = $parent_list1?array_merge($parent_list,$parent_list1):$parent_list;
        $this->apiResult($parent_list);
    }

    public function lists()
    {
        $type = $this->request->get('type','','trim');
        $pid = $this->request->get('pid',0,'intval');
        $page = $this->request->get('page', 1, 'intval');
        $page_size = $this->request->get('page_size', 10, 'intval');
        if($type=='discuss')
        {
            $order['discuss'] ='desc';
        }else if($type=='focus'){
            $order['focus'] ='desc';
        }else{
            $order['discuss_update'] ='desc';
        }
        $where[] = ['status','=',1];

        $parent_list = $page==1 ? db('topic')->where(['status'=>1,'is_parent'=>1,'pid'=>0])->column('id,title') : [];

        if($pid){
            $child_ids = TopicModel::getTopicWithChildIds($pid);
            $where[] = ['id','IN',$child_ids];
        }

        $list = db('topic')->where($where)->order($order)->page($page,$page_size)->select()->toArray();

        foreach ($list as $key => $value)
        {
            $pic = $this->request->domain().'/static/common/image/topic.svg';
            $list[$key]['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'topic', $value['id']) ? 1 : 0;
            $list[$key]['description'] = $value['description'] ? str_cut(strip_tags(htmlspecialchars_decode($value['description'])), 0, 45) : '';
            $list[$key]['pic'] = $value['pic'] ? ImageHelper::replaceImageUrl($value['pic']) : $pic;
        }

        $this->apiResult([
            'list'=> $list,
            'parent_list'=>$parent_list,
        ]);
    }

    // 话题详情
    public function detail()
    {
        $topic_id = $this->request->get('id', 0,'intval');
        $topic_info = db('topic')->where(['id' => $topic_id, 'status' => 1])->find();
        if (!$topic_info) $this->apiResult([], 0, '话题不存在或已被删除');

        $topic_info['description'] = $topic_info['description'] ? htmlspecialchars_decode($topic_info['description']) : '';
        $topic_info['has_focus'] = FocusLogic::checkUserIsFocus($this->user_id, 'topic', $topic_info['id']) ? 1 : 0;
        $topic_info['pic'] = $topic_info['pic'] ? ImageHelper::replaceImageUrl($topic_info['pic']) : $this->request->domain().'/static/common/image/topic.svg';
        $this->apiResult($topic_info);
    }

    // 话题关联数据
    public function relations()
    {
        $sort =  $this->request->param('sort','new','trim');
        $page = $this->request->param('page',1,'intval');
        $page_size = $this->request->param('page_size',10,'intval');
        $words_count = $this->request->param('words_count',100,'intval');
        $item_type = $this->request->param('type','','trim');
        $category_id = $this->request->param('category_id',0,'intval');
        $topic_id = $this->request->param('topic_id',0,'intval');
        if(!$topic_id)
        {
            $this->apiError();
        }
        $item_type = $item_type=='all'? null : $item_type;
        $list = \app\model\api\v1\Common::getPostRelationList($this->user_id,$item_type,$sort,$page,$page_size,0,$words_count,$category_id,$topic_id);

        $this->apiResult($list);
    }

    // 最近使用话题
    public function lately_topics($item_type='', $item_id = 0, $topic_id = 0)
    {
        $data = [];
        $recent_topic_list = TopicModel::getRecentTopic($this->user_id, $item_type, $item_id);

        // 默认的话题
        $isNew = true;
        $topic = $topic_id ? TopicModel::getById($topic_id) : false;

        $pic = $this->request->domain().'/static/common/image/topic.svg';
        if($recent_topic_list)
        {
            foreach ($recent_topic_list as $key => $item) {
                $item['pic'] = $item['pic'] ? ImageHelper::replaceImageUrl($item['pic']) : $pic;
                if ($topic_id && $topic && $topic_id == $item['id']) {
                    $isNew = false;
                    $item['is_checked'] = 1;
                }
                if ($item['is_checked']) {
                    array_unshift($data, $item);
                } else {
                    array_push($data, $item);
                }
            }
        }

        if ($isNew && $topic_id && $topic) {
            $topic['is_checked'] = 1;
            $topic['pic'] = $topic['pic'] ? ImageHelper::replaceImageUrl($topic['pic']) : $pic;
            array_unshift($data, $topic);
        }

        $this->apiSuccess('操作成功', $data);
    }

    // 保存设置
    public function save_setting()
    {
        $postData = $this->request->post();
        $topics = array_unique(array_column($postData['topics'], 'id'));
        if (get_setting('topic_enable') == 'Y' && empty($topics)) $this->apiError('请至少设置一个话题');

        if (get_setting('topic_enable') == 'Y' && get_setting('max_topic_select') < count($topics)) $this->apiError('您最多只可设置'.get_setting('max_topic_select').'个话题');

        TopicModel::updateRelation($postData['item_type'], $postData['item_id'], $topics, $this->user_id);

        $this->apiSuccess('操作成功', TopicModel::getTopicByItemType($postData['item_type'], $postData['item_id']));
    }

    // 搜索话题
    public function search()
    {
        $item_type = $this->request->get('item_type');
        $item_id = $this->request->get('item_id',0,'intval');
        $keywords = $this->request->get('keywords', '', 'trim');
        if (!$keywords) $this->apiSuccess('请输入关键词', []);
        $cache_key = 'get_topic_q'.md5(trim($keywords).'_search_type'.$item_type.'_id'.$item_id);
        if (!$topic_list = cache($cache_key)) {
            $where = 'status=1';
            if ($keywords) {
                $where .= " AND title regexp'".$keywords."'";
            }

            $topic_list = TopicModel::getTopic($where);
            $pic = $this->request->domain().'/static/common/image/topic.svg';
            foreach ($topic_list as $key => $val) {
                $topic_list[$key]['is_checked'] = 0;
                if (TopicModel::checkTopicRelation($val['id'], $item_id, $item_type)) {
                    $topic_list[$key]['is_checked'] = 1;
                }
                $topic_list[$key]['pic'] = $val['pic'] ? ImageHelper::replaceImageUrl($val['pic'], $this->request->domain()) : $pic;
            }
            cache($cache_key,$topic_list,60);
        }

        $this->apiSuccess('获取成功', $topic_list ?: []);
    }

    // 创建话题
    public function create()
    {
        if ($this->user_info['permission']['create_topic_enable'] == 'N') $this->apiError('你没有创建话题权限');
        $data = $this->request->post();
        try {
            validate(\app\validate\Topic::class)->check($data);
        } catch (ValidateException $e) {
            $this->apiError($e->getError());
        }

        if ($result = TopicModel::saveTopic($data['title'], $this->user_id)) {
            $this->apiSuccess('创建成功', [[
                'id' => $result,
                'title' => $data['title'],
                'description' => '',
                'is_checked' => 1,
                'pic' => $this->request->domain().'/static/common/image/topic.svg'
            ]]);
        } else {
            $this->apiError(TopicModel::getError());
        }
    }

    public function get_content_topics()
    {
        $topic_id = $this->request->param('topic_id',0,'intval');
        $topics = db('topic')->where('id', $topic_id)->column('id,title');
        $this->apiResult($topics);
    }
}