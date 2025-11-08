# 支付服务模块使用说明

## 📋 概述

本支付服务模块基于官方SDK封装，支持微信支付和支付宝支付的扫码支付功能，提供统一的接口设计和完善的异常处理机制。

## 🏗️ 目录结构

```
app/service/payment/
├── PaymentInterface.php          # 支付接口定义
├── PaymentResult.php            # 统一返回结果类
├── PaymentFactory.php           # 支付工厂类
├── PaymentService.php           # 支付服务统一入口
├── WechatPayment.php           # 微信支付实现
├── AlipayPayment.php           # 支付宝支付实现
├── config/
│   ├── PaymentConfig.php       # 配置管理
│   └── PaymentConfigValidator.php # 配置验证
├── exceptions/
│   └── PaymentException.php    # 支付异常类
└── README.md                   # 使用说明
```

## 🔧 环境配置

### 1. 安装依赖

```bash
# 微信支付官方SDK
composer require wechatpay/wechatpay-guzzle-middleware

# 支付宝官方SDK
composer require alipaysdk/easysdk
```

### 2. 环境变量配置

在 `.env` 文件中添加以下配置：

```bash
# 微信支付配置
WECHAT_APP_ID=wx1234567890abcdef
WECHAT_MCH_ID=1234567890
WECHAT_PRIVATE_KEY_PATH=./cert/wechat_private_key.pem
WECHAT_CERT_SERIAL=1A2B3C4D5E6F7890
WECHAT_CERT_PATH=./cert/wechatpay_cert.pem
WECHAT_NOTIFY_URL=https://yourdomain.com/api/wechat/notify

# 支付宝配置
ALIPAY_APP_ID=2021001234567890
ALIPAY_PRIVATE_KEY=MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAo...
ALIPAY_PUBLIC_KEY=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...
ALIPAY_GATEWAY_HOST=alipay.com
ALIPAY_NOTIFY_URL=https://yourdomain.com/api/alipay/notify

# 通用配置
PAYMENT_DEFAULT_CURRENCY=CNY
PAYMENT_TIMEOUT=30
PAYMENT_LOG_LEVEL=info
```

### 3. 证书文件

将微信支付证书文件放置在项目根目录的 `cert/` 文件夹中：

```
cert/
├── wechat_private_key.pem      # 微信商户私钥
├── wechatpay_cert.pem          # 微信支付证书
└── ...
```

## 🚀 快速开始

### 1. 创建扫码支付

```php
use app\service\payment\PaymentService;

// 订单数据
$orderData = [
    'order_no' => 'ORDER20241014001',
    'subject' => '商品名称',
    'amount' => 0.01,           // 金额（元）
    'body' => '商品详细描述'     // 可选
];

// 创建微信扫码支付
$result = PaymentService::createQrPayment('wechat', $orderData);

if ($result['success']) {
    echo "支付链接: " . $result['qr_url'];
    // 前端使用 qr_url 生成二维码
} else {
    echo "创建失败: " . $result['message'];
}
```

### 2. 查询订单状态

```php
$result = PaymentService::queryPayment('wechat', 'ORDER20241014001');

if ($result['success']) {
    echo "订单状态: " . $result['status'];
    echo "支付金额: " . $result['amount'];
} else {
    echo "查询失败: " . $result['message'];
}
```

### 3. 申请退款

```php
$refundData = [
    'order_no' => 'ORDER20241014001',
    'refund_no' => 'REFUND20241014001',
    'refund_amount' => 0.01,
    'total_amount' => 0.01,
    'reason' => '用户申请退款'
];

$result = PaymentService::refundPayment('wechat', $refundData);
```

## 🔄 高级用法

### 1. 使用工厂模式

```php
use app\service\payment\PaymentFactory;

// 创建支付实例
$wechatPay = PaymentFactory::create('wechat');
$alipay = PaymentFactory::create('alipay');

// 批量创建
$payments = PaymentFactory::createMultiple(['wechat', 'alipay']);
```

### 2. 自定义配置

```php
$customConfig = [
    'app_id' => 'custom_app_id',
    'mch_id' => 'custom_mch_id',
    // ... 其他配置
];

$payment = PaymentFactory::create('wechat', $customConfig);
```

### 3. 统一下单

```php
// 自动选择可用的支付方式
$result = PaymentService::unifiedOrder($orderData, 'wechat');
```

## 📊 订单状态说明

| 状态 | 说明 |
|------|------|
| `pending` | 等待支付 |
| `paid` | 支付成功 |
| `closed` | 订单关闭 |
| `refunded` | 已退款 |
| `failed` | 支付失败 |
| `unknown` | 未知状态 |

## ⚠️ 异常处理

所有支付操作都会返回统一格式的结果：

```php
[
    'success' => true,          // 操作是否成功
    'message' => '操作成功',     // 返回消息
    'error_code' => 0,          // 错误代码（失败时）
    // ... 其他数据
]
```

### 异常代码

- `1001` - 配置错误
- `1002` - 网络错误
- `1003` - API错误
- `1004` - 验证错误
- `1005` - 业务错误

## 🔍 调试和日志

所有支付操作都会记录详细日志，可以通过ThinkPHP的日志系统查看：

```php
// 日志文件位置
runtime/log/
```

## 🛡️ 安全建议

1. **证书安全**：妥善保管支付证书和私钥文件
2. **HTTPS**：生产环境必须使用HTTPS
3. **签名验证**：严格验证回调签名
4. **金额校验**：支付前后都要验证金额
5. **订单唯一性**：确保订单号的唯一性

## 📝 注意事项

1. **测试环境**：开发阶段使用沙箱环境
2. **金额单位**：统一使用元作为金额单位
3. **订单号**：建议使用有意义的订单号规则
4. **超时时间**：合理设置支付超时时间
5. **回调处理**：正确处理支付回调通知
6. **二维码生成**：前端负责根据返回的 `qr_url` 生成二维码

## 🔗 相关链接

- [微信支付开发文档](https://pay.weixin.qq.com/wiki/doc/apiv3/index.shtml)
- [支付宝开放平台](https://opendocs.alipay.com/open/270/105898)
- [ThinkPHP 8.0 文档](https://www.kancloud.cn/manual/thinkphp8_0/1736025)

## 📞 技术支持

如有问题，请按照以下方式排查。
1.请检查配置信息是否正确
2.打开日志，查看日志记录
3.请联系相关技术人员
开发：1990119630@qq.com 顺子
