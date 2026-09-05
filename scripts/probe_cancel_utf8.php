<?php

declare(strict_types=1);

$files = [
    'app/controller/AssetController.php',
    'app/support/WorkerActions.php',
];

foreach ($files as $f) {
    $bin = file_get_contents($f);
    $ok = mb_check_encoding($bin, 'UTF-8');
    echo $f . ' utf8=' . ($ok ? 'yes' : 'NO') . ' size=' . strlen((string) $bin) . PHP_EOL;

    $needles = [
        '任务已由用户取消',
        '已取消',
        '没有可取消的排队任务',
        '任务已由数字员工取消',
        '仅 status=queued',
    ];
    foreach ($needles as $needle) {
        $pos = strpos((string) $bin, $needle);
        echo '  contains[' . $needle . ']=' . ($pos === false ? 'no' : ('yes@' . $pos)) . PHP_EOL;
        if ($pos !== false) {
            $slice = substr((string) $bin, max(0, $pos - 8), strlen($needle) + 16);
            echo '    hex=' . bin2hex($slice) . PHP_EOL;
            echo '    slice_utf8=' . (mb_check_encoding($slice, 'UTF-8') ? 'yes' : 'NO') . PHP_EOL;
        }
    }
}

$msgGood = '已取消 3 个排队任务';
$msgFromFile = null;
$controller = file_get_contents('app/controller/AssetController.php');
if (is_string($controller) && preg_match('/已取消 \{\$cancelled\} 个排队任务/u', $controller, $m)) {
    $msgFromFile = $m[0];
}

foreach ([
    'literal' => $msgGood,
    'from_file_pattern' => $msgFromFile ?? '',
] as $label => $msg) {
    $json = json_encode(['msg' => $msg], JSON_UNESCAPED_UNICODE);
    echo $label . ' json_ok=' . ($json !== false ? 'yes' : 'no') . ' err=' . json_last_error_msg() . ' utf8=' . (mb_check_encoding($msg, 'UTF-8') ? 'yes' : 'no') . PHP_EOL;
}

// Simulate ThinkPHP-ish response encoding path
$payload = [
    'cancelled' => 1,
    'series_id' => 1,
    'asset_id' => 0,
];
$full = [
    'code' => 0,
    'msg' => '已取消 1 个排队任务',
    'data' => $payload,
];
$json = json_encode($full, JSON_UNESCAPED_UNICODE);
echo 'full_json=' . ($json !== false ? $json : ('FAIL:' . json_last_error_msg())) . PHP_EOL;
