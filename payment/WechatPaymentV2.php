<?php

namespace app\service\payment;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

/**
 * 微信支付 V2 版本实现
 * 支持统一下单、订单查询、退款、关闭订单等功能
 */
class WechatPaymentV2 implements PaymentInterface
{
    private $config;
    private $client;

    public function __construct($config)
    {
        $this->config = $config;
        $this->initClient();
    }

    /**
     * 初始化HTTP客户端
     * V2版本不需要复杂的中间件
     */
    private function initClient()
    {
        try {
            $this->validateConfig();
            
            $this->client = new Client([
                'base_uri' => 'https://api.mch.weixin.qq.com/',
                'timeout' => 30,
                'verify' => false, // 可根据需要开启SSL验证
            ]);
            
        } catch (\Exception $e) {
            Log::error('微信支付V2客户端初始化失败', [
                'error' => $e->getMessage(),
                'config_check' => [
                    'app_id' => !empty($this->config['app_id']) ? 'set' : 'not set',
                    'mch_id' => !empty($this->config['mch_id']) ? 'set' : 'not set',
                    'api_key' => !empty($this->config['api_key']) ? 'set' : 'not set',
                    'notify_url' => !empty($this->config['notify_url']) ? 'set' : 'not set',
                ]
            ]);
            throw new \Exception('微信支付V2客户端初始化失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证配置参数
     */
    private function validateConfig()
    {
        $required = ['app_id', 'mch_id', 'api_key', 'notify_url'];
        
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \Exception("微信支付V2配置缺失: {$key}");
            }
        }
    }

    /**
     * 创建扫码支付
     */
    public function createQrPayment(array $orderData): PaymentResult
    {

        try {
            // 参数映射和验证
            $outTradeNo = $orderData['out_trade_no'] ?? $orderData['order_no'] ?? '';
            $amount = $orderData['amount'] ?? 0;
            $description = $orderData['description'] ?? $orderData['subject'] ?? '';
            $expireTime = $orderData['expire_time'] ?? null;

            // 验证必需字段
            $missing = [];
            if (empty($outTradeNo)) {
                $missing[] = 'out_trade_no或order_no(商户订单号)';
            }
            if (empty($amount) || !is_numeric($amount)) {
                $missing[] = 'amount(金额)';
            }
            if (empty($description)) {
                $missing[] = 'description或subject(商品描述)';
            }

            if (!empty($missing)) {
                throw new \Exception('订单数据不完整，缺少字段: ' . implode(', ', $missing));
            }

            // 构建请求参数
            $requestData = [
                'appid' => $this->config['app_id'],
                'mch_id' => $this->config['mch_id'],
                'body' => $description,
                'out_trade_no' => $outTradeNo,
                'total_fee' => (int)$amount, // V2使用分为单位
                'trade_type' => 'NATIVE',
                'notify_url' => $this->config['notify_url'],
                'nonce_str' => $this->generateNonceStr(),
                'spbill_create_ip' => $this->getClientIp(),
            ];
            
            // 添加过期时间（V2格式：yyyyMMddHHmmss）
            if (!empty($expireTime)) {
                $requestData['time_expire'] = date('YmdHis', $expireTime);
            }

            // 生成签名
            $requestData['sign'] = $this->generateSign($requestData);

//            Log::info('微信支付V2请求参数', [
//                'out_trade_no' => $outTradeNo,
//                'amount' => $amount,
//                'request_data' => array_merge($requestData, ['sign' => '***'])
//            ]);

            // 转换为XML并发送请求
            $xmlData = $this->arrayToXml($requestData);

            $response = $this->client->request('POST', 'pay/unifiedorder', [
                'body' => $xmlData,
                'headers' => [
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'User-Agent' => 'WechatPayV2/1.0'
                ]
            ]);

            // 解析XML响应
            $result = $this->parseXmlResponse($response->getBody()->getContents());
            
            // 检查返回结果
            if ($result['return_code'] !== 'SUCCESS') {
                throw new \Exception('微信支付V2请求失败: ' . ($result['return_msg'] ?? '未知错误'));
            }
            
            if ($result['result_code'] !== 'SUCCESS') {
                throw new \Exception('微信支付V2业务失败: ' . ($result['err_code_des'] ?? $result['err_code'] ?? '未知错误'));
            }
            
            if (empty($result['code_url'])) {
                throw new \Exception('微信支付V2返回数据异常，未获取到支付链接');
            }
            
//            Log::info('微信支付V2订单创建成功', [
//                'out_trade_no' => $outTradeNo,
//                'prepay_id' => $result['prepay_id'] ?? '',
//                'code_url' => $result['code_url']
//            ]);
            
            return PaymentResult::success(
                '微信支付V2订单创建成功',
                $result,
                $result['code_url'], // 二维码链接
                $outTradeNo,
                $result['prepay_id'] ?? null,
                $amount / 100, // 转换为元
                'pending'
            );

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            
            Log::error('微信支付V2网络请求失败', [
                'http_status' => $statusCode,
                'error_message' => $e->getMessage(),
                'error_body' => $errorBody,
                'out_trade_no' => $outTradeNo ?? ''
            ]);
            
            return PaymentResult::fail('微信支付V2网络请求失败: ' . $e->getMessage(), [
                'http_status' => $statusCode,
                'error_body' => $errorBody
            ]);
            
        } catch (\Exception $e) {
            Log::error('微信支付V2创建失败', [
                'error' => $e->getMessage(),
                'out_trade_no' => $outTradeNo ?? ''
            ]);
            
            return PaymentResult::fail('微信支付V2创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $orderNo): PaymentResult
    {
        try {
            $requestData = [
                'appid' => $this->config['app_id'],
                'mch_id' => $this->config['mch_id'],
                'out_trade_no' => $orderNo,
                'nonce_str' => $this->generateNonceStr(),
            ];
            
            $requestData['sign'] = $this->generateSign($requestData);
            
            $xmlData = $this->arrayToXml($requestData);
            $response = $this->client->request('POST', 'pay/orderquery', [
                'body' => $xmlData,
                'headers' => ['Content-Type' => 'application/xml; charset=utf-8']
            ]);
            
            $result = $this->parseXmlResponse($response->getBody()->getContents());
            
            if ($result['return_code'] !== 'SUCCESS') {
                throw new \Exception('查询失败: ' . ($result['return_msg'] ?? '未知错误'));
            }
            
            return PaymentResult::success(
                '查询订单成功',
                $result,
                null,
                $orderNo,
                $result['transaction_id'] ?? null,
                ($result['total_fee'] ?? 0) / 100,
                $this->mapTradeState($result['trade_state'] ?? '')
            );

        } catch (\Exception $e) {
            Log::error('微信支付V2查询订单失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return PaymentResult::fail('查询订单失败: ' . $e->getMessage());
        }
    }

    /**
     * 申请退款
     */
    public function refund(array $refundData): PaymentResult
    {
        try {
            $requestData = [
                'appid' => $this->config['app_id'],
                'mch_id' => $this->config['mch_id'],
                'out_trade_no' => $refundData['out_trade_no'],
                'out_refund_no' => $refundData['out_refund_no'],
                'total_fee' => $refundData['total_amount'],
                'refund_fee' => $refundData['refund_amount'],
                'refund_desc' => $refundData['reason'] ?? '商户退款',
                'nonce_str' => $this->generateNonceStr(),
            ];
            
            $requestData['sign'] = $this->generateSign($requestData);
            
            $xmlData = $this->arrayToXml($requestData);
            
            // 退款需要使用证书
            $options = [
                'body' => $xmlData,
                'headers' => ['Content-Type' => 'application/xml; charset=utf-8']
            ];
            
            // 如果配置了证书路径，添加证书验证
            if (!empty($this->config['cert_path']) && !empty($this->config['key_path'])) {
                $options['cert'] = $this->config['cert_path'];
//                $options['ssl_key'] = $this->config['key_path'];
            }
            $response = $this->client->request('POST', 'secapi/pay/refund', $options);
            $result = $this->parseXmlResponse($response->getBody()->getContents());
            if ($result['return_code'] !== 'SUCCESS') {
                throw new \Exception('退款请求失败: ' . ($result['return_msg'] ?? '未知错误'));
            }
            
            if ($result['result_code'] !== 'SUCCESS') {
                throw new \Exception('退款失败: ' . ($result['err_code_des'] ?? $result['err_code'] ?? '未知错误'));
            }
            
            return PaymentResult::success(
                '退款申请成功',
                $result,
                null,
                $refundData['out_trade_no'],
                $result['refund_id'] ?? null,
                $refundData['refund_amount'] / 100,
                'processing'
            );

        } catch (\Exception $e) {
            Log::error('微信支付V2退款失败', [
                'refund_data' => $refundData,
                'error' => $e->getMessage()
            ]);
            
            return PaymentResult::fail('退款失败: ' . $e->getMessage());
        }
    }

    /**
     * 关闭订单
     */
    public function closeOrder(string $orderNo): PaymentResult
    {
        try {
            $requestData = [
                'appid' => $this->config['app_id'],
                'mch_id' => $this->config['mch_id'],
                'out_trade_no' => $orderNo,
                'nonce_str' => $this->generateNonceStr(),
            ];
            
            $requestData['sign'] = $this->generateSign($requestData);
            
            $xmlData = $this->arrayToXml($requestData);
            $response = $this->client->request('POST', 'pay/closeorder', [
                'body' => $xmlData,
                'headers' => ['Content-Type' => 'application/xml; charset=utf-8']
            ]);
            
            $result = $this->parseXmlResponse($response->getBody()->getContents());
            
            if ($result['return_code'] !== 'SUCCESS') {
                throw new \Exception('关闭订单失败: ' . ($result['return_msg'] ?? '未知错误'));
            }
            
            return PaymentResult::success(
                '订单关闭成功',
                $result,
                null,
                $orderNo,
                null,
                null,
                'closed'
            );

        } catch (\Exception $e) {
            Log::error('微信支付V2关闭订单失败', [
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return PaymentResult::fail('关闭订单失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成随机字符串
     */
    private function generateNonceStr($length = 32)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * 生成签名
     */
    private function generateSign($data)
    {
        // 1. 参数排序
        ksort($data);
        
        // 2. 构造签名字符串
        $stringA = '';
        foreach ($data as $key => $value) {
            if ($value !== '' && $key !== 'sign') {
                $stringA .= $key . '=' . $value . '&';
            }
        }
        
        // 3. 拼接API密钥
        $stringSignTemp = $stringA . 'key=' . $this->config['api_key'];
        
        // 4. MD5签名并转大写
        return strtoupper(md5($stringSignTemp));
    }

    /**
     * 验证签名
     */
    public function verifySign($data)
    {
        if (!isset($data['sign'])) {
            return false;
        }
        
        $sign = $data['sign'];
        unset($data['sign']);
        
        return $this->generateSign($data) === $sign;
    }

    /**
     * 数组转XML
     */
    private function arrayToXml($data)
    {
        $xml = '<xml>';
        foreach ($data as $key => $value) {
            if (is_numeric($value)) {
                $xml .= "<{$key}>{$value}</{$key}>";
            } else {
                $xml .= "<{$key}><![CDATA[{$value}]]></{$key}>";
            }
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * XML转数组
     */
    private function parseXmlResponse($xml)
    {
        $data = [];
        
        // 移除XML声明
        $xml = preg_replace('/^<\?xml.*?\?>/i', '', $xml);
        
        // 使用SimpleXML解析
        try {
            $xmlObj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xmlObj === false) {
                throw new \Exception('XML解析失败');
            }
            
            // 转换为数组
            foreach ($xmlObj as $key => $value) {
                $data[$key] = (string)$value;
            }
            
        } catch (\Exception $e) {
            Log::error('XML解析失败', [
                'xml' => $xml,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('响应数据解析失败: ' . $e->getMessage());
        }
        
        return $data;
    }

    /**
     * 获取客户端IP
     */
    private function getClientIp()
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_REAL_IP'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? 
              '127.0.0.1';
        
        // 如果是多个IP，取第一个
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }
        
        return trim($ip);
    }

    /**
     * 映射交易状态
     */
    private function mapTradeState($tradeState)
    {
        $stateMap = [
            'SUCCESS' => 'paid',
            'REFUND' => 'refunded',
            'NOTPAY' => 'pending',
            'CLOSED' => 'closed',
            'REVOKED' => 'cancelled',
            'USERPAYING' => 'paying',
            'PAYERROR' => 'failed'
        ];

        return $stateMap[$tradeState] ?? 'unknown';
    }
}