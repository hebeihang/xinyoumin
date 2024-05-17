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


namespace app\model\admin;
use app\common\library\helper\AuthHelper;
use think\facade\Request;
use think\facade\Session;
use think\Model;

class AdminLog extends Model
{
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public function admin()
    {
        return $this->belongsTo('app\model\Users', 'uid');
    }

    // 管理员日志记录
    public static function record()
    {
        // 入库信息
        $adminId   = session('admin_login_uid');
        $url       = Request::url();
        $title     = '';
        $content   = Request::except(['_pjax']);
        $ip        = Request::ip();
        $userAgent = Request::server('HTTP_USER_AGENT');

        // 标题处理
        $auth = AuthHelper::instance();
        $titleArr = $auth->getBreadCrumb();
        if (is_array($titleArr)) {
            foreach ($titleArr as $k => $v) {
                $title = $v['title'] . ' -> ' . $title;
            }
            $title = substr($title, 0, strlen($title) - 4);
        }

        // 内容处理(过长的内容和涉及密码的内容不进行记录)
        if ($content) {
            foreach ($content as $k => $v) {
                if (is_string($v) && strlen($v) > 200 || stripos($k, 'password') !== false) {
                    unset($content[$k]);
                }
            }
        }

        // 登录处理
        if (strpos($url, 'index/login') !== false) {
            $title = '[登录成功]';
            $content = '';
        }

        // 插入数据
        if (!empty($title)) {
            // 查询管理员上一条数据
            $result = self::where('uid', '=', $adminId)->order('id', 'desc')->find();
            if ($result) {
                if ($result->url != $url) {
                    self::create([
                        'title'       => $title ? $title : '',
                        'content'     => !is_scalar($content) ? json_encode($content) : $content,
                        'url'         => $url,
                        'uid'    => $adminId,
                        'user_agent'   => $userAgent,
                        'ip'          => $ip
                    ]);
                }
            } else {
                self::create([
                    'title'       => $title ? $title : '',
                    'content'     => !is_scalar($content) ? json_encode($content) : $content,
                    'url'         => $url,
                    'uid'    => $adminId,
                    'user_agent'   => $userAgent,
                    'ip'          => $ip
                ]);
            }
        }
    }

    //管理员回收站内容记录
    public static function recycle($table,$item_id,$realDelete=0): bool
    {
        return false;
        if(!$table || !$item_id) return false;
        // 入库信息
        $adminId   = session('admin_login_uid');
        $url       = Request::url();
        $title     = '';
        $ip        = Request::ip();
        $userAgent = Request::server('HTTP_USER_AGENT');

        // 标题处理
        $auth = AuthHelper::instance();
        $titleArr = $auth->getBreadCrumb();
        foreach ($titleArr as $k => $v) {
            $title = $v['title'] . ' -> ' . $title;
        }
        $title = substr($title, 0, strlen($title) - 4);

        // 插入数据
        if (!empty($title)) {
            $item_id = is_array($item_id)?$item_id:explode(',',$item_id);
            foreach ($item_id as $id)
            {
                db('admin_recycle')->insert([
                    'title'       => $title,
                    'table'     => $table,
                    'item_id'     => $id,
                    'url'         => $url,
                    'uid'    => $adminId,
                    'user_agent'   => $userAgent,
                    'ip'          => $ip,
                    'real_delete'=>$realDelete,
                    'create_time'=>time()
                ]);
            }
            return true;
        }
        return false;
    }
}