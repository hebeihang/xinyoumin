<?php
namespace app\common\controller;
use app\common\library\helper\CheckHelper;
use app\common\library\helper\StringHelper;
use app\common\library\helper\TemplateHelper;
use app\common\library\helper\UserAuthHelper;
use app\common\library\helper\WeChatHelper;
use app\common\traits\Common;
use app\model\Users;
use think\App;
use think\exception\HttpResponseException;
use think\facade\Config;
use think\facade\Request;
use think\helper\Str;

/**
 * 前台控制器基类
 */
class Frontend extends Base
{
    use Common;
    protected $user_id;
    protected $user_info;
    protected $theme = 'default';
    protected $model;
    protected $needLogin;
    protected $returnUrl;

    //控制器名称
    protected $controller;
    //方法名称
    protected $action;
    protected $isMobile;
    protected $settings;
    protected $userAuth;
    protected $themePath;
    protected $baseUrl;

    public function __construct(App $app)
    {
        parent::__construct($app);

        //检查IP封禁
        $this->checkIpAllowed();

        $this->user_info = Users::getUserInfo(intval(getLoginUid()));
        $this->user_id = $this->user_info['uid'] ?? 0;
        $root = get_setting('sub_dir','/');
        $root = '/'.ltrim($root,'/');
        $this->baseUrl = rtrim(Request::domain().$root,'/');
        $this->theme = TemplateHelper::instance()->getDefaultTheme();

        $this->settings = get_setting();
        $this->themePath = get_setting('cdn_url',$this->baseUrl).'/templates/'.$this->theme.'/static/';

        //防止用户不存在session清除 BUG
        if(!$this->user_id && getLoginUid())
        {
            Users::logout();
        }

        //全局前台钩子（只可用于逻辑处理）
        hook('FrontendAppInit');

        $this->userAuth = UserAuthHelper::instance();

        //是否是手机端
        $this->isMobile = Request::isMobile();

        $controller = $this->request->controller();
        if (strpos($controller, '.')) {
            $pos        = strrpos($controller, '.');
            $controller = substr($controller, 0, $pos) . '.' . Str::snake(substr($controller, $pos + 1));
        } else {
            $controller = Str::snake($controller);
        }

        $this->controller = $controller;

        $this->action = Str::snake($this->request->action());
        $theme_block = public_path().'templates/'.$this->theme.'/html/block.php';
        $this->assign([
            'baseUrl'=> $this->baseUrl,
            'thisController'       => parse_name($this->controller),
            'thisAction'           => $this->action,
            'thisRequest'          => parse_name("{$this->controller}/{$this->action}"),
            'cdnUrl' => get_setting('cdn_url',$this->baseUrl),
            '_ajax' =>$this->request->param('_ajax', 0),
            '_ajax_open'=>$this->request->param('_ajax_open', 0),
            '_pjax'=>$this->request->isPjax() ? 1 : 0,
            'setting'=>$this->settings,
            'static_url'=>$this->themePath,
            'user_info'=>$this->user_info,
            'user_id'=>$this->user_id,
            'version'=>env('APP_DEBUG') ? time() : config('version.version'),
            'isMobile'=>$this->isMobile,
            'navMenu'=>$this->userAuth->getNav($this->controller.'/'.$this->action),//顶部导航
            'footerMenu'=>$this->userAuth->getNav($this->controller.'/'.$this->action,'footer'),//底部导航
            'integral_rule'=>db('integral_rule')->where(['status'=>1])->cache(true)->column('integral','name'),
            'theme_block'=>$theme_block,
            'theme_config'=>get_theme_setting()
        ]);

        //站点关闭检查
        if(!CheckHelper::checkSiteStatus())
        {
            $this->siteCloseNotify();
        }

        if ($this->user_id && $this->user_info['status'] === 3) {
            $users_forbidden = db('users_forbidden')->where(['uid' => $this->user_info['uid'], 'status' => 1])->find();
            if($users_forbidden && $users_forbidden['forbidden_time']>time())
            {
                $this->error('您的账号因触发社区封禁规则现已被管理员封禁！封禁原因：' . $users_forbidden['forbidden_reason'] . ';解封时间：' . date('Y-m-d H:i:s', $users_forbidden['forbidden_time']));
            }
        }

        //检测是否登录
        if (UserAuthHelper::instance()->match($this->needLogin) && !$this->user_id) {
            hook('frontendNoLogin', $this);
            $url = $this->request->url();
            if ($url == '/') {
                $this->redirect(url('account/login'));
            }
            $this->error('请先登录后进行操作', url('account/login'));
        }

        //检查用户在线状态
        CheckHelper::checkOnline();

        //检查游客权限 和 浏览网站权限
        if($this->user_info['permission']['visit_website']!='Y' && request()->controller()!='Account' && request()->action()!='login')
        {
            $this->error('您所在组已禁止浏览网站',url('account/login'));
        }

        // 检测当前用户是在其他地方登录
        if ($this->user_id && get_setting('unique_login')=='Y' && !CheckHelper::checkUserIsLoginOtherPlatform($this->user_id))
        {
            Users::logout();
            $this->error('您已在其他地方登录','/');
        }

        // 控制器初始化
        $this->initialize();
    }

