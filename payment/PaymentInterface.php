<?php

namespace app\service\payment;

/**
 * 支付接口
 * 定义统一的支付方法，确保不同支付方式的一致性
 */
interface PaymentInterface
{
    /**
     * 创建扫码支付订单
     * @param array $orderData 订单数据
     * @return PaymentResult
     */
    public function createQrPayment(array $orderData): PaymentResult;

    /**
     * 查询订单状态
     * @param string $orderNo 商户订单号
     * @return PaymentResult
     */
    public function queryOrder(string $orderNo): PaymentResult;

    /**
     * 申请退款
     * @param array $refundData 退款数据
     * @return PaymentResult
     */
    public function refund(array $refundData): PaymentResult;

    /**
     * 关闭订单
     * @param string $orderNo 商户订单号
     * @return PaymentResult
     */
    public function closeOrder(string $orderNo): PaymentResult;
}