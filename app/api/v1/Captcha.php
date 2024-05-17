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

namespace app\api\v1;

use app\common\controller\Api;

class Captcha extends Api
{
    protected $s = '';
    protected $mainImg = ''; // 主图
    protected $gapImg = ''; // 缺口图
    protected $expireTime = 360;
    public function initialize()
    {
        parent::initialize();
        $s = $this->request->param('session_id', '');
        if (!preg_match('/^[a-zA-Z0-9_]{0,20}$/', $s)) $this->apiError('错误的请求');
        $this->s = $s;
    }

    // 生成图形验证码
    public function generate()
    {
        @unlink(rtrim(public_path('storage/xlg/'.$this->s.'main.jpg'), DIRECTORY_SEPARATOR));
        @unlink(rtrim(public_path('storage/xlg/'.$this->s.'gap.png'), DIRECTORY_SEPARATOR));
        cache("tuxing_yzm_x{$this->s}", rand(130, 550), $this->expireTime);
        cache("tuxing_yzm_y{$this->s}", rand(50, 260), $this->expireTime);
        cache("tuxing_yzm_img{$this->s}", rtrim(public_path('static/plugins/xlgYzm/style/i/yzm_pic/'.rand(1, 61).'.jpg'), DIRECTORY_SEPARATOR), $this->expireTime);
        cache("tuxing_yzm_moban{$this->s}", rtrim(public_path('static/plugins/xlgYzm/style/i/yzm_pic/moban/'.rand(1, 4).'.png'), DIRECTORY_SEPARATOR), $this->expireTime);
        cache("tuxing_yzm_opacity{$this->s}", rand(30, 80), $this->expireTime);
        cache("tuxing_yzm_time{$this->s}", time() + 300, $this->expireTime);
        cache("tuxing_yzm_error_cishu{$this->s}", 2, $this->expireTime);

        $this->generateImages();

        $this->apiSuccess('验证码已生成', [
            'main' => $this->mainImg,
            'gap' => $this->gapImg
        ]);
    }