    public function initialize()
    {
        $this->TDK();
        $this->script();
        $this->style();
        //记录来源页面地址
        $return_url = $_SERVER['HTTP_REFERER'] ?? '/';
        session('return_url',base64_encode($return_url));
        $this->returnUrl = $return_url;
        $this->assign('return_url',$return_url);
        if($this->isMobile)
        {
            //微信端jsapi分享
            $this->assign([
                'jsSdkConfig'=>WeChatHelper::instance()->getJsSdkConfig()
            ]);
        }
    }

    public function fetch(string $template = '', array $vars = [])
    {
        $view_path = public_path().'templates'.DS.$this->theme.DS.'html'.DS;
        if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
            $view_path = public_path().'templates'.DS.$this->theme.DS.'mobile'.DS;

        if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
            $view_path = public_path().'templates'.DS.$this->theme.DS.'wechat'.DS;

        $depr = Config::get('view.view_depr');
        $view_suffix = 'php';
        if($this->controller=='index' && $this->action=='index')
        {
            $template = '/index';
        }

        $template = str_replace(['/', ':'], $depr, $template);
        if ('' == $template) {
            $template = str_replace('.', DIRECTORY_SEPARATOR, $this->controller) . $depr . $this->action;
        } elseif (false === strpos($template, $depr)) {
            $template = str_replace('.', DIRECTORY_SEPARATOR, $this->controller) . $depr . $template;
        }

        if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
        {
            $theme_block = public_path().'templates/'.$this->theme.'/wechat/block.php';
        }else if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
        {
            $theme_block = public_path().'templates/'.$this->theme.'/mobile/block.php';
        }else{
            $theme_block = public_path().'templates/'.$this->theme.'/html/block.php';
        }

        $theme_config = get_theme_setting();

        //检查模板是否存在，不存在继续使用默认模板
        if($this->theme != 'default' && !file_exists($view_path . 'block' . '.' . $view_suffix))
        {
            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
            {
                $theme_block = public_path().'templates/default/wechat/block.php';
            }else if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
            {
                $theme_block = public_path().'templates/default/mobile/block.php';
            }else{
                $theme_block = public_path().'templates/default/html/block.php';
            }
        }

        if ($this->theme != 'default' && !file_exists($view_path . $template . '.' . $view_suffix)) {
            if(!$theme_config = TemplateHelper::instance()->getTemplatesConfigs($this->theme))
            {
                $theme_config = TemplateHelper::instance()->getTemplatesConfigs('default');
            }

            $view_path = public_path().'templates'.DS.'default'.DS.'html'.DS;
            $this->theme = 'default';
            $this->themePath = get_setting('cdn_url',$this->baseUrl).'/templates/'.$this->theme.'/static/';

            if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
                $view_path = public_path().'templates'.DS.'default'.DS.'mobile'.DS;

            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
                $view_path = public_path().'templates'.DS.'default'.DS.'wechat'.DS;
        }

        $controller = str_replace('.','/',$this->controller);

        $version = env('APP_DEBUG') ? time() : config('version.version');
        //手机端资源自动加载路径
        if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
        {
            $autoloadJs = file_exists(public_path().'templates'.DS.$this->theme.DS.'static'.DS.'wechat'.DS.'js'.DS.parse_name("{$controller}/{$this->action}.js")) ? 1 : 0;
            $autoloadCss = file_exists(public_path().'templates'.DS.$this->theme.DS.'static'.DS.'wechat'.DS.'css'.DS.parse_name("{$controller}/{$this->action}.css")) ? 1 : 0;
        }elseif(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
        {
            $autoloadJs = file_exists(public_path().'templates'.DS.$this->theme.DS.'static'.DS.'mobile'.DS.'js'.DS.parse_name("{$controller}/{$this->action}.js")) ? 1 : 0;
            $autoloadCss = file_exists(public_path().'templates'.DS.$this->theme.DS.'static'.DS.'mobile'.DS.'css'.DS.parse_name("{$controller}/{$this->action}.css")) ? 1 : 0;
        }else{
            $autoloadJs = file_exists(public_path().'templates'.DS.$this->theme.DS.'static'.DS.'js'.DS.parse_name("{$controller}/{$this->action}.js")) ? 1 : 0;
            $autoloadCss = file_exists(public_path().'templates'.DS.$this->theme.DS.'static'.DS.'css'.DS.parse_name("{$controller}/{$this->action}.css")) ? 1 : 0;
        }

        //自动加载js
        if($autoloadJs){
            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
            {
                $this->script(['/templates/'.$this->theme .'/static/wechat/js/'.$controller.'/'.$this->action.'.js?v='.$version]);
            }elseif(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
            {
                $this->script(['/templates/'.$this->theme .'/static/mobile/js/'.$controller.'/'.$this->action.'.js?v='.$version]);
            }else{
                $this->script(['/templates/'.$this->theme .'/static/js/'.$controller.'/'.$this->action.'.js?v='.$version]);
            }
        }

        //自动加载css
        if($autoloadCss){
            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
            {
                $this->style(['/templates/'.$this->theme.'/static/wechat/css/'.$controller.'/'.$this->action.'.css?v='.$version]);
            }elseif(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
            {
                $this->style(['/templates/'.$this->theme.'/static/mobile/css/'.$controller.'/'.$this->action.'.css?v='.$version]);
            }else{
                $this->style(['/templates/'.$this->theme.'/static/css/'.$controller.'/'.$this->action.'.css?v='.$version]);
            }
        }

        //渲染模板配置
        $this->assign([
            'theme_config'=>$theme_config,
            'theme_block'=>$theme_block
        ]);

        $this->view->config([
            'view_path' => $view_path,
            'view_suffix'=>'php'
        ]);
        return $this->view->fetch($template, $vars);
    }

    /**
     * 关键词审核
     * @param null $content
     * @param string $approval_type
     * @return bool
     */
    public function publish_approval_valid($content = null,string $approval_type='publish_question_approval'): bool
    {
        //管理员不审核
        if ($this->user_info['group_id']==1 OR $this->user_info['group_id']==2)
        {
            return false;
        }

        //违禁词审核
        if ($content AND StringHelper::sensitive_word_exists($content))
        {
            return true;
        }
        //未开启审核
        if (!$this->user_info['permission']['publish_approval_time_start'] AND !$this->user_info['permission']['publish_approval_time_end'] AND $this->user_info['permission'][$approval_type] == 'N')
        {
            return false;
        }

        //规定时间内审核
        if ($this->user_info['permission']['publish_approval_time_start'] && $this->user_info['permission']['publish_approval_time_end'] && $this->user_info['permission'][$approval_type]=='Y')
        {
            $publish_approval_time_start =str_replace(':','',$this->user_info['permission']['publish_approval_time_start']);
            $publish_approval_time_end =str_replace(':','',$this->user_info['permission']['publish_approval_time_end']);

            //同一天
            if($publish_approval_time_start < $publish_approval_time_end)
            {
                if (time() >= strtotime(date('Y-m-d',time()).$this->user_info['permission']['publish_approval_time_start']) && time() <= strtotime(date('Y-m-d',time()).$this->user_info['permission']['publish_approval_time_end']))
                {
                    return true;
                }
            }

            //跨天
            if($publish_approval_time_start > $publish_approval_time_end)
            {
                if (time() >= strtotime(date('Y-m-d',time()).$this->user_info['permission']['publish_approval_time_start']) && time() <= strtotime(date('Y-m-d',strtotime('+1 day')).$this->user_info['permission']['publish_approval_time_end']))
                {
                    return true;
                }
            }
        }

        if($this->user_info['permission'][$approval_type]=='Y' && !$this->user_info['permission']['publish_approval_time_start'] && !$this->user_info['permission']['publish_approval_time_end'])
        {
            return true;
        }

        return false;
    }

    //不存在方法返回404
    public function __call($name, $arguments)
    {
        $this->error404();
    }

    /**
     * 站点关闭
     */
    protected function siteCloseNotify()
    {
        $result = [
            'code' => 1,
            'msg'  => '',
            'url'  => '',
        ];
        $type = $this->getResponseType();
        if ($type === 'html') {
            $response = view(public_path().'templates'.DS.$this->theme.DS.'html'.DS.'global'.DS.'close.php', $result);
        } elseif ($type === 'json') {
            $response = json($result);
        }
        throw new HttpResponseException($response);
    }

    /**
     * 404 页面
     * @param null $url
     */
    protected function error404($url = null)
    {
        $url = $this->getUrl($url);
        $result = [
            'code' => 1,
            'msg'  => '',
            'url'  => $url,
        ];
        $type = $this->getResponseType();
        if ($type === 'html') {
            $view_path = public_path().'templates'.DS.$this->theme.DS.'html'.DS.'global'.DS.'404.php';
            if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
                $view_path = public_path().'templates'.DS.$this->theme.DS.'mobile'.DS.'global'.DS.'404.php';
            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
                $view_path = public_path().'templates'.DS.$this->theme.DS.'wechat'.DS.'global'.DS.'404.php';
            $response = view($view_path, $result,404);
        } elseif ($type === 'json') {
            $response = json($result);
        }
        throw new HttpResponseException($response);
    }

    /**
     * 操作成功跳转的快捷方法
     * @access protected
     * @param mixed $msg 提示信息
     * @param null $url 跳转的 URL 地址
     * @param mixed $data 返回的数据
     * @param int $wait 跳转等待时间
     * @return void
     */
    protected function success($msg = '', $url = null, $data = '', int $wait = 3): void
    {
        $url = $this->getUrl($url);
        $result = [
            'code' => 1,
            'msg'  => L($msg),
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];

        $type = $this->getResponseType();
        if ($type === 'html') {
            $view_path = public_path().'templates'.DS.$this->theme.DS.'html'.DS.'global'.DS.'jump.php';
            if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
                $view_path = public_path().'templates'.DS.$this->theme.DS.'mobile'.DS.'global'.DS.'jump.php';
            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
                $view_path = public_path().'templates'.DS.$this->theme.DS.'wechat'.DS.'global'.DS.'jump.php';
            $view_path = file_exists($view_path) ? $view_path : config('app.dispatch_success_tmpl');
            $response = view($view_path, $result);
        } elseif ($type === 'json') {
            $response = json($result);
        }

        throw new HttpResponseException($response);
    }

    /**
     * 操作错误跳转的快捷方法
     * @access protected
     * @param mixed $msg 提示信息
     * @param null $url 跳转的 URL 地址
     * @param mixed $data 返回的数据
     * @param int $wait 跳转等待时间
     */
    protected function error($msg = '', $url = null, $data = '', int $wait = 3)
    {
        if (is_string($url)) {
            $url = (string)$url;
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : app('route')->buildUrl($url)->__toString();
        }elseif(is_object($url))
        {
            $url = (string)$url;
        }else{
            $url = null;
        }

        $type   = $this->getResponseType();
        $result = [
            'code' => 0,
            'msg'  => L($msg),
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];

        if ($type === 'html') {
            $view_path = public_path().'templates'.DS.$this->theme.DS.'html'.DS.'global'.DS.'jump.php';
            if(get_setting('mobile_enable')=='Y' && ENTRANCE=='mobile')
                $view_path = public_path().'templates'.DS.$this->theme.DS.'mobile'.DS.'global'.DS.'jump.php';
            if(get_setting('wechat_enable')=='Y' && ENTRANCE=='wechat')
                $view_path = public_path().'templates'.DS.$this->theme.DS.'wechat'.DS.'global'.DS.'jump.php';
            $view_path = file_exists($view_path) ? $view_path : config('app.dispatch_success_tmpl');
            $response = view($view_path, $result);
        } elseif ($type === 'json') {
            $response = json($result);
        }

        throw new HttpResponseException($response);
    }

}