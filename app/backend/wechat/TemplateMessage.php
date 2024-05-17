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

namespace app\backend\wechat;

use EasyWeChat\Kernel\Exceptions\InvalidConfigException;
use GuzzleHttp\Exception\GuzzleException;
use think\facade\Request;

class TemplateMessage extends WeChatFactor
{
    protected $table = 'wechat_templates';
    public function index()
    {
        $columns = [
            ['id', '编号'],
            /*['wechat_account_id', '关联公众号'],*/
            ['template_id', '模板ID'],
            ['title', '模板标题'],
            ['primary_industry', '模板主分类'],
            ['deputy_industry', '所属行业'],
            ['example', '模板示例'],
            ['status', '是否启用', 'status', '0', [['0' => '禁用'], ['1' => '启用']]]
        ];

        $search = [];
        if ($this->request->param('_list') == 1) {
            $orderByColumn = $this->request->param('orderByColumn') ?? 'id';
            $isAsc = $this->request->param('isAsc') ?? 'desc';
            $where = $this->makeBuilder->getWhere($search);
            return db($this->table)
                ->where($where)
                ->order([$orderByColumn => $isAsc])
                ->paginate([
                    'query'     => Request::get(),
                    'list_rows' =>$this->request->param('pageSize',get_setting("contents_per_page",15))
                ])
                ->toArray();
        }

        $top_button = [
            'templates'=>[
                'title'   => '同步模板列表',
                'icon'    => 'fa fa-download',
                'class'   => 'btn btn-danger btn-sm aw-ajax-get',
                'url'     => (string)url('getPrivateTemplates'),
                'target'      => '',
                'confirm'     =>'是否同步模板列表？',
                'href' => ''
            ]];

        // 构建页面
        return $this->tableBuilder
            ->setUniqueId('id')
            ->addColumns($columns)
            ->addColumn('right_button', '操作', 'btn')
            ->addRightButtons(['delete','field' => [
                'title'       => '编辑通知字段',
                'icon'        => '',
                'href'       =>'',
                'class'       => 'btn btn-success btn-xs aw-ajax-open',
                'url'        => (string)url('edit', ['id' => '__id__']),
            ]])
            ->addTopButtons($top_button)
            ->fetch();
    }

    //编辑
    public function edit($id=0)
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $extends = explode(PHP_EOL, $data['extends']);
            $updateData = [];
            if($extends)
            {
                foreach ($extends as $key => $val)
                {
                    if($val)
                    {
                        list($replace, $pattern) = explode('===', trim($val));
                        $updateData[$replace] = $pattern;
                    }
                }
            }

            $res = db($this->table)->where(['id'=>$data['id']])->update(['extends'=>json_encode($updateData,JSON_UNESCAPED_UNICODE)]);

            if ($res) {
                $this->success('更新成功', url('index'));
            }

            $this->error('更新失败');
        }

        $info = db($this->table)->find($id);
        $extends = json_decode($info['extends'],true);
        $templateField = '';
        foreach ($extends as $k=>$v)
        {
            $templateField.=$k.'==='.$v.PHP_EOL;
        }
        //$extends = implode('===',$extends);
        return $this->formBuilder
            ->addHidden('id',$info['id'])
            ->addTextarea('example', '通知示例', '',$info['example'],'readonly disabled')
            ->addTextarea('extends', '通知字段', '一行一个，分别对应示例中的变量，可使用通知变量有[#site_name#]网站名称,[#title#]内容标题,[#time#]通知时间,[#user_name#]接收人,[#from_username#]发送人,[#subject#]通知配置标题,[#message#]通知配置内容主题,[#sender_uid#]发送人UID,[#recipient_uid#]接收人UID',$templateField)
            ->fetch();
    }

    //同步模板消息列表
    public function getPrivateTemplates()
    {
        try {
            $template_lists = $this->wechatFactor->template_message->getPrivateTemplates();
            $template_lists = $template_lists['template_list'];
            foreach ($template_lists as $k=>$v)
            {
                if(!$id = db($this->table)
                    ->where([
                        'wechat_account_id'=>$this->wechatAccountId,
                        'template_id'=>$v['template_id']
                    ])->value('id')){
                    preg_match_all("/{{(.*?)\./", $v["content"], $matches);
                    db($this->table)
                        ->where(['wechat_account_id'=>$this->wechatAccountId,'template_id'=>$v['template_id']])
                        ->insert([
                            'wechat_account_id'=>$this->wechatAccountId,
                            'template_id'=>$v['template_id'],
                            'title'=>$v['title'],
                            'primary_industry'=>$v['primary_industry']??'',
                            'deputy_industry'=>$v['deputy_industry']??'',
                            'content'=>$v['content']??'',
                            'example'=>$v['example']??'',
                            'extends'=>json_encode(array_flip($matches[1])),
                            'status'=>1,
                            'create_time'=>time(),
                            'update_time'=>time()
                        ]);
                }
            }
            $this->success('同步成功');
        } catch (InvalidConfigException|GuzzleException $e) {
            $this->error('同步失败');
        }
    }
}