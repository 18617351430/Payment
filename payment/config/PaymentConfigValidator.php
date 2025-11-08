<?php

namespace app\service\payment\config;

class PaymentConfigValidator
{
    public static function validateWechatConfig($config)
    {
        $required = [
            'app_id' => '微信应用ID',
            'mch_id' => '商户号',
            'mch_serial_number' => '商户API证书序列号',
            'private_key_path' => '商户私钥文件路径',
            'wechatpay_cert_path' => '微信支付平台证书路径',
            'notify_url' => '支付回调地址'
        ];

        foreach ($required as $key => $name) {
            if (empty($config[$key])) {
                throw new \Exception("微信支付配置缺失: {$name}");
            }
        }

        // 验证证书文件是否存在
        if (!file_exists($config['private_key_path'])) {
            throw new \Exception("商户私钥文件不存在: {$config['private_key_path']}");
        }

        if (!file_exists($config['wechatpay_cert_path'])) {
            throw new \Exception("微信支付平台证书文件不存在: {$config['wechatpay_cert_path']}");
        }

        return true;
    }

    public static function validateAlipayConfig($config)
    {
        // 基础必需配置
        $basicRequired = [
            'app_id' => '支付宝应用ID',
            'notify_url' => '支付回调地址'
        ];

        foreach ($basicRequired as $key => $name) {
            if (empty($config[$key])) {
                throw new \Exception("支付宝配置缺失: {$name}");
            }
        }

        // 验证私钥配置（文件路径或明文私钥二选一）
        $hasPrivateKeyPath = !empty($config['private_key_path']);
        $hasPrivateKey = !empty($config['private_key']);
        
        if (!$hasPrivateKeyPath && !$hasPrivateKey) {
            throw new \Exception("支付宝配置缺失: 应用私钥文件路径或明文私钥（二选一）");
        }

        // 如果使用文件路径，验证文件是否存在
        if ($hasPrivateKeyPath && !file_exists($config['private_key_path'])) {
            throw new \Exception("应用私钥文件不存在: {$config['private_key_path']}");
        }

        // 验证支付宝公钥配置（文件路径或明文公钥二选一）
        $hasPublicKeyPath = !empty($config['alipay_public_key_path']);
        $hasPublicKey = !empty($config['alipay_public_key']);
        
        if (!$hasPublicKeyPath && !$hasPublicKey) {
            throw new \Exception("支付宝配置缺失: 支付宝公钥文件路径或明文公钥（二选一）");
        }

        // 如果使用文件路径，验证文件是否存在
        if ($hasPublicKeyPath && !file_exists($config['alipay_public_key_path'])) {
            throw new \Exception("支付宝公钥文件不存在: {$config['alipay_public_key_path']}");
        }

        return true;
    }

    public static function validateWechatV2Config($config)
    {
        $required = [
            'app_id' => '微信应用ID',
            'mch_id' => '商户号',
            'api_key' => 'API密钥',
            'notify_url' => '支付回调地址'
        ];

        foreach ($required as $key => $name) {
            if (empty($config[$key])) {
                throw new \Exception("微信支付V2配置缺失: {$name}");
            }
        }

        // 验证API密钥长度（微信V2 API密钥为32位）
        if (strlen($config['api_key']) !== 32) {
            throw new \Exception("微信支付V2 API密钥长度必须为32位");
        }

        return true;
    }
}
