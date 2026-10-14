<?php

declare(strict_types=1);

// Run against a disposable MySQL server only. This creates and drops its own database.
require __DIR__ . '/../vendor/autoload.php';

use app\model\CreditLedger;
use app\model\PaymentOrder;
use app\model\User;
use app\support\PaymentService;
use app\support\WechatPayClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use think\facade\Db;
use WeChatPay\Builder;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;

$app = new think\App();
$app->initialize();
$dsn = getenv('PAYMENT_TEST_MYSQL_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set PAYMENT_TEST_MYSQL_DSN to an isolated MySQL instance (e.g. mysql:host=127.0.0.1;port=35409).\n");
    exit(1);
}
$dbUser = getenv('PAYMENT_TEST_MYSQL_USER') ?: 'root';
$dbPassword = getenv('PAYMENT_TEST_MYSQL_PASSWORD') ?: '';
if (($argv[1] ?? '') === '--callback-worker') {
    try {
        $fixture = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
        if (!preg_match('/^payment_test_[a-f0-9]{12}$/D', $fixture['database']['connections']['payment_test']['database'])) {
            throw new RuntimeException('Worker requires an isolated test database');
        }
        $app->config->set($fixture['database'], 'database');
        PaymentService::handleNotify($fixture['body'], $fixture['headers'], new WechatPayClient($fixture['settings']));
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
$pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'payment_test_' . bin2hex(random_bytes(6));
$fixtureDir = sys_get_temp_dir() . '/workflow_payment_' . bin2hex(random_bytes(8));
mkdir($fixtureDir);
$count = 0;

function check(bool $condition, string $name): void
{
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
    $count++;
}

function rejects(callable $action, string $name): void
{
    $thrown = false;
    try { $action(); } catch (Throwable) { $thrown = true; }
    check($thrown, $name);
}

try {
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4');
    $dsnParts = [];
    parse_str(str_replace(';', '&', substr($dsn, 6)), $dsnParts);
    $app->config->set([
        'default' => 'payment_test',
        'connections' => ['payment_test' => [
            'type' => 'mysql', 'hostname' => $dsnParts['host'] ?? '127.0.0.1',
            'hostport' => $dsnParts['port'] ?? '3306', 'database' => $dbName,
            'username' => $dbUser, 'password' => $dbPassword, 'charset' => 'utf8mb4',
            'prefix' => '', 'fields_strict' => true, 'fields_cache' => false,
        ]],
    ], 'database');
    Db::execute('CREATE TABLE users (id int unsigned PRIMARY KEY AUTO_INCREMENT, status tinyint NOT NULL DEFAULT 1,
        credit_balance decimal(14,2) NOT NULL DEFAULT 0, create_time datetime, update_time datetime) ENGINE=InnoDB');
    PaymentService::ensureSchema();
    $user = User::create(['status' => 1, 'credit_balance' => 7.5]);
    $userId = (int) $user->getAttr('id');

    $opensslOptions = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $opensslConfig = getenv('OPENSSL_CONF');
    if ($opensslConfig) $opensslOptions['config'] = $opensslConfig;
    $platformKey = openssl_pkey_new($opensslOptions);
    if (!$platformKey) throw new RuntimeException('Could not generate test RSA key; configure OPENSSL_CONF.');
    openssl_pkey_export($platformKey, $privatePem, null, $opensslOptions);
    $publicPem = openssl_pkey_get_details($platformKey)['key'];
    file_put_contents($fixtureDir . '/public.pem', $publicPem);
    file_put_contents($fixtureDir . '/private.pem', $privatePem);
    $serial = 'PUB_KEY_ID_TEST';
    $settings = [
        'enabled' => true, 'app_id' => 'wx_test_app', 'merchant_id' => 'test_merchant',
        'merchant_serial_no' => 'TEST_MERCHANT_SERIAL', 'api_v3_key' => str_repeat('k', 32),
        'merchant_private_key_path' => $fixtureDir . '/private.pem',
        'platform_public_key_id' => $serial, 'platform_public_key_path' => $fixtureDir . '/public.pem',
        'notify_url' => 'https://example.test/api/payments/wechat/notify',
    ];
    $callbackClient = new WechatPayClient($settings);
    $callbackClient->assertReady();
    check(true, 'SDK configuration accepts public-key mode');
    check(!PaymentService::available(), 'payments disabled with absent real merchant credentials');

    $requests = [];
    $queryTransaction = null;
    $mode = 'ok';
    $handler = HandlerStack::create(function ($request) use (&$requests, &$queryTransaction, &$mode, $platformKey, $serial) {
        $requests[] = $request;
        if ($mode === 'timeout') return Create::rejectionFor(new RuntimeException('simulated timeout'));
        $body = $request->getMethod() === 'GET' ? json_encode($queryTransaction) :
            ($request->getUri()->getPath() === '/v3/pay/transactions/native' ? '{"code_url":"weixin://wxpay/bizpayurl?pr=test"}' : '');
        $timestamp = (string) time();
        $nonce = 'test_response_nonce';
        return Create::promiseFor(new Response($body === '' ? 204 : 200, [
            'Wechatpay-Timestamp' => $timestamp, 'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Serial' => $serial,
            'Wechatpay-Signature' => $mode === 'bad_signature' ? 'invalid' : Rsa::sign(Formatter::joinedByLineFeed($timestamp, $nonce, $body), $platformKey),
        ], $body));
    });
    $sdk = Builder::factory([
        'mchid' => $settings['merchant_id'], 'serial' => $settings['merchant_serial_no'],
        'privateKey' => $platformKey, 'certs' => [$serial => Rsa::from($publicPem, Rsa::KEY_TYPE_PUBLIC)], 'handler' => $handler,
    ]);
    $client = new WechatPayClient($settings, $sdk);

    rejects(fn () => PaymentService::createNativeOrder($userId, 'arbitrary_price', bin2hex(random_bytes(16)), $client), 'unknown package rejected');
    rejects(fn () => PaymentService::createNativeOrder($userId, 'credits_10', 'bad', $client), 'invalid idempotency key rejected');
    $requestId = bin2hex(random_bytes(16));
    $order = PaymentService::createNativeOrder($userId, 'credits_10', $requestId, $client);
    $nativeBody = json_decode((string) end($requests)->getBody(), true);
    check($nativeBody['amount']['total'] === 1000 && $order['credit_amount'] === 1000.0, '10 CNY priced in integer fen and grants 1000 credits');
    check(str_starts_with($order['code_url'], 'weixin://wxpay/'), 'verified Native response yields QR URL');
    check(end($requests)->hasHeader('Authorization'), 'official SDK signs outgoing request');
    check(strtotime($nativeBody['time_expire']) > time(), 'Native order has explicit expiry');
    $requestCount = count($requests);
    $retry = PaymentService::createNativeOrder($userId, 'credits_10', $requestId, $client);
    check($retry['id'] === $order['id'] && count($requests) === $requestCount, 'repeated checkout returns the same order without another upstream request');
    foreach (['credits_100' => [10000, 10000.0], 'credits_500' => [50000, 50000.0]] as $id => [$fen, $credits]) {
        $tier = PaymentService::createNativeOrder($userId, $id, bin2hex(random_bytes(16)), $client);
        check((int) round($tier['amount_cny'] * 100) === $fen && $tier['credit_amount'] === $credits, $id . ' server pricing');
    }
    rejects(fn () => PaymentService::orderStatus($userId + 1, $order['id'], $client), 'another user cannot inspect an order');
    check(count(PaymentService::recentOrders($userId + 1)) === 0, 'order history isolated by user');

    $transaction = [
        'appid' => $settings['app_id'], 'mchid' => $settings['merchant_id'],
        'out_trade_no' => $order['order_no'], 'transaction_id' => 'test_transaction_1',
        'trade_state' => 'SUCCESS', 'success_time' => date(DATE_RFC3339),
        'amount' => ['total' => 1000, 'currency' => 'CNY'],
    ];
    $notification = function (array $data, ?string $time = null) use ($settings, $platformKey, $serial): array {
        $body = json_encode([
            'event_type' => 'TRANSACTION.SUCCESS', 'resource_type' => 'encrypt-resource',
            'resource' => ['algorithm' => 'AEAD_AES_256_GCM', 'nonce' => 'test_iv_1234', 'associated_data' => 'transaction',
                'ciphertext' => AesGcm::encrypt(json_encode($data), $settings['api_v3_key'], 'test_iv_1234', 'transaction')],
        ]);
        $timestamp = $time ?? (string) time();
        $nonce = 'notify_nonce';
        return [$body, ['timestamp' => $timestamp, 'nonce' => $nonce, 'serial' => $serial,
            'signature' => Rsa::sign(Formatter::joinedByLineFeed($timestamp, $nonce, $body), $platformKey)]];
    };
    [$body, $headers] = $notification($transaction);
    rejects(fn () => PaymentService::handleNotify($body . ' ', $headers, $callbackClient), 'changed raw callback bytes rejected');
    rejects(fn () => PaymentService::handleNotify($body, array_replace($headers, ['serial' => 'UNKNOWN']), $callbackClient), 'unknown signer serial rejected');
    [$staleBody, $staleHeaders] = $notification($transaction, (string) (time() - 601));
    rejects(fn () => PaymentService::handleNotify($staleBody, $staleHeaders, $callbackClient), 'stale callback signature rejected');
    foreach ([
        'incorrect amount' => ['amount' => ['total' => 1, 'currency' => 'CNY']],
        'incorrect currency' => ['amount' => ['total' => 1000, 'currency' => 'USD']],
        'incorrect merchant' => ['mchid' => 'another_merchant'],
        'incorrect app' => ['appid' => 'another_app'],
        'non-success state' => ['trade_state' => 'NOTPAY'],
    ] as $name => $changes) {
        [$badBody, $badHeaders] = $notification(array_replace($transaction, $changes));
        rejects(fn () => PaymentService::handleNotify($badBody, $badHeaders, $callbackClient), $name . ' rejected after signature verification');
    }
    check((float) User::find($userId)->getAttr('credit_balance') === 7.5 && CreditLedger::count() === 0, 'invalid notifications leave balances and ledger untouched');
    PaymentService::handleNotify($body, $headers, $callbackClient);
    PaymentService::handleNotify($body, $headers, $callbackClient);
    check((float) User::find($userId)->getAttr('credit_balance') === 1007.5 && CreditLedger::count() === 1, 'duplicate callbacks credit exactly once');
    check(PaymentOrder::find($order['id'])->getAttr('status') === 'paid', 'successful callback marks order paid');
    $queryTransaction = $transaction;
    $requestCount = count($requests);
    PaymentService::orderStatus($userId, $order['id'], $client);
    check(count($requests) === $requestCount, 'paid orders do not call provider again');

    $fallback = PaymentService::createNativeOrder($userId, 'credits_10', bin2hex(random_bytes(16)), $client);
    $queryTransaction = array_replace($transaction, ['out_trade_no' => $fallback['order_no'], 'transaction_id' => 'test_transaction_2']);
    $result = PaymentService::orderStatus($userId, $fallback['id'], $client);
    check($result['status'] === 'paid' && CreditLedger::count() === 2, 'verified query repairs a missing callback');
    check(str_contains((string) end($requests)->getUri(), $fallback['order_no']), 'query preserves uppercase order number');

    $mode = 'timeout';
    $unknown = PaymentService::createNativeOrder($userId, 'credits_10', bin2hex(random_bytes(16)), $client);
    check($unknown['status'] === 'pending' && $unknown['code_url'] === '', 'ambiguous submission timeout remains recoverable');
    $mode = 'bad_signature';
    $unverified = PaymentService::createNativeOrder($userId, 'credits_10', bin2hex(random_bytes(16)), $client);
    check($unverified['code_url'] === '', 'SDK refuses QR from an unverified upstream response');
    $result = PaymentService::orderStatus($userId, $unknown['id'], $client);
    check($result['status'] === 'pending' && $result['sync_warning'] !== '', 'unverified query cannot settle credits');

    $mode = 'ok';
    $late = PaymentService::createNativeOrder($userId, 'credits_10', bin2hex(random_bytes(16)), $client);
    PaymentOrder::where('id', $late['id'])->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);
    $queryTransaction = array_replace($transaction, ['out_trade_no' => $late['order_no'], 'trade_state' => 'NOTPAY']);
    $result = PaymentService::orderStatus($userId, $late['id'], $client);
    check($result['status'] === 'closed' && str_ends_with(end($requests)->getUri()->getPath(), '/close'), 'expired unpaid order closed through provider API');
    [$lateBody, $lateHeaders] = $notification(array_replace($transaction, ['out_trade_no' => $late['order_no'], 'transaction_id' => 'test_transaction_3']));
    PaymentService::handleNotify($lateBody, $lateHeaders, $callbackClient);
    check(PaymentOrder::find($late['id'])->getAttr('status') === 'paid' && CreditLedger::count() === 3, 'late authenticated success credits a locally closed order');

    $rollback = PaymentService::createNativeOrder($userId, 'credits_10', bin2hex(random_bytes(16)), $client);
    $before = (float) User::find($userId)->getAttr('credit_balance');
    [$collisionBody, $collisionHeaders] = $notification(array_replace($transaction, ['out_trade_no' => $rollback['order_no']]));
    rejects(fn () => PaymentService::handleNotify($collisionBody, $collisionHeaders, $callbackClient), 'same provider transaction cannot fund two orders');
    check((float) User::find($userId)->getAttr('credit_balance') === $before && CreditLedger::count() === 3,
        'failed settlement rolls back balance and ledger together');

    $concurrent = PaymentService::createNativeOrder($userId, 'credits_10', bin2hex(random_bytes(16)), $client);
    [$parallelBody, $parallelHeaders] = $notification(array_replace($transaction,
        ['out_trade_no' => $concurrent['order_no'], 'transaction_id' => 'parallel_transaction']));
    $fixturePath = $fixtureDir . '/callback.json';
    file_put_contents($fixturePath, json_encode([
        'database' => $app->config->get('database'), 'body' => $parallelBody,
        'headers' => $parallelHeaders, 'settings' => $settings,
    ]));
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--callback-worker', $fixturePath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrent callback worker');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'concurrent callback worker completed: ' . trim($output));
    }
    check((float) User::find($userId)->getAttr('credit_balance') === $before + 1000 && CreditLedger::count() === 4,
        'parallel callbacks serialized by MySQL order lock and credited exactly once');
    echo "\n$count checks passed against disposable MySQL with signed/encrypted fixtures.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    $testExitCode = 1;
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    foreach (glob($fixtureDir . '/*') ?: [] as $path) unlink($path);
    rmdir($fixtureDir);
}
exit($testExitCode ?? 0);