    protected function generateImages()
    {
        $x = cache("tuxing_yzm_x{$this->s}");
        $y = cache("tuxing_yzm_y{$this->s}");
        $img = cache("tuxing_yzm_img{$this->s}");
        $moban = cache("tuxing_yzm_moban{$this->s}");
        $opacity = cache("tuxing_yzm_opacity{$this->s}");
        //创建源图的实例
        $src = imagecreatefromstring(file_get_contents($img));
        //新建一个真彩色图像【尺寸 = 90x90】【目前是不透明的】
        $res_image = imagecreatetruecolor(90, 90);
        //创建透明背景色，主要127参数，其他可以0-255，因为任何颜色的透明都是透明
        $transparent = imagecolorallocatealpha($res_image, 255, 255, 255, 127);
        //指定颜色为透明（做了移除测试，发现没问题）
        imagecolortransparent($res_image, $transparent);
        //填充图片颜色【填充会将相同颜色值的进行替换】
        imagefill($res_image, 0, 0, $transparent);//左边的半圆

        //实现两个内凹槽【填补上纯黑色】
        $tempImg = imagecreatefrompng($moban);//加载模板图
        for($i=0; $i < 90; $i++){// 遍历图片的像素点
            for ($j=0; $j < 90; $j++) {
                if(imagecolorat($tempImg, $i, $j) !== 0){// 获取模板上某个点的色值【取得某像素的颜色索引值】【0 = 黑色】
                    $rgb = imagecolorat($src, $x + $i, $y + $j);// 对应原图上的点
                    imagesetpixel($res_image, $i, $j, $rgb);// 移动到新的图像资源上
                }
            }
        }

        //制作一个半透明白色蒙版
        $mengban = imagecreatetruecolor(90, 90);
        //先让蒙版变成透明的
        //指定颜色为透明（做了移除测试，发现没问题）
        imagecolortransparent($mengban, $transparent);
        //填充图片颜色【填充会将相同颜色值的进行替换】
        imagefill($mengban, 0, 0, $transparent);
        $huise = imagecolorallocatealpha($res_image, 255, 255, 255, $opacity);
        for($i=0; $i < 90; $i++){// 遍历图片的像素点
            for ($j=0; $j < 90; $j++) {
                $rgb = imagecolorat($res_image, $i, $j); // 获取模板上某个点的色值【取得某像素的颜色索引值】
                if($rgb !== 2147483647){// 获取模板上某个点的色值【取得某像素的颜色索引值】【0 = 黑色】
                    imagesetpixel($mengban, $i, $j, $huise);// 对应点上画上黑色
                }
            }
        }
        //把修改后的图片，放回原本的位置
        imagecopyresampled(
            $src,//裁剪后的存放图片资源
            $res_image,//裁剪的原图资源
            $x, $y,//存放的图片，开始存放的位置
            0,0,//开始裁剪原图的位置
            90, 90,//存放的原图宽高
            90, 90//裁剪的原图宽高
        );
        //把蒙版添加到原图上去
        imagecopyresampled(
            $src,//裁剪后的存放图片资源
            $mengban,//裁剪的原图资源
            $x+1, $y+1,//存放的图片，开始存放的位置
            0,0,//开始裁剪原图的位置
            90-2, 90-2,//存放的原图宽高
            90, 90//裁剪的原图宽高
        );
        $mainPath = '/storage/xlg/'.$this->s.'main.jpg';
        imagejpeg($src, rtrim(public_path($mainPath), DIRECTORY_SEPARATOR));

        // 缺口图
        //补上白色边框
        $tempImg = imagecreatefrompng($moban.'.png'); // 加载模板图
        $white = imagecolorallocatealpha($res_image, 255, 255, 255, 1);
        for($i=0; $i < 90; $i++){// 遍历图片的像素点
            for ($j=0; $j < 90; $j++) {
                if(imagecolorat($tempImg, $i, $j) === 0){// 获取模板上某个点的色值【取得某像素的颜色索引值】【0 = 黑色】
                    imagesetpixel($res_image, $i, $j, $white);// 对应点上画上黑色
                }
            }
        }
        //创建一个90x382宽高 且 透明的图片
        $res_image2 = imagecreatetruecolor(90, 382);
        //指定颜色为透明（做了移除测试，发现没问题）
        imagecolortransparent($res_image2, $transparent);
        //填充图片颜色【填充会将相同颜色值的进行替换】
        imagefill($res_image2, 0, 0, $transparent);//左边的半圆
        //把裁剪的图片，移到新图片上
        imagecopyresampled(
            $res_image2,//裁剪后的存放图片资源
            $res_image,//裁剪的原图资源
            0, $y,//存放的图片，开始存放的位置
            0, 0,//开始裁剪原图的位置
            90, 90,//存放的原图宽高
            90, 90//裁剪的原图宽高
        );
        $gapPath = '/storage/xlg/'.$this->s.'gap.png';
        imagepng($res_image2, rtrim(public_path($gapPath), DIRECTORY_SEPARATOR));

        $this->gapImg = $this->request->domain().$gapPath.'?t='.time();
        $this->mainImg = $this->request->domain().$mainPath.'?t='.time();
    }

    // 验证
    public function check()
    {
        $cacheX = cache("tuxing_yzm_x{$this->s}");
        $cacheExpire = cache("tuxing_yzm_time{$this->s}");
        $cacheTimes = cache("tuxing_yzm_error_cishu{$this->s}");
        if (!($cacheX && $cacheExpire))  $this->apiSuccess('请先获取图形验证码');

        if ($cacheExpire <= time()) $this->apiSuccess('验证码已过期，有效期为5分钟');
        $x = $this->request->post('x');
        if (!preg_match('/^[0-9]{1,4}$/', $x)) $this->apiSuccess('验证错误');
        if ($x <= $cacheX + 4 && $x >= $cacheX - 4){// 左右两边都有4px的包容度
            $this->apiSuccess('');
        } else {
            if ($cacheTimes == 0)  $this->apiSuccess('验证码错误次数过多，请重新获取');
            cache("tuxing_yzm_error_cishu{$this->s}", $cacheTimes - 1);
            $this->apiSuccess('验证错误');
        }
    }

    // 清除
    public function clear()
    {
        cache("tuxing_yzm_x{$this->s}", null);
        cache("tuxing_yzm_y{$this->s}", null);
        cache("tuxing_yzm_img{$this->s}", null);
        cache("tuxing_yzm_moban{$this->s}", null);
        cache("tuxing_yzm_opacity{$this->s}", null);
        cache("tuxing_yzm_time{$this->s}", null);
        cache("tuxing_yzm_error_cishu{$this->s}", null);
        @unlink(rtrim(public_path('storage/xlg/'.$this->s.'main.jpg'), DIRECTORY_SEPARATOR));
        @unlink(rtrim(public_path('storage/xlg/'.$this->s.'gap.png'), DIRECTORY_SEPARATOR));
        $this->apiSuccess('清除成功');
    }
}
