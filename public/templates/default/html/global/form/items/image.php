<div class="form-group {$form[type].extra_class|default=''}" id="form_group_{$form[type].name}">
    <label class="control-label" for="{$form[type].name}">{$form[type].title|htmlspecialchars}:{notempty name="form[type].required"}(<span class="text-danger ">*</span>){/notempty}</label>
    <div class="row">
        <div class="col-{if $form[type].tips}8{else /}7{/if} col-md-4 col-lg-4">
            <input class="form-control {notempty name="form[type].required"}required{/notempty}" type="text" id="{$form[type].name}" name="{$form[type].name}" value="{$form[type].value}" placeholder="{$form[type].placeholder}" {$form[type].extra_attr|raw}>
        </div>
        <div class="col-12 col-md-6 col-lg-6">
            <div id="fileList_{$form[type].name}" class="uploader-list"></div>
            <div id="filePicker_{$form[type].name}"><i class="fa fa-upload m-r-10"></i> {:L('选择图片')}</div>
            <a href="javascript:;">
                <img class="image_preview_info" src="{$form[type].value ?: '/static/common/image/default-cover.svg'}" id="{$form[type].name}_preview">
            </a>
        </div>
    </div>
    {notempty name="form[type].tips"}
    <div class="mt-1" style="font-size:0.9rem;display: block;color: #dc3545;">
        {$form[type].tips|raw}
    </div>
    {/notempty}
</div>
<script type="text/javascript">
    upload('fileList_{$form[type].name}', 'filePicker_{$form[type].name}', '{$form[type].name}_preview', '{$form[type].name}', false, '{$form[type].ext|default=""}', '{$form[type].size|default="0"}','img',"{$form[type].path|default='common'}");
</script>

