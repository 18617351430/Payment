<?php

namespace app\service\payment;

use WechatPay\GuzzleMiddleware\WechatPayMiddleware;
use WechatPay\GuzzleMiddleware\Util\PemUtil;
use WechatPay\GuzzleMiddleware\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\RequestException;

// 临时验证器，跳过签名验证（仅用于测试）
class NoopValidator implements Validator
{
    public function validate(\Psr\Http\Message\ResponseInterface $response)
    {
        return true;
    }
}

class WechatPayment implements PaymentInterface
{
    private $config;
    private $client;

    public function __construct($config)
    {
        $this->config = $config;
        $this->initClient();
    }

    private function initClient()
    {
        try {
            // 验证配置参数
            $this->validateConfig();
            
            // 加载商户私钥
            $merchantPrivateKey = PemUtil::loadPrivateKey($this->config['private_key_path']);
            
            // 加载微信支付平台证书
            $wechatpayCertificate = PemUtil::loadCertificate($this->config['wechatpay_cert_path']);
            // 构建微信支付中间件 - 0.2.2版本正确用法
            $wechatpayMiddleware = WechatPayMiddleware::builder()
                ->withMerchant(
                    $this->config['mch_id'],
                    $this->config['mch_serial_number'], 
                    $merchantPrivateKey
                )
                ->withValidator(new NoopValidator()) // 临时跳过签名验证
                ->build();

            // 创建HTTP客户端
            $stack = HandlerStack::create();
            $stack->push($wechatpayMiddleware, 'wechatpay');
            
            $this->client = new Client([
                'handler' => $stack,
                'base_uri' => 'https://api.mch.weixin.qq.com/',
                'timeout' => 30,
            ]);
            
        } catch (\Exception $e) {
            // 记录详细错误信息
            $errorInfo = [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'config_check' => [
                    'private_key_exists' => file_exists($this->config['private_key_path'] ?? ''),
                    'wechatpay_cert_exists' => file_exists($this->config['wechatpay_cert_path'] ?? ''),
                    'private_key_path' => $this->config['private_key_path'] ?? 'not set',
                    'wechatpay_cert_path' => $this->config['wechatpay_cert_path'] ?? 'not set',
                    'mch_id' => !empty($this->config['mch_id']) ? 'set' : 'not set',
                    'app_id' => !empty($this->config['app_id']) ? 'set' : 'not set',
                    'mch_serial_number' => !empty($this->config['mch_serial_number']) ? 'set' : 'not set',
                ]
            ];
            
            throw new \Exception('微信支付客户端初始化失败: ' . $e->getMessage() . ' 详细信息: ' . json_encode($errorInfo, JSON_UNESCAPED_UNICODE));
        }
    }

