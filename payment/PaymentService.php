<?php

namespace app\service\payment;

use app\service\OrderService;
use app\service\payment\exceptions\PaymentException;
use think\facade\Log;

/**
 * 支付服务统一入口类
 * 提供高级封装的支付功能
 */
class PaymentService
{
    /**
     * 创建扫码支付
     * @param string $paymentType 支付类型 wechat/alipay
     * @param array $orderData 订单数据
     * @return array
     */
    public static function createQrPayment(string $paymentType, array $orderData): array
    {

        try {
            // 创建支付实例
            $payment = PaymentFactory::create($paymentType);

            // 创建支付订单
            $result = $payment->createQrPayment($orderData);

            if (!$result->success) {
                throw PaymentException::businessError($result->message);
            }

            // 构建返回数据
            $responseData = [
                'success' => true,
                'payment_type' => $paymentType,
                'order_no' => $result->orderNo,
                'qr_url' => $result->qrCode,
                'amount' => $result->amount,
                'expire_time' => $orderData["expire_time"],
                'raw_data' => $result->data
            ];
            Log::info('扫码支付创建成功', [
                'payment_type' => $paymentType,
                'order_no' => $result->orderNo,
                'amount' => $result->amount
            ]);

            return $responseData;

        } catch (PaymentException $e) {
            Log::error('扫码支付创建失败', [
                'payment_type' => $paymentType,
                'order_data' => $orderData,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];

        } catch (\Exception $e) {
            Log::error('扫码支付异常', [
                'payment_type' => $paymentType,
                'order_data' => $orderData,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '支付服务异常，请稍后重试',
                'error_code' => 500
            ];
        }
    }

    /**
     * 查询支付订单状态
     * @param string $paymentType 支付类型
     * @param string $orderNo 订单号
     * @return array
     */
    public static function queryPayment(string $paymentType, string $orderNo): array
    {
        try {
            $payment = PaymentFactory::create($paymentType);
            $result = $payment->queryOrder($orderNo);

            if (!$result->success) {
                throw PaymentException::businessError($result->message);
            }

            return [
                'success' => true,
                'payment_type' => $paymentType,
                'order_no' => $result->orderNo,
                'trade_no' => $result->tradeNo,
                'status' => $result->status,
                'amount' => $result->amount,
                'raw_data' => $result->data
            ];

        } catch (PaymentException $e) {
            Log::error('支付查询失败', [
                'payment_type' => $paymentType,
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];

        } catch (\Exception $e) {
            Log::error('支付查询异常', [
                'payment_type' => $paymentType,
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '查询服务异常，请稍后重试',
                'error_code' => 500
            ];
        }
    }

    /**
     * 申请退款
     * @param string $paymentType 支付类型
     * @param array $refundData 退款数据
     * @return array
     */
    public static function refundPayment(string $paymentType, array $refundData): array
    {
        try {
            $payment = PaymentFactory::create($paymentType);
            $result = $payment->refund($refundData);
            if (!$result->success) {
                throw PaymentException::businessError($result->message);
            }

            Log::info('退款申请成功', [
                'payment_type' => $paymentType,
                'order_no' => $refundData['order_no'],
                'refund_amount' => $refundData['refund_amount']
            ]);

            return [
                'success' => true,
                'payment_type' => $paymentType,
                'order_no' => $result->orderNo,
                'refund_no' => $refundData['refund_no'] ?? null,
                'refund_amount' => $result->amount,
                'raw_data' => $result->data
            ];

        } catch (PaymentException $e) {
            Log::error('退款申请失败', [
                'payment_type' => $paymentType,
                'refund_data' => $refundData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];

        } catch (\Exception $e) {
            Log::error('退款申请异常', [
                'payment_type' => $paymentType,
                'refund_data' => $refundData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '退款服务异常，请稍后重试',
                'error_code' => 500
            ];
        }
    }

    /**
     * 关闭订单
     * @param string $paymentType 支付类型
     * @param string $orderNo 订单号
     * @return array
     */
    public static function closePayment(string $paymentType, string $orderNo): array
    {
        try {
            $payment = PaymentFactory::create($paymentType);
            $result = $payment->closeOrder($orderNo);

            if (!$result->success) {
                throw PaymentException::businessError($result->message);
            }

            Log::info('订单关闭成功', [
                'payment_type' => $paymentType,
                'order_no' => $orderNo
            ]);

            return [
                'success' => true,
                'payment_type' => $paymentType,
                'order_no' => $orderNo,
                'message' => '订单关闭成功'
            ];

        } catch (PaymentException $e) {
            Log::error('订单关闭失败', [
                'payment_type' => $paymentType,
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];

        } catch (\Exception $e) {
            Log::error('订单关闭异常', [
                'payment_type' => $paymentType,
                'order_no' => $orderNo,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '关闭订单服务异常，请稍后重试',
                'error_code' => 500
            ];
        }
    }
    
    /**
     * 关闭订单（别名方法，保持API一致性）
     * @param string $paymentType 支付类型
     * @param string $orderNo 订单号
     * @return array
     */
    public static function closeOrder(string $paymentType, string $orderNo): array
    {
        return self::closePayment($paymentType, $orderNo);
    }

    /**
     * 获取支持的支付方式
     * @return array
     */
    public static function getSupportedPayments(): array
    {
        try {
            $availableTypes = PaymentFactory::getAvailableTypes();
            $supportedPayments = [];

            foreach ($availableTypes as $type) {
                $supportedPayments[] = [
                    'type' => $type,
                    'name' => self::getPaymentName($type),
                    'icon' => self::getPaymentIcon($type),
                    'available' => true
                ];
            }

            return [
                'success' => true,
                'payments' => $supportedPayments
            ];

        } catch (\Exception $e) {
            Log::error('获取支付方式失败', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'message' => '获取支付方式失败',
                'payments' => []
            ];
        }
    }

    /**
     * 统一下单（自动选择支付方式）
     * @param array $orderData 订单数据
     * @param string $preferType 优先支付类型
     * @return array
     */
    public static function unifiedOrder(array $orderData, string $preferType = 'wechat'): array
    {
        try {
            // 获取可用的支付方式
            $availableTypes = PaymentFactory::getAvailableTypes();
            
            if (empty($availableTypes)) {
                throw PaymentException::configError('没有可用的支付方式，请检查配置');
            }

            // 选择支付方式
            $paymentType = in_array($preferType, $availableTypes) ? $preferType : $availableTypes[0];

            // 创建支付订单
            return self::createQrPayment($paymentType, $orderData);

        } catch (\Exception $e) {
            Log::error('统一下单失败', [
                'order_data' => $orderData,
                'prefer_type' => $preferType,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '下单失败: ' . $e->getMessage(),
                'error_code' => 500
            ];
        }
    }

    /**
     * 获取支付方式名称
     */
    private static function getPaymentName(string $type): string
    {
        $names = [
            'wechat' => '微信支付',
            'alipay' => '支付宝支付'
        ];

        return $names[$type] ?? $type;
    }

    /**
     * 获取支付方式图标
     */
    private static function getPaymentIcon(string $type): string
    {
        $icons = [
            'wechat' => 'wechat-pay-icon',
            'alipay' => 'alipay-icon'
        ];

        return $icons[$type] ?? '';
    }

    /**
     * 验证订单数据完整性
     * @param array $orderData
     * @return bool
     */
    public static function validateOrderData(array $orderData): bool
    {
        try {
            PaymentConfigValidator::validateOrderData($orderData);
            return true;
        } catch (PaymentException $e) {
            return false;
        }
    }

    /**
     * 生成订单号
     * @param string $prefix 前缀
     * @return string
     */
    public static function generateOrderNo(string $prefix = 'PAY'): string
    {
        return $prefix . date('YmdHis') . sprintf('%06d', mt_rand(0, 999999));
    }
}