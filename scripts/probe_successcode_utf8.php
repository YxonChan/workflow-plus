<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$bad = "a\xC3\x28b";
$resp = successCode(['x' => $bad, 'ok' => '已取消 1 个排队任务'], '已取消 1 个排队任务');
$out = $resp->getContent();
echo $out . PHP_EOL;
echo (str_contains($out, 'Malformed') ? "HAS_ERR\n" : "OK\n");
