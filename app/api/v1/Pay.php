<?php
namespace app\api\v1;
use app\common\controller\Api;
use plugins\pay\library\PayHelper;
use plugins\pay\model\OrderDetail;

class Pay extends Api
{
    private $pay_config;
    public function initialize()
    {
        parent::initialize();
        $this->pay_config = get_plugins_config('pay');
    }

    public function checkProvider()
    {
        $result = [];
        if($this->pay_config){
            $result['alipay']= $this->pay_config['alipay']['enable']=='Y';
            $result['wechat']= $this->pay_config['wechat']['enable']=='Y';
            $result['balance']= $this->pay_config['balance']['enable']=='Y';
        }

        $this->apiResult($result);
    }

    //选择支付方式
    public function createOrder()
    {
        if($this->request->isPost())
        {
            if($this->pay_config['alipay']['enable']=='N' && $this->pay_config['wechat']['enable']=='N' && $this->pay_config['balance']['enable']=='N'){
                $this->apiError('支付类型未配置');
            }

            $postData = $this->request->post();

            if(!$order_info = OrderDetail::insertOrder($this->user_id,$postData))
            {
                return false;
            }
            $this->apiResult($order_info);
        }
    }

    //二维码支付
    public function qrcode()
    {
        $order_id = $this->request->param('order_id',0);

        if(!$order_id)
        {
            $this->result([],0,'订单不存在');
        }
        $pay_type = $this->request->param('pay_type');
        $gateway = $this->request->param('gateway','scan');
        $return_url = $this->request->param('return_url');
        $notify_url = $this->request->param('notify_url');
        $order_info = OrderDetail::getOrderInfo($order_id);
        if(!$order_info)
        {
            $this->result([],0,'订单不存在');
        }
        OrderDetail::updateOrder($order_id,['pay_type'=>$pay_type,'gateway'=>$gateway]);

        $options = [
            'body'   => $order_info['title'].'#'.$order_info['relation_id'],
            'out_trade_no'  =>$order_info['out_trade_no'],
            'amount'        => $order_info['amount'],
            'order_id'       => $order_id,
        ];

        $pay_type_text = [
            'wechat'=>'微信',
            'alipay'=>'支付宝'
        ];

        $codeImg = '';

        if($pay_type=='wechat' && !$codeImg = $this->paySdk->wechat('scan',$options))
        {
            $this->error(PayHelper::getError());
        }

        if($pay_type=='alipay' && !$codeImg = $this->paySdk->alipay('scan',$options))
        {
            $this->error(PayHelper::getError());
        }

        $this->assign([
            'img'=>$codeImg,
            'return_url'=>$return_url,
            'notify_url'=>$notify_url,
            'order_id'       => $order_id,
        ]);
        $this->result(['html'=>$this->fetch(),'text'=>$pay_type_text[$pay_type]],1,'');
        return $this->fetch();
    }

    /**
     * 余额支付
     */
    public function balance()
    {
        if(!$this->request->isPost())
        {
            $this->error('支付方式不正确');
        }

        $password = $this->request->post('password');
        $order_id = $this->request->post('order_id');
        $url = $this->request->post('url');
        $user = db('users')->where('uid',$this->user_id)->field('password,salt,deal_password')->find();
        if($this->user_info['deal_password'])
        {
            if(!password_verify(authCode($password),$user['deal_password']))
            {
                $this->error('交易密码不正确');
            }
        }else{
            $password = authCode($password);
            if($user['password']!=compile_password($password,$user['salt']))
            {
                $this->error('用户密码不正确');
            }
        }

        if(!OrderDetail::updateBalanceOrder($order_id))
        {
            $this->error(OrderDetail::getError());
        }

        $this->success('支付成功',$url);
    }
}