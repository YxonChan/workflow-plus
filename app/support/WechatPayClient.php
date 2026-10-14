<?php

declare(strict_types=1);

namespace app\support;

use WeChatPay\Builder;
use WeChatPay\BuilderChainable;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;

class WechatPayClient
{
    private array $settings;
    private ?BuilderChainable $sdk;

    public function __construct(?array $settings = null, ?BuilderChainable $sdk = null)
    {
        $this->settings = $settings ?? (array) config('payment.wechat');
        $this->sdk = $sdk;
    }

    public function appId(): string
    {
        return (string) ($this->settings['app_id'] ?? '');
    }

    public function merchantId(): string
    {
        return (string) ($this->settings['merchant_id'] ?? '');
    }

    public function assertReady(): void
    {
        if (!($this->settings['enabled'] ?? false)) {
            throw new \RuntimeException('微信支付暂未开通');
        }
        foreach (['app_id', 'merchant_id', 'merchant_serial_no', 'notify_url'] as $key) {
            if (trim((string) ($this->settings[$key] ?? '')) === '') {
                throw new \RuntimeException('微信支付配置不完整');
            }
        }
        if (strlen((string) ($this->settings['api_v3_key'] ?? '')) !== 32) {
            throw new \RuntimeException('微信支付 APIv3 密钥无效');
        }
        $url = parse_url((string) $this->settings['notify_url']);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) {
            throw new \RuntimeException('微信支付回调地址必须使用 HTTPS');
        }
        $this->client();
    }

    public function createNative(array $order): string
    {
        $response = $this->client()->chain('v3/pay/transactions/native')->post(['json' => [
            'appid' => $order['app_id'],
            'mchid' => $order['merchant_id'],
            'description' => mb_strcut($order['description'], 0, 127, 'UTF-8'),
            'out_trade_no' => $order['order_no'],
            'notify_url' => $this->settings['notify_url'],
            'time_expire' => date(DATE_RFC3339, strtotime($order['expires_at'])),
            'amount' => ['total' => $order['amount_fen'], 'currency' => 'CNY'],
        ]]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $codeUrl = (string) ($body['code_url'] ?? '');
        if (!str_starts_with($codeUrl, 'weixin://wxpay/')) {
            throw new \RuntimeException('微信支付二维码无效');
        }
        return $codeUrl;
    }

    public function query(string $orderNo, string $merchantId): array
    {
        $response = $this->client()->v3->pay->transactions->outTradeNo->_out_trade_no_->get([
            'out_trade_no' => $orderNo,
            'query' => ['mchid' => $merchantId],
        ]);
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function close(string $orderNo, string $merchantId): void
    {
        $this->client()->v3->pay->transactions->outTradeNo->_out_trade_no_->close->post([
            'out_trade_no' => $orderNo,
            'json' => ['mchid' => $merchantId],
        ]);
    }

    /** Authenticate the original bytes before parsing or decrypting them. */
    public function notification(string $body, array $headers): array
    {
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        $serial = (string) ($headers['serial'] ?? '');
        if (strlen($body) > 65536 || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || $nonce === '' || $signature === '') {
            throw new \RuntimeException('支付通知签名参数无效');
        }
        $keys = $this->verificationKeys();
        if (!isset($keys[$serial]) || !Rsa::verify(
            Formatter::joinedByLineFeed($timestamp, $nonce, $body), $signature, $keys[$serial]
        )) {
            throw new \RuntimeException('支付通知验签失败');
        }
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['event_type'] ?? '') !== 'TRANSACTION.SUCCESS'
            || ($payload['resource_type'] ?? '') !== 'encrypt-resource'
            || !is_array($payload['resource'] ?? null)) {
            throw new \RuntimeException('支付通知类型无效');
        }
        $resource = $payload['resource'];
        if (($resource['algorithm'] ?? '') !== 'AEAD_AES_256_GCM'
            || strlen((string) ($this->settings['api_v3_key'] ?? '')) !== 32) {
            throw new \RuntimeException('支付通知加密参数无效');
        }
        return json_decode(AesGcm::decrypt(
            (string) ($resource['ciphertext'] ?? ''),
            $this->settings['api_v3_key'],
            (string) ($resource['nonce'] ?? ''),
            (string) ($resource['associated_data'] ?? '')
        ), true, 512, JSON_THROW_ON_ERROR);
    }

    private function client(): BuilderChainable
    {
        return $this->sdk ??= Builder::factory([
            'mchid' => $this->merchantId(),
            'serial' => (string) ($this->settings['merchant_serial_no'] ?? ''),
            'privateKey' => Rsa::from($this->readKey('merchant_private_key_path'), Rsa::KEY_TYPE_PRIVATE),
            'certs' => $this->verificationKeys(),
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
        ]);
    }

    private function verificationKeys(): array
    {
        $keys = [];
        $publicKeyId = (string) ($this->settings['platform_public_key_id'] ?? '');
        if ($publicKeyId !== '') {
            $keys[$publicKeyId] = Rsa::from($this->readKey('platform_public_key_path'), Rsa::KEY_TYPE_PUBLIC);
        }
        $certPath = trim((string) ($this->settings['platform_certificate_path'] ?? ''));
        if ($certPath !== '') {
            // A directory supports overlapping certificates during rotation.
            $paths = is_dir($certPath) ? (glob(rtrim($certPath, '/\\') . '/*.pem') ?: []) : [$certPath];
            foreach ($paths as $path) {
                $cert = $this->readFile($path);
                $info = openssl_x509_parse($cert);
                if (!$info || empty($info['serialNumberHex']) || $info['validFrom_time_t'] > time()
                    || $info['validTo_time_t'] < time()) {
                    throw new \RuntimeException('微信支付平台证书无效或已过期');
                }
                $keys[strtoupper($info['serialNumberHex'])] = Rsa::from($cert, Rsa::KEY_TYPE_PUBLIC);
            }
        }
        if (!$keys) {
            throw new \RuntimeException('微信支付验签公钥或平台证书未配置');
        }
        return $keys;
    }

    private function readKey(string $name): string
    {
        return $this->readFile((string) ($this->settings[$name] ?? ''));
    }

    private function readFile(string $path): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('微信支付密钥文件不可读取');
        }
        $data = file_get_contents($path);
        if (!is_string($data) || $data === '') {
            throw new \RuntimeException('微信支付密钥文件为空');
        }
        return $data;
    }
}
