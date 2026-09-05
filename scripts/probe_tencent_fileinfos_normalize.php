<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use app\support\TencentVodAigcImageClient;

$client = new TencentVodAigcImageClient();
$ref = new ReflectionClass($client);
$method = $ref->getMethod('buildFileInfos');
$method->setAccessible(true);

$cases = [
    'plain_url' => [
        ['https://example.com/a.jpg'],
        [],
        [['Type' => 'Url', 'Url' => 'https://example.com/a.jpg']],
    ],
    'options_fileurl_legacy' => [
        [],
        ['FileInfos' => [['Type' => 'Url', 'FileUrl' => 'https://example.com/b.jpg']]],
        [['Type' => 'Url', 'Url' => 'https://example.com/b.jpg']],
    ],
    'options_fileid' => [
        [],
        ['file_infos' => [['Type' => 'File', 'FileId' => 'abc123']]],
        [['Type' => 'File', 'FileId' => 'abc123']],
    ],
    'skip_empty' => [
        [''],
        ['FileInfos' => [['Type' => 'Url', 'Url' => '']]],
        [],
    ],
];

$failed = 0;
foreach ($cases as $name => [$urls, $options, $expected]) {
    $got = $method->invoke($client, $urls, $options);
    $ok = $got === $expected;
    echo ($ok ? 'PASS' : 'FAIL') . " {$name} got=" . json_encode($got, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (!$ok) {
        $failed++;
        echo '  expected=' . json_encode($expected, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    foreach ($got as $item) {
        if (array_key_exists('FileUrl', $item)) {
            echo "FAIL {$name} leaked FileUrl key\n";
            $failed++;
        }
    }
}

exit($failed > 0 ? 2 : 0);
