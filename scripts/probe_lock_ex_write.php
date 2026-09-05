<?php

declare(strict_types=1);

function timedWrite(string $path, int $bytes, int $flags = 0): void
{
    $data = str_repeat('x', $bytes);
    $started = microtime(true);
    $written = @file_put_contents($path, $data, $flags);
    $ms = (int) round((microtime(true) - $started) * 1000);
    $ok = $written !== false ? 'ok' : 'fail';
    $label = ($flags & LOCK_EX) ? 'LOCK_EX' : 'NOLOCK';
    $mb = (int) round($bytes / 1048576);
    echo "{$label} {$mb}MB {$path}: {$ok} {$ms}ms\n";
}

$storage = '/var/www/html/public/storage/_locktest.bin';
$tmp = sys_get_temp_dir() . '/_locktest.bin';

echo "php-fpm procs:\n";
passthru("ps aux | grep php-fpm | grep -v grep");
echo "---\n";

timedWrite($storage, 1024 * 1024, LOCK_EX);
timedWrite($storage, 1024 * 1024, 0);
timedWrite($storage, 8 * 1024 * 1024, LOCK_EX);
timedWrite($tmp, 8 * 1024 * 1024, LOCK_EX);

@unlink($storage);
@unlink($tmp);
