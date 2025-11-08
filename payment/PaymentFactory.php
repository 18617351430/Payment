<?php

namespace app\service\payment;

use app\service\payment\config\PaymentConfig;
use app\service\payment\config\PaymentConfigValidator;
use app\service\payment\exceptions\PaymentException;

/**
 * 支付工厂类
 * 统一创建不同类型的支付实例
 */
class PaymentFactory
{
    /**
     * 支持的支付类型
     */
    const WECHAT = 'wechat';
    const WECHAT_V2 = 'wechat_v2';
    const ALIPAY = 'alipay';

    /**
     * 支付类型映射
     * @var array
     */
    private static array $paymentClasses = [
        self::WECHAT => WechatPayment::class,
        self::WECHAT_V2 => WechatPaymentV2::class,
        self::ALIPAY => AlipayPayment::class,
    ];

    /**
     * 支付实例缓存
     * @var array
     */
    private static array $instances = [];

    /**
     * 创建支付实例
     * @param string $type 支付类型
     * @param array $config 自定义配置（可选）
     * @return PaymentInterface
     * @throws PaymentException
     */
    public static function create(string $type, array $config = []): PaymentInterface
    {
        // 验证支付类型
        if (!isset(self::$paymentClasses[$type])) {
            throw PaymentException::validationError("不支持的支付类型: {$type}");
        }

        // 生成缓存键
        $cacheKey = $type . '_' . md5(serialize($config));

        // 如果已有实例且配置相同，直接返回
        if (isset(self::$instances[$cacheKey])) {
            return self::$instances[$cacheKey];
        }

        try {
            // 获取配置
            $finalConfig = $config ?: self::getDefaultConfig($type);
            // 创建实例
            $className = self::$paymentClasses[$type];
            $instance = new $className($finalConfig);

            // 缓存实例
            self::$instances[$cacheKey] = $instance;

            return $instance;

        } catch (PaymentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw PaymentException::configError("创建{$type}支付实例失败: " . $e->getMessage());
        }
    }

    /**
     * 批量创建支付实例
     * @param array $types 支付类型数组
     * @return array
     */
    public static function createMultiple(array $types): array
    {
        $instances = [];
        
        foreach ($types as $type) {
            try {
                $instances[$type] = self::create($type);
            } catch (PaymentException $e) {
                // 某个支付方式创建失败时，记录日志但不影响其他支付方式
                \think\facade\Log::warning("创建{$type}支付实例失败", [
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $instances;
    }

    /**
     * 获取默认配置
     * @param string $type
     * @return array
     */
    private static function getDefaultConfig(string $type): array
    {
        switch ($type) {
            case self::WECHAT:
                return PaymentConfig::getWechatConfig();
            case self::WECHAT_V2:
                return PaymentConfig::getWechatV2Config();
            case self::ALIPAY:
                return PaymentConfig::getAlipayConfig();
            default:
                throw PaymentException::validationError("未知的支付类型: {$type}");
        }
    }

    /**
     * 验证支付配置
     * @param string $type
     * @return bool
     * @throws PaymentException
     */
    public static function validateConfig(string $type): bool
    {
        switch ($type) {
            case self::WECHAT:
                return PaymentConfigValidator::validateWechatConfig();
            case self::WECHAT_V2:
                return PaymentConfigValidator::validateWechatV2Config();
            case self::ALIPAY:
                return PaymentConfigValidator::validateAlipayConfig();
            default:
                throw PaymentException::validationError("不支持的支付类型: {$type}");
        }
    }

    /**
     * 获取支持的支付类型列表
     * @return array
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::$paymentClasses);
    }

    /**
     * 检查支付类型是否支持
     * @param string $type
     * @return bool
     */
    public static function isSupported(string $type): bool
    {
        return isset(self::$paymentClasses[$type]);
    }

    /**
     * 清理实例缓存
     * @param string|null $type 指定类型，为null时清理所有
     */
    public static function clearCache(?string $type = null): void
    {
        if ($type === null) {
            self::$instances = [];
        } else {
            foreach (self::$instances as $key => $instance) {
                if (strpos($key, $type . '_') === 0) {
                    unset(self::$instances[$key]);
                }
            }
        }
    }

    /**
     * 获取所有可用的支付方式
     * 检查配置是否完整，返回可用的支付类型
     * @return array
     */
    public static function getAvailableTypes(): array
    {
        $available = [];

        foreach (self::getSupportedTypes() as $type) {
            try {
                self::validateConfig($type);
                $available[] = $type;
            } catch (PaymentException $e) {
                // 配置不完整的支付方式跳过
                \think\facade\Log::info("支付方式{$type}配置不完整，跳过", [
                    'type' => $type,
                    'reason' => $e->getMessage()
                ]);
            }
        }

        return $available;
    }

    /**
     * 创建推荐的支付实例
     * 根据配置完整性自动选择合适的支付方式
     * @param string $preferType 优先选择的支付类型
     * @return PaymentInterface|null
     */
    public static function createRecommended(string $preferType = self::WECHAT): ?PaymentInterface
    {
        $availableTypes = self::getAvailableTypes();

        if (empty($availableTypes)) {
            return null;
        }

        // 如果首选类型可用，优先使用
        if (in_array($preferType, $availableTypes)) {
            return self::create($preferType);
        }

        // 否则使用第一个可用的支付方式
        return self::create($availableTypes[0]);
    }
}