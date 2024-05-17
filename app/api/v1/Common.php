<?php
namespace app\api\v1;
use app\common\controller\Api;
use app\common\library\helper\UploadHelper;
use app\logic\common\FocusLogic;
use app\model\Category;
use app\model\Config as ConfigModel;
use app\model\Draft;
use app\model\Vote;
use app\model\Attach;
use app\common\library\helper\ImageHelper;

class Common extends Api
{
    /**
     * 获取分类列表
     * @return void
     */
    public function category()
    {
        $type = $this->request->param('type','','trim');
        $category = ['common','question','article'];
        $category = array_merge($category,array_keys(config('aws.category')));

        if(!in_array($type,$category))
        {
            $this->apiResult([],-1,'请求参数错误');
        }
        $categoryListByType = \app\model\api\v1\Common::getCategoryListByType($type);
        if(!$categoryListByType)
        {
            $this->apiResult([],-1,'请求参数错误');
        }
        $this->apiResult($categoryListByType);
    }

    // 配置信息
    public function config()
    {
        $config = ConfigModel::getConfigs();
        $config['site_logo'] = replacePic($config['site_logo']);

        $announce_info =  db('announce')
            ->where(['status'=>1])
            ->order(['create_time'=>'DESC','sort'=>'DESC'])
            ->field('id,message')
            ->find();

        if($announce_info)
        {
            $announce_info['message'] = str_cut(strip_tags(htmlspecialchars_decode($announce_info['message'])),0,150);
        }

        $module_config = [
            'navList'=>[
                [
                    'icon'=>'icon-databaseset-fill',
                    'text'=> '资源',
                    'iconSize'=> 60,
                ],
                [
                    'icon'=>'icon-connection',
                    'text'=> '导航',
                    'iconSize'=> 60,
                ],

                [
                    'icon'=>'icon-file-fill',
                    'text'=>'文章',
                    'iconSize'=> 60
                ],
                [
                    'icon'=> 'icon-question-circle-fill',
                    'text'=> '求助',
                    'iconSize'=> 60
                ],
                [
                    'icon'=> 'icon-huati1',
                    'text'=> '话题',
                    'iconSize'=> 60
                ],
                [
                    'icon'=> 'icon-user-circle',
                    'text'=> '用户',
                    'iconSize'=> 60
                ]
            ],
            'swiperList'=>[

            ]
        ];

        $data = [
            'system'=>$config,
            'announce'=>$announce_info,
            'module'=>$module_config
        ];

        $this->apiResult($data);
    }

    /**
     * 发送短信
     * @return void
     */
    public function sms()
    {
        $mobile = $this->request->param('mobile','','intval');

        if(!$mobile)
        {
            $this->apiResult([],-1,'请输入正确的手机号');
        }

        $result = hook('sms',['mobile'=>$mobile]);

        if($result=='')
        {
            $this->apiResult([],-1,'短信功能未启用');
        }

        $code = cache('sms_'.$mobile);
        if(!$code)
        {
            $this->apiResult([],-1,'验证码发送失败');
        }
        $result = json_decode($result,true);
        if($result['code']==0)
        {
            $this->apiResult([],-1,$result['msg']);
        }
        $this->apiResult(['code'=>$code],1,$result['msg']);
    }

    /**
     * 聚合数据
     * @return void
     */
    public function mixed_list()
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

    // 热门搜索
    public function hot_search()
    {
        $page = $this->request->get('page', 1, 'intval');
        $page_size = $this->request->get('page_size', 15, 'intval');
        $data = \app\model\Common::getHotSearchList($page, $page_size);
        $this->apiResult($data);
    }

