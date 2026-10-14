# 微信支付接入

积分页提供微信支付 Native 扫码购买：10 元 / 1000 积分、100 元 / 10000 积分、500 元 / 50000 积分。套餐金额和积分数固定在 `config/payment.php`，前端只提交套餐 ID。

## 开通和配置

需要已开通 Native 支付的微信支付商户号，以及与该商户号绑定的 AppID。本次不包含公众号 JSAPI、小程序、H5 跳转支付或自动退款。

在服务器安装依赖：

```bash
composer install --no-dev
cd frontend
npm ci
npm run build
```

微信支付使用官方 `wechatpay/wechatpay` PHP SDK，请确保运行时支持 curl、OpenSSL、SimpleXML。当前 Docker 构建复制本机的 `vendor`，构建前必须先安装上述依赖。

在服务器 `.env` 中配置以下字段。密钥文件放在 Web 根目录之外，赋予 PHP 运行用户读取权限；Docker 部署需要将密钥以只读方式挂载到 PHP 容器，填写容器内的路径。

| 配置 | 内容 |
| --- | --- |
| `WECHAT_PAY_ENABLED` | 配置完成后设为 `1`；默认 `0` |
| `WECHAT_PAY_APP_ID` | 与商户号绑定的 AppID |
| `WECHAT_PAY_MERCHANT_ID` | 商户号 |
| `WECHAT_PAY_MERCHANT_SERIAL_NO` | 商户 API 证书序列号 |
| `WECHAT_PAY_API_V3_KEY` | 32 字节 APIv3 密钥 |
| `WECHAT_PAY_MERCHANT_PRIVATE_KEY_PATH` | 商户 `apiclient_key.pem` 路径 |
| `WECHAT_PAY_PLATFORM_PUBLIC_KEY_ID` | 微信支付公钥 ID，例如 `PUB_KEY_ID_...` |
| `WECHAT_PAY_PLATFORM_PUBLIC_KEY_PATH` | 微信支付公钥 PEM 文件路径 |
| `WECHAT_PAY_PLATFORM_CERTIFICATE_PATH` | 平台证书模式可填写证书文件，或包含多份 `.pem` 证书的目录 |
| `WECHAT_PAY_NOTIFY_URL` | `https://实际域名/api/payments/wechat/notify` |
| `WECHAT_PAY_DESCRIPTION_PREFIX` | 用户支付账单上的商品描述前缀 |

公钥模式和平台证书模式至少配置一种。公钥、证书用于核验微信的通知和 API 响应，不是商户自己的 API 证书。平台证书需要在过期前更新；目录模式支持轮换期间保留多份有效证书。

回调地址必须公网可达、使用 HTTPS、允许 POST，不能经过登录页面、验证码或前端 SPA 重写。除回调接口外，所有支付接口都要求现有用户登录。接口会自动检测配置，配置缺失或密钥不可读取时，积分页显示“微信支付暂未开通”。

## 数据库和订单流程

推荐上线前执行 `database/sql/20261009_add_wechat_payment.sql`。应用也会按项目已有模式自动创建订单表，自动建表需要数据库账号具备 CREATE 权限；积分表由现有积分服务维护。

订单保留套餐、金额（整数分）、用户、AppID 和商户号快照。每次购买携带请求 ID，同一个用户重复提交该 ID 会返回同一订单。二维码有效期为 15 分钟，页面每 5 秒查询一次本地订单；后端每笔订单最多每 5 秒主动向微信查单一次。页面关闭不会取消已创建的交易，用户可通过最近订单再次打开支付窗口。

收到成功回调时，官方 SDK 验证原始请求体签名、签名时间戳和公钥/证书 ID，然后进行 AES-GCM 解密。服务端校验 AppID、商户号、订单号、人民币币种、金额和交易成功状态后，在同一个 MySQL 事务中锁定订单、锁定用户、增加积分、写入积分流水，并标记订单已支付。相同订单重复通知不会再次加积分；微信交易号的唯一索引防止同一交易关联两笔订单。

回调延迟时，已验签的微信查单结果也可完成相同入账流程。网络超时或响应验签失败保留为待确认订单，不会根据前端点击或本地计时增加积分。到期后先查单再关闭未支付的微信订单；随后收到已验证的成功通知仍会入账，避免支付与关单竞争导致漏账。

## 验证

自动测试只使用自建的临时测试数据库，结束后删除该测试库，不修改应用数据库或调用真实微信支付：

```bash
PAYMENT_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=35409' php scripts/test_wechat_payment.php
```

可用 `PAYMENT_TEST_MYSQL_USER` 和 `PAYMENT_TEST_MYSQL_PASSWORD` 配置隔离测试服务器的账号。测试账号需要创建和删除测试数据库的权限。Windows 上生成测试 RSA 密钥时，可能需要设置 `OPENSSL_CONF` 指向本机 OpenSSL 配置文件。

前端交互验证脚本为 `frontend/scripts/test-wechat-payment.mjs`，需要 Playwright 与 Chrome。可通过 `PLAYWRIGHT_MODULE_PATH` 指定已有 Playwright 安装路径，并通过 `PAYMENT_UI_BASE_URL` 指定正在运行的 Vite 地址。该脚本使用模拟 API，检查购买、二维码、余额刷新、网络重试、最近订单恢复及过期/禁用状态，不产生真实付款。

启用真实支付前，使用实际商户配置完成一笔 10 元付款，确认微信回调返回 HTTP 204、订单变成 paid、积分增加 1000、只产生一条 `ref_type=payment_order` 的充值流水；再重放合法通知并验证不会重复入账。真实商户支付验证需要实际商户材料与公网回调域名，目前未执行。
