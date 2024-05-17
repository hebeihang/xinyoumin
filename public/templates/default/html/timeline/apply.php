{extend name="$theme_block" /}
{block name="main"}
<div class="p-3 bg-white">
    <form action="" method="post">
        <div class="form-group">
            <label class="control-label font-weight-bold">您的身份</label>
            <div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="beta_type" id="inlineRadio1" value="developer">
                    <label class="form-check-label" for="inlineRadio1">{:L('开发者')}</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="beta_type" id="inlineRadio2" value="operations">
                    <label class="form-check-label" for="inlineRadio2">{:L('运营人员')}</label>
                </div>

                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="beta_type" id="inlineRadio2" value="other">
                    <label class="form-check-label" for="inlineRadio2">{:L('其他')}</label>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label font-weight-bold">当前正在使用WeCenter的站点</label>
            <input type="text" class="form-control" name="website">
        </div>

        <div class="form-group">
            <label class="control-label font-weight-bold">申请理由</label>
            <textarea name="reason" class="form-control" rows="4"></textarea>
        </div>

        <div class="form-group clearfix">
            <a href="javascript:;" class="btn btn-primary btn-sm float-right aw-ajax-form">提交申请</a>
        </div>
    </form>
</div>
{/block}