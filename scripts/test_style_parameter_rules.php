<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/topthink/framework/src/helper.php';

use app\controller\StylePresetController;
use app\support\SeriesStylePrompt;
use app\support\StyleParameterRules;
use app\support\StylePresetDefaults;
use think\exception\HttpException;

function check(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    echo "PASS: {$name}\n";
}

$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/style_parameter_cases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    $parameters = StyleParameterRules::normalize($case['raw']);
    $valid = true;
    try {
        StyleParameterRules::validate($parameters, $case['style']);
    } catch (InvalidArgumentException) {
        $valid = false;
    }
    check($valid === $case['valid'], $case['name']);
    foreach ($case['contains'] ?? [] as $key => $expected) {
        check($parameters[$key] === $expected, $case['name'] . ': preserves ' . $key);
    }
    check(StyleParameterRules::normalize($parameters) === $parameters, $case['name'] . ': idempotent');
}

foreach (StylePresetDefaults::official() as $preset) {
    check(count($preset['visual_styles']) === 1, $preset['code'] . ': one visual style');
    StyleParameterRules::validate($preset['parameters'], $preset['visual_styles'][0]);
}
check(!str_contains(SeriesStylePrompt::imageStyleRule('anime'), '色彩鲜艳'), 'anime defaults allow desaturated colors');
check(!str_contains(SeriesStylePrompt::imageStyleRule('3d'), '高对比度'), '3D defaults allow low contrast');
check(!str_contains(SeriesStylePrompt::imageStyleRule('3d'), '精细建模'), '3D defaults allow low poly');

// Exercise the actual save normalization without a database or a running application.
$reflection = new ReflectionClass(StylePresetController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizePayload');
$base = ['name' => 'Test', 'visual_styles' => ['anime'], 'parameters' => ['rendering' => ['Cel Animation'], 'palette' => ['Sepia Tone']],
    'prompt_mode' => 'parameters', 'prompt' => 'STALE CLIENT PROMPT', 'technique_prompt' => 'STALE TECHNIQUE',
    'lighting_prompt' => 'STALE SHOT LIGHTING', 'prompt_extra' => 'Additional detail'];
$saved = $normalize->invoke($controller, $base);
check(!str_contains($saved['prompt'], 'STALE'), 'save rebuilds prompt from current parameters');
check(str_contains($saved['prompt'], 'Sepia Tone') && str_contains($saved['prompt'], 'Additional detail'), 'save includes tone and supplemental text');
check(str_contains($saved['technique_prompt'], 'Cel Animation'), 'save derives character technique from rendering selection');
check($saved['lighting_prompt'] === '', 'parameter mode does not force shot lighting globally');
check(!str_contains($saved['prompt'], 'Cel Animation'), 'technique stays separate from tone');
$changed = $base;
$changed['parameters']['palette'] = ['Cold Blue Night'];
$updated = $normalize->invoke($controller, $changed);
check(str_contains($updated['prompt'], 'Cold Blue Night') && !str_contains($updated['prompt'], 'Sepia Tone'), 'parameter update cannot retain stale generated tone');

foreach ([
    'multiple visual styles' => ['visual_styles' => ['anime', 'realistic']],
    'conflicting contrast' => ['parameters' => ['contrast' => ['High Contrast Hard', 'Low Contrast Flat']]],
    'wrong rendering family' => ['parameters' => ['rendering' => ['Clay Stop Motion']]],
    'unsupported custom value' => ['parameters' => ['palette' => ['unknown value']]],
    'missing tone' => ['parameters' => ['rendering' => ['Cel Animation']]],
] as $name => $override) {
    $rejected = false;
    try {
        $normalize->invoke($controller, array_replace($base, $override));
    } catch (HttpException $error) {
        $rejected = $error->getStatusCode() === 422;
    }
    check($rejected, 'API rejects ' . $name);
}
$manual = $normalize->invoke($controller, array_replace($base, ['prompt_mode' => 'manual']));
check($manual['prompt'] === 'STALE CLIENT PROMPT' && $manual['technique_prompt'] === 'STALE TECHNIQUE', 'manual prompts remain editable and preserved');
echo "All style parameter checks passed.\n";
