<?php

declare(strict_types=1);

/**
 * 校验：中国地区不被英文默认分镜模板带偏；完整 source_text 优先于 description 摘要。
 */

require __DIR__ . '/../vendor/autoload.php';

use app\controller\SeriesController;

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

$ref = new ReflectionClass(SeriesController::class);

$lang = $ref->getMethod('nodePromptOutputLanguage');
$lang->setAccessible(true);
$rule = $ref->getMethod('storyboardRegionLanguageRule');
$rule->setAccessible(true);
$prefer = $ref->getMethod('preferFullSeriesSourceText');
$prefer->setAccessible(true);

$controller = $ref->newInstanceWithoutConstructor();

$englishTemplate = 'Please split ... Output English natural-language storyboard prompts. Write visual descriptions in English.';
assertTrue($lang->invoke($controller, $englishTemplate) === '', '英文默认模板不应被识别为显式英文指令');
assertTrue($lang->invoke($controller, '请输出英文分镜，必须使用英文') === 'english', '显式中文“输出英文”应识别为 english');
assertTrue($lang->invoke($controller, '请输出中文分镜，必须使用中文') === 'chinese', '显式中文“输出中文”应识别为 chinese');

$chinaRule = (string) $rule->invoke($controller, 'china', $englishTemplate);
assertTrue(str_contains($chinaRule, '必须使用中文'), 'china + 英文模板 → 仍要求中文输出');
assertTrue(!str_contains($chinaRule, '已明确要求英文输出'), 'china + 英文模板 → 不应判定为用户要求英文');

$westernRule = (string) $rule->invoke($controller, 'western', $englishTemplate);
assertTrue(str_contains($westernRule, '必须使用英文'), 'western 默认英文');

$full = str_repeat("第1集\n正文\n", 20) . "第2集\n更多正文";
$short = mb_substr($full, 0, 200);
assertTrue($prefer->invoke($controller, $short, $full) === $full, '完整 source_text 应覆盖短摘要');
assertTrue($prefer->invoke($controller, $full, '') === $full, '无存档时沿用请求体');
assertTrue($prefer->invoke($controller, '', $full) === $full, '空请求体时用存档正文');

echo "ALL_PASSED\n";
