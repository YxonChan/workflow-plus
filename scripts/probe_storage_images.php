<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$root = rtrim((string) root_path('public'), '/\\');
$rows = think\facade\Db::name('asset_images')
    ->where('url', '<>', '')
    ->order('id', 'desc')
    ->limit(30)
    ->field('id,url')
    ->select()
    ->toArray();

$ok = 0;
$miss = 0;
foreach ($rows as $row) {
    $url = (string) $row['url'];
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    $local = $root . rawurldecode($path);
    if (is_file($local)) {
        $ok++;
        continue;
    }
    $miss++;
    echo 'MISS|' . $row['id'] . '|' . $url . '|' . $local . PHP_EOL;
}
echo "ok={$ok} miss={$miss}\n";

if ($rows !== []) {
    $sample = (string) $rows[0]['url'];
    $path = (string) (parse_url($sample, PHP_URL_PATH) ?: '');
    $enc = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $enc = '/' . $enc;
    echo "SAMPLE={$sample}\n";
    echo "ENC={$enc}\n";
}
