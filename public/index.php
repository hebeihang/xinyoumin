<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;
define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', dirname(__DIR__) . DS);
define('G_PRIVATEKEY','pvjxzjmzvewfscft');
define('G_IV', 'qvibvaiwgouxwjeu');

require __DIR__ . '/../vendor/autoload.php';
// 判断是否安装程序
if (!is_file(ROOT_PATH . 'install' . DS . 'lock' . DS . 'install.lock')) {
    header("location:./install.php");exit;
}
// 执行HTTP应用并响应
$http = (new App())->http;
$response = $http->run();
$response->send();
$http->end($response);
