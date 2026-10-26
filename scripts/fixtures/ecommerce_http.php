<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$store = sys_get_temp_dir() . '/ecommerce-http-fixture.json';
$state = is_file($store) ? json_decode(file_get_contents($store), true) : ['posts' => [], 'gets' => []];
header('Content-Type: application/json');
if ($path === '/reset') { file_put_contents($store, json_encode(['posts' => [], 'gets' => [], 'result_url' => $_GET['result_url'] ?? '/storage/fixtures/ecommerce.png'])); echo '{}'; return; }
if ($path === '/state') { echo json_encode($state); return; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/chat/completions') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $content = $payload['messages'][1]['content'];
    $input = json_decode(is_array($content) ? $content[0]['text'] : $content, true);
    $state['text_posts'][] = $payload;
    file_put_contents($store, json_encode($state));
    if (($input['task'] ?? '') === 'ecommerce_material_analysis') {
        $result = ['materials' => array_map(static fn ($media) => ['id' => $media['id'],
            'kind' => str_contains($media['name'], 'person') ? 'person' : 'product',
            'summary' => str_contains($media['name'], 'person') ? '一位穿浅色衣服的成年女性，无法判断真实身份。' : '一桶带提手的商品，包装细节以参考图为准。'], $input['materials'])];
        echo json_encode(['choices' => [['message' => ['content' => json_encode($result, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'total_tokens' => 1200]]); return;
    }
    $brief = ($input['task'] ?? '') === 'ecommerce_brief_consult';
    if (isset($input['image_analysis_available'])) {
        $result = ['headline' => '日常之选', 'subtitle' => '让生活多一点心动', 'selling_points' => ['简洁外观'], 'product_name' => '商品', 'observations' => ['以用户图片及资料为依据']];
        echo json_encode(['choices' => [['message' => ['content' => json_encode($result, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100]]); return;
    }
    $result = ['title' => '回家送油', 'characters' => [
        ['id' => 'c1', 'name' => '女儿', 'description' => '成年女性，齐肩黑发，浅色上衣'],
        ['id' => 'c2', 'name' => '妈妈', 'description' => '中年女性，短发，家居围裙']],
        'shots' => [['start' => 0, 'end' => $input['duration'], 'action' => '女儿将油递给妈妈，妈妈在厨房炒菜。',
            'dialogue' => [['character_id' => 'c2', 'text' => '这油多少钱？'], ['character_id' => 'c1', 'text' => '现在买很实惠。']]]], 'warnings' => ['优惠信息发布前需核实']];
    if ($brief) {
        $ready = isset($input['answered_questions']['tone']);
        $result += ['ready' => $ready, 'message' => $ready ? '已按你的选择整理好视频方案。' : '想让这段视频呈现哪种感觉？',
            'questions' => $ready ? [] : [['id' => 'tone', 'text' => '这段视频想要哪种感觉？', 'options' => ['自然生活感', '精致广告感']]],
            'proposal' => $ready ? $input['duration'] . '秒竖屏短片：女儿带油回家，妈妈在厨房炒菜，两人自然交流价格；商品保持参考图外观。' : ''];
    }
    echo json_encode(['choices' => [['message' => ['content' => json_encode($result, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100]]); return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/images/generations') {
    $id = 'tsk_ecommerce_' . (count($state['posts']) + 1);
    $state['posts'][$id] = json_decode(file_get_contents('php://input'), true);
    file_put_contents($store, json_encode($state));
    echo json_encode(['id' => $id, 'object' => 'generation.task', 'status' => 'pending']); return;
}
if (preg_match('#^/v1/images/generations/(tsk_ecommerce_\d+)$#', $path, $m)) {
    $id = $m[1]; $state['gets'][$id] = ($state['gets'][$id] ?? 0) + 1;
    file_put_contents($store, json_encode($state));
    echo json_encode(['id' => $id, 'status' => $state['gets'][$id] === 1 ? 'in_progress' : 'completed',
        'result' => ['data' => [['url' => $state['result_url']]]],
        'billing' => ['status' => 'settled', 'cost_usd' => '0.004772'], 'usage' => ['input_tokens' => 17, 'output_tokens' => 196]]); return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/videos/generations') {
    $state['video_posts'][] = json_decode(file_get_contents('php://input'), true);
    file_put_contents($store, json_encode($state));
    echo json_encode(['id' => 'tsk_ecommerce_video', 'status' => 'queued']); return;
}
if ($path === '/v1/videos/generations/tsk_ecommerce_video') {
    $state['video_gets'] = ($state['video_gets'] ?? 0) + 1;
    file_put_contents($store, json_encode($state));
    echo json_encode(['status' => 'completed', 'video_url' => 'http://127.0.0.1:18089/storage/fixtures/ecommerce-video.mp4']); return;
}
http_response_code(404); echo '{"error":"Unexpected fixture request"}';
