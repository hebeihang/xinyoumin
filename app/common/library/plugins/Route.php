<?php
declare(strict_types=1);

namespace app\common\library\plugins;

use think\facade\Request;
use think\facade\Event;
use think\facade\Config;
use think\exception\HttpException;
use think\helper\Str;

class Route
{
    /**
     * 插件路由请求
     * @return mixed
     */
    public static function execute()
    {
        $app = app();
        $request = $app->request;
        $plugin = $request->route('plugin');
        $controller = $request->route('controller','Index');
        $action = $request->route('action','index');
        Event::trigger('plugins_begin', $request);
        if (empty($plugin) || empty($controller) || empty($action)) {
            throw new HttpException(500, lang('plugin can not be empty'));
        }

        $request->plugin = $plugin;
        // 设置当前请求的控制器、操作
        $request->setController($controller)->setAction($action);

        // 获取插件基础信息
        $info = get_plugins_info($plugin);
        if (!$info) {
            throw new HttpException(404, lang('plugin %s not found', [$plugin]));
        }
        if (!$info['status']) {
            throw new HttpException(500, lang('plugin %s is disabled', [$plugin]));
        }

        // 监听plugin_module_init
        Event::trigger('plugin_module_init', $request);
        $layer = self::getLayer();
        $class = get_plugins_class($plugin, $layer, $controller);

        if (!$class) {
            throw new HttpException(404, lang('plugin controller %s not found', [$controller]));
        }

        // 重写视图基础路径
        $config = Config::get('view');
        $config['view_path'] = $app->plugins->getpluginsPath() . $plugin . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR.$layer.DIRECTORY_SEPARATOR;
        if($layer=='controller'){
            $config['view_path'] = $app->plugins->getpluginsPath() . $plugin . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        }
        Config::set($config, 'view');

        // 生成控制器对象
        $instance = new $class($app);
        $vars = [];
        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
        } elseif (is_callable([$instance, '_empty'])) {
            // 空操作
            $call = [$instance, '_empty'];
            $vars = [$action];
        } else {
            // 操作不存在
            throw new HttpException(404, lang('plugin action %s not found', [get_class($instance).'->'.$action.'()']));
        }
        Event::trigger('plugins_action_begin', $call);
        return call_user_func_array($call, $vars);
    }

    private static function getLayer()
    {
        //接口请求需定义ApiToken
        $AccessToken = request()->header('ApiToken');
        $apiEnable = false;
        $client = authCode($AccessToken);
        $plugin = request()->route('plugin');
        $isClient = db('app_token')
            ->where(['token' => $client,'plugin'=>$plugin,'type'=>2])
            ->whereOr(['token' => $AccessToken,'plugin'=>$plugin,'type'=>2])
            ->value('id');
        if (($client && $isClient) || ($AccessToken && $isClient))
        {
            $apiEnable = true;
        }
        //手机wap
        if(Request::isMobile() && !$apiEnable && get_setting('mobile_enable')=='Y')
        {
            $layer='mobile';
        }elseif (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false && !$apiEnable && get_setting('wechat_enable')=='Y') {
            $layer='wechat';
        }elseif($apiEnable)
        {
            $layer='api';
        }elseif(strstr(Request::url(),app()->config->get('app.admin'))!==false)
        {
            $layer='backend';
        }else{
            $layer='controller';
        }
        $controller = request()->route('controller','Index');
        $controller = Str::title($controller);
        //兼容老版本的插件控制器
        $namespace = '\\plugins\\' . $plugin . '\\'.$layer.'\\' . $controller;
        return class_exists($namespace)?$layer:'controller';
    }
}