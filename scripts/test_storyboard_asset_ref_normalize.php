<?php

declare(strict_types=1);

/**
 * 纯函数冒烟：验证分镜「图片N：资产名」→「@资产名」规范化。
 * 不依赖 ThinkPHP 容器，直接复刻 SeriesController 中的正则逻辑。
 */

function normalizeStoryboardAssetReferenceFormat(string $text): string
{
    if (trim($text) === '') {
        return $text;
    }

    $text = preg_replace_callback(
        '/^([ \t]*(?:引用资产|Referenced\s+assets)[：:]\s*)(.+)$/imu',
        static function (array $matches): string {
            $prefix = (string) ($matches[1] ?? '');
            $body = (string) ($matches[2] ?? '');
            $body = preg_replace('/(?:图片|Image)\s*(\d+)\s*[：:]\s*/iu', '', $body) ?? $body;
            $body = preg_replace('/(?:图片|Image)\s*\d+/iu', '', $body) ?? $body;
            $body = glueStoryboardReferenceLineBody($body);
            if ($body === '') {
                return rtrim($prefix);
            }

            return $prefix . $body;
        },
        $text,
    ) ?? $text;

    $text = preg_replace(
        '/(?:图片|Image)\s*\d+\s*[：:]\s*([^\s,，、;；。：:（）()【】\[\]]+)/iu',
        '@$1',
        $text,
    ) ?? $text;

    $text = preg_replace('/(?:图片|Image)\s*\d+/iu', '', $text) ?? $text;

    return $text;
}

function glueStoryboardReferenceLineBody(string $body): string
{
    $body = preg_replace('/[，,;；、|｜]+/u', ' ', $body) ?? $body;
    $body = preg_replace('/\s+/u', ' ', trim($body)) ?? trim($body);
    if ($body === '') {
        return '';
    }

    $tokens = preg_split('/\s+/u', $body) ?: [];
    $hasAt = false;
    foreach ($tokens as $token) {
        if (str_starts_with(trim((string) $token), '@')) {
            $hasAt = true;
            break;
        }
    }
    if (!$hasAt) {
        $names = [];
        $seenBare = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token, " \t\n\r\0\x0B,，、;；。");
            if ($token === '') {
                continue;
            }
            $mention = '@' . ltrim($token, '@');
            if (isset($seenBare[$mention])) {
                continue;
            }
            $seenBare[$mention] = true;
            $names[] = $mention;
        }
        return implode(' ', $names);
    }

    $mentions = [];
    $current = '';
    foreach ($tokens as $token) {
        $token = trim((string) $token, " \t\n\r\0\x0B,，、;；。");
        if ($token === '') {
            continue;
        }
        if (str_starts_with($token, '@')) {
            if ($current !== '') {
                $mentions[] = $current;
            }
            $current = '@' . ltrim($token, '@');
            continue;
        }
        if ($current !== '') {
            $current .= ' ' . $token;
            continue;
        }
        $current = '@' . ltrim($token, '@');
    }
    if ($current !== '') {
        $mentions[] = $current;
    }

    $mentions = array_map(
        static fn (string $mention): string => preg_replace('/(\S+)\s+\1·/u', '$1·', $mention) ?? $mention,
        $mentions,
    );

    return implode(' ', array_values(array_unique($mentions)));
}

function mergeAdjacentStoryboardMentions(string $body, array $labels): string
{
    preg_match_all('/@[^@]+/u', $body, $matches);
    $parts = array_values(array_filter(array_map('trim', $matches[0] ?? [])));
    if ($parts === []) {
        return $body;
    }
    $labelSet = [];
    foreach ($labels as $label) {
        $labelSet[mb_strtolower(trim((string) $label))] = trim((string) $label);
    }
    $merged = [];
    $i = 0;
    $count = count($parts);
    while ($i < $count) {
        $best = $parts[$i];
        $consumed = 1;
        $acc = ltrim($parts[$i], '@');
        for ($j = $i + 1; $j < $count; $j++) {
            $next = ltrim($parts[$j], '@');
            $joined = $acc . ' ' . $next;
            $collapsed = preg_replace('/(\S+)\s+\1·/u', '$1·', $joined) ?? $joined;
            $hit = $labelSet[mb_strtolower(trim($collapsed))] ?? $labelSet[mb_strtolower(trim($joined))] ?? null;
            if ($hit === null) {
                break;
            }
            $best = '@' . $hit;
            $acc = $hit;
            $consumed = $j - $i + 1;
        }
        $single = $labelSet[mb_strtolower(ltrim($best, '@'))] ?? null;
        if (is_string($single) && $single !== '') {
            $best = '@' . $single;
        }
        $merged[] = $best;
        $i += $consumed;
    }

    return implode(' ', $merged);
}

$tests = 0;

function assertTrue(bool $cond, string $label): void
{
    global $tests;
    $tests++;
    if (!$cond) {
        fwrite(STDERR, "Assertion failed [{$label}]\n");
        exit(1);
    }
}

function assertContains(string $needle, string $haystack, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label . " missing: {$needle}\n---\n{$haystack}\n---");
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    assertTrue(!str_contains($haystack, $needle), $label . " still has: {$needle}\n---\n{$haystack}\n---");
}

$sample = <<<'T'
【视频节点01｜15s｜延期危机】
引用资产：图片1：导演·第1集常服、图片2：排期表、图片3：财务报表

镜头1（0-1s｜景别：中近景）：@导演·第1集常服 坐在昏暗的办公室工位前，眉头紧锁，手指用力按在 @排期表 上，纸上红圈标注「延期 3 天」格外刺眼。

镜头2（1-2s｜景别：特写）：镜头切至 @财务报表 特写，红笔圈出的「2万/小时」制作成本被手指重重戳点。

镜头3（2-3s｜景别：特写）：画面快切至电脑屏幕，图片4：错误提示 一闪而过。

定焦画面（14-15s｜景别：中近景）：@导演·第1集常服 双手撑桌，低头凝视桌面上散落的 @排期表 和 @财务报表。
T;

$out = normalizeStoryboardAssetReferenceFormat($sample);

assertNotContains('图片1', $out, 'cn-prefix-1');
assertNotContains('图片2', $out, 'cn-prefix-2');
assertNotContains('图片3', $out, 'cn-prefix-3');
assertNotContains('图片4', $out, 'cn-prefix-4');
assertNotContains('图片', $out, 'any-图片');
assertContains('引用资产：@导演·第1集常服 @排期表 @财务报表', $out, 'ref-line');
assertContains('@错误提示', $out, 'body-image-n');

$en = <<<'T'
【Video Node 01｜15s｜Hook】
Referenced assets: Image 1: DirectorCasual, Image 2: ScheduleBoard

Shot 1（0-5s｜Shot size: Medium）：@DirectorCasual stares at Image 3: CostReport.
T;

$enOut = normalizeStoryboardAssetReferenceFormat($en);
assertNotContains('Image 1', $enOut, 'en-prefix-1');
assertNotContains('Image 2', $enOut, 'en-prefix-2');
assertNotContains('Image 3', $enOut, 'en-prefix-3');
assertTrue(!preg_match('/Image\s*\d+/i', $enOut), 'en-no-image-n');
assertContains('Referenced assets: @DirectorCasual @ScheduleBoard', $enOut, 'en-ref-line');
assertContains('@CostReport', $enOut, 'en-body-cost');

$split = 'Referenced assets: @Jude Parker @Parker·Episode 1 Casual Travel Wear';
$splitOut = normalizeStoryboardAssetReferenceFormat($split);
assertContains('@Jude Parker', $splitOut, 'glue-jude-parker');
assertNotContains('@Parker ', $splitOut . ' ', 'no-bare-parker-token');
assertContains('@Parker·Episode 1 Casual Travel Wear', $splitOut, 'glue-look-rest');

$labels = ['Jude Parker', 'June Harper', 'Jude Parker·Episode 1 Casual Travel Wear'];
$merged = mergeAdjacentStoryboardMentions($splitOut, $labels);
assertContains('@Jude Parker·Episode 1 Casual Travel Wear', $merged, 'merge-full-look');
assertNotContains('@Jude @Parker', $merged, 'merged-not-split');

echo "Storyboard asset ref normalize tests passed: {$tests}\n";