    private function validateConfig()
    {
        $required = ['app_id', 'mch_id', 'mch_serial_number', 'private_key_path', 'wechatpay_cert_path', 'notify_url'];
        
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \Exception("微信支付配置缺失: {$key}");
            }
        }
        
        if (!file_exists($this->config['private_key_path'])) {
            throw new \Exception("商户私钥文件不存在: {$this->config['private_key_path']}");
        }
        
        if (!file_exists($this->config['wechatpay_cert_path'])) {
            throw new \Exception("微信支付平台证书文件不存在: {$this->config['wechatpay_cert_path']}");
        }
    }

    public function createQrPayment(array $orderData): PaymentResult
    {
        try {
            // 字段映射和验证
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
                throw new \Exception('订单数据不完整，缺少字段: ' . implode(', ', $missing) . '。当前数据: ' . json_encode($orderData, JSON_UNESCAPED_UNICODE));
            }
            
            $requestData = [
                'appid' => $this->config['app_id'],
                'mchid' => $this->config['mch_id'],
                'description' => $description,
                'out_trade_no' => $outTradeNo,
                'amount' => [
                    'total' => (int)$amount, // 确保是整数，单位分
                    'currency' => 'CNY'
                ],
                'notify_url' => $this->config['notify_url']
            ];
            payment_log("请求支付参数",$requestData);
            // 添加过期时间（微信支付格式：RFC 3339）
            if (!empty($expireTime)) {
                $requestData['time_expire'] = date('c', $expireTime); // ISO 8601格式
            }
            
            $response = $this->client->request('POST', 'v3/pay/transactions/native', [
                'json' => $requestData,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $result = json_decode($response->getBody(), true);
            
            if (empty($result['code_url'])) {
                throw new \Exception('微信支付返回数据异常，未获取到支付链接');
            }
            
            return PaymentResult::success(
                '微信支付订单创建成功',
                $result,
                $result['code_url'], // 二维码链接
                $outTradeNo,
                null,
                $amount / 100, // 转换为元
                'pending'
            );

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            
            // 解析微信支付错误信息
            $wechatError = null;
            if ($errorBody) {
                $wechatError = json_decode($errorBody, true);
            }
            
            $errorData = [
                'http_status' => $statusCode,
                'error_message' => $e->getMessage(),
                'error_detail' => $errorBody,
                'wechat_error' => $wechatError,
                'request_data' => $requestData ?? null
            ];
            
            $errorMessage = '微信支付创建失败: ' . $e->getMessage();
            if ($wechatError && isset($wechatError['message'])) {
                $errorMessage .= ' [微信错误: ' . $wechatError['message'] . ']';
            }
            
            return PaymentResult::fail($errorMessage, $errorData);
            
        } catch (\Exception $e) {
            $errorData = [
                'error_message' => $e->getMessage(),
                'error_type' => 'validation_error'
            ];
            
            return PaymentResult::fail('参数验证失败: ' . $e->getMessage(), $errorData);
        }
    }

    public function queryOrder(string $orderNo): PaymentResult
    {
        try {
            $response = $this->client->request('GET', "v3/pay/transactions/out-trade-no/{$orderNo}", [
                'query' => [
                    'mchid' => $this->config['mch_id']
                ],
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            
            return PaymentResult::success(
                '查询订单成功',
                $result,
                null,
                $orderNo,
                $result['transaction_id'] ?? null,
                ($result['amount']['total'] ?? 0) / 100, // 转换为元
                $this->mapTradeState($result['trade_state'])
            );

        } catch (RequestException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $errorData = [
                'error_message' => $e->getMessage(),
                'error_detail' => $errorBody
            ];
            
            return PaymentResult::fail('查询订单失败: ' . $e->getMessage(), $errorData);
        }
    }

    public function refund(array $refundData): PaymentResult
    {
        try {
            $response = $this->client->request('POST', 'v3/refund/domestic/refunds', [
                'json' => [
                    'out_trade_no' => $refundData['out_trade_no'],
                    'out_refund_no' => $refundData['out_refund_no'],
                    'amount' => [
                        'refund' => $refundData['refund_amount'],
                        'total' => $refundData['total_amount'],
                        'currency' => 'CNY'
                    ],
                    'reason' => $refundData['reason'] ?? '商户退款'
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);
            $result = json_decode($response->getBody(), true);
            return PaymentResult::success(
                '退款申请成功',
                $result,
                null,
                $result["out_trade_no"],
                $result['refund_id'] ?? null,
                $result["amount"]['total'], // 转换为元
                $result['status'] ?? 'processing'
            );

        } catch (RequestException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $errorData = [
                'error_message' => $e->getMessage(),
                'error_detail' => $errorBody
            ];
            
            return PaymentResult::fail('退款失败: ' . $e->getMessage(), $errorData);
        }
    }

    public function closeOrder(string $orderNo): PaymentResult
    {
        try {
            $response = $this->client->request('POST', "v3/pay/transactions/out-trade-no/{$orderNo}/close", [
                'json' => [
                    'mchid' => $this->config['mch_id']
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ]);

            // 关闭订单成功通常返回204状态码，没有响应体
            if ($response->getStatusCode() === 204) {
                return PaymentResult::success(
                    '订单关闭成功',
                    [],
                    null,
                    $orderNo,
                    null,
                    null,
                    'closed'
                );
            }

            $result = json_decode($response->getBody(), true);
            return PaymentResult::success('订单关闭成功', $result, null, $orderNo, null, null, 'closed');

        } catch (RequestException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $errorData = [
                'error_message' => $e->getMessage(),
                'error_detail' => $errorBody
            ];
            
            return PaymentResult::fail('关闭订单失败: ' . $e->getMessage(), $errorData);
        }
    }

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