{extend name="$theme_block" /}
{block name="header"}
<header class="aui-header">
    <div class="aui-header-title">{:L('重置密码')}</div>
</header>
{/block}
{block name="main"}
<div class="main-container mt-1">
    <div class="bg-white mb-1">
        <form action="" method="POST">
            <div class="block mb-0">
                <div class="block-content tab-content p-3 bg-white" id="tabMain">
                    <input type="hidden" name="token" value="{:token()}" />
                    <input type="hidden" name="active_code" value="{$active_code}">
                    <div class="form-group bline">
                        <input type="password" class="aw-form-control" name="password" placeholder="{:L('请输入新的密码')}">
                    </div>
                    <div class="form-group bline">
                        <input type="password" class="aw-form-control" name="re_password" placeholder="{:L('请再次输入新的密码')}">
                    </div>

                    <div class="form-group newbut" style=" margin-top:30px;">
                        <button type="button" class="btn btn-block btn-primary login-button aw-ajax-form">{:L('立即提交')}</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
{/block}
{block name="footer"}{/block}
