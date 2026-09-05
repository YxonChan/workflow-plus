<?php

declare(strict_types=1);

/**
 * 校验本地剧本分集标记正则，覆盖剧本创作常见的「第N集」格式。
 * 用法：php scripts/test_local_script_episode_markers.php
 */

$regex = '/^[ \t]*(?:(?:EPISODE|Episode|episode)\s*([0-9一二三四五六七八九十百]+)|第\s*([0-9一二三四五六七八九十百]+)\s*集)(?:\s*[：:\-—–|｜]\s*|\s+)?([^\r\n]*?)[ \t]*$/mu';

$cases = [
    ['第1集', true, '1', ''],
    ['第 1 集', true, '1', ''],
    ['第1集：开端', true, '1', '开端'],
    ['第 1 集：开端', true, '1', '开端'],
    ['第 1 集 开端', true, '1', '开端'],
    ['第01集', true, '01', ''],
    ['第1集标题', true, '1', '标题'],
    ['EPISODE 1', true, '1', ''],
    ['Episode 1: Title', true, '1', 'Title'],
    ['EPISODE 1 - Title', true, '1', 'Title'],
    ['episode 2 | Hook', true, '2', 'Hook'],
    ['第一集', true, '一', ''],
    ['第 十二 集：转折', true, '十二', '转折'],
    ['第1', false, '', ''],
    ['集1', false, '', ''],
    ['这是第1集的摘要', false, '', ''],
];

$failed = 0;
foreach ($cases as [$sample, $shouldMatch, $expectedNumber, $expectedTitle]) {
    $matched = preg_match($regex, $sample, $m) === 1;
    $number = '';
    $title = '';
    if ($matched) {
        $number = trim((string) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')));
        $title = trim((string) ($m[3] ?? ''));
    }

    $ok = $matched === $shouldMatch
        && (!$shouldMatch || ($number === $expectedNumber && $title === $expectedTitle));

    if (!$ok) {
        $failed++;
        fwrite(STDERR, "FAIL: {$sample} matched=" . ($matched ? 'yes' : 'no') . " number={$number} title={$title}\n");
    } else {
        echo "OK: {$sample}\n";
    }
}

$script = <<<'TEXT'
标题
编剧 Xunboo

第1集：开端
内景 咖啡厅 日
小明走进来。

第 2 集 冲突
外景 街道 夜
小红追了上去。

EPISODE 3: Resolution
INT. OFFICE - DAY
They talk.
TEXT;

preg_match_all($regex, $script, $all, PREG_OFFSET_CAPTURE);
$count = count($all[0] ?? []);
if ($count !== 3) {
    $failed++;
    fwrite(STDERR, "FAIL: multiline expected 3 episodes, got {$count}\n");
} else {
    echo "OK: multiline episode count=3\n";
}

if ($failed > 0) {
    fwrite(STDERR, "FAILED {$failed} assertion(s)\n");
    exit(1);
}

echo "ALL PASSED\n";
exit(0);
