<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$store = sys_get_temp_dir() . '/toapis-media-fixture.json';
$state = is_file($store) ? json_decode(file_get_contents($store), true) : ['posts' => [], 'gets' => []];
header('Content-Type: application/json');
if ($path === '/fixture/reset') {
    file_put_contents($store, json_encode(['posts' => [], 'gets' => []]));
    echo '{}';
    return;
}
if ($path === '/fixture/state') {
    echo json_encode($state);
    return;
}
if ($path === '/fixture.png') {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
    return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/videos/generations') {
    $state['video_post'] = json_decode(file_get_contents('php://input'), true);
    file_put_contents($store, json_encode($state));
    echo json_encode(['id' => 'tsk_video_fixture', 'status' => 'queued']);
    return;
}
if ($path === '/v1/videos/generations/tsk_video_fixture') {
    $state['video_gets'] = ($state['video_gets'] ?? 0) + 1;
    file_put_contents($store, json_encode($state));
    echo json_encode($state['video_gets'] === 1 ? ['status' => 'queued']
        : ['status' => 'completed', 'video_url' => 'http://api.toapis.cn:18088/storage/video-fixture.mp4']);
    return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/images/generations') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = 'tsk_img_fixture_' . (count($state['posts']) + 1);
    $state['posts'][$id] = $body;
    file_put_contents($store, json_encode($state));
    echo json_encode(['id' => $id, 'object' => 'generation.task', 'status' => 'pending']);
    return;
}
if (preg_match('#^/v1/images/generations/(tsk_img_fixture_\d+)$#', $path, $match)) {
    $id = $match[1];
    $calls = ($state['gets'][$id] ?? 0) + 1;
    $state['gets'][$id] = $calls;
    file_put_contents($store, json_encode($state));
    $body = $state['posts'][$id];
    $pending = str_contains($body['prompt'], 'delayed') && $calls === 1;
    $billing = ['status' => 'settled', 'cost_usd' => '0.004772'];
    if (str_contains($body['prompt'], 'billing-pending') && $calls === 1) $billing = ['status' => 'pending'];
    if (str_contains($body['prompt'], 'zero')) $billing = ['status' => 'settled', 'cost_usd' => '0'];
    echo json_encode(['id' => $id, 'status' => $pending ? 'in_progress' : 'completed',
        'result' => $pending ? null : ['data' => [['url' => 'http://api.toapis.cn:18088/fixture.png']]],
        'billing' => $billing, 'usage' => ['input_tokens' => 17, 'output_tokens' => 196, 'total_tokens' => 213]]);
    return;
}
http_response_code(404);
echo json_encode(['error' => 'Unexpected fixture request']);
