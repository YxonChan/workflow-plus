<?php

declare(strict_types=1);

$counts = [];
$fpm = [];
foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
    $comm = @trim((string) @file_get_contents($dir . '/comm'));
    if ($comm === '') {
        continue;
    }
    $counts[$comm] = ($counts[$comm] ?? 0) + 1;
    if ($comm !== 'php-fpm') {
        continue;
    }
    $pid = basename($dir);
    $wchan = @trim((string) @file_get_contents($dir . '/wchan'));
    $stat = @trim((string) @file_get_contents($dir . '/stat'));
    $state = '';
    if ($stat !== '' && preg_match('/\)\s+(\S+)/', $stat, $m)) {
        $state = $m[1];
    }
    $fpm[] = "pid={$pid} state={$state} wchan={$wchan}";
}

arsort($counts);
echo "=== comm counts ===\n";
foreach ($counts as $name => $count) {
    echo str_pad((string) $count, 5, ' ', STR_PAD_LEFT) . " {$name}\n";
}
echo "=== php-fpm ===\n";
echo implode("\n", $fpm) . "\n";
