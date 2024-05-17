{__NOLAYOUT__}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8" http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>404-{:L('对不起')}！{:L('您访问的页面不存在')}</title>
    <style type="text/css">
        .head404{ width:580px; height:234px; margin:50px auto 0 auto; background:url(/static/common/image/head404.png) no-repeat; }
        .txtbg404{ width:499px; height:169px; margin:10px auto 0 auto; background:url(/static/common/image/txtbg404.png) no-repeat;}
        .txtbg404 .txtbox{ width:390px; position:relative; top:30px; left:60px;color:#eee; font-size:13px;}
        .txtbg404 .txtbox p {margin:5px 0; line-height:18px;}
        .txtbg404 .txtbox .paddingBox { padding-top:15px;}
        .txtbg404 .txtbox p a { color:#eee; text-decoration:none;}
        .txtbg404 .txtbox p a:hover { color:#FC9D1D; text-decoration:underline;}
    </style>
</head>
<body bgcolor="#494949">
<div class="head404"></div>
<div class="txtbg404">
    <div class="txtbox">
        <p>{:L('对不起')}，{:L('您请求的页面不存在')}、{:L('或已被删除')}、{:L('或暂时不可用')}</p>
        <p class="paddingBox">{:L('请点击以下链接继续浏览网页')}</p>
        <p>> <a style="cursor:pointer" href="javascript:;" onclick="history.back()">{:L('返回上一页面')}</a></p>
        <p>> <a href="/">{:L('返回网站首页')}</a></p>
    </div>
</div>
</body>
</html>