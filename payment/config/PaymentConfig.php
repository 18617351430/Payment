<?php

namespace app\service\payment\config;

class PaymentConfig
{
    public static function getWechatConfig()
    {
        return [
            'app_id' => env('WECHAT_APP_ID'),
            'mch_id' => env('WECHAT_MCH_ID'),
            'mch_serial_number' => env('WECHAT_MCH_SERIAL_NUMBER'), // 商户API证书序列号
            'private_key_path' => self::getAbsolutePath(env('WECHAT_PRIVATE_KEY_PATH')),
            'wechatpay_cert_path' => self::getAbsolutePath(env('WECHAT_WECHATPAY_CERT_PATH')), // 微信支付平台证书
            'notify_url' => env('WECHAT_NOTIFY_URL')
        ];
    }

    public static function getAlipayConfig()
    {
        return [
            'app_id' => env('ALIPAY_APP_ID'),
            // 支持文件路径和明文密钥两种方式
            'private_key_path' => env('ALIPAY_PRIVATE_KEY_PATH') ? self::getAbsolutePath(env('ALIPAY_PRIVATE_KEY_PATH')) : null,
            'private_key' => env('ALIPAY_PRIVATE_KEY'), // 明文私钥
            'alipay_public_key_path' => env('ALIPAY_PUBLIC_KEY_PATH') ? self::getAbsolutePath(env('ALIPAY_PUBLIC_KEY_PATH')) : null,
            'alipay_public_key' => env('ALIPAY_PUBLIC_KEY'), // 明文公钥
            'notify_url' => env('ALIPAY_NOTIFY_URL'),
            'sandbox' => env('ALIPAY_SANDBOX', false)
        ];
    }

    private static function getAbsolutePath($relativePath)
    {
        if (empty($relativePath)) {
            return '';
        }
        
        // 如果已经是绝对路径，直接返回
        if (strpos($relativePath, '/') === 0 || strpos($relativePath, ':\\') !== false) {
            return $relativePath;
        }
        
        // 相对路径转换为绝对路径（基于项目根目录）
        return root_path() . $relativePath;
    }
    public static function getWechatV2Config()
    {
        return [
            'app_id' => env('V2_WECHAT_APP_ID'),
            'mch_id' => env('V2_WECHAT_MCH_ID'),
            'api_key' => env('V2_WECHAT_API_KEY'), // V2 的32位密钥
            'notify_url' => env('V2_WECHAT_NOTIFY_URL'),
            'cert_path' => env('V2_WECHAT_CERT_PATH'), // 商户证书（退款时需要）
            'key_path' => env('V2_WECHAT_KEY_PATH')    // 商户私钥（退款时需要）
        ];
    }
}
