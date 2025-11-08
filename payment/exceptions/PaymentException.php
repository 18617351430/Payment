<?php

namespace app\service\payment\exceptions;

use Exception;

/**
 * 支付异常类
 * 处理支付相关的异常情况
 */
class PaymentException extends Exception
{
    /**
     * 错误代码常量
     */
    const CONFIG_ERROR = 1001;      // 配置错误
    const NETWORK_ERROR = 1002;     // 网络错误
    const API_ERROR = 1003;         // API错误
    const VALIDATION_ERROR = 1004;  // 验证错误
    const BUSINESS_ERROR = 1005;    // 业务错误

    /**
     * 构造函数
     */
    public function __construct(string $message = "", int $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 创建配置错误异常
     */
    public static function configError(string $message): self
    {
        return new self($message, self::CONFIG_ERROR);
    }

    /**
     * 创建网络错误异常
     */
    public static function networkError(string $message): self
    {
        return new self($message, self::NETWORK_ERROR);
    }

    /**
     * 创建API错误异常
     */
    public static function apiError(string $message): self
    {
        return new self($message, self::API_ERROR);
    }

    /**
     * 创建验证错误异常
     */
    public static function validationError(string $message): self
    {
        return new self($message, self::VALIDATION_ERROR);
    }

    /**
     * 创建业务错误异常
     */
    public static function businessError(string $message): self
    {
        return new self($message, self::BUSINESS_ERROR);
    }
}