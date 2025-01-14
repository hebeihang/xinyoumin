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

namespace plugins\sms;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use app\common\library\helper\DataHelper;
use app\common\library\helper\IpHelper;
use Darabonba\OpenApi\Models\Config;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use TencentCloud\Common\Credential;
use TencentCloud\Sms\V20190711\Models\SendSmsRequest as TencentSendSmsRequest;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sms\V20190711\SmsClient;
use think\facade\Cache;
use app\common\controller\Plugins;

class Plugin extends Plugins
{
    public function sms($param=[])
    {
        $smsConfig = $this->getConfig();
        if($smsConfig['base']['enable']=='N')
        {
            return json_encode(['code'=>0,'msg'=>L('短信功能未启用')], JSON_UNESCAPED_UNICODE);
        }

        //阿里云短信
        if($smsConfig['base']['enable']=='ali')
        {
            $config = new Config([
                "accessKeyId" =>  $smsConfig['ali']['AccessKeyId'],
                "accessKeySecret" => $smsConfig['ali']['AccessKeySecret']
            ]);
            $code = rand(100000,999999);
            $cache_code = Cache::get('sms_'.$param['mobile']);
            // 访问的域名
            $config->endpoint = $smsConfig['ali']['Endpoint'] ?: "dysmsapi.aliyuncs.com";
            $client = new Dysmsapi($config);
            $sendSmsRequest = new SendSmsRequest([
                "phoneNumbers" => $param['mobile'],
                "signName" => $smsConfig['ali']['SignName'],
                "templateCode" => $smsConfig['ali']['TemplateCode'],
                "templateParam" => json_encode(array('code'=>$code), JSON_UNESCAPED_UNICODE),
            ]);

            if(!$cache_code)
            {
                $result = $client->sendSms($sendSmsRequest);
                $res = DataHelper::objToArray($result->body);
                if($res['code']=='OK')
                {
                    //5分钟有效
                    Cache::set('sms_'.$param['mobile'],$code,60*5);
                    //插入短信记录
                    db('sms_log')->insert([
                        'mobile'=>$param['mobile'],
                        'send_type'=>'阿里云短信',
                        'template_code'=>$smsConfig['ali']['TemplateCode'],
                        'content'=>$code,
                        'ip'=>IpHelper::getRealIp(),
                        'create_time'=>time()
                    ]);
                    return json_encode(['code'=>1,'msg'=>L('验证码发送成功'),'data'=>['mobile'=>$param['mobile']]], JSON_UNESCAPED_UNICODE);
                }
                //return $res['message'];
                return json_encode(['code'=>0,'msg'=>$res['message']], JSON_UNESCAPED_UNICODE);
            }

            return json_encode(['code'=>0,'msg'=>L('验证码5分钟内有效，请使用收到的验证码进行填写')],JSON_UNESCAPED_UNICODE);
        }

        //腾讯云短信
        if($smsConfig['base']['enable']=='tencent')
        {
            // 短信应用SDK AppID
            $appId = $smsConfig['tencent']['AccessKeyId']; // 1400开头
            $SecretId = $smsConfig['tencent']['SecretId'];
            // 短信应用SDK AppKey
            $appKey = $smsConfig['tencent']['AccessKeySecret'];
            // 签名
            $smsSign = $smsConfig['tencent']['SignName'];
            // 模板id
            $templateCode = $smsConfig['tencent']['TemplateCode'];

            try {
                $cred = new Credential($SecretId,  $appKey);
                // 实例化一个 http 选项，可选，无特殊需求时可以跳过
                $httpProfile = new HttpProfile();
                $httpProfile->setEndpoint("sms.tencentcloudapi.com");
                // 实例化一个 client 选项，可选，无特殊需求时可以跳过
                $clientProfile = new ClientProfile();
                $clientProfile->setSignMethod("HmacSHA256"); // 指定签名算法（默认为 HmacSHA256）
                $clientProfile->setHttpProfile($httpProfile);

                // 实例化 SMS 的 client 对象，clientProfile 是可选的
                $client = new SmsClient($cred, "", $clientProfile);

                // 实例化一个 sms 发送短信请求对象，每个接口都会对应一个 request 对象。
                $req = new TencentSendSmsRequest();

                /* 短信应用 ID: 在 [短信控制台] 添加应用后生成的实际 SDKAppID，例如1400006666 */
                $req->SmsSdkAppid = $appId;
                /* 短信签名内容: 使用 UTF-8 编码，必须填写已审核通过的签名，可登录 [短信控制台] 查看签名信息 */
                $req->Sign = $smsSign;
                /* 短信码号扩展号: 默认未开通，如需开通请联系 [sms helper] */
                $req->ExtendCode = "0";
                /* 下发手机号码，采用 e.164 标准，+[国家或地区码][手机号]
                   * 例如+8613711112222， 其中前面有一个+号 ，86为国家码，13711112222为手机号，最多不要超过200个手机号*/
                $req->PhoneNumberSet = ['+86'.$param['mobile']];
                /* 国际/港澳台短信 senderid: 国内短信填空，默认未开通，如需开通请联系 [sms helper] */
                $req->SenderId = "";
                /* 用户的 session 内容: 可以携带用户侧 ID 等上下文信息，server 会原样返回 */
                $req->SessionContext = "";
                /* 模板 ID: 必须填写已审核通过的模板 ID。可登录 [短信控制台] 查看模板 ID */
                $req->TemplateID = $templateCode;
                /* 模板参数: 若无模板参数，则设置为空*/
                $code = rand(100000,999999);
                $req->TemplateParamSet = array($code,'5');
                $cache_code = Cache::get('sms_'.$param['mobile']);
                // 通过 client 对象调用 SendSms 方法发起请求。注意请求方法名与请求对象是对应的
                if(!$cache_code) {
                    $resp = $client->SendSms($req);
                    $resp = $resp->toJsonString();
                    $rsp = json_decode($resp, true);
                    if ($rsp['SendStatusSet'][0]['Code'] == 'Ok') {
                        Cache::set('sms_'.$param['mobile'],$code,60*5);
                        //插入短信记录
                        db('sms_log')->insert([
                            'mobile' => $param['mobile'],
                            'send_type' => '腾讯云短信',
                            'template_code' => $templateCode,
                            'content' => $code,
                            'ip' => IpHelper::getRealIp(),
                            'create_time' => time()
                        ]);
                        return json_encode(['code' => 1, 'msg' => L('短信发送成功')], JSON_UNESCAPED_UNICODE);
                    } else {
                        return json_encode(['code' => 0, 'msg' => L('短信发送失败')], JSON_UNESCAPED_UNICODE);
                    }
                }
                return json_encode(['code'=>0,'msg'=>L('验证码5分钟内有效，请使用收到验证码进行填写')],JSON_UNESCAPED_UNICODE);
            }
            catch(TencentCloudSDKException $e) {
                return json_encode(['code'=>0,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
        }
        return '';
    }
}