<?php

namespace app\service\payment;

/**
 * 支付结果统一返回类
 * 封装所有支付操作的返回结果，确保格式一致性
 */
class PaymentResult
{
    /**
     * 操作是否成功
     * @var bool
     */
    public bool $success;

    /**
     * 返回消息
     * @var string
     */
    public string $message;

    /**
     * 原始返回数据
     * @var array
     */
    public array $data;

    /**
     * 二维码支付链接
     * @var string|null
     */
    public ?string $qrCode;

    /**
     * 商户订单号
     * @var string|null
     */
    public ?string $orderNo;

    /**
     * 第三方交易号
     * @var string|null
     */
    public ?string $tradeNo;

    /**
     * 支付金额（元）
     * @var float|null
     */
    public ?float $amount;

    /**
     * 订单状态
     * @var string|null
     */
    public ?string $status;

    /**
     * 构造函数
     */
    public function __construct(
        bool $success = false,
        string $message = '',
        array $data = [],
        ?string $qrCode = null,
        ?string $orderNo = null,
        ?string $tradeNo = null,
        ?float $amount = null,
        ?string $status = null
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
        $this->qrCode = $qrCode;
        $this->orderNo = $orderNo;
        $this->tradeNo = $tradeNo;
        $this->amount = $amount;
        $this->status = $status;
    }

    /**
     * 创建成功的结果
     */
    public static function success(
        string $message = '操作成功',
        array $data = [],
        ?string $qrCode = null,
        ?string $orderNo = null,
        ?string $tradeNo = null,
        ?float $amount = null,
        ?string $status = null
    ): self {
        return new self(true, $message, $data, $qrCode, $orderNo, $tradeNo, $amount, $status);
    }

    /**
     * 创建失败的结果
     */
    public static function fail(string $message = '操作失败', array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'qr_code' => $this->qrCode,
            'order_no' => $this->orderNo,
            'trade_no' => $this->tradeNo,
            'amount' => $this->amount,
            'status' => $this->status,
        ];
    }
}