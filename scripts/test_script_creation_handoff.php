<?php

declare(strict_types=1);

/**
 * Offline checks for script-creation handoff parsing / episode split.
 * Usage: php scripts/test_script_creation_handoff.php
 */

require __DIR__ . '/../vendor/autoload.php';

use app\support\ScriptCreationService;

$ref = new ReflectionClass(ScriptCreationService::class);

$call = static function (string $method, array $args) use ($ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};

$failed = 0;
$assert = static function (bool $ok, string $label) use (&$failed): void {
    if ($ok) {
        echo "OK: {$label}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL: {$label}\n");
};

$parsed = $call('parseDeliverablePackage', [
    "<<<DELIVERABLE>>>\n完整剧本正文\n<<<HANDOFF>>>\n交接摘要A\n",
    'drafting',
]);
$assert(($parsed['deliverable'] ?? '') === '完整剧本正文', 'parse deliverable');
$assert(($parsed['handoff'] ?? '') === '交接摘要A', 'parse handoff');

$fallback = $call('parseDeliverablePackage', ["没有标记的长文本输出", 'direction']);
$assert(($fallback['deliverable'] ?? '') === '没有标记的长文本输出', 'fallback deliverable');
$assert(($fallback['handoff'] ?? '') !== '', 'fallback handoff non-empty');

$script = <<<'TEXT'
第1集：开端
内景 咖啡厅 日
小明走进来。

第2集：冲突
外景 街道 夜
小红追上去。
TEXT;
$episodes = $call('splitScriptEpisodes', [$script]);
$assert(count($episodes) === 2, 'split two episodes');
$assert(str_contains((string) ($episodes[0]['marker'] ?? ''), '第1集'), 'episode 1 marker');

$budgeted = $call('enforceInputBudget', ['system', str_repeat('中文内容', 80000), 8192]);
$assert(mb_strlen($budgeted) < mb_strlen(str_repeat('中文内容', 80000)), 'input budget trims oversized prompt');

if ($failed > 0) {
    fwrite(STDERR, "FAILED {$failed}\n");
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
