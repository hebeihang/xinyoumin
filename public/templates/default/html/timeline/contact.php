{extend name="$theme_block" /}
{block name="main"}
<div class="p-3 bg-white">
    <div class="alert-danger alert">
        <p class="mb-2">
            授权相关请联系销售顾问咨询或留下您的联系方式，我们尽快与您联系
        </p>
        <dl>
            <dt>400电话</dt>
            <dd>400-0800-558</dd>
        </dl>

        <dl class="mb-0">
            <dt>客服QQ</dt>
            <dd>3020438181</dd>
        </dl>
    </div>
    <form action="" method="post">
        <div class="form-group">
            <label class="control-label font-weight-bold">咨询版本</label>
            <div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="authorize_type" id="inlineRadio1" value="1">
                    <label class="form-check-label" for="inlineRadio1">{:L('个人授权版')}</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="authorize_type" id="inlineRadio2" value="2">
                    <label class="form-check-label" for="inlineRadio2">{:L('企业授权版')}</label>
                </div>

                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="authorize_type" id="inlineRadio2" value="3">
                    <label class="form-check-label" for="inlineRadio2">{:L('旗舰授权版')}</label>
                </div>

                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="authorize_type" id="inlineRadio2" value="4">
                    <label class="form-check-label" for="inlineRadio2">{:L('定制开发')}</label>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label font-weight-bold">联系方式</label>
            <input type="text" class="form-control" name="phone" placeholder="留下您的联系方式我们尽快与您取得联系">
        </div>

        <div class="form-group">
            <label class="control-label font-weight-bold">其他说明</label>
            <textarea name="reason" class="form-control" rows="4"></textarea>
        </div>
        <div class="form-group clearfix">
            <a href="javascript:;" class="btn btn-primary btn-sm float-right aw-ajax-form">提交</a>
        </div>
    </form>
</div>
{/block}