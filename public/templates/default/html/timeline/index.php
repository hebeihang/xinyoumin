{extend name="$theme_block" /}
{block name="header"}
<style>
    .w-top-img{
        background-image: url('/static/common/image/banner-bg.png');
        background-color: rgba(86,61,124,.9);
        background-size:auto 100%;
        height: 280px;
    }
</style>
<div class="w-top-img">
    {include file="global/nav"}
    <div class="container index-search">
        <div class="row">
            <h2 class="col-12">下载中心</h2>
            <p class="mb-3 w-100" style="color: #eee">免费下载WeCenter学习版体验全部功能！</p>
        </div>
    </div>
</div>
{/block}

{block name="main"}
<div class="container mt-2">
    <div class="">
        <div class="row text-white text-center">
            <dl class="col-md-4 position-relative">
                <dt><img src="{$static_url}images/mf1.png" class="rounded w-100" alt="WeCenter免费版"></dt>
                <dd class="position-absolute w-100" style="top: 1rem;left: 0">
                    <h3 class="text-white text-center">学习免费版</h3>
                    <p>基础功能、仅供学习交流</p>
                    <a href="{:url('timeline/index',['type'=>'stable'])}" class="mt-2 btn btn-outline-light" data-pjax="tabMain">立即下载</a>
                </dd>
            </dl>
            <dl class="col-md-4 position-relative">
                <dt><img src="{$static_url}images/sq2.png" class="rounded w-100" alt="WeCenter商业授权"></dt>
                <dd class="position-absolute w-100" style="top: 1rem;left: 0">
                    <h3 class="text-white text-center">商业授权版（苔知社）</h3>
                    <p>享受持续更新、升级变更内容通知</p>
                    <a href="javascript:;" class="mt-2 btn btn-outline-light aw-ajax-open" data-url="{:url('timeline/contact')}">购买授权</a>
                </dd>
            </dl>
            <dl class="col-md-4 position-relative">
                <dt><img src="{$static_url}images/dz3.png" class="rounded w-100" alt="WeCenter官方定制"></dt>
                <dd class="position-absolute w-100" style="top: 1rem;left: 0">
                    <h3 class="text-white text-center">官方定制服务</h3>
                    <p>个性需求定制开发，正规、专业</p>
                    <a href="javascript:;" class="mt-2 btn btn-outline-light aw-ajax-open" data-url="{:url('timeline/contact')}">立即咨询</a>
                </dd>
            </dl>
            <div class="clear"></div>
        </div>
    </div>
    <div id="tabMain">
        <nav class="bg-white">
            <div class="nav nav-tabs px-3 aw-pjax-a">
                <a class="nav-item nav-link {if $type=='stable'}active{/if}" data-pjax="tabMain" href="{:url('timeline/index')}" > 更新日志 </a>
                {if $user_id && $user_info['beta_user']}
                <a class="nav-item nav-link {if $type=='beta'}active{/if}" data-pjax="tabMain" href="{:url('timeline/index',['type'=>'beta'])}"> 测试版 </a>
                {/if}
            </div>
        </nav>

        {if $user_id && !$user_info['beta_user']}
        <div class="p-3 bg-white mb-1">
            <a class="btn btn-secondary btn-sm aw-ajax-open" data-url="{:url('timeline/apply')}" href="javascript:;"> 申请内测 </a>
        </div>
        {/if}
        {if $list}
        {foreach $list as $k=>$v}
        <div class="card border-0">
            <div class="card-header clearfix bg-white">
                <div class="card-header-title float-left">
                    <span class="font-weight-bold font-12"> V{$v.version} {$type=='beta' ? 'Beta版' : '正式版'}</span>
                    <span class="text-muted ml-3">发布于：{:date('Y-m-d',$v['build_time'])}</span>
                </div>
                <div class="download float-right" data-key="{$v.auth_key}">
                    {if $v.full_download_url}
                    <button type="button" data-type="full" class="btn btn-success btn-sm downLoadBtn">完整包</button>
                    {/if}
                    {if $v.download_url}
                    <button type="button" data-type="increase" class="btn btn-warning btn-sm text-white downLoadBtn">增量包</button>
                    {/if}
                </div>
            </div>
            <div class="card-body">
                {$v.description|raw}
            </div>
        </div>
        {/foreach}
        {else/}
        <p class="text-center py-3 text-muted">
            <img src="{$cdnUrl}/static/common/image/empty.svg">
            <span class="d-block">暂无更新日志</span>
        </p>
        {/if}
    </div>
</div>
<script>
    $(document).on('click', '.downLoadBtn', function () {
        var type = $(this).data('type');
        var key = $(this).parent('div').data('key');
        console.log(key);
        $('#attach-name').val(key);
        $('#attachType').val(type);
        $('#attach-download-form').attr('action',baseUrl+'/timeline/download');
        $('#attach-download-form').submit();
    })
</script>
{/block}