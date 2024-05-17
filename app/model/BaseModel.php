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

namespace app\model;
use think\facade\Request;
use think\Model;

class BaseModel extends Model
{
	//错误信息
	public static $error;
	public static $dbName;
	public static $isMobile;
    public static $userId;

	public function __construct(array $data = [])
	{
		parent::__construct($data);
		self::$isMobile = Request::isMobile();
        self::$userId = intval(session('login_uid'));
	}

	/**
	 * 设置错误信息
	 * @param $error
	 * @return mixed
	 */
	public static function setError($error) {
		return self::$error = L($error);
	}

	/**
	 * 获取错误信息
	 * @return mixed
	 */
	public static function getError() {
		return self::$error;
	}
}