<?php

declare(strict_types=1);

// Local-only provider fixture for the prompt assistant transport checks.
header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_URI'] !== '/chat/completions' || !is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'Unexpected fixture request']]);
    return;
}
$messageContent = $payload['messages'][1]['content'] ?? '';
$parts = is_array($messageContent) ? $messageContent : [];
$input = json_decode($parts[0]['text'] ?? (is_string($messageContent) ? $messageContent : ''), true);
$case = $input['fixture_case'] ?? 'normal';
$errorStatuses = ['upstream_error' => 401, 'insufficient_balance' => 402, 'forbidden' => 403, 'missing_model' => 404,
    'invalid_parameters' => 400, 'rate_limited' => 429, 'unavailable' => 503];
if (isset($errorStatuses[$case])) {
    http_response_code($errorStatuses[$case]);
    echo json_encode(['error' => ['message' => 'Private upstream diagnostic must not reach the user']]);
    return;
}
$result = ['thinking_disabled' => ($payload['thinking']['type'] ?? '') === 'disabled',
    'json_mode' => ($payload['response_format']['type'] ?? '') === 'json_object',
    'no_tools' => !isset($payload['tools']), 'authorized' => ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer fixture-only-key',
    'system_role' => ($payload['messages'][0]['role'] ?? '') === 'system'];
$content = $case === 'malformed' ? 'not JSON' : json_encode($result);
$badReview = '{"issues":["Product facts need verification."]},"notes":["Text review only."]}';
if ($case === 'review_extra_brace') $content = $badReview;
if ($case === 'array_root') $content = '[]';
if ($case === 'vision') {
    $content = json_encode(['image_count' => count(array_filter($parts, static fn (array $part): bool => ($part['type'] ?? '') === 'image_url')),
        'inline_image' => str_starts_with($parts[1]['image_url']['url'] ?? '', 'data:image/')]);
}
if (str_starts_with($payload['model'] ?? '', 'deepseek-fixture-')) {
    $scenario = substr($payload['model'], strlen('deepseek-fixture-'));
    $isRepair = array_key_exists('previous_response', $input);
    if ($parts !== [] && isset($input['references'])) {
        $content = '{"observations":["A purple product."],"uncertain":[]}';
    } elseif (isset($input['result'])) {
        if ($scenario === 'unavailable' || ($scenario === 'repair_unavailable' && $isRepair)) {
            http_response_code(503);
            echo json_encode(['error' => ['message' => 'Private fixture review failure']]);
            return;
        }
        $content = !$isRepair || $scenario === 'invalid' ? $badReview : '{"issues":[],"notes":[]}';
    } elseif (isset($input['methods'])) {
        $content = json_encode(['title' => 'Earphones', 'prompt_body' => 'Earphones with water droplets on a clean background.',
            'reference_usage' => empty($input['context']['references']) ? [] : [['reference_index' => 1, 'instruction' => 'Preserve the product exterior; replace the background.']],
            'shots' => [], 'assumptions' => [], 'asset_requirements' => []]);
    } else {
        $content = json_encode(['action' => 'generate_prompt', 'reply' => 'Prompt ready',
            'brief' => ['subject' => 'earphones', 'goal' => 'product detail image'],
            'questions' => [], 'skill_ids' => ['builtin:product-image'], 'reason' => 'Product photography', 'candidate' => null]);
    }
    if (in_array($scenario, ['all_repairs', 'image_budget'], true) && !$isRepair) $content = '{';
}
if ($case === 'fenced') {
    $content = "```json\n" . $content . "\n```";
}
echo json_encode(['choices' => [['message' => ['content' => $content], 'finish_reason' => $case === 'truncated' ? 'length' : 'stop']],
    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30]]);
