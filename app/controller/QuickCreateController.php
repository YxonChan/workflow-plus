<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Asset;
use app\model\AssetImage;
use app\model\QuickCreateFaceVerification;
use app\model\ModelConfig;
use app\model\QuickCreateMessage;
use app\model\User;
use app\support\AssetVisibility;
use app\support\ImageProviderTaskState;
use app\support\MediaStorage;
use app\support\ModelConfigResolver;
use app\support\PendingImageTaskException;
use app\support\ToapisPrivateAvatarService;
use app\support\RedisCache;
use think\facade\Db;

/**
 * 灵感速创：对话式快速生成图片/视频。
 * 跳过工作流编排，@ 引用资产后直接调用生成模型；实际执行由 quick-create:worker 异步完成。
 */
class QuickCreateController extends BaseController
{
    private const MODES = ['image', 'video'];
    private const MAX_PROMPT = 4000;
    private const MAX_REFS = 6;
    private const MAX_COUNT = 4;
    private const HISTORY_LIMIT = 100;
    /** @var array<int, QuickCreateFaceVerification|null> */
    private array $faceVerificationCache = [];

    public function faceVerification()
    {
        $userId = $this->currentUserId();
        $url = $this->canonicalizeStorageUrl(trim((string) ($this->request->param('url') ?? '')));
        if ($url === '' || (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url))) {
            abort(422, '图片地址无效');
        }
        $sourceHash = hash('sha256', $url);
        $existing = Db::transaction(function () use ($userId, $url, $sourceHash): ?QuickCreateFaceVerification {
            return QuickCreateFaceVerification::where('user_id', $userId)
                ->where('source_hash', $sourceHash)
                ->whereIn('status', ['queued', 'running', 'passed', 'failed'])
                ->order(['id' => 'desc'])
                ->lock(true)
                ->find();
        });
        if ($existing instanceof QuickCreateFaceVerification) {
            if ((string) $existing->getAttr('status') === 'failed') {
                $existing->save([
                    'status' => 'queued',
                    'asset_id' => 0,
                    'asset_image_id' => 0,
                    'asset_json' => [],
                    'asset_url' => '',
                    'error_message' => '',
                    'started_at' => null,
                    'finished_at' => null,
                ]);
                $existing->setAttr('status', 'queued');
                $existing->setAttr('asset_id', 0);
                $existing->setAttr('asset_image_id', 0);
                $existing->setAttr('asset_json', []);
                $existing->setAttr('asset_url', '');
                $existing->setAttr('error_message', '');
                $existing->setAttr('started_at', null);
                $existing->setAttr('finished_at', null);
            }
            $message = (string) $existing->getAttr('status') === 'passed' ? '人脸已通过' : '人脸检测已在处理中';
            return successCode(['verification' => $this->serializeFaceVerification($existing)], $message, (string) $existing->getAttr('status') === 'passed' ? 200 : 202);
        }
        $task = QuickCreateFaceVerification::create([
            'user_id' => $userId, 'source_url' => $url, 'source_hash' => $sourceHash, 'status' => 'queued',
            'asset_json' => [], 'asset_url' => '', 'error_message' => '',
        ]);
        return successCode(['verification' => $this->serializeFaceVerification($task)], '人脸检测已加入队列', 202);
    }

    public function faceVerificationStream()
    {
        $id = (int) ($this->request->param('id') ?? 0);
        $userId = $this->currentUserId();
        if ($id <= 0 || !QuickCreateFaceVerification::where('id', $id)->where('user_id', $userId)->find()) {
            return response('检测任务不存在', 404);
        }
        @set_time_limit(0); @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) { @ob_end_flush(); }
        if (!headers_sent()) { header('Content-Type: text/event-stream; charset=utf-8'); header('Cache-Control: no-cache, no-transform'); header('Connection: keep-alive'); header('X-Accel-Buffering: no'); }
        $last = -1; $started = time(); echo "retry: 2000\n\n";
        while (!connection_aborted() && time() - $started < 180) {
            $version = RedisCache::version('quick_create_face:' . $id);
            if ($version !== $last) {
                $task = QuickCreateFaceVerification::where('id', $id)->where('user_id', $userId)->find();
                if (!$task instanceof QuickCreateFaceVerification) break;
                echo "event: face_verification\n" . 'data: ' . json_encode($this->serializeFaceVerification($task), JSON_UNESCAPED_UNICODE) . "\n\n";
                $last = $version;
                if (in_array((string) $task->getAttr('status'), ['passed', 'failed'], true)) { @ob_flush(); @flush(); break; }
            } else { echo ": heartbeat\n\n"; }
            @ob_flush(); @flush(); sleep(1);
        }
        exit;
    }

    public function faceVerificationStatus()
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($this->request->param('ids') ?? []))));
        if ($ids === []) {
            return successCode(['verifications' => []]);
        }
        $rows = QuickCreateFaceVerification::where('user_id', $this->currentUserId())->whereIn('id', array_slice($ids, 0, 50))->select();
        return successCode(['verifications' => array_map(fn (QuickCreateFaceVerification $row): array => $this->serializeFaceVerification($row), $rows->all())]);
    }

    public function runQueuedFaceVerification(int $taskId): void
    {
        $task = QuickCreateFaceVerification::find($taskId);
        if (!$task instanceof QuickCreateFaceVerification) return;
        $userId = (int) $task->getAttr('user_id'); $url = trim((string) $task->getAttr('source_url'));
        try {
            $asset = Asset::create(['user_id' => $userId, 'series_id' => 0, 'type' => 'character', 'name' => '速创人脸-' . $taskId, 'description' => '速创上传图片人脸验证', 'tags' => ['quick_create_face'], 'is_hidden' => 1, 'sort' => 0, 'toapis_group_id' => '']);
            $image = AssetImage::create(['user_id' => $userId, 'asset_id' => (int) $asset->getAttr('id'), 'view_type' => 'look', 'url' => $url, 'note' => '速创上传图片', 'image_prompt' => '', 'sort' => 0, 'reference_role' => 'look', 'variant_name' => '速创人脸', 'reference_key' => 'quick-create-face-' . $taskId, 'toapis_asset_id' => '', 'toapis_asset_url' => '', 'toapis_status' => 'processing', 'video_ref_url' => '']);
            $result = (new ToapisPrivateAvatarService())->ingestLook($asset, $image, $url, $userId);
            $payload = array_merge($result, [
                'asset_id' => (int) $asset->getAttr('id'),
                'asset_image_id' => (int) $image->getAttr('id'),
                'asset_url' => (string) ($result['asset_url'] ?? ''),
            ]);
            $task->save(['asset_id' => $payload['asset_id'], 'asset_image_id' => $payload['asset_image_id'], 'asset_json' => $payload, 'asset_url' => $payload['asset_url'], 'status' => 'passed', 'error_message' => '', 'finished_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            $task->save(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 1800), 'finished_at' => date('Y-m-d H:i:s')]);
        }
        RedisCache::bumpVersion('quick_create_face:' . $taskId);
    }

    private const ASPECT_RATIOS = ['', 'adaptive', '16:9', '9:16', '1:1', '4:3', '3:4', '21:9'];
    private const VIDEO_RESOLUTIONS = ['', '480p', '720p', '1080p', '768P', '2K'];
    private const IMAGE_RESOLUTIONS = ['', '1K', '2K', '4K'];
    private const IMAGE_QUALITIES = ['', 'low', 'medium', 'high'];

    /**
     * 历史消息列表。
     * 返回当前用户最近的速创记录（含生成结果），供页面刷新后恢复会话。
     */
    public function index()
    {
        $limit = max(1, min((int) $this->request->param('limit', 20), self::HISTORY_LIMIT));
        $beforeId = max(0, (int) $this->request->param('before_id', 0));

        $query = QuickCreateMessage::where('user_id', $this->currentUserId());
        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query
            ->order(['id' => 'desc'])
            ->limit($limit + 1)
            ->select()
            ->all();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $oldestId = $rows === [] ? 0 : (int) $rows[count($rows) - 1]->getAttr('id');
        $messages = array_map(fn (QuickCreateMessage $m): array => $this->serialize($m), $rows);

        return successCode([
            'messages' => array_reverse($messages),
            'has_more' => $hasMore,
            'oldest_id' => $oldestId,
            'limit' => $limit,
        ]);
    }

    /**
     * 发送一条速创消息并排队生成。
     * 校验模式、提示词、模型与资产引用后写入 queued 记录，由 worker 异步执行。
     */
    public function send()
    {
        $payload = $this->request->param();

        $mode = strtolower(trim((string) ($payload['mode'] ?? 'video')));
        if (!in_array($mode, self::MODES, true)) {
            abort(422, '生成模式仅支持 image 或 video');
        }

        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($prompt === '') {
            abort(422, '请输入生成描述');
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            abort(422, '生成描述过长（最多 ' . self::MAX_PROMPT . ' 字）');
        }

        $userId = $this->currentUserId();
        $modelConfigId = (int) ($payload['model_config_id'] ?? 0);
        $model = ModelConfigResolver::resolve($mode, $userId, $modelConfigId);
        if (!$model instanceof ModelConfig) {
            abort(422, $mode === 'image' ? '没有可用的图片模型，请联系管理员配置' : '没有可用的视频模型，请联系管理员配置');
        }

        $refs = $this->normalizeAssetRefs(
            is_array($payload['asset_refs'] ?? null) ? $payload['asset_refs'] : [],
            $userId,
            $mode,
            $model
        );
        $options = $this->sanitizeOptions(
            is_array($payload['options'] ?? null) ? $payload['options'] : [],
            $mode,
            $model
        );

        $message = QuickCreateMessage::create([
            'user_id' => $userId,
            'mode' => $mode,
            'prompt' => $prompt,
            'model_config_id' => (int) $model->getAttr('id'),
            'asset_refs_json' => $refs,
            'options_json' => $options,
            'status' => 'queued',
            'result_urls_json' => [],
            'error_message' => '',
        ]);

        return successCode(['message' => $this->serialize($message)], 'success', 201);
    }

    /**
     * 基于已有图片编辑或重新生成。
     * 源消息和结果索引由服务端校验，避免前端伪造任意图片 URL 作为参考图。
     * 空 instruction 表示沿用原提示词重新生成；非空 instruction 表示编辑当前图片。
     */
    public function edit()
    {
        $payload = $this->request->param();
        $sourceMessageId = (int) ($payload['source_message_id'] ?? 0);
        $resultIndex = (int) ($payload['result_index'] ?? -1);
        $instruction = trim((string) ($payload['instruction'] ?? ''));

        if ($sourceMessageId <= 0) {
            abort(422, '源图片消息无效');
        }
        if ($resultIndex < 0) {
            abort(422, '源图片索引无效');
        }
        if (mb_strlen($instruction) > 2000) {
            abort(422, '本次修改要求不能超过 2000 字');
        }

        $userId = $this->currentUserId();
        $source = QuickCreateMessage::where('id', $sourceMessageId)
            ->where('user_id', $userId)
            ->find();
        if (!$source instanceof QuickCreateMessage) {
            abort(404, '源图片消息不存在');
        }
        if ((string) $source->getAttr('mode') !== 'image' || (string) $source->getAttr('status') !== 'success') {
            abort(422, '只有已成功生成的图片可以编辑或重新生成');
        }

        $resultUrls = $source->getAttr('result_urls_json') ?: [];
        if (!is_array($resultUrls)) {
            $resultUrls = [];
        }
        $sourceUrl = trim((string) ($resultUrls[$resultIndex] ?? ''));
        if ($sourceUrl === '') {
            abort(422, '源图片不存在或已失效');
        }

        $model = ModelConfigResolver::resolve('image', $userId, (int) $source->getAttr('model_config_id'));
        if (!$model instanceof ModelConfig) {
            abort(422, '原图片模型不存在或已停用，请先配置可用的图片模型');
        }

        $sourceRefs = $source->getAttr('asset_refs_json') ?: [];
        if (!is_array($sourceRefs)) {
            $sourceRefs = [];
        }

        $refs = [[
            'kind' => 'upload',
            'asset_id' => 0,
            'name' => '当前生成图片',
            'type' => 'upload',
            'image_url' => $sourceUrl,
            'reference_alias' => 'image_1',
        ]];
        foreach ($sourceRefs as $sourceRef) {
            if (!is_array($sourceRef)) {
                continue;
            }
            $url = trim((string) ($sourceRef['image_url'] ?? ''));
            if ($url === '' || $url === $sourceUrl || count($refs) >= self::MAX_REFS) {
                continue;
            }
            $refs[] = [
                'kind' => (string) ($sourceRef['kind'] ?? 'upload'),
                'asset_id' => (int) ($sourceRef['asset_id'] ?? 0),
                'asset_image_id' => (int) ($sourceRef['asset_image_id'] ?? 0),
                'name' => trim((string) ($sourceRef['name'] ?? '参考图')) ?: '参考图',
                'type' => trim((string) ($sourceRef['type'] ?? 'upload')) ?: 'upload',
                'image_url' => $url,
                'reference_alias' => 'image_' . (count($refs) + 1),
            ];
            if (isset($sourceRef['face_verification']) && is_array($sourceRef['face_verification'])) {
                $refs[count($refs) - 1]['face_verification'] = $sourceRef['face_verification'];
            }
        }

        $options = $source->getAttr('options_json') ?: [];
        if (!is_array($options)) {
            $options = [];
        }

        $options = $this->sanitizeOptions($options, 'image');
        $options['count'] = 1;

        $message = QuickCreateMessage::create([
            'user_id' => $userId,
            'mode' => 'image',
            'prompt' => $this->buildEditPrompt((string) $source->getAttr('prompt'), $instruction),
            'model_config_id' => (int) $model->getAttr('id'),
            'asset_refs_json' => $refs,
            'options_json' => $options,
            'status' => 'queued',
            'result_urls_json' => [],
            'error_message' => '',
            'parent_message_id' => $sourceMessageId,
            'parent_result_index' => $resultIndex,
            'edit_instruction' => $instruction,
        ]);

        return successCode(['message' => $this->serialize($message)], 'success', 201);
    }

    /**
     * 轮询消息状态。
     * 前端只对 queued/running 的消息静默轮询，返回最新状态与结果。
     */
    public function status()
    {
        $payload = $this->request->param();
        $ids = array_values(array_filter(array_map('intval', (array) ($payload['ids'] ?? []))));
        if ($ids === []) {
            return successCode(['messages' => []]);
        }

        $rows = QuickCreateMessage::where('user_id', $this->currentUserId())
            ->whereIn('id', array_slice($ids, 0, 50))
            ->select();

        return successCode([
            'messages' => array_map(fn (QuickCreateMessage $m): array => $this->serialize($m), $rows->all()),
        ]);
    }

    /**
     * Worker 执行入口：处理一条已被 claim 为 running 的速创消息。
     * 图片走 AssetController::callImageGeneration，视频走 SeriesController::callWorkflowVideoGeneration，
     * 复用既有生成、轮询、落盘与 AI 日志链路。
     */
    public function runQueuedMessage(int $messageId): void
    {
        $message = QuickCreateMessage::find($messageId);
        if (!$message instanceof QuickCreateMessage) {
            throw new \RuntimeException("速创消息不存在：{$messageId}");
        }

        $userId = (int) $message->getAttr('user_id');
        $mode = (string) $message->getAttr('mode');
        $prompt = (string) $message->getAttr('prompt');
        $refs = $message->getAttr('asset_refs_json') ?: [];
        $options = $message->getAttr('options_json') ?: [];
        if (!is_array($refs)) {
            $refs = [];
        }
        if (!is_array($options)) {
            $options = [];
        }

        // claim 后立即写一次心跳，避免在模型解析或参考图准备阶段被 stale 回收。
        $this->touchRunningMessage($messageId);

        try {
            $model = ModelConfigResolver::resolve($mode, $userId, (int) $message->getAttr('model_config_id'));
            if (!$model instanceof ModelConfig) {
                throw new \RuntimeException($mode === 'image' ? '图片模型不存在或已停用。' : '视频模型不存在或已停用。');
            }

            $count = max(1, min(self::MAX_COUNT, (int) ($options['count'] ?? 1)));
            $primaryImageUrl = $this->firstRefImageUrl($refs);
            $referenceImageUrls = $this->allRefImageUrls($refs);
            $referenceVideoUrls = $this->allRefVideoUrls($refs);
            $urls = [];
            $lastLogId = 0;
            $resumeTaskId = ImageProviderTaskState::extractTaskId((string) $message->getAttr('error_message'));

            if ($mode === 'image') {
                $existingUrls = $message->getAttr('result_urls_json') ?: [];
                if (!is_array($existingUrls)) {
                    $existingUrls = [];
                }
                $urls = array_values(array_filter(array_map('strval', $existingUrls)));
                $lastLogId = (int) ($message->getAttr('ai_request_log_id') ?: 0);
                $startIndex = count($urls);
                $controller = new AssetController($this->app);
                $controller->setRuntimeUserId($userId);
                $this->applyImageOptionsToModel($model, $options);
                for ($i = $startIndex; $i < $count; $i++) {
                    $url = $controller->callImageGeneration($model, $prompt, [
                        'source' => 'quick_create_image',
                        'user_id' => $userId,
                        'quick_create_message_id' => $messageId,
                        'main_image_urls' => $referenceImageUrls,
                        'allow_pending_yield' => true,
                        'inline_poll_attempts' => 1,
                        'provider_task_id' => $i === $startIndex ? $resumeTaskId : '',
                    ]);
                    $urls[] = MediaStorage::persistRemoteUrl($url, 'generated/quick-create', [
                        'user_id' => $userId,
                        'source' => 'quick_create_image',
                    ]);
                    $lastLogId = $controller->lastAiRequestLogId();
                    // 心跳：多条生成时刷新 update_time，避免被 stale 回收误重排。
                    if (!$this->updateRunningMessage($messageId, ['result_urls_json' => array_values(array_filter($urls))])) {
                        throw new \RuntimeException('速创任务已被回收，停止继续生成。');
                    }
                }
            } else {
                $controller = new SeriesController($this->app);
                $controller->setRuntimeUserId($userId);
                $assets = $this->assetsForVideoGeneration($refs);
                $videoOptions = $this->videoOptionsFromMessage($options);
                for ($i = 0; $i < $count; $i++) {
                    $urls[] = $controller->callWorkflowVideoGeneration(
                        $model,
                        $prompt,
                        $primaryImageUrl,
                        trim((string) ($options['duration'] ?? '')),
                        $assets,
                        [
                            'source' => 'quick_create_video',
                            'user_id' => $userId,
                            'quick_create_message_id' => $messageId,
                            'video_options' => $videoOptions,
                            'reference_videos' => $referenceVideoUrls,
                        ],
                        function (int $attempt, string $status) use ($messageId): void {
                            $this->touchRunningMessage($messageId);
                        }
                    );
                    $lastLogId = $controller->lastAiRequestLogId();
                    // 心跳：多条生成时刷新 update_time，避免被 stale 回收误重排。
                    if (!$this->updateRunningMessage($messageId, ['result_urls_json' => array_values(array_filter($urls))])) {
                        throw new \RuntimeException('速创任务已被回收，停止继续生成。');
                    }
                }
            }

            if (!$this->updateRunningMessage($messageId, [
                'status' => 'success',
                'result_urls_json' => array_values(array_filter($urls)),
                'ai_request_log_id' => $lastLogId > 0 ? $lastLogId : null,
                'error_message' => '',
                'finished_at' => date('Y-m-d H:i:s'),
            ])) {
                throw new \RuntimeException('速创任务已被回收，未写入成功结果。');
            }
        } catch (PendingImageTaskException $e) {
            $this->updateRunningMessage($messageId, [
                'status' => 'queued',
                'error_message' => ImageProviderTaskState::pendingMessage($e->taskId()),
                'finished_at' => null,
            ]);
        } catch (\Throwable $e) {
            $this->updateRunningMessage($messageId, [
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1800),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 归一化资产引用：资产必须属于当前用户；上传引用只接受本地或 HTTP(S) URL。
     */
    private function normalizeAssetRefs(array $items, int $userId, string $mode = 'image', ?ModelConfig $model = null): array
    {
        $refs = [];
        $assetIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $assetId = (int) ($item['asset_id'] ?? 0);
            if ($assetId > 0) {
                $assetIds[] = $assetId;
            }
        }

        $assetMap = [];
        $ownerNames = [];
        if ($assetIds !== []) {
            $assets = AssetVisibility::scopedAssetQuery($userId, 'all')
                ->with(['images'])
                ->whereIn('id', array_unique($assetIds))
                ->select();
            $ownerIds = [];
            foreach ($assets as $asset) {
                $assetMap[(int) $asset->getAttr('id')] = $asset;
                $ownerId = (int) $asset->getAttr('user_id');
                if ($ownerId !== $userId) {
                    $ownerIds[] = $ownerId;
                }
            }
            if ($ownerIds !== []) {
                $ownerNames = User::whereIn('id', array_unique($ownerIds))->column('display_name', 'id');
            }
        }

        foreach ($items as $item) {
            if (!is_array($item) || count($refs) >= self::MAX_REFS) {
                continue;
            }

            $assetId = (int) ($item['asset_id'] ?? 0);
            $referenceAlias = $this->normalizeReferenceAlias((string) ($item['reference_alias'] ?? ''));
            if ($assetId > 0) {
                $asset = $assetMap[$assetId] ?? null;
                if (!$asset instanceof Asset) {
                    continue;
                }
                $images = $asset->getAttr('images') ?: [];
                $wantedImageId = (int) ($item['asset_image_id'] ?? 0);
                $matchedImage = null;
                $fallbackImage = null;
                foreach ($images as $image) {
                    if (trim((string) $image->getAttr('url')) === '') {
                        continue;
                    }
                    if ($wantedImageId > 0 && (int) $image->getAttr('id') === $wantedImageId) {
                        $matchedImage = $image;
                        break;
                    }
                    if ($fallbackImage === null) {
                        $fallbackImage = $image;
                    }
                }
                $selectedImage = $matchedImage ?? $fallbackImage;
                if ($selectedImage === null) {
                    continue;
                }
                $ownerId = (int) $asset->getAttr('user_id');
                $name = $this->assetRefDisplayName($asset, $selectedImage);
                if ($ownerId !== $userId) {
                    $ownerName = $ownerNames[$ownerId] ?? '';
                    if ($ownerName !== '') {
                        $name .= '（来自 ' . $ownerName . '）';
                    }
                }
                $refs[] = [
                    'kind' => 'asset',
                    'asset_id' => $assetId,
                    'asset_image_id' => (int) ($selectedImage->getAttr('id') ?? 0),
                    'name' => $name,
                    'type' => (string) $asset->getAttr('type'),
                    'image_url' => trim((string) $selectedImage->getAttr('url')),
                    'reference_alias' => $referenceAlias,
                ];
                continue;
            }

            $mediaType = strtolower(trim((string) ($item['media_type'] ?? 'image'))) === 'video' ? 'video' : 'image';
            if ($mediaType === 'video' && ($mode !== 'video' || !$this->isMiniMaxVideoModel($model))) {
                abort(422, '只有 MiniMax 视频模型支持视频参考素材');
            }
            $url = trim((string) ($item['url'] ?? ($mediaType === 'video' ? ($item['video_url'] ?? '') : ($item['image_url'] ?? ''))));
            if ($url === '' || (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url))) {
                continue;
            }
            $ref = [
                'kind' => 'upload',
                'asset_id' => 0,
                'name' => trim((string) ($item['name'] ?? '')) ?: ($mediaType === 'video' ? '参考视频' : '参考图'),
                'type' => 'upload',
                'media_type' => $mediaType,
                'reference_alias' => $referenceAlias,
            ];
            $ref[$mediaType === 'video' ? 'video_url' : 'image_url'] = $this->canonicalizeStorageUrl($url);
            if ($mediaType === 'image') {
                $verificationId = (int) ($item['face_verification_id'] ?? 0);
                if ($verificationId > 0) {
                    $verification = QuickCreateFaceVerification::where('id', $verificationId)
                        ->where('user_id', $userId)
                        ->where('source_url', $this->canonicalizeStorageUrl($url))
                        ->find();
                    if ($verification instanceof QuickCreateFaceVerification) {
                        $ref['face_verification'] = $this->serializeFaceVerification($verification);
                    }
                }
            }
            if ($mediaType === 'video') {
                $ref['duration_seconds'] = max(0.0, (float) ($item['duration_seconds'] ?? 0));
            }
            $refs[] = $ref;
        }

        $videoRefs = array_values(array_filter($refs, static fn (array $ref): bool => ($ref['media_type'] ?? 'image') === 'video'));
        if (count($videoRefs) > 3) {
            abort(422, 'MiniMax 参考视频最多 3 段');
        }
        $knownDuration = array_sum(array_map(static fn (array $ref): float => (float) ($ref['duration_seconds'] ?? 0), $videoRefs));
        if ($knownDuration > 15.001) {
            abort(422, 'MiniMax 参考视频总时长不能超过 15 秒');
        }

        return $refs;
    }

    private function normalizeReferenceAlias(string $alias): string
    {
        $alias = strtolower(trim($alias));
        return preg_match('/^(?:image|video)_[1-9]$/', $alias) === 1 ? $alias : '';
    }

    /**
     * 引用展示名：人物造型（reference_role=look）附带具体造型名，避免只显示资产名
     * 而看不出引用的是哪一套服装/造型；核心视图与其他类型资产保持只显示资产名。
     */
    private function assetRefDisplayName(Asset $asset, AssetImage $image): string
    {
        $name = (string) $asset->getAttr('name');
        if ((string) ($image->getAttr('reference_role') ?? 'view') !== 'look') {
            return $name;
        }

        $variantName = trim((string) ($image->getAttr('variant_name') ?? ''));

        return $name . ' · ' . ($variantName !== '' ? $variantName : '默认造型');
    }

    /**
     * 前端为了本地预览会把 /storage/ 相对路径拼上 window.location.origin（如开发环境的
     * 127.0.0.1:5174），这个地址上游 AI 服务商访问不到。这里剥离协议+主机，只保留
     * /storage/ 相对路径，交由下游 normalizeImageUrlForToapis 等逻辑按 MEDIA_PUBLIC_BASE_URL
     * 重新拼接真正可公网访问的地址；非本地存储的外部 URL 原样保留。
     */
    private function canonicalizeStorageUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (str_starts_with($path, '/storage/v1/object/')) {
            return $url;
        }

        if (preg_match('#^https?://[^/]+(/storage/.*)$#i', $url, $matches) === 1) {
            return $matches[1];
        }

        return $url;
    }

    /**
     * 生成参数白名单，未识别的值直接丢弃。
     */
    private function sanitizeOptions(array $options, string $mode, ?ModelConfig $model = null): array
    {
        $out = [];

        $aspectRatio = trim((string) ($options['aspect_ratio'] ?? ''));
        if (in_array($aspectRatio, self::ASPECT_RATIOS, true) && $aspectRatio !== '' && $aspectRatio !== 'adaptive') {
            $out['aspect_ratio'] = $aspectRatio;
        }

        $resolution = trim((string) ($options['resolution'] ?? ''));
        $allowedResolutions = $mode === 'image'
            ? self::IMAGE_RESOLUTIONS
            : ($this->isMiniMaxVideoModel($model) ? ['', '768P', '2K'] : ['', '480p', '720p', '1080p']);
        if ($resolution !== '' && in_array($resolution, $allowedResolutions, true)) {
            $out['resolution'] = $resolution;
        }

        if ($mode === 'image') {
            $quality = strtolower(trim((string) ($options['quality'] ?? '')));
            if ($quality !== '' && in_array($quality, self::IMAGE_QUALITIES, true)) {
                $out['quality'] = $quality;
            }
        }

        $count = (int) ($options['count'] ?? 1);
        $out['count'] = max(1, min(self::MAX_COUNT, $count));

        if ($mode === 'video') {
            $duration = (int) ($options['duration'] ?? 0);
            if ($duration > 0) {
                $out['duration'] = $this->isMiniMaxVideoModel($model)
                    ? max(4, min(15, $duration))
                    : max(5, min(15, $duration));
            }
            if (!$this->isMiniMaxVideoModel($model) && array_key_exists('generate_audio', $options)) {
                $out['generate_audio'] = (bool) $options['generate_audio'];
            }
        }

        return $out;
    }

    private function isMiniMaxVideoModel(?ModelConfig $model): bool
    {
        if (!$model instanceof ModelConfig) {
            return false;
        }
        $options = $model->getAttr('options') ?: [];
        $provider = is_array($options) ? strtolower(trim((string) ($options['provider'] ?? ''))) : '';

        return in_array($provider, ['minimax', 'minimax_v2'], true)
            || str_contains(strtolower((string) $model->getAttr('endpoint')), 'api.minimaxi.com/v2/video_generation');
    }

    /**
     * 用户选择的图片参数临时合并进模型 options（仅内存，不落库）。
     * 腾讯云点播 OG/image2：比例/分辨率上游无效，仍隐藏；画质 quality 可映射 ModelVersion。
     */
    private function applyImageOptionsToModel(ModelConfig $model, array $options): void
    {
        $imageOptions = [];
        $quality = strtolower(trim((string) ($options['quality'] ?? '')));
        if (in_array($quality, ['low', 'medium', 'high'], true)) {
            $imageOptions['quality'] = $quality;
            // 避免模型默认 model_version=image2_high 盖住用户所选画质。
            $imageOptions['model_version'] = \app\support\ImageGenerationOptions::defaultModelVersion($quality);
        }

        if (!$this->isTencentVodImageModel($model)) {
            if (trim((string) ($options['aspect_ratio'] ?? '')) !== '') {
                $imageOptions['aspect_ratio'] = (string) $options['aspect_ratio'];
            }
            if (trim((string) ($options['resolution'] ?? '')) !== '') {
                $imageOptions['resolution'] = (string) $options['resolution'];
            }
        }

        if ($imageOptions === []) {
            return;
        }

        $modelOptions = $model->getAttr('options') ?: [];
        if (!is_array($modelOptions)) {
            $modelOptions = [];
        }
        $model->setAttr('options', array_merge($modelOptions, $imageOptions));
    }

    private function isTencentVodImageModel(?ModelConfig $model): bool
    {
        if (!$model instanceof ModelConfig) {
            return false;
        }
        $options = $model->getAttr('options') ?: [];
        $provider = is_array($options) ? strtolower(trim((string) ($options['provider'] ?? ''))) : '';
        $endpoint = strtolower((string) $model->getAttr('endpoint'));

        return in_array($provider, ['tencent_vod', 'tencent', 'vod_aigc'], true)
            || str_contains($endpoint, 'vod.tencentcloudapi.com');
    }

    private function videoOptionsFromMessage(array $options): array
    {
        $videoOptions = [];
        foreach (['aspect_ratio', 'resolution'] as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value !== '') {
                $videoOptions[$key] = $value;
            }
        }
        $duration = (int) ($options['duration'] ?? 0);
        if ($duration > 0) {
            $videoOptions['duration'] = $duration;
        }
        if (array_key_exists('generate_audio', $options)) {
            $videoOptions['generate_audio'] = (bool) $options['generate_audio'];
        }

        return $videoOptions;
    }

    /**
     * 视频轮询期间刷新速创消息心跳。只更新仍属于 running 的消息，
     * 防止旧进程在任务已被回收/失败后又覆盖最终状态。
     */
    private function touchRunningMessage(int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        QuickCreateMessage::where('id', $messageId)
            ->where('status', 'running')
            ->update(['update_time' => date('Y-m-d H:i:s')]);
    }

    /**
     * 仅允许当前仍为 running 的进程写入最终状态，避免 stale 回收后旧进程迟到覆盖结果。
     */
    private function updateRunningMessage(int $messageId, array $data): bool
    {
        if ($messageId <= 0) {
            return false;
        }
        if (array_key_exists('result_urls_json', $data) && is_array($data['result_urls_json'])) {
            $data['result_urls_json'] = json_encode(
                array_values(array_filter(array_map('strval', $data['result_urls_json']))),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        $data['update_time'] = date('Y-m-d H:i:s');

        return QuickCreateMessage::where('id', $messageId)
            ->where('status', 'running')
            ->update($data) > 0;
    }

    private function buildEditPrompt(string $originalPrompt, string $instruction): string
    {
        $originalPrompt = trim($originalPrompt);
        $instruction = trim($instruction);
        $suffix = "\n\n基于当前参考图重新生成。尽量保留主体、构图、视角、光影和整体风格，仅调整本次要求涉及的内容。";
        if ($instruction !== '') {
            $suffix .= "\n本次修改要求：{$instruction}";
        }

        $available = max(0, self::MAX_PROMPT - mb_strlen($suffix));
        return trim(mb_substr($originalPrompt, 0, $available) . $suffix);
    }

    private function firstRefImageUrl(array $refs): string
    {
        foreach ($refs as $ref) {
            $url = trim((string) ($ref['image_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * 图片模式下把所有 @ 引用/上传的参考图一并传给生成接口，让模型把多张图合并处理，
     * 而不是像单参考图历史链路那样只取第一张。
     */
    private function allRefImageUrls(array $refs): array
    {
        $urls = [];
        foreach ($refs as $ref) {
            $url = trim((string) ($ref['image_url'] ?? ''));
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function allRefVideoUrls(array $refs): array
    {
        $urls = [];
        foreach ($refs as $ref) {
            $url = trim((string) ($ref['video_url'] ?? ''));
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return array_slice($urls, 0, 3);
    }

    /**
     * 转成视频生成链路要求的参考资产结构（buildVideoReferenceImages 的输入）。
     */
    private function assetsForVideoGeneration(array $refs): array
    {
        $assets = [];
        foreach ($refs as $ref) {
            $verification = is_array($ref['face_verification'] ?? null)
                ? $ref['face_verification']
                : [];
            $verifiedAssetUrl = strtolower((string) ($verification['status'] ?? '')) === 'passed'
                ? trim((string) ($verification['asset_url'] ?? ''))
                : '';
            // 已通过人脸验证的速创图片必须复用 ToAPIs asset:// 资源，避免退回原始上传图。
            $url = $verifiedAssetUrl !== ''
                ? $verifiedAssetUrl
                : trim((string) ($ref['image_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $assets[] = [
                'id' => (int) ($ref['asset_id'] ?? 0),
                'name' => (string) ($ref['name'] ?? ''),
                'type' => (string) ($ref['type'] ?? 'other'),
                'image_url' => $url,
                'reference_alias' => (string) ($ref['reference_alias'] ?? ''),
            ];
        }

        return $assets;
    }

    private function serialize(QuickCreateMessage $message): array
    {
        $rawRefs = $message->getAttr('asset_refs_json');
        $refs = $this->hydrateFaceVerificationRefs(
            is_array($rawRefs) ? $rawRefs : [],
            (int) $message->getAttr('user_id')
        );
        return [
            'id' => (int) $message->getAttr('id'),
            'mode' => (string) $message->getAttr('mode'),
            'prompt' => (string) $message->getAttr('prompt'),
            'model_config_id' => (int) $message->getAttr('model_config_id'),
            'parent_message_id' => $message->getAttr('parent_message_id') !== null ? (int) $message->getAttr('parent_message_id') : null,
            'parent_result_index' => $message->getAttr('parent_result_index') !== null ? (int) $message->getAttr('parent_result_index') : null,
            'edit_instruction' => (string) $message->getAttr('edit_instruction'),
            'asset_refs' => $refs,
            'options' => $message->getAttr('options_json') ?: [],
            'status' => (string) $message->getAttr('status'),
            'result_urls' => $message->getAttr('result_urls_json') ?: [],
            'error_message' => (string) $message->getAttr('error_message'),
            'create_time' => $message->getAttr('create_time'),
            'finished_at' => $message->getAttr('finished_at'),
        ];
    }

    private function serializeFaceVerification(QuickCreateFaceVerification $task): array
    {
        return [
            'id' => (int) $task->getAttr('id'),
            'status' => (string) $task->getAttr('status'),
            'source_url' => (string) $task->getAttr('source_url'),
            'asset_id' => (int) $task->getAttr('asset_id'),
            'asset_image_id' => (int) $task->getAttr('asset_image_id'),
            'asset_url' => (string) $task->getAttr('asset_url'),
            'asset' => $task->getAttr('asset_json') ?: [],
            'message' => (string) $task->getAttr('error_message'),
            'verified_at' => $task->getAttr('finished_at'),
        ];
    }

    private function hydrateFaceVerificationRefs(array $refs, int $userId): array
    {
        foreach ($refs as $index => $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $id = (int) ($ref['face_verification']['id'] ?? 0);
            if ($id > 0) {
                if (!array_key_exists($id, $this->faceVerificationCache)) {
                    $task = QuickCreateFaceVerification::where('id', $id)->where('user_id', $userId)->find();
                    $this->faceVerificationCache[$id] = $task instanceof QuickCreateFaceVerification ? $task : null;
                }
                $task = $this->faceVerificationCache[$id];
                if ($task instanceof QuickCreateFaceVerification) {
                    $refs[$index]['face_verification'] = $this->serializeFaceVerification($task);
                    continue;
                }
            }

            // 历史消息可能未写入 face_verification；按图片地址回填已有检测结果，避免重新生成后还要再点一次。
            $mediaType = (string) ($ref['media_type'] ?? ((isset($ref['video_url']) && $ref['video_url'] !== '') ? 'video' : 'image'));
            if ($mediaType === 'video') {
                continue;
            }
            $url = $this->canonicalizeStorageUrl(trim((string) ($ref['image_url'] ?? $ref['url'] ?? '')));
            if ($url === '') {
                continue;
            }
            $sourceHash = hash('sha256', $url);
            $cacheKey = -crc32($userId . ':' . $sourceHash);
            if (!array_key_exists($cacheKey, $this->faceVerificationCache)) {
                $task = QuickCreateFaceVerification::where('user_id', $userId)
                    ->where('source_hash', $sourceHash)
                    ->order(['id' => 'desc'])
                    ->find();
                $this->faceVerificationCache[$cacheKey] = $task instanceof QuickCreateFaceVerification ? $task : null;
            }
            $task = $this->faceVerificationCache[$cacheKey];
            if ($task instanceof QuickCreateFaceVerification) {
                $refs[$index]['face_verification'] = $this->serializeFaceVerification($task);
            }
        }

        return $refs;
    }
}