    // 搜索
    public function search()
    {
        $type = $this->request->get('type','all');
        if (!$keywords = $this->request->get('q','', 'trim')) $this->apiResult([]);
        $keywords = preg_replace('/[\'.,:;*?~`!@#$%^&+=)(<>{}]|\]|\[|\/|\\\|\"|\|/', '', $keywords);
        $page = $this->request->get('page', 1, 'intval');
        $limit = $this->request->get('page_size',10, 'intval');

        $data = (new \app\logic\search\Search())->search($keywords, $type, $this->user_id, 'all', $page, $limit);
        $data = $data ? $data['list'] : [];
        $result = [];
        if (!empty($data)) {
            $avatar = $this->request->domain().'/static/common/image/default-avatar.svg';
            $anonymous = [
                'nick_name' => '匿名用户',
                'avatar' => $avatar
            ];
            foreach ($data as $val) {
                if ('users' == $val['search_type']) {
                    $result[] = [
                        'type' => 'users',
                        'uid' => $val['uid'],
                        'title' => $val['name'],
                        'nick_name' => $val['nick_name'],
                        'has_focus' => $val['has_focus'],
                        'fans_count' => $val['fans_count'],
                        'integral' => $val['integral'],
                        'agree_count' => $val['agree_count'],
                        'signature' => $val['signature'] ?: '这家伙还没有留下自我介绍～',
                        'avatar' => $val['avatar'] ? ImageHelper::replaceImageUrl($val['avatar']) : $avatar
                    ];
                    continue;
                }
                $user = [];
                $val['is_anonymous'] = $val['is_anonymous'] ?? 0;
                switch ($val['search_type']) {
                    case 'question':
                        $content = $val['detail'];
                        if ($val['is_anonymous']) {
                            $user = $anonymous;
                        } else {
                            $user = [
                                'uid' => $val['uid'],
                                'nick_name' => $val['user_info']['nick_name'],
                                'avatar' => $val['user_info']['avatar'] ? ImageHelper::replaceImageUrl($val['user_info']['avatar']) : $avatar
                            ];
                        }
                        break;
                    case 'answer':
                        $content = mb_chunk_split($keywords, strip_tags(htmlspecialchars_decode($val['answer_info']['content'])),100);
                        if ($val['answer_info']['is_anonymous']) {
                            $user = $anonymous;
                        } else {
                            $user = [
                                'uid' => $val['answer_info']['uid'],
                                'nick_name' => $val['answer_info']['user_info']['nick_name'],
                                'avatar' => $val['answer_info']['user_info']['avatar'] ? ImageHelper::replaceImageUrl($val['answer_info']['user_info']['avatar']) : $avatar
                            ];
                        }
                        break;
                    case 'article':
                        $content = $val['message'];
                        if ($val['is_anonymous']) {
                            $user = $anonymous;
                        } else {
                            $user = [
                                'uid' => $val['uid'],
                                'nick_name' => $val['user_info']['nick_name'],
                                'avatar' => $val['user_info']['avatar'] ? ImageHelper::replaceImageUrl($val['user_info']['avatar']) : $avatar
                            ];
                        }
                        break;
                    default:
                        $content = $val['description'];
                }

                $result[] = [
                    'id' => $val['id'],
                    'type' => $val['search_type'],
                    'title' => $val['title'],
                    'content' => $content,
                    'user_info' => $user,
                    'topics' => $val['topics'] ?? [],
                    'create_time' => date_friendly($val['create_time']),
                    'view_count' => $val['view_count'] ?? 0,
                    'agree_count' => $val['agree_count'] ?? 0,
                    'answer_count' => $val['answer_count'] ?? 0,
                    'comment_count' => $val['comment_count'] ?? 0,
                    'has_focus' => $val['has_focus'] ?? 0,
                    'discuss' => $val['discuss'] ?? 0,
                    'focus' => $val['focus'] ?? 0,
                    'is_anonymous' => $val['is_anonymous'] ?? 0,
                    'images' => isset($val['img_list']) ? ImageHelper::replaceImageUrl($val['img_list']) : []
                ];
            }
        }

        $this->apiResult($result);
    }

    // 点赞、踩操作
    public function set_vote()
    {
        $item_id = $this->request->post('item_id',0,'intval');
        $item_type = $this->request->post('item_type');
        $vote_value = intval($this->request->post('vote_value'));

        if (!$result = Vote::saveVote($this->user_id, $item_id, $item_type, $vote_value)) {
            $this->apiResult([], 0, Vote::getError());
        }
        $this->apiResult([], 1, '操作成功');
    }

    // 关注
    public function update_focus()
    {
        $item_id = $this->request->post('id',0,'intval');
        $item_type = $this->request->post('type');
        if (!FocusLogic::updateFocusAction($item_id, $item_type, $this->user_id)) {
            $this->apiResult([], 0, FocusLogic::getError());
        }

        $this->apiResult([], 1, '操作成功');
    }

    // 获取access_key
    public function get_access_key()
    {
        $data = $this->request->get();
        $data['item_id'] = $data['item_id'] ?? 0;
        $data['item_type'] = $data['item_type'] ?? 'common';
        if ($data['item_id']) {
            $attach = db('attach')->where(['item_id' => $data['item_id'], 'uid' => $this->user_id, 'item_type' => $data['item_type']])->find();
            $accessKey = $attach ? $attach['access_key'] : md5($this->user_id.time());
        } else {
            $accessKey = md5($this->user_id.time());
        }

        $this->apiSuccess('获取成功', ['access_key' => $accessKey]);
    }

