<?php

namespace app\service\payment;

use app\service\payment\config\PaymentConfig;
use app\service\payment\config\PaymentConfigValidator;
use app\service\payment\exceptions\PaymentException;
use Alipay\EasySDK\Kernel\Factory;
use Alipay\EasySDK\Kernel\Config;
use think\facade\Log;

/**
 * 支付宝支付实现类
 * 基于官方 alipaysdk/easysdk SDK
 */
class AlipayPayment implements PaymentInterface
{
    /**
     * 配置信息
     * @var array
     */
    private array $config;

    /**
     * 构造函数
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();
        $this->initSdk();
    }

    /**
     * 验证配置
     */
    private function validateConfig(): void
    {
        PaymentConfigValidator::validateAlipayConfig($this->config);
    }

    /**
     * 初始化SDK
     */
    private function initSdk(): void
    {
        try {
            $options = new Config();
            $options->protocol = 'https';
            $options->gatewayHost = $this->config['sandbox'] ? 'openapi.alipaydev.com' : 'openapi.alipay.com';
            $options->signType = 'RSA2';
            $options->appId = $this->config['app_id'];
            
            // 处理私钥（文件路径或明文）
            if (!empty($this->config['private_key'])) {
                // 使用明文私钥
                $options->merchantPrivateKey = $this->config['private_key'];
            } elseif (!empty($this->config['private_key_path']) && file_exists($this->config['private_key_path'])) {
                // 使用私钥文件
                $options->merchantPrivateKey = file_get_contents($this->config['private_key_path']);
            } else {
                throw new \Exception('未找到有效的应用私钥配置');
            }
            
            // 处理支付宝公钥（文件路径或明文）
            if (!empty($this->config['alipay_public_key'])) {
                // 使用明文公钥
                $options->alipayPublicKey = $this->config['alipay_public_key'];
            } elseif (!empty($this->config['alipay_public_key_path']) && file_exists($this->config['alipay_public_key_path'])) {
                // 使用公钥文件
                $options->alipayPublicKey = file_get_contents($this->config['alipay_public_key_path']);
            } else {
                throw new \Exception('未找到有效的支付宝公钥配置');
            }
            
            $options->notifyUrl = $this->config['notify_url'];

            Factory::setOptions($options);

        } catch (\Exception $e) {
            Log::error('支付宝SDK初始化失败', [
                'error' => $e->getMessage(),
                'config' => array_keys($this->config)
            ]);
            throw new \Exception('支付宝SDK初始化失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建扫码支付订单
     */
    public function createQrPayment(array $orderData): PaymentResult
    {
        try {
            // 字段映射和验证
            $outTradeNo = $orderData['out_trade_no'] ?? $orderData['order_no'] ?? '';
            $amount = $orderData['amount'] ?? 0;
            $description = $orderData['description'] ?? $orderData['subject'] ?? '';
            $expireTime = $orderData['expire_time'] ?? null;
            
            // 验证必需字段
            if (empty($outTradeNo) || empty($amount) || !is_numeric($amount) || empty($description)) {
                throw new \Exception('订单数据不完整');
            }

            // 计算超时时间（支付宝格式：相对时间，如 "30m"）
            $timeoutExpress = '30m'; // 默认30分钟
            if (!empty($expireTime)) {
                $timeoutMinutes = max(1, ceil(($expireTime - time()) / 60)); // 计算剩余分钟数，最少1分钟
                $timeoutExpress = $timeoutMinutes . 'm';
            }
            // 使用支付宝EasySDK创建扫码支付
            // 注意：EasySDK的preCreate方法有限制，但我们可以在应用层面控制超时
            $result = Factory::Payment()->FaceToFace()->preCreate(
                $description,           // 订单标题
                $outTradeNo,           // 商户订单号
                number_format($amount / 100, 2, '.', '') // 金额，转换为元
            );

            if ($result->code != '10000') {
                throw new \Exception('支付宝API调用失败: ' . ($result->msg ?? '未知错误'));
            }

            // 记录超时时间信息，用于应用层面的控制
            $resultData = (array) $result;
            $resultData['timeout_express'] = $timeoutExpress;
            $resultData['expire_timestamp'] = $expireTime;

            return PaymentResult::success(
                '支付宝支付订单创建成功（超时时间：' . $timeoutExpress . '）',
                $resultData,
                $result->qrCode,        // 二维码链接
                $outTradeNo,
                null,
                $amount / 100,          // 转换为元
                'pending'
            );

        } catch (\Exception $e) {
            // 如果高级API失败，回退到基础API
            try {
                Log::warning('支付宝高级API调用失败，回退到基础API', [
                    'error' => $e->getMessage(),
                    'order_no' => $outTradeNo ?? 'unknown'
                ]);
                
                // 回退到基础的preCreate方法
                $result = Factory::Payment()->FaceToFace()->preCreate(
                    $description,           // 订单标题
                    $outTradeNo,           // 商户订单号
                    number_format($amount / 100, 2, '.', '') // 金额，转换为元
                );

                if ($result->code != '10000') {
                    throw new \Exception('支付宝API调用失败: ' . ($result->msg ?? '未知错误'));
                }

                return PaymentResult::success(
                    '支付宝支付订单创建成功（基础模式）',
                    (array) $result,
                    $result->qrCode,        // 二维码链接
                    $outTradeNo,
                    null,
                    $amount / 100,          // 转换为元
                    'pending'
                );
                
            } catch (\Exception $fallbackError) {
                $errorData = [
                    'primary_error' => $e->getMessage(),
                    'fallback_error' => $fallbackError->getMessage(),
                    'order_data' => $orderData,
                    'expire_time' => $expireTime ?? 'not set',
                    'timeout_express' => $timeoutExpress ?? 'not calculated'
                ];
                
                return PaymentResult::fail('支付宝支付创建失败: ' . $e->getMessage(), $errorData);
            }
        }
    }

    /**
     * 查询订单状态
     */
    public function queryOrder(string $orderNo): PaymentResult
    {
        try {
            $result = Factory::Payment()->Common()->query($orderNo);

            if ($result->code != '10000') {
                throw new \Exception('查询订单失败: ' . ($result->msg ?? '未知错误'));
            }

            return PaymentResult::success(
                '查询订单成功',
                (array) $result,
                null,
                $orderNo,
                $result->tradeNo ?? null,
                isset($result->totalAmount) ? (float) $result->totalAmount : null,
                $this->mapTradeStatus($result->tradeStatus ?? '')
            );

        } catch (\Exception $e) {
            $errorData = [
                'error_message' => $e->getMessage(),
                'order_no' => $orderNo
            ];
            
            return PaymentResult::fail('查询订单失败: ' . $e->getMessage(), $errorData);
        }
    }

    /**
     * 申请退款
     */
    public function refund(array $refundData): PaymentResult
    {
        try {
            $outTradeNo = $refundData['out_trade_no'] ?? '';
            $refundAmount = $refundData['refund_amount'] ?? 0;
            $refundReason = $refundData['reason'] ?? '商户退款';

            if (empty($outTradeNo) || empty($refundAmount)) {
                throw new \Exception('退款数据不完整');
            }
            $result = Factory::Payment()->Common()->refund(
                $outTradeNo,
                number_format($refundAmount / 100, 2, '.', ''), // 转换为元
                $refundReason
            );
            if ($result->code != '10000') {
                throw new \Exception('退款申请失败: ' . ($result->msg ?? '未知错误'));
            }

            return PaymentResult::success(
                '退款申请成功',
                (array) $result,
                null,
                $outTradeNo,
                $result->tradeNo ?? null,
                $refundAmount / 100, // 转换为元
                'refunded'
            );

        } catch (\Exception $e) {
            $errorData = [
                'error_message' => $e->getMessage(),
                'refund_data' => $refundData
            ];

            return PaymentResult::fail('退款失败: ' . $e->getMessage(), $errorData);
        }
    }

    /**
     * 关闭订单
     */
    public function closeOrder(string $orderNo): PaymentResult
    {
        try {
            $result = Factory::Payment()->Common()->close($orderNo);

            if ($result->code != '10000') {
                throw new \Exception('关闭订单失败: ' . ($result->msg ?? '未知错误'));
            }

            return PaymentResult::success(
                '订单关闭成功',
                (array) $result,
                null,
                $orderNo,
                null,
                null,
                'closed'
            );

        } catch (\Exception $e) {
            $errorData = [
                'error_message' => $e->getMessage(),
                'order_no' => $orderNo
            ];
            
            return PaymentResult::fail('关闭订单失败: ' . $e->getMessage(), $errorData);
        }
    }

    /**
     * 映射交易状态
     */
    private function mapTradeStatus($status): string
    {
        $statusMap = [
            'WAIT_BUYER_PAY' => 'pending',     // 交易创建，等待买家付款
            'TRADE_CLOSED' => 'closed',        // 未付款交易超时关闭，或支付完成后全额退款
            'TRADE_SUCCESS' => 'paid',         // 交易支付成功
            'TRADE_FINISHED' => 'finished',    // 交易结束，不可退款
        ];

        return $statusMap[$status] ?? 'unknown';
    }
}