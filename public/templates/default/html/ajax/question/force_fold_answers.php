{if $list}
{foreach $list as $k=>$v}
<div class="aw-answer-item p-3 mb-1 bg-white" data-uninterested_count="{$v.uninterested_count}" id="question-answer-{$v.id}" data-answer-id="{$v.id}">
    <div class="user-details-card pt-0 pb-2 position-relative clearfix">
        <div class="user-details-card-avatar float-left" style="position: relative">
            {if $v.is_anonymous}
            <a href="javascript:;">
                <img src="/static/common/image/default-avatar.svg" class="rounded" alt="{:L('匿名用户')}" data-toggle="popover" title="{:L('匿名用户')}" style="width: 40px;height: 40px">
            </a>
            {else/}
            <a href="{$v['user_info']['url']}" class="aw-username" data-id="{$v.uid}" title="{$v['user_info']['name']}">
                <img src="{$v['user_info']['avatar']}" class="rounded" onerror="this.src='/static/common/image/default-avatar.svg'" alt="{$v['user_info']['name']}" style="width: 40px;height: 40px">
                {if $v['user_info']['verified']}
                <img src="{$v['user_info']['verified_icon']}" width="16" height="16" class="verifiedInfo position-absolute">
                {/if}
            </a>
            {/if}
        </div>
        <div class="user-details-card-name float-left ml-2">
            {if $v.is_anonymous}<a href="javascript:;" data-toggle="popover" title="{:L('匿名用户')}">{:L('匿名用户')}</a>{else/}<a href="{$v['user_info']['url']}" data-id="{$v.uid}" class="aw-username" title="{$v['user_info']['name']}">{$v['user_info']['name']}</a> <span class="badge badge-success">{$v['user_info']['group_name']|default=''}</span> {/if} {if $setting.show_answer_user_ip=='Y' && $v.answer_user_local}<span class="ml-2 text-muted font-8">{:L('用户来自于')}: {$v.answer_user_local}</span>{/if} <br><span class="ml-0"> {:date_friendly($v['create_time'])} </span>
        </div>

        {if $v['is_best']}
        <div class="aw-answer-best">
            <i class="fa fa-award " title="{:L('最佳回答')}"></i>
        </div>
        {/if}
    </div>

    <div class="aw-content">
        <div class="aw-answer-content overflow-hidden">
            {:html_entity_decode($v.content)}
        </div>
        {if $v.content}
        <div class="aw-answer-show aw-alpha-hidden" style="display: none">
            <span style="cursor: pointer;"><i class="icon-chevrons-down"></i> {:L('阅读全文')}</span>
        </div>
        <div class="aw-answer-hide aw-alpha-hidden mt-3" style="display: none;background:none;position: inherit;height: auto">
            <span style="position: unset;float: left;cursor: pointer"><i class="icon-chevrons-up"></i> {:L('收起全文')}</span>
        </div>
        {/if}
    </div>

    <!--回答操作-->
    <div class="answer-btn-actions mt-3">
        <label class="mr-1">
            <a href="javascript:;" class="aw-ajax-agree {if $v['vote_value']==1}active{/if}"  onclick="AWS.User.agree(this,'answer','{$v.id}');">
                <i class="icon-thumb_up"></i> {:L('赞同')} <span> {$v.agree_count}</span>
            </a>
        </label>

        <label class="mr-3 ">
            <a href="javascript:;" class="aw-ajax-against {if $v['vote_value']==-1}active{/if}"  onclick="AWS.User.against(this,'answer','{$v.id}');">
                <i class="icon-thumb_down"></i>
            </a>
        </label>

        <label class="mr-3">
            <a href="javascript:;" class="answerCommentBtn" data-url="{:url('comment/answer',['id'=>$v.id])}" data-id="{$v.id}">
                <i class="icon-chat"></i> <span class="answer-comment-count{$v.id}">{$v.comment_count}</span>{:L('评论')}
            </a>
        </label>

        {if $user_id}
        <label class="mr-3">
            <a href="javascript:;" {if !$v.checkReport} onclick="AWS.User.report(this,'answer','{$v.id}')"{/if} ><i class="icon-warning"></i>{$v.checkReport ? L('已举报') : L('举报')}</a>
        </label>

        <label class="mr-3">
            <a href="javascript:;" onclick="AWS.User.favorite(this,'answer','{$v.id}')"><i class="icon-turned_in"></i>{if $v.checkFavorite} {:L('已收藏')}{else}{:L('收藏')}{/if}</a>
        </label>

        {if $user_id!=$v['uid']}
        <label class="mr-3">
            <a href="javascript:;"  {if $v.has_thanks} class="active" {else/}onclick="AWS.User.thanks(this,'{$v.id}')"{/if}>
            <i class="icon-favorite"></i> <span>{$v.has_thanks ? L('已感谢') : L('感谢')}</span>
            </a>
        </label>
        {/if}

        {if !$v.has_uninterested}
        <label class="mr-3">
            <a href="javascript:;" onclick="AWS.User.uninterested(this,'answer','{$v.id}')">
                <i class="icon-report"></i> {:L('不感兴趣')}
            </a>
        </label>
        {/if}

        {if isSuperAdmin() || isNormalAdmin() || (get_setting('uninterested_fold')<=$v.uninterested_count && $user_id==$question_info['uid'])}
        <label class="mr-3">
            <a href="javascript:;" data-url="{:url('answer/force')}" onclick="AWS.User.forceFoldAnswer(this,'{$v.id}')">
                <i class="fa fa-cut "></i> <span>{:L($v.force_fold?'取消折叠':'折叠')}</span>
            </a>
        </label>
        {/if}

        {/if}

        <div class="dropdown d-inline-block mr-3">
            <a href="javascript:;" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fa fa-fw fa-share-alt font-9 d-sm-inline-block"></i> {:L('分享')}
            </a>
            <div class="dropdown-menu p-0 border-0 font-size-sm">
                <div class="text-center d-block py-2" style="min-width: 100px">
                    <a href="javascript:;"  class="dropdown-item aw-clipboard" data-clipboard-text="{:url('question/detail',['answer'=>$v.id,'id'=>$question_info.id],true,true)}"><i class="icon-link"></i>
                        {:L('复制链接')}</a>
                    <a href="javascript:;" onclick="AWS.User.share('{$question_info.title}','{:url('question/detail',['answer'=>$v.id,'id'=>$question_info.id],true,true)}','','weibo')" class="dropdown-item "><i class="fab fa-weibo text-warning"></i>
                        {:L('新浪微博')}</a>
                    <a href="javascript:;" onclick="AWS.User.share('{$question_info.title}','{:url('question/detail',['answer'=>$v.id,'id'=>$question_info.id],true,true)}','','qzone')" class="dropdown-item "><i class="fab fa-qq text-primary"></i>
                        {:L('腾讯空间')}</a>
                    <div class="aw-qrcode-container" data-share="{:url('question/detail',['answer'=>$v.id,'id'=>$question_info.id],true,true)}">
                        <a href="javascript:;" class="dropdown-item "><i class="fab fa-weixin text-success"></i>
                            {:L('微信扫一扫')}</a>
                        <div class="aw-qrcode text-center py-2"></div>
                    </div>
                </div>
            </div>
        </div>
        <!--问题回答操作栏钩子-->
        {:hook('question_answer_bottom_action',$v)}

        <div class="aw-share clearfix d-inline-block">
            <div class="social-share" data-disabled="google,twitter,facebook,linkedin,douban"></div>
        </div>
        {if $user_id}
        <div class="dropdown d-inline-block">
            <a href="javascript:;" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fa fa-ellipsis-h d-none d-sm-inline-block"></i>
            </a>
            <div class="dropdown-menu p-0 border-0 font-size-sm">
                <div class="text-center d-block py-2" style="min-width: 100px">
                    {if $user_info['uid']==$v['uid'] || get_user_permission('modify_answer')=='Y'}
                    <a href="javascript:;"  class="dropdown-item awsAnswerEditor" data-question-id="{$v.question_id}" data-answer-id="{$v['id']}">{:L('编辑')}</a>
                    {/if}
                    {if $user_info['uid']==$v['uid'] || get_user_permission('remove_answer')=='Y'}
                    <a href="javascript:;" title="{:L('删除回答')}" class="dropdown-item aw-ajax-get" data-confirm="{:L('是否删除该回答')}?" data-url="{:url('answer/delete_answer?answer_id='.$v['id'])}">{:L('删除')}</a>
                    {/if}
                </div>
            </div>
        </div>
        {/if}
    </div>

    <!--评论框动态显示-->
    <div class="answerCommentBox mt-2 border" id="answerCommentBox-{$v.id}" style="display: none;margin-left: 45px">
        <div class="answerCommentHeader clearfix px-3 pt-3">
            <h6 class="font-10 float-left mb-1"><span class="answer-comment-count{$v.id}">{$v.comment_count}</span> {:L('评论')}</h6>
        </div>
        <div class="answerCommentList px-3"></div>
        <div class="pageElement"></div>
        <div class="commentForm clearfix rounded aw-replay-box"></div>
    </div>
</div>
{/foreach}
{/if}