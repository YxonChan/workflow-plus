<?php

declare(strict_types=1);

/**
 * Integration check for episode workflow node invalidation and chained video
 * version lineage. It creates isolated test data, calls the real HTTP API for
 * the two user-facing actions, and then removes its own records.
 */

const TEST_PREFIX = '__codex_node_interaction_test__';

$videoUrls = [
    'parent_a' => 'https://files.toapis.com/images/tsk_vid_01KVA7VP70M53HZB4C10QCAXB5/1781681675_38dcbd71.mp4',
    'child_a' => 'https://files.toapis.com/images/tsk_vid_01KVA82P7E5B5F55NV6DK8NZDG/1781681979_4be86289.mp4',
    'parent_b' => 'https://files.toapis.com/images/tsk_vid_01KVA8EZMWQJ789ADYHXDGA69Y/1781682380_564dd9c6.mp4',
    'parent_c' => 'https://files.toapis.com/images/tsk_vid_01KVA0P8K034PKH2942NN16ZFX/1781674111_7c52c6ae.mp4',
    'orphan_time' => 'https://files.toapis.com/videos/tsk_vid_01KV854HNS0H3GX7ZXVKXKXDQE/1781611644_10eb7b29.mp4',
];

$endFrames = [
    'parent_a' => 'https://cjrbzgngjsrqieakusub.supabase.co/storage/v1/object/public/images/generated/video-end-frames/20260617/11476b8ff731bf3eab04e3ebb118c386.png',
    'child_a' => 'https://cjrbzgngjsrqieakusub.supabase.co/storage/v1/object/public/images/generated/video-end-frames/20260617/e6eff78d4ab3fd3389c8669997fda596.png',
    'parent_b' => 'https://cjrbzgngjsrqieakusub.supabase.co/storage/v1/object/public/images/generated/video-end-frames/20260617/0f12c9ca97802d3e01a9325ceccafff9.png',
    'parent_c' => 'https://cjrbzgngjsrqieakusub.supabase.co/storage/v1/object/public/images/generated/video-end-frames/20260617/9ba2873e360f07e74d5cd7164b391345.png',
    'orphan_c' => 'https://cjrbzgngjsrqieakusub.supabase.co/storage/v1/object/public/images/generated/video-end-frames/20260617/4c1b648adaa4b3e494c6bf4f40f635b7.png',
    'orphan_time' => 'https://cjrbzgngjsrqieakusub.supabase.co/storage/v1/object/public/images/generated/video-end-frames/20260616/456c184288d395d0ce8ba8274f0c292b.png',
];

$env = readEnvFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $env['DB_HOST'] ?? 'db',
        $env['DB_PORT'] ?? '3306',
        $env['DB_NAME'] ?? 'aimage',
        $env['DB_CHARSET'] ?? 'utf8mb4',
    ),
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

$apiBase = rtrim((string) getenv('TEST_API_BASE_URL'), '/');
if ($apiBase === '') {
    $apiBase = 'http://web';
}

$created = [
    'series_id' => 0,
    'workflow_id' => 0,
    'episode_id' => 0,
];
$failures = 0;
$issues = 0;
$token = '';

try {
    cleanupOldTestData($pdo);
    $token = login($apiBase);
    pass('login as user1');

    $ids = seedBaseWorkflow($pdo);
    $created = array_merge($created, $ids);
    pass('seed isolated series, episode, workflow');

    apiPost($apiBase, '/api/episodes/prepare-workflow', ['id' => $ids['episode_id']], $token);
    $run = fetchOne($pdo, <<<'SQL'
SELECT * FROM workflow_runs
WHERE series_id = ? AND workflow_id = ?
  AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.scope')) = 'episode'
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.episode_id')) AS UNSIGNED) = ?
ORDER BY id DESC LIMIT 1
SQL, [$ids['series_id'], $ids['workflow_id'], $ids['episode_id']]);
    assertTrue($run !== null, 'prepare creates workflow run');
    $runId = (int) $run['id'];
    $runNodes = fetchRunNodesByWorkflowId($pdo, $runId);
    foreach (['input', 'storyboard', 'shot_process', 'image', 'video', 'output'] as $nodeId) {
        assertTrue(isset($runNodes[$nodeId]), "prepare creates run node {$nodeId}");
    }

    markDownstreamNodesSuccessful($pdo, $runNodes);
    $videoState = seedVideoState($pdo, $ids, $runId, (int) $runNodes['video']['id'], $videoUrls, $endFrames);
    pass('seed simulated successful video state');

    seedImageState($pdo, $ids, (int) $runNodes['image']['id']);
    seedOutputState($pdo, (int) $runNodes['output']['id']);
    pass('seed simulated successful image and output state');

    $episodeBeforeNoopSave = apiPost($apiBase, '/api/episodes/detail', ['id' => $ids['episode_id']], $token);
    apiPost($apiBase, '/api/episodes/update', [
        'id' => $ids['episode_id'],
        'plot_input' => '测试剧情：两段镜头用于验证节点重跑和视频血缘。',
    ], $token);
    $episodeAfterNoopSave = apiPost($apiBase, '/api/episodes/detail', ['id' => $ids['episode_id']], $token);
    assertSame(
        workflowNodeStatusMap($episodeBeforeNoopSave),
        workflowNodeStatusMap($episodeAfterNoopSave),
        'save unchanged plot_input keeps workflow node statuses',
    );
    assertSame(
        shotCurrentStateMap($episodeBeforeNoopSave),
        shotCurrentStateMap($episodeAfterNoopSave),
        'save unchanged plot_input keeps current shot media',
    );

    $updatedEpisode = apiPost($apiBase, '/api/episodes/update', [
        'id' => $ids['episode_id'],
        'plot_input' => '测试剧情：plot input 已改写，用于验证整条下游失效。',
    ], $token);
    pass('update plot_input through API');
    assertNodeStatuses($updatedEpisode, [
        'input' => 'queued',
        'storyboard' => 'queued',
        'shot_process' => 'queued',
        'image' => 'queued',
        'video' => 'queued',
        'output' => 'queued',
    ], 'plot_input update resets workflow node statuses');
    assertAllShotImagesCleared($updatedEpisode, 'plot_input update clears image current results');
    assertAllShotVideosCleared($updatedEpisode, 'plot_input update clears video current results');
    assertSelectedVersionCount($pdo, $ids['episode_id'], 'image', 0, 'plot_input update unselects image versions');
    assertSelectedVersionCount($pdo, $ids['episode_id'], 'video', 0, 'plot_input update unselects video versions');

    restoreSuccessfulNodeState($pdo, $runNodes, $videoState, $videoUrls, $endFrames);
    seedImageState($pdo, $ids, (int) $runNodes['image']['id']);
    seedOutputState($pdo, (int) $runNodes['output']['id']);
    pass('restore simulated successful state after plot_input invalidation checks');

    apiPost($apiBase, '/api/episodes/run-node', [
        'id' => $ids['episode_id'],
        'node_id' => 'input',
    ], $token);
    pass('run upstream input node through API');

    $afterInputNodes = fetchRunNodesByWorkflowId($pdo, $runId);
    foreach (['storyboard', 'shot_process', 'image', 'video', 'output'] as $nodeId) {
        $node = $afterInputNodes[$nodeId];
        assertSame('queued', (string) $node['status'], "rerun input queues downstream {$nodeId}");
        assertSame('', (string) $node['raw_output'], "rerun input clears raw output for {$nodeId}");
        assertSame('', (string) $node['error_message'], "rerun input clears error for {$nodeId}");
        assertJsonEmpty($node['output_json'], "rerun input clears output_json for {$nodeId}");
        assertTrue($node['started_at'] === null, "rerun input clears started_at for {$nodeId}");
        assertTrue($node['finished_at'] === null, "rerun input clears finished_at for {$nodeId}");
    }

    $cancelled = fetchOne($pdo, 'SELECT status FROM video_jobs WHERE id = ?', [$videoState['unfinished_job_id']]);
    assertSame('cancelled', (string) ($cancelled['status'] ?? ''), 'rerun input cancels unfinished video job');

    $afterInputEpisode = apiPost($apiBase, '/api/episodes/detail', ['id' => $ids['episode_id']], $token);
    assertSame([], shotCurrentStateMap($afterInputEpisode), 'rerun input clears current storyboard revision shots from episode detail');

    restoreVideoState($pdo, $videoState, $videoUrls, $endFrames);
    seedImageState($pdo, $ids, (int) $runNodes['image']['id']);
    seedOutputState($pdo, (int) $runNodes['output']['id']);
    pass('restore simulated video state for lineage checks');

    $afterStoryboardUpdate = apiPost($apiBase, '/api/episodes/update-node-content', [
        'id' => $ids['episode_id'],
        'node_id' => 'storyboard',
        'content' => json_encode([
            'shots' => [
                ['index' => 1, 'title' => '镜头 1', 'text' => '新的分镜 1'],
                ['index' => 2, 'title' => '镜头 2', 'text' => '新的分镜 2'],
                ['index' => 3, 'title' => '镜头 3', 'text' => '新的分镜 3'],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ], $token);
    pass('update storyboard node content through API');
    assertNodeStatuses($afterStoryboardUpdate, [
        'input' => 'success',
        'storyboard' => 'success',
        'shot_process' => 'queued',
        'image' => 'queued',
        'video' => 'queued',
        'output' => 'queued',
    ], 'storyboard content update only resets downstream nodes');
    assertNodeRawOutputContains($pdo, (int) $runNodes['storyboard']['id'], '新的分镜 3', 'storyboard content update writes new raw output');
    assertAllShotImagesCleared($afterStoryboardUpdate, 'storyboard content update clears current images');
    assertAllShotVideosCleared($afterStoryboardUpdate, 'storyboard content update clears current videos');
    assertSelectedVersionCount($pdo, $ids['episode_id'], 'image', 0, 'storyboard content update unselects image versions');
    assertSelectedVersionCount($pdo, $ids['episode_id'], 'video', 0, 'storyboard content update unselects video versions');

    restoreSuccessfulNodeState($pdo, $runNodes, $videoState, $videoUrls, $endFrames);
    seedImageState($pdo, $ids, (int) $runNodes['image']['id']);
    seedOutputState($pdo, (int) $runNodes['output']['id']);
    pass('restore simulated successful state after storyboard content update checks');

    $afterShotProcessUpdate = apiPost($apiBase, '/api/episodes/update-node-content', [
        'id' => $ids['episode_id'],
        'node_id' => 'shot_process',
        'content' => "镜头 1：新的处理结果\n镜头 2：新的处理结果\n镜头 3：新的处理结果",
    ], $token);
    pass('update shot_process node content through API');
    assertNodeStatuses($afterShotProcessUpdate, [
        'input' => 'success',
        'storyboard' => 'success',
        'shot_process' => 'success',
        'image' => 'queued',
        'video' => 'queued',
        'output' => 'queued',
    ], 'shot_process content update only resets downstream nodes');
    assertNodeRawOutputContains($pdo, (int) $runNodes['shot_process']['id'], '镜头 3：新的处理结果', 'shot_process content update writes new raw output');
    assertAllShotImagesCleared($afterShotProcessUpdate, 'shot_process content update clears current images');
    assertAllShotVideosCleared($afterShotProcessUpdate, 'shot_process content update clears current videos');
    assertSelectedVersionCount($pdo, $ids['episode_id'], 'image', 0, 'shot_process content update unselects image versions');
    assertSelectedVersionCount($pdo, $ids['episode_id'], 'video', 0, 'shot_process content update unselects video versions');

    restoreSuccessfulNodeState($pdo, $runNodes, $videoState, $videoUrls, $endFrames);
    seedImageState($pdo, $ids, (int) $runNodes['image']['id']);
    seedOutputState($pdo, (int) $runNodes['output']['id']);
    pass('restore simulated successful state after shot_process content update checks');

    apiPost($apiBase, '/api/episodes/select-media-version', [
        'id' => $ids['episode_id'],
        'version_id' => $videoState['version1b_id'],
    ], $token);
    $shot2AfterB = fetchOne($pdo, 'SELECT status, video_url, video_end_frame_url FROM shots WHERE id = ?', [$videoState['shot2_id']]);
    assertSame('pending', (string) ($shot2AfterB['status'] ?? ''), 'select parent B marks shot 2 pending');
    assertSame('', trim((string) ($shot2AfterB['video_url'] ?? '')), 'select parent B clears shot 2 video_url');
    assertSame('', trim((string) ($shot2AfterB['video_end_frame_url'] ?? '')), 'select parent B clears shot 2 end frame');
    $selectedAfterB = (int) scalar($pdo, 'SELECT COUNT(*) FROM shot_media_versions WHERE shot_id = ? AND media_type = ? AND is_selected = 1', [$videoState['shot2_id'], 'video']);
    assertSame(0, $selectedAfterB, 'select parent B unselects all shot 2 video versions');
    $orphanTime = fetchOne($pdo, 'SELECT parent_version_id, is_selected FROM shot_media_versions WHERE id = ?', [$videoState['version2_time_orphan_id']]);
    assertTrue((int) ($orphanTime['parent_version_id'] ?? 0) === 0 && (int) ($orphanTime['is_selected'] ?? 0) === 0, 'parent B does not recover time-based workflow orphan');

    apiPost($apiBase, '/api/episodes/select-media-version', [
        'id' => $ids['episode_id'],
        'version_id' => $videoState['version1a_id'],
    ], $token);
    assertShotUsesVersion($pdo, $videoState['shot2_id'], $videoState['version2a_id'], $videoUrls['child_a'], 'switch back to parent A restores child A');

    apiPost($apiBase, '/api/episodes/select-media-version', [
        'id' => $ids['episode_id'],
        'version_id' => $videoState['version1c_id'],
    ], $token);
    assertShotUsesVersion($pdo, $videoState['shot2_id'], $videoState['version2c_orphan_id'], $videoUrls['orphan_time'], 'input frame orphan restores for parent C');
    $orphanC = fetchOne($pdo, 'SELECT parent_version_id FROM shot_media_versions WHERE id = ?', [$videoState['version2c_orphan_id']]);
    assertSame($videoState['version1c_id'], (int) ($orphanC['parent_version_id'] ?? 0), 'input frame orphan gets parent_version_id backfilled');

    seedOutputState($pdo, (int) $runNodes['output']['id']);
    apiPost($apiBase, '/api/episodes/select-media-version', [
        'id' => $ids['episode_id'],
        'version_id' => $videoState['version1b_id'],
    ], $token);
    $afterOutputInvalidation = apiPost($apiBase, '/api/episodes/detail', ['id' => $ids['episode_id']], $token);
    $outputNodeAfterVersionSwitch = nodeFromEpisode($afterOutputInvalidation, 'output');
    assertSame('queued', (string) ($outputNodeAfterVersionSwitch['status'] ?? ''), 'video version switch queues output node');
    assertSame([], (array) ($outputNodeAfterVersionSwitch['output_json'] ?? []), 'video version switch clears output node output_json');

    // 单镜改分镜：保留历史视频版本（is_selected=0），当前成片清空，shot_key 稳定
    restoreSuccessfulNodeState($pdo, $runNodes, $videoState, $videoUrls, $endFrames);
    seedImageState($pdo, $ids, (int) $runNodes['image']['id']);
    seedOutputState($pdo, (int) $runNodes['output']['id']);
    $beforeSingleShotVersions = (int) scalar(
        $pdo,
        'SELECT COUNT(*) FROM shot_media_versions WHERE shot_id = ? AND media_type = ? AND orphaned = 0 AND url <> ?',
        [$videoState['shot1_id'], 'video', '']
    );
    assertTrue($beforeSingleShotVersions >= 1, 'single-shot fixture has video versions on shot 1');
    $shot1KeyBefore = (string) scalar($pdo, 'SELECT shot_key FROM shots WHERE id = ?', [$videoState['shot1_id']]);
    $shot2KeyBefore = (string) scalar($pdo, 'SELECT shot_key FROM shots WHERE id = ?', [$videoState['shot2_id']]);

    $afterSingleShot = apiPost($apiBase, '/api/episodes/update-node-content', [
        'id' => $ids['episode_id'],
        'node_id' => 'shot_process',
        'content' => "【视频节点01｜镜头 1】\n镜头 1：改过的分镜文案\n\n【视频节点02｜镜头 2】\n镜头 2：依赖镜头 1 尾帧。",
        'update_mode' => 'single_shot',
        'changed_shot_index' => 1,
        'storyboard_shots' => [
            [
                'index' => 1,
                'title' => '镜头 1',
                'content_text' => '镜头 1：改过的分镜文案',
                'shot_key' => $shot1KeyBefore,
            ],
            [
                'index' => 2,
                'title' => '镜头 2',
                'content_text' => '镜头 2：依赖镜头 1 尾帧。',
                'shot_key' => $shot2KeyBefore,
            ],
        ],
    ], $token);
    pass('single-shot storyboard update through API');

    $currentRevisionId = (int) scalar($pdo, 'SELECT current_storyboard_revision_id FROM episodes WHERE id = ?', [$ids['episode_id']]);
    $newShot1 = fetchOne($pdo, 'SELECT id, shot_key, video_url, status, `desc` FROM shots WHERE episode_id = ? AND storyboard_revision_id = ? AND `index` = 1', [$ids['episode_id'], $currentRevisionId]);
    $newShot2 = fetchOne($pdo, 'SELECT id, shot_key, video_url, status FROM shots WHERE episode_id = ? AND storyboard_revision_id = ? AND `index` = 2', [$ids['episode_id'], $currentRevisionId]);
    assertTrue(is_array($newShot1) && is_array($newShot2), 'single-shot update creates current revision shots');
    assertSame($shot1KeyBefore, (string) ($newShot1['shot_key'] ?? ''), 'single-shot update preserves shot 1 shot_key');
    assertSame($shot2KeyBefore, (string) ($newShot2['shot_key'] ?? ''), 'single-shot update preserves shot 2 shot_key');
    assertSame('', trim((string) ($newShot1['video_url'] ?? '')), 'single-shot update clears changed shot current video_url');
    assertTrue(trim((string) ($newShot2['video_url'] ?? '')) !== '', 'single-shot update keeps unchanged shot video_url');

    $keptVersions = (int) scalar(
        $pdo,
        'SELECT COUNT(*) FROM shot_media_versions WHERE shot_id = ? AND media_type = ? AND orphaned = 0 AND url <> ?',
        [(int) $newShot1['id'], 'video', '']
    );
    assertTrue($keptVersions >= $beforeSingleShotVersions, 'single-shot update clones historical video versions onto changed shot');
    $selectedOnChanged = (int) scalar(
        $pdo,
        'SELECT COUNT(*) FROM shot_media_versions WHERE shot_id = ? AND media_type = ? AND is_selected = 1 AND orphaned = 0',
        [(int) $newShot1['id'], 'video']
    );
    assertSame(0, $selectedOnChanged, 'single-shot update leaves changed shot video versions unselected');
    $promptKept = (int) scalar(
        $pdo,
        'SELECT COUNT(*) FROM shot_media_versions WHERE shot_id = ? AND media_type = ? AND orphaned = 0 AND prompt <> ?',
        [(int) $newShot1['id'], 'video', '']
    );
    assertTrue($promptKept > 0, 'single-shot update keeps historical prompts on cloned video versions');

    $detailAfterSingle = apiPost($apiBase, '/api/episodes/detail', ['id' => $ids['episode_id']], $token);
    $shot1FromDetail = null;
    foreach ((array) ($detailAfterSingle['shots'] ?? []) as $shotRow) {
        if ((int) ($shotRow['index'] ?? 0) === 1) {
            $shot1FromDetail = $shotRow;
            break;
        }
    }
    assertTrue(is_array($shot1FromDetail), 'episode detail returns shot 1 after single-shot update');
    $detailVideoVersions = array_values(array_filter(
        (array) ($shot1FromDetail['media_versions'] ?? []),
        static fn ($v): bool => is_array($v) && ($v['media_type'] ?? '') === 'video' && trim((string) ($v['url'] ?? '')) !== ''
    ));
    assertTrue(count($detailVideoVersions) >= 1, 'episode detail exposes historical video versions after single-shot update');
} catch (Throwable $e) {
    $failures++;
    out('FAIL', $e->getMessage());
} finally {
    try {
        cleanupOldTestData($pdo);
        pass('cleanup test data');
    } catch (Throwable $cleanupError) {
        $failures++;
        out('FAIL', 'cleanup failed: ' . $cleanupError->getMessage());
    }
}

echo PHP_EOL;
echo "Summary: failures={$failures}, issues={$issues}" . PHP_EOL;
if ($issues > 0) {
    echo "Issues are behavior risks detected by the test; they do not fail the script by themselves." . PHP_EOL;
}
exit($failures > 0 ? 1 : 0);

function readEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $env;
}

function login(string $apiBase): string
{
    $response = apiPost($apiBase, '/api/auth/login', [
        'username' => 'user1',
        'password' => '123456',
    ]);
    $token = trim((string) ($response['token'] ?? $response['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('login response did not include token');
    }
    return $token;
}

function apiPost(string $apiBase, string $path, array $payload, string $token = ''): array
{
    $ch = curl_init($apiBase . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        throw new RuntimeException("HTTP {$path} failed: {$error}");
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("HTTP {$path} returned non-JSON status={$status}: {$raw}");
    }
    if ($status >= 400 || (int) ($decoded['code'] ?? 0) !== 0) {
        throw new RuntimeException("HTTP {$path} failed status={$status}: " . ($decoded['message'] ?? $raw));
    }
    $data = $decoded['data'] ?? [];
    return is_array($data) ? $data : [];
}

function cleanupOldTestData(PDO $pdo): void
{
    $seriesIds = fetchColumn($pdo, 'SELECT id FROM series WHERE title LIKE ?', [TEST_PREFIX . '%']);
    if ($seriesIds === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
    $episodeIds = fetchColumn($pdo, "SELECT id FROM episodes WHERE series_id IN ({$placeholders})", $seriesIds);
    $runIds = fetchColumn($pdo, "SELECT id FROM workflow_runs WHERE series_id IN ({$placeholders})", $seriesIds);
    $shotIds = $episodeIds !== []
        ? fetchColumn($pdo, 'SELECT id FROM shots WHERE episode_id IN (' . implode(',', array_fill(0, count($episodeIds), '?')) . ')', $episodeIds)
        : [];

    if ($shotIds !== []) {
        execSql($pdo, 'DELETE FROM shot_media_versions WHERE shot_id IN (' . implode(',', array_fill(0, count($shotIds), '?')) . ')', $shotIds);
        execSql($pdo, 'DELETE FROM video_jobs WHERE shot_id IN (' . implode(',', array_fill(0, count($shotIds), '?')) . ')', $shotIds);
        execSql($pdo, 'DELETE FROM shots WHERE id IN (' . implode(',', array_fill(0, count($shotIds), '?')) . ')', $shotIds);
    }
    if ($episodeIds !== []) {
        execSql($pdo, 'DELETE FROM storyboard_revisions WHERE episode_id IN (' . implode(',', array_fill(0, count($episodeIds), '?')) . ')', $episodeIds);
    }
    if ($runIds !== []) {
        execSql($pdo, 'DELETE FROM workflow_run_nodes WHERE run_id IN (' . implode(',', array_fill(0, count($runIds), '?')) . ')', $runIds);
        execSql($pdo, 'DELETE FROM workflow_runs WHERE id IN (' . implode(',', array_fill(0, count($runIds), '?')) . ')', $runIds);
    }
    if ($episodeIds !== []) {
        execSql($pdo, 'DELETE FROM episodes WHERE id IN (' . implode(',', array_fill(0, count($episodeIds), '?')) . ')', $episodeIds);
    }
    execSql($pdo, "DELETE FROM workflows WHERE id IN (SELECT workflow_id FROM (SELECT workflow_id FROM episodes WHERE series_id IN ({$placeholders})) t)", $seriesIds);
    execSql($pdo, "DELETE FROM workflows WHERE name LIKE ?", [TEST_PREFIX . '%']);
    execSql($pdo, "DELETE FROM series WHERE id IN ({$placeholders})", $seriesIds);
}

function seedBaseWorkflow(PDO $pdo): array
{
    $now = now();
    $graph = [
        'nodes' => [
            node('input', '剧情输入', 'input'),
            node('storyboard', '文本分镜', 'text'),
            node('shot_process', '分镜处理', 'text'),
            node('image', '分镜图片', 'image'),
            node('video', '视频生成', 'video', ['chainShots' => true]),
            node('output', '最终输出', 'output'),
        ],
        'edges' => [
            edge('input', 'storyboard'),
            edge('storyboard', 'shot_process'),
            edge('shot_process', 'image'),
            edge('image', 'video'),
            edge('video', 'output'),
        ],
    ];
    $workflowId = insert($pdo, 'workflows', [
        'user_id' => 1,
        'name' => TEST_PREFIX . ' workflow ' . date('YmdHis'),
        'description' => 'Codex node interaction integration test',
        'graph' => json_encode($graph, JSON_UNESCAPED_UNICODE),
        'viewport' => json_encode(['x' => 0, 'y' => 0, 'zoom' => 1], JSON_UNESCAPED_UNICODE),
        'is_default' => 0,
        'scope' => 'episode',
        'sort' => 9999,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $seriesId = insert($pdo, 'series', [
        'user_id' => 1,
        'title' => TEST_PREFIX . ' series ' . date('YmdHis'),
        'description' => 'Codex integration test series',
        'series_workflow_id' => null,
        'visual_style' => 'realistic',
        'region' => 'china',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $episodeId = insert($pdo, 'episodes', [
        'user_id' => 1,
        'series_id' => $seriesId,
        'number' => 1,
        'title' => TEST_PREFIX . ' episode',
        'status' => 'production',
        'workflow_id' => $workflowId,
        'plot_input' => '测试剧情：两段镜头用于验证节点重跑和视频血缘。',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    return [
        'series_id' => $seriesId,
        'workflow_id' => $workflowId,
        'episode_id' => $episodeId,
    ];
}

function node(string $id, string $label, string $kind, array $params = []): array
{
    return [
        'id' => $id,
        'label' => $label,
        'kind' => $kind,
        'data' => [
            'label' => $label,
            'kind' => $kind,
            'params' => $params,
        ],
    ];
}

function edge(string $source, string $target): array
{
    return ['id' => $source . '-' . $target, 'source' => $source, 'target' => $target];
}

function markDownstreamNodesSuccessful(PDO $pdo, array $runNodes): void
{
    foreach (['storyboard', 'shot_process', 'image', 'video', 'output'] as $nodeId) {
        execSql($pdo, <<<'SQL'
UPDATE workflow_run_nodes
SET status = 'success',
    output_json = ?,
    raw_output = ?,
    error_message = 'old error should be cleared',
    started_at = ?,
    finished_at = ?,
    duration_ms = 123
WHERE id = ?
SQL, [
            json_encode(['old' => true, 'node' => $nodeId], JSON_UNESCAPED_UNICODE),
            "old raw {$nodeId}",
            now(),
            now(),
            (int) $runNodes[$nodeId]['id'],
        ]);
    }
}

function seedVideoState(PDO $pdo, array $ids, int $runId, int $videoRunNodeId, array $videoUrls, array $endFrames): array
{
    $now = now();
    $storyboardRevisionId = seedStoryboardRevision($pdo, $ids, $runId);
    $ids['storyboard_revision_id'] = $storyboardRevisionId;
    $shot1Key = '11111111-1111-4111-8111-111111111111';
    $shot2Key = '22222222-2222-4222-8222-222222222222';
    $shot1Id = insert($pdo, 'shots', [
        'user_id' => 1,
        'episode_id' => $ids['episode_id'],
        'storyboard_revision_id' => $storyboardRevisionId,
        'shot_key' => $shot1Key,
        'index' => 1,
        'desc' => '镜头 1：父视频版本。',
        'duration' => '8',
        'status' => 'done',
        'image_url' => 'https://example.test/shot1.png',
        'video_url' => $videoUrls['parent_a'],
        'video_end_frame_url' => $endFrames['parent_a'],
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $shot2Id = insert($pdo, 'shots', [
        'user_id' => 1,
        'episode_id' => $ids['episode_id'],
        'storyboard_revision_id' => $storyboardRevisionId,
        'shot_key' => $shot2Key,
        'index' => 2,
        'desc' => '镜头 2：依赖镜头 1 尾帧。',
        'duration' => '8',
        'status' => 'done',
        'image_url' => 'https://example.test/shot2.png',
        'video_url' => $videoUrls['child_a'],
        'video_end_frame_url' => $endFrames['child_a'],
        'create_time' => $now,
        'update_time' => $now,
    ]);

    $job1aId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot1Id, 1, 'success', $videoUrls['parent_a'], $endFrames['parent_a']);
    $job1bId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot1Id, 1, 'success', $videoUrls['parent_b'], $endFrames['parent_b']);
    $job1cId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot1Id, 1, 'success', $videoUrls['parent_c'], $endFrames['parent_c']);
    $job2aId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot2Id, 2, 'success', $videoUrls['child_a'], $endFrames['child_a'], $job1aId, $endFrames['parent_a']);
    $job2TimeOrphanId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot2Id, 2, 'success', $videoUrls['orphan_time'], $endFrames['orphan_time'], null, 'https://example.test/not-parent-b.png');
    $job2cOrphanId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot2Id, 2, 'success', $videoUrls['orphan_time'], $endFrames['orphan_c'], null, $endFrames['parent_c']);
    $unfinishedJobId = videoJob($pdo, $ids, $runId, $videoRunNodeId, $shot2Id, 2, 'blocked', '', '', 999999999, '');

    $version1aId = mediaVersion($pdo, $ids, $videoRunNodeId, $shot1Id, $job1aId, null, $videoUrls['parent_a'], $endFrames['parent_a'], true, 'workflow');
    $version1bId = mediaVersion($pdo, $ids, $videoRunNodeId, $shot1Id, $job1bId, null, $videoUrls['parent_b'], $endFrames['parent_b'], false, 'workflow');
    $version1cId = mediaVersion($pdo, $ids, $videoRunNodeId, $shot1Id, $job1cId, null, $videoUrls['parent_c'], $endFrames['parent_c'], false, 'workflow');
    $version2aId = mediaVersion($pdo, $ids, $videoRunNodeId, $shot2Id, $job2aId, $version1aId, $videoUrls['child_a'], $endFrames['child_a'], true, 'workflow', $endFrames['parent_a']);
    $version2TimeOrphanId = mediaVersion($pdo, $ids, $videoRunNodeId, $shot2Id, $job2TimeOrphanId, null, $videoUrls['orphan_time'], $endFrames['orphan_time'], false, 'workflow', 'https://example.test/not-parent-b.png');
    $version2cOrphanId = mediaVersion($pdo, $ids, $videoRunNodeId, $shot2Id, $job2cOrphanId, null, $videoUrls['orphan_time'], $endFrames['orphan_c'], false, 'workflow', $endFrames['parent_c']);

    return compact(
        'shot1Id',
        'shot2Id',
        'job1aId',
        'job1bId',
        'job1cId',
        'job2aId',
        'job2TimeOrphanId',
        'job2cOrphanId',
        'unfinishedJobId',
        'version1aId',
        'version1bId',
        'version1cId',
        'version2aId',
        'version2TimeOrphanId',
        'version2cOrphanId',
    ) + [
        'shot1_id' => $shot1Id,
        'shot2_id' => $shot2Id,
        'shot1_key' => $shot1Key,
        'shot2_key' => $shot2Key,
        'job2a_id' => $job2aId,
        'unfinished_job_id' => $unfinishedJobId,
        'version1a_id' => $version1aId,
        'version1b_id' => $version1bId,
        'version1c_id' => $version1cId,
        'version2a_id' => $version2aId,
        'version2_time_orphan_id' => $version2TimeOrphanId,
        'version2c_orphan_id' => $version2cOrphanId,
        'storyboard_revision_id' => $storyboardRevisionId,
    ];
}

function seedStoryboardRevision(PDO $pdo, array $ids, int $runId): int
{
    $now = now();
    execSql($pdo, 'UPDATE storyboard_revisions SET status = ? WHERE episode_id = ?', ['archived', $ids['episode_id']]);
    $revisionId = insert($pdo, 'storyboard_revisions', [
        'user_id' => 1,
        'series_id' => $ids['series_id'],
        'episode_id' => $ids['episode_id'],
        'workflow_run_id' => $runId,
        'workflow_run_node_id' => null,
        'raw_output' => '镜头 1：父视频版本。' . "\n" . '镜头 2：依赖镜头 1 尾帧。',
        'content_hash' => hash('sha256', 'test-storyboard-revision-' . $ids['episode_id'] . '-' . $now),
        'shot_count' => 2,
        'status' => 'current',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    execSql($pdo, 'UPDATE episodes SET current_storyboard_revision_id = ? WHERE id = ?', [$revisionId, $ids['episode_id']]);
    return $revisionId;
}

function seedImageState(PDO $pdo, array $ids, int $imageRunNodeId): void
{
    $revisionId = currentStoryboardRevisionId($pdo, $ids['episode_id']);
    if ($revisionId <= 0) {
        return;
    }
    $shots = fetchAll($pdo, 'SELECT id, `index` FROM shots WHERE episode_id = ? AND storyboard_revision_id = ? ORDER BY `index` ASC', [$ids['episode_id'], $revisionId]);
    foreach ($shots as $shot) {
        $shotId = (int) ($shot['id'] ?? 0);
        $index = (int) ($shot['index'] ?? 0);
        if ($shotId <= 0 || $index <= 0) {
            continue;
        }
        execSql($pdo, 'DELETE FROM shot_media_versions WHERE shot_id = ? AND media_type = ?', [$shotId, 'image']);
        $imageUrl = 'https://example.test/image-shot-' . $index . '.png';
        insert($pdo, 'shot_media_versions', [
            'user_id' => 1,
            'series_id' => $ids['series_id'],
            'episode_id' => $ids['episode_id'],
            'storyboard_revision_id' => $revisionId,
            'shot_id' => $shotId,
            'workflow_run_node_id' => $imageRunNodeId,
            'video_job_id' => null,
            'parent_version_id' => null,
            'model_config_id' => 0,
            'media_type' => 'image',
            'url' => $imageUrl,
            'poster_url' => '',
            'end_frame_url' => '',
            'prompt' => 'test image prompt ' . $index,
            'source' => 'generated',
            'is_selected' => 1,
            'ai_request_log_id' => null,
            'meta_json' => json_encode(['node_id' => 'image', 'shot_index' => $index], JSON_UNESCAPED_UNICODE),
            'create_time' => now(),
            'update_time' => now(),
        ]);
        execSql($pdo, 'UPDATE shots SET image_url = ?, status = ? WHERE id = ?', [$imageUrl, 'done', $shotId]);
    }
}

function seedOutputState(PDO $pdo, int $outputRunNodeId): void
{
    execSql($pdo, <<<'SQL'
UPDATE workflow_run_nodes
SET status = 'success',
    output_json = ?,
    raw_output = ?,
    error_message = '',
    started_at = ?,
    finished_at = ?,
    duration_ms = 456
WHERE id = ?
SQL, [
        json_encode([
            'video_url' => 'https://example.test/final-output.mp4',
            'video_count' => 2,
        ], JSON_UNESCAPED_UNICODE),
        'final output ready',
        now(),
        now(),
        $outputRunNodeId,
    ]);
}

function restoreSuccessfulNodeState(PDO $pdo, array $runNodes, array $videoState, array $videoUrls, array $endFrames): void
{
    markDownstreamNodesSuccessful($pdo, $runNodes);
    restoreVideoState($pdo, $videoState, $videoUrls, $endFrames);
}

function restoreVideoState(PDO $pdo, array $state, array $videoUrls, array $endFrames): void
{
    $revisionId = (int) ($state['storyboard_revision_id'] ?? 0);
    if ($revisionId > 0) {
        $episodeId = (int) scalar($pdo, 'SELECT episode_id FROM storyboard_revisions WHERE id = ?', [$revisionId]);
        if ($episodeId > 0) {
            execSql($pdo, 'UPDATE storyboard_revisions SET status = CASE WHEN id = ? THEN ? ELSE ? END WHERE episode_id = ?', [$revisionId, 'current', 'archived', $episodeId]);
            execSql($pdo, 'UPDATE episodes SET current_storyboard_revision_id = ? WHERE id = ?', [$revisionId, $episodeId]);
        }
    }
    execSql($pdo, 'UPDATE shots SET status = ?, video_url = ?, video_end_frame_url = ? WHERE id = ?', ['done', $videoUrls['parent_a'], $endFrames['parent_a'], $state['shot1_id']]);
    execSql($pdo, 'UPDATE shots SET status = ?, video_url = ?, video_end_frame_url = ? WHERE id = ?', ['done', $videoUrls['child_a'], $endFrames['child_a'], $state['shot2_id']]);
    execSql($pdo, 'UPDATE shot_media_versions SET is_selected = CASE WHEN id IN (?, ?) THEN 1 ELSE 0 END WHERE shot_id IN (?, ?) AND media_type = ?', [
        $state['version1a_id'],
        $state['version2a_id'],
        $state['shot1_id'],
        $state['shot2_id'],
        'video',
    ]);
    execSql($pdo, 'UPDATE video_jobs SET status = ?, video_url = ?, end_frame_url = ?, error_message = ? WHERE id = ?', ['success', $videoUrls['child_a'], $endFrames['child_a'], '', $state['job2a_id']]);
}

function videoJob(PDO $pdo, array $ids, int $runId, int $videoRunNodeId, int $shotId, int $shotIndex, string $status, string $videoUrl, string $endFrameUrl, ?int $dependsOnJobId = null, string $inputImageUrl = ''): int
{
    $now = now();
    return insert($pdo, 'video_jobs', [
        'user_id' => 1,
        'series_id' => $ids['series_id'],
        'episode_id' => $ids['episode_id'],
        'storyboard_revision_id' => $ids['storyboard_revision_id'] ?? null,
        'shot_id' => $shotId,
        'workflow_id' => $ids['workflow_id'],
        'workflow_run_id' => $runId,
        'workflow_run_node_id' => $videoRunNodeId,
        'model_config_id' => 0,
        'node_id' => 'video',
        'node_label' => '视频生成',
        'node_prompt' => 'test prompt',
        'shot_index' => $shotIndex,
        'total_shots' => 2,
        'duration' => '8',
        'source_image_url' => 'https://example.test/source-' . $shotIndex . '.png',
        'input_image_url' => $inputImageUrl,
        'previous_end_frame_url' => $inputImageUrl,
        'video_url' => $videoUrl,
        'end_frame_url' => $endFrameUrl,
        'status' => $status,
        'depends_on_job_id' => $dependsOnJobId,
        'chain_shots' => 1,
        'shot_data_json' => json_encode(['index' => $shotIndex, 'description' => 'test shot ' . $shotIndex], JSON_UNESCAPED_UNICODE),
        'assets_json' => json_encode([], JSON_UNESCAPED_UNICODE),
        'video_options_json' => json_encode(['size' => '9:16'], JSON_UNESCAPED_UNICODE),
        'request_context_json' => json_encode(['final_prompt' => 'test final prompt'], JSON_UNESCAPED_UNICODE),
        'ai_request_log_id' => null,
        'attempts' => 0,
        'error_message' => '',
        'started_at' => $status === 'queued' ? null : $now,
        'finished_at' => $status === 'queued' ? null : $now,
        'create_time' => $now,
        'update_time' => $now,
    ]);
}

function mediaVersion(PDO $pdo, array $ids, int $videoRunNodeId, int $shotId, int $jobId, ?int $parentVersionId, string $url, string $endFrameUrl, bool $selected, string $source, string $inputImageUrl = ''): int
{
    $now = now();
    $shotKey = (string) scalar($pdo, 'SELECT shot_key FROM shots WHERE id = ?', [$shotId]);
    return insert($pdo, 'shot_media_versions', [
        'user_id' => 1,
        'series_id' => $ids['series_id'],
        'episode_id' => $ids['episode_id'],
        'storyboard_revision_id' => $ids['storyboard_revision_id'] ?? null,
        'shot_id' => $shotId,
        'shot_key' => $shotKey !== '' ? $shotKey : null,
        'workflow_run_node_id' => $videoRunNodeId,
        'video_job_id' => $jobId,
        'parent_version_id' => $parentVersionId,
        'model_config_id' => 0,
        'media_type' => 'video',
        'url' => $url,
        'poster_url' => 'https://example.test/poster.png',
        'end_frame_url' => $endFrameUrl,
        'prompt' => 'test version prompt',
        'source' => $source,
        'is_selected' => $selected ? 1 : 0,
        'orphaned' => 0,
        'ai_request_log_id' => null,
        'meta_json' => json_encode(['input_image_url' => $inputImageUrl], JSON_UNESCAPED_UNICODE),
        'create_time' => $now,
        'update_time' => $now,
    ]);
}

function assertShotUsesVersion(PDO $pdo, int $shotId, int $versionId, string $expectedUrl, string $message): void
{
    $shot = fetchOne($pdo, 'SELECT status, video_url FROM shots WHERE id = ?', [$shotId]);
    assertSame('done', (string) ($shot['status'] ?? ''), $message . ' sets shot done');
    assertSame($expectedUrl, (string) ($shot['video_url'] ?? ''), $message . ' sets shot video_url');
    $version = fetchOne($pdo, 'SELECT is_selected FROM shot_media_versions WHERE id = ?', [$versionId]);
    assertSame(1, (int) ($version['is_selected'] ?? 0), $message . ' selects expected version');
}

function workflowNodeStatusMap(array $episode): array
{
    $result = [];
    foreach ((array) ($episode['workflow_state']['nodes'] ?? []) as $node) {
        $result[(string) ($node['workflow_node_id'] ?? '')] = (string) ($node['status'] ?? '');
    }
    ksort($result);
    return $result;
}

function shotCurrentStateMap(array $episode): array
{
    $result = [];
    foreach ((array) ($episode['shots'] ?? []) as $shot) {
        $result[(int) ($shot['index'] ?? $shot['id'] ?? 0)] = [
            'image_url' => trim((string) ($shot['image_url'] ?? '')),
            'video_url' => trim((string) ($shot['video_url'] ?? '')),
            'video_end_frame_url' => trim((string) ($shot['video_end_frame_url'] ?? '')),
        ];
    }
    ksort($result);
    return $result;
}

function assertNodeStatuses(array $episode, array $expected, string $message): void
{
    $nodes = workflowNodeStatusMap($episode);
    foreach ($expected as $nodeId => $status) {
        assertSame($status, $nodes[$nodeId] ?? null, $message . ' [' . $nodeId . ']');
    }
}

function assertAllShotImagesCleared(array $episode, string $message): void
{
    foreach ((array) ($episode['shots'] ?? []) as $shot) {
        assertSame('', trim((string) ($shot['image_url'] ?? '')), $message . ' [shot ' . (int) ($shot['index'] ?? $shot['id'] ?? 0) . ']');
    }
}

function assertAllShotVideosCleared(array $episode, string $message): void
{
    foreach ((array) ($episode['shots'] ?? []) as $shot) {
        $shotLabel = ' [shot ' . (int) ($shot['index'] ?? $shot['id'] ?? 0) . ']';
        assertSame('', trim((string) ($shot['video_url'] ?? '')), $message . ' video_url' . $shotLabel);
        assertSame('', trim((string) ($shot['video_end_frame_url'] ?? '')), $message . ' video_end_frame_url' . $shotLabel);
    }
}

function assertSelectedVersionCount(PDO $pdo, int $episodeId, string $mediaType, int $expected, string $message): void
{
    $revisionId = currentStoryboardRevisionId($pdo, $episodeId);
    if ($revisionId <= 0) {
        assertSame($expected, 0, $message);
        return;
    }

    $count = (int) scalar(
        $pdo,
        'SELECT COUNT(*) FROM shot_media_versions WHERE episode_id = ? AND storyboard_revision_id = ? AND media_type = ? AND is_selected = 1',
        [$episodeId, $revisionId, $mediaType],
    );
    assertSame($expected, $count, $message);
}

function currentStoryboardRevisionId(PDO $pdo, int $episodeId): int
{
    return (int) scalar($pdo, 'SELECT current_storyboard_revision_id FROM episodes WHERE id = ?', [$episodeId]);
}

function nodeFromEpisode(array $episode, string $workflowNodeId): ?array
{
    foreach ((array) ($episode['workflow_state']['nodes'] ?? []) as $node) {
        if ((string) ($node['workflow_node_id'] ?? '') === $workflowNodeId) {
            return is_array($node) ? $node : null;
        }
    }
    return null;
}

function assertNodeRawOutputContains(PDO $pdo, int $runNodeId, string $needle, string $message): void
{
    $row = fetchOne($pdo, 'SELECT raw_output FROM workflow_run_nodes WHERE id = ?', [$runNodeId]);
    $raw = (string) ($row['raw_output'] ?? '');
    assertTrue(str_contains($raw, $needle), $message);
}

function fetchRunNodesByWorkflowId(PDO $pdo, int $runId): array
{
    $rows = fetchAll($pdo, 'SELECT * FROM workflow_run_nodes WHERE run_id = ? ORDER BY sort ASC', [$runId]);
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['workflow_node_id']] = $row;
    }
    return $out;
}

function assertJsonEmpty(?string $json, string $message): void
{
    $value = $json !== null && $json !== '' ? json_decode($json, true) : [];
    assertSame([], is_array($value) ? $value : null, $message);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    pass($message);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
    pass($message);
}

function pass(string $message): void
{
    out('PASS', $message);
}

function issue(string $message): void
{
    global $issues;
    $issues++;
    out('ISSUE', $message);
}

function out(string $level, string $message): void
{
    echo '[' . $level . '] ' . $message . PHP_EOL;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function insert(PDO $pdo, string $table, array $data): int
{
    $columns = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`,`', $columns),
        implode(',', array_fill(0, count($columns), '?')),
    );
    execSql($pdo, $sql, array_values($data));
    return (int) $pdo->lastInsertId();
}

function execSql(PDO $pdo, string $sql, array $params = []): void
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchColumn(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