    // 文件上传
    public function upload()
    {
        $uploadObj = UploadHelper::instance();

        $uploadPath= $this->request->post('path','common');
        $access_key = $this->request->post('access_key', md5($this->user_id.time()));

        // 上传初始化钩子
        hook('uploadInit', ['uploadValidate' => $uploadObj->uploadVal()]);
        $result = $uploadObj->setAccessKey($access_key)->setUploadPath($uploadPath)->setUploadType($this->request->param('upload_type','img'))->upload($this->user_id,'','');
        if ($result['code'] == 1) {
            $url = ImageHelper::replaceImageUrl($result['url']);
            // 微信小程序图片安全检测 图片尺寸不超过 750px x 1334px 大小不超过1M
            if (ENTRANCE == 'wechat' && $result['is_image'] && $result['width'] <= 750 && $result['height'] <= 1334 && $result['size'] <= 1024) $this->wxminiCheckImage($url);
            $this->apiResult(['path' => $url], 1, '上传成功');
        } else {
            $this->apiResult([], 0, $result['msg'] ?? '上传失败');
        }
    }

    // 删除附件
    public function remove_attach()
    {
        $url = $this->request->post('url', '');
        if (!$url) $this->apiError('错误的请求');
        $url = str_replace($this->request->domain(), '', $url);
        $attach = Attach::where('url', $url)->find();
        if (!$attach) $this->apiSuccess('删除成功');

        $attach->delete();
        @unlink($attach['path']);

        $this->apiSuccess('删除成功');
    }

    // 保存草稿
    public function save_draft()
    {
        $item_id = $this->request->post('item_id',0,'intval');
        $item_type = $this->request->post('item_type', 'question');
        $data = $this->request->post('data');

        // 微信小程序内容安全检测
        if (ENTRANCE == 'wechat') $this->wxminiCheckText(json_encode($data, JSON_UNESCAPED_UNICODE));

        if ($item_type != 'answer') {
            if (empty($data) || !$data['title']) $this->apiError('保存草稿失败');
        } else {
            if (empty($data) || !$data['content']) $this->apiError('保存草稿失败');
        }

        $data['is_anonymous'] = isset($data['is_anonymous']) ? intval( $data['is_anonymous']) : 0;
        unset($data['__token__']);
        if (Draft::saveDraft($this->user_id, $item_type, $data, $item_id)) {
            $this->apiSuccess('保存草稿成功');
        } else {
            $this->apiError('保存草稿失败');
        }
    }

    public function check_update()
    {
        $edition_type = $this->request->param('edition_type');
        $version_type = $this->request->param('version_type');
        $version_type = $this->request->param('version_type');
        /* data:{
            // 版本更新内容 支持<br>自动换行
            describe: '1. 修复已知问题<br>
                    2. 优化用户体验',
            edition_url: '', //apk、wgt包下载地址或者应用市场地址  安卓应用市场 market://details?id=xxxx 苹果store itms-apps://itunes.apple.com/cn/app/xxxxxx
            edition_force: 0, //是否强制更新 0代表否 1代表是
            package_type: 1 //0是apk升级 1是wgt升级
            edition_issue:1 //是否发行  0否 1是 为了控制上架应用市场审核时不能弹出热更新框
            edition_number:100 //版本号 最重要的manifest里的版本号 （检查更新主要以服务器返回的edition_number版本号是否大于当前app的版本号来实现是否更新）
            edition_name:'1.0.0'// 版本名称 manifest里的版本名称
            edition_silence:0 // 是否静默更新 0代表否 1代表是
        }*/

        $data = [
            'describe'=>'1. 修复已知问题<br>2. 优化用户体验',
            'edition_url'=>'https://dm.wecenter.com/__UNI__F7512345.wgt',
            'edition_force'=>0,
            'package_type'=>0,
            'edition_issue'=>0,
            'edition_number'=>215,
            'edition_name'=>'1.0.1',
            'edition_silence'=>0
        ];

        $this->apiSuccess($data);
    }

    public function announce()
    {
        $id = $this->request->param('id',0,'intval');
        $announce_info =  db('announce')
            ->where(['status'=>1,'id'=>$id])
            ->find();

        if($announce_info)
        {
            $announce_info['message'] = htmlspecialchars_decode($announce_info['message']);
            $announce_info['create_time'] = date_friendly($announce_info['create_time']);
        }

        $this->apiResult($announce_info);
    }
}