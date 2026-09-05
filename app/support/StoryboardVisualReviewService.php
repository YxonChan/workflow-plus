<?php

declare(strict_types=1);

namespace app\support;

use app\model\AiRequestLog;
use app\model\Asset;
use app\model\AssetImage;
use app\model\AssetImageVersion;
use app\model\Episode;
use app\model\ModelConfig;
use app\model\Shot;
use think\App;

final class StoryboardVisualReviewService
{
    private const MAX_REFERENCES = 3;
    private const REVIEW_TTL = 1800;
    private const CONFIRM_TTL = 600;
    private const MAX_LOCAL_IMAGE_BYTES = 15 * 1024 * 1024;

    /** @var array<string, array<string, string>> */
    private const TRAIT_LABELS = [
        'hair_color' => [
            'black' => '黑色', 'red' => '红色', 'brown' => '棕色', 'blonde' => '金色',
            'white' => '白色', 'silver' => '银色', 'gray' => '灰色', 'blue' => '蓝色', 'purple' => '紫色',
        ],
        'hair_length' => ['long' => '长发', 'short' => '短发'],
        'hair_texture' => ['straight' => '直发', 'curly' => '卷发'],
        'eye_color' => [
            'black' => '黑色', 'brown' => '棕色', 'blue' => '蓝色', 'green' => '绿色',
            'gray' => '灰色', 'red' => '红色',
        ],
        'age_stage' => ['child' => '儿童', 'teen' => '青少年', 'adult' => '成年人', 'elderly' => '老年人'],
        'eyewear' => ['glasses' => '佩戴眼镜', 'no_glasses' => '未戴眼镜'],
        'facial_hair' => ['beard' => '有胡须', 'clean_shaven' => '无胡须'],
    ];

    public function review(App $app, int $userId, int $episodeId, array $references): array
    {
        $episode = Episode::where('id', $episodeId)->where('user_id', $userId)->find();
        if (!$episode instanceof Episode) {
            abort(404, '剧集不存在');
        }
        $revisionId = (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
        if ($revisionId <= 0) {
            abort(422, '当前剧集还没有分镜，无法检查');
        }
        $references = $this->resolveReferences($userId, (int) $episode->getAttr('series_id'), $references);
        if ($references === []) {
            abort(422, '请拖入人物图片或使用 @ 引用当前作品的资产图片');
        }

        $shots = Shot::where('user_id', $userId)
            ->where('episode_id', $episodeId)
            ->where('storyboard_revision_id', $revisionId)
            ->order(['index' => 'asc', 'id' => 'asc'])
            ->select();
        if ($shots->isEmpty()) {
            abort(422, '当前分镜版本没有可检查的镜头');
        }

        $model = ModelConfigResolver::resolve('text', $userId);
        if (!$model instanceof ModelConfig) {
            abort(422, '没有可用的文本模型，无法进行实际图片识别');
        }

        $consistency = new StoryboardConsistencyService();
        $issues = [];
        $baselines = [];
        $unmentioned = [];
        foreach ($references as $reference) {
            $vision = $this->analyzeImage($app, $userId, $model, $reference);
            $traits = $vision['traits'];
            if ($traits === []) {
                abort(422, '视觉模型没有可靠识别出「' . $reference['asset_name'] . '」的可检查属性，请换一张更清晰的单人参考图');
            }
            $baselines[] = [
                'asset_id' => $reference['asset_id'],
                'asset_name' => $reference['asset_name'],
                'image_url' => $reference['image_url'],
                'source' => $reference['kind'],
                'traits' => $traits,
                'confidence' => $vision['confidence'],
                'notes' => $vision['notes'],
            ];

            $mentionCount = 0;
            foreach ($shots as $shot) {
                if (!$shot instanceof Shot) {
                    continue;
                }
                $description = trim((string) $shot->getAttr('desc'));
                if ($description === '' || mb_stripos($description, $reference['asset_name']) === false) {
                    continue;
                }
                $mentionCount++;
                $actualTraits = $consistency->extractTraits($description, $reference['asset_name']);
                foreach ($consistency->compareTraits($traits, $actualTraits) as $difference) {
                    $shotIndex = (int) $shot->getAttr('index');
                    $issueId = substr(hash('sha256', implode('|', [
                        $userId,
                        $episodeId,
                        $revisionId,
                        (int) $shot->getAttr('id'),
                        $reference['asset_id'],
                        (string) ($difference['field'] ?? ''),
                        (string) ($difference['expected'] ?? ''),
                        (string) ($difference['actual'] ?? ''),
                    ])), 0, 24);
                    $fieldLabel = (string) ($difference['field_label'] ?? '外观属性');
                    $expected = (string) ($difference['expected'] ?? '');
                    $actual = (string) ($difference['actual'] ?? '');
                    $issues[] = [
                        'issue_id' => $issueId,
                        'severity' => (string) ($difference['severity'] ?? 'warning'),
                        'shot_id' => (int) $shot->getAttr('id'),
                        'shot_index' => (int) $shot->getAttr('index'),
                        'asset_id' => $reference['asset_id'],
                        'asset_name' => $reference['asset_name'],
                        'field' => (string) ($difference['field'] ?? ''),
                        'field_label' => $fieldLabel,
                        'expected' => $expected,
                        'actual' => $actual,
                        'image_evidence' => (string) ($difference['asset_evidence'] ?? ''),
                        'storyboard_evidence' => (string) ($difference['storyboard_evidence'] ?? ''),
                        'confidence' => (float) ($traits[(string) ($difference['field'] ?? '')]['confidence'] ?? $vision['confidence']),
                        'message' => "镜头{$shotIndex}中「{$reference['asset_name']}」的{$fieldLabel}与参考图不一致：图片识别为{$expected}，分镜写为{$actual}",
                        'suggestion' => "若参考图为准，将镜头{$shotIndex}中「{$reference['asset_name']}」的{$fieldLabel}改为{$expected}；若这是剧情变化，请标记为剧情例外。",
                    ];
                }
            }
            if ($mentionCount === 0) {
                $unmentioned[] = $reference['asset_name'];
            }
        }

        $reviewToken = bin2hex(random_bytes(24));
        $reviewData = [
            'user_id' => $userId,
            'series_id' => (int) $episode->getAttr('series_id'),
            'episode_id' => $episodeId,
            'episode_number' => (int) $episode->getAttr('number'),
            'episode_title' => (string) $episode->getAttr('title'),
            'revision_id' => $revisionId,
            'issues' => $issues,
            'created_at' => time(),
            'expires_at' => time() + self::REVIEW_TTL,
        ];
        $this->storeToken($this->reviewKey($reviewToken), $reviewData, self::REVIEW_TTL);

        return [
            'read_only' => true,
            'review_token' => $reviewToken,
            'expires_in' => self::REVIEW_TTL,
            'scope' => [
                'series_id' => (int) $episode->getAttr('series_id'),
                'episode_id' => $episodeId,
                'episode_number' => (int) $episode->getAttr('number'),
                'episode_title' => (string) $episode->getAttr('title'),
                'storyboard_revision_id' => $revisionId,
            ],
            'checked_shots' => $shots->count(),
            'baselines' => $baselines,
            'issue_count' => count($issues),
            'issues' => $issues,
            'unmentioned_assets' => $unmentioned,
            'limits' => '实际图片由当前文本模型的多模态能力识别；只比较图片中明确、可信的属性与本集分镜文本，不检查整部作品，不自动修改。',
        ];
    }

    public function preview(int $userId, string $reviewToken, array $decisions): array
    {
        $review = $this->loadToken($this->reviewKey($reviewToken), $userId, '检查结果已过期，请重新检查本集分镜');
        $episodeId = (int) $review['episode_id'];
        $revisionId = (int) $review['revision_id'];
        $this->assertCurrentRevision($userId, $episodeId, $revisionId);

        $issueMap = [];
        foreach (is_array($review['issues'] ?? null) ? $review['issues'] : [] as $issue) {
            if (is_array($issue) && trim((string) ($issue['issue_id'] ?? '')) !== '') {
                $issueMap[(string) $issue['issue_id']] = $issue;
            }
        }
        $selected = [];
        $decisionSummary = ['modify' => 0, 'exception' => 0, 'ignore' => 0];
        foreach (array_slice($decisions, 0, 100) as $decision) {
            if (!is_array($decision)) {
                continue;
            }
            $issueId = trim((string) ($decision['issue_id'] ?? ''));
            $action = trim((string) ($decision['action'] ?? 'ignore'));
            if (!isset($issueMap[$issueId]) || !array_key_exists($action, $decisionSummary)) {
                continue;
            }
            $decisionSummary[$action]++;
            if ($action === 'modify') {
                $selected[$issueId] = $issueMap[$issueId];
            }
        }
        if ($selected === []) {
            abort(422, '没有选择需要修改的问题；剧情例外和忽略不会写入分镜');
        }

        $issuesByShot = [];
        foreach ($selected as $issue) {
            $issuesByShot[(int) $issue['shot_id']][] = $issue;
        }
        $model = ModelConfigResolver::resolve('text', $userId);
        if (!$model instanceof ModelConfig) {
            abort(422, '没有可用的文本模型，无法生成修改预览');
        }

        $previews = [];
        foreach ($issuesByShot as $shotId => $shotIssues) {
            $shot = Shot::where('id', $shotId)
                ->where('user_id', $userId)
                ->where('episode_id', $episodeId)
                ->where('storyboard_revision_id', $revisionId)
                ->find();
            if (!$shot instanceof Shot) {
                abort(409, '分镜已经变化，请重新检查');
            }
            $before = trim((string) $shot->getAttr('desc'));
            $after = $this->rewriteShot($userId, $model, $before, $shotIssues);
            if ($after === '' || $after === $before) {
                abort(422, '镜头' . (int) $shot->getAttr('index') . '没有生成有效修改，请重新选择问题');
            }
            $previews[] = [
                'shot_id' => $shotId,
                'shot_index' => (int) $shot->getAttr('index'),
                'before' => $before,
                'after' => $after,
                'issue_ids' => array_values(array_map(static fn (array $item): string => (string) $item['issue_id'], $shotIssues)),
                'changes' => array_values(array_map(static fn (array $item): array => [
                    'asset_name' => (string) $item['asset_name'],
                    'field_label' => (string) $item['field_label'],
                    'from' => (string) $item['actual'],
                    'to' => (string) $item['expected'],
                ], $shotIssues)),
            ];
        }

        $confirmationToken = bin2hex(random_bytes(24));
        $confirmation = [
            'user_id' => $userId,
            'series_id' => (int) $review['series_id'],
            'episode_id' => $episodeId,
            'revision_id' => $revisionId,
            'review_token' => $reviewToken,
            'previews' => $previews,
            'created_at' => time(),
            'expires_at' => time() + self::CONFIRM_TTL,
        ];
        $this->storeToken($this->confirmationKey($confirmationToken), $confirmation, self::CONFIRM_TTL);

        return [
            'confirmation_token' => $confirmationToken,
            'expires_in' => self::CONFIRM_TTL,
            'scope' => [
                'episode_id' => $episodeId,
                'storyboard_revision_id' => $revisionId,
            ],
            'modified_shot_count' => count($previews),
            'decision_summary' => $decisionSummary,
            'previews' => $previews,
            'impact' => '确认后只修改以上镜头并创建一个新分镜版本；其他镜头不变，旧版本保留，相关镜头视频标记为需要重新生成。',
        ];
    }

    public function apply(App $app, int $userId, string $confirmationToken): array
    {
        $key = $this->confirmationKey($confirmationToken);
        $confirmation = $this->loadToken($key, $userId, '确认令牌已过期，请重新生成修改预览');
        $lock = RedisCache::acquireLock('worker_visual_apply:' . $confirmationToken, 120);
        if ($lock === null) {
            abort(409, '该修改正在执行或确认服务不可用，请勿重复提交');
        }

        try {
            $episodeId = (int) $confirmation['episode_id'];
            $revisionId = (int) $confirmation['revision_id'];
            $this->assertCurrentRevision($userId, $episodeId, $revisionId);
            $controller = new \app\controller\SeriesController($app);
            $result = $controller->applyAgentStoryboardRepairs(
                $userId,
                $episodeId,
                $revisionId,
                is_array($confirmation['previews'] ?? null) ? $confirmation['previews'] : [],
            );
            RedisCache::delete($key);
            RedisCache::delete($this->reviewKey((string) ($confirmation['review_token'] ?? '')));

            return [
                'applied' => true,
                'episode_id' => $episodeId,
                'previous_storyboard_revision_id' => $revisionId,
                'storyboard_revision_id' => (int) ($result['current_storyboard_revision_id'] ?? 0),
                'modified_shot_count' => count(is_array($confirmation['previews'] ?? null) ? $confirmation['previews'] : []),
                'episode' => $result,
            ];
        } finally {
            RedisCache::releaseLock('worker_visual_apply:' . $confirmationToken, $lock);
        }
    }

    private function resolveReferences(int $userId, int $seriesId, array $references): array
    {
        $resolved = [];
        foreach (array_slice($references, 0, self::MAX_REFERENCES) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $assetId = (int) ($reference['asset_id'] ?? 0);
            $asset = $assetId > 0
                ? Asset::where('id', $assetId)->where('user_id', $userId)->where('series_id', $seriesId)->where('type', 'character')->find()
                : null;
            if (!$asset instanceof Asset) {
                abort(422, '每张参考图都必须关联当前作品中的一个人物资产');
            }

            $kind = (string) ($reference['kind'] ?? 'asset');
            $url = '';
            $assetImageId = (int) ($reference['asset_image_id'] ?? 0);
            $assetImageVersionId = (int) ($reference['asset_image_version_id'] ?? 0);
            if ($kind === 'upload') {
                $upload = WorkerReferenceToken::resolve($userId, (string) ($reference['upload_token'] ?? ''));
                $url = $upload['url'];
            } elseif ($assetImageVersionId > 0) {
                $version = AssetImageVersion::where('id', $assetImageVersionId)
                    ->where('user_id', $userId)
                    ->where('asset_id', $assetId)
                    ->find();
                if (!$version instanceof AssetImageVersion) {
                    abort(422, '所选资产图片版本不存在或不属于当前用户');
                }
                $assetImageId = (int) $version->getAttr('asset_image_id');
                $url = trim((string) $version->getAttr('url'));
            } elseif ($assetImageId > 0) {
                $image = AssetImage::where('id', $assetImageId)->where('asset_id', $assetId)->find();
                if (!$image instanceof AssetImage) {
                    abort(422, '所选资产图片不存在');
                }
                $url = trim((string) $image->getAttr('url'));
            }
            if ($url === '') {
                abort(422, '参考图片地址为空，请重新选择图片版本');
            }

            $resolved[] = [
                'kind' => $kind === 'upload' ? 'upload' : 'asset',
                'asset_id' => $assetId,
                'asset_name' => (string) $asset->getAttr('name'),
                'asset_description' => (string) $asset->getAttr('description'),
                'asset_image_id' => $assetImageId,
                'asset_image_version_id' => $assetImageVersionId,
                'image_url' => $url,
            ];
        }

        return $resolved;
    }

    private function analyzeImage(App $app, int $userId, ModelConfig $model, array $reference): array
    {
        $imageInput = $this->imageInput($app, (string) $reference['image_url']);
        $allowed = [];
        foreach (self::TRAIT_LABELS as $field => $values) {
            $allowed[$field] = array_keys($values);
        }
        $messages = [
            [
                'role' => 'system',
                'content' => '你是人物参考图属性提取器。只根据图片像素识别画面中的主要人物；不使用文件名猜测。只输出 JSON，不要 markdown。无法确定的字段必须省略。',
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '人物名称仅用于标记：' . (string) $reference['asset_name'] . "\n"
                            . '允许字段和值：' . json_encode($allowed, JSON_UNESCAPED_UNICODE) . "\n"
                            . '输出格式：{"person_detected":true,"confidence":0.0,"traits":{"hair_color":{"value":"black","confidence":0.0}},"notes":[]}。confidence 范围 0 到 1。多视图人物设定图按同一人物识别。',
                    ],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageInput, 'detail' => 'high']],
                ],
            ],
        ];
        $raw = $this->completeModel($userId, $model, $messages, 900, 0.1, 'worker_visual_review');
        $decoded = $this->decodeJsonObject($raw);
        if (($decoded['person_detected'] ?? true) === false) {
            abort(422, '视觉模型没有在「' . $reference['asset_name'] . '」参考图中识别到人物');
        }
        $traits = $this->normalizeVisionTraits(is_array($decoded['traits'] ?? null) ? $decoded['traits'] : []);

        return [
            'confidence' => $this->confidence($decoded['confidence'] ?? 0.5),
            'traits' => $traits,
            'notes' => array_slice(array_values(array_filter(array_map('strval', is_array($decoded['notes'] ?? null) ? $decoded['notes'] : []))), 0, 5),
        ];
    }

    /** @return array<string, array{value:string,label:string,evidence:string,confidence:float}> */
    private function normalizeVisionTraits(array $traits): array
    {
        $normalized = [];
        foreach (self::TRAIT_LABELS as $field => $labels) {
            $raw = $traits[$field] ?? null;
            $value = is_array($raw) ? trim((string) ($raw['value'] ?? '')) : trim((string) $raw);
            if ($value === '' || !isset($labels[$value])) {
                continue;
            }
            $confidence = $this->confidence(is_array($raw) ? ($raw['confidence'] ?? 0.5) : 0.5);
            if ($confidence < 0.55) {
                continue;
            }
            $normalized[$field] = [
                'value' => $value,
                'label' => $labels[$value],
                'evidence' => '实际图片识别（置信度 ' . number_format($confidence * 100, 0) . '%）',
                'confidence' => $confidence,
            ];
        }

        return $normalized;
    }

    private function rewriteShot(int $userId, ModelConfig $model, string $before, array $issues): string
    {
        $changes = array_map(static fn (array $issue): array => [
            'character' => (string) $issue['asset_name'],
            'field' => (string) $issue['field_label'],
            'from' => (string) $issue['actual'],
            'to' => (string) $issue['expected'],
        ], $issues);
        $messages = [
            [
                'role' => 'system',
                'content' => '你是分镜文本外科式编辑器。只修改指定人物的指定外观属性，保持原语言、镜头数量、时长、动作、对白、场景、道具和镜头语言完全不变。只输出 JSON：{"description":"修改后的完整分镜文本"}。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'original_description' => $before,
                    'required_changes' => $changes,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
        $raw = $this->completeModel($userId, $model, $messages, 4096, 0.1, 'worker_storyboard_repair_preview');
        $decoded = $this->decodeJsonObject($raw);

        return trim((string) ($decoded['description'] ?? ''));
    }

    private function completeModel(
        int $userId,
        ModelConfig $model,
        array $messages,
        int $maxTokens,
        float $temperature,
        string $source,
    ): string {
        $endpoint = trim((string) $model->getAttr('endpoint'));
        if ($endpoint === '') {
            abort(422, '文本模型 endpoint 为空');
        }
        if (!str_contains($endpoint, '/chat/completions')) {
            $endpoint = rtrim($endpoint, '/') . '/chat/completions';
        }
        $modelId = trim((string) $model->getAttr('model_id'));
        $payload = [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];
        if ($this->shouldDisableDeepSeekThinking($endpoint, $modelId)) {
            $payload['thinking'] = ['type' => 'disabled'];
        }
        CreditService::assertTextAffordable(
            $userId,
            CreditService::estimatePromptTokensFromMessages($messages),
            $maxTokens,
            $modelId
        );
        $headers = ['Content-Type: application/json'];
        $apiKey = trim((string) $model->getAttr('api_key'));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $startedAt = microtime(true);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            abort(500, '初始化 AI 请求失败');
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = (string) curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawBody = is_string($response) ? $response : '';
        $decoded = json_decode($rawBody, true);
        $message = is_array($decoded['choices'][0]['message'] ?? null)
            ? $decoded['choices'][0]['message']
            : [];
        $content = trim((string) ($message['content'] ?? ''));
        if ($content === '') {
            $content = trim((string) ($message['reasoning_content'] ?? ($message['reasoning'] ?? '')));
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logRequest($userId, $model, $endpoint, $payload, $rawBody, $content, $httpStatus, $curlErrno, $curlError, $durationMs, $source);

        if ($curlErrno !== 0) {
            abort(502, 'AI 请求失败：' . ($curlErrno === 28 ? '响应超时，请稍后再试' : $curlError));
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $hint = $source === 'worker_visual_review' ? '；当前文本模型可能不支持图片输入，请更换支持视觉的多模态模型' : '';
            abort(502, 'AI 服务异常：HTTP ' . $httpStatus . $hint);
        }
        if ($content === '') {
            abort(502, 'AI 返回内容为空');
        }

        return $content;
    }

    private function shouldDisableDeepSeekThinking(string $endpoint, string $modelId): bool
    {
        $endpoint = strtolower($endpoint);
        $modelId = strtolower($modelId);

        return str_contains($endpoint, 'api.deepseek.com')
            || str_starts_with($modelId, 'deepseek-v4')
            || str_starts_with($modelId, 'deepseek-chat')
            || str_starts_with($modelId, 'deepseek-reasoner');
    }

    private function logRequest(
        int $userId,
        ModelConfig $model,
        string $endpoint,
        array $payload,
        string $rawBody,
        string $content,
        int $httpStatus,
        int $curlErrno,
        string $curlError,
        int $durationMs,
        string $source,
    ): void {
        try {
            $loggedPayload = $this->redactDataUrls($payload);
            AiRequestLog::create([
                'user_id' => $userId,
                'source' => $source,
                'model_config_id' => (int) $model->getAttr('id'),
                'llm_model' => (string) $model->getAttr('model_id'),
                'finish_reason' => '',
                'max_tokens' => (int) ($payload['max_tokens'] ?? 0),
                'usage_json' => [],
                'endpoint' => mb_substr($endpoint, 0, 500),
                'context_json' => json_encode(['source' => $source], JSON_UNESCAPED_UNICODE),
                'request_json' => json_encode($loggedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'http_status' => $httpStatus,
                'response_body' => mb_substr($rawBody, 0, 60000),
                'curl_errno' => $curlErrno,
                'curl_error' => mb_substr($curlError, 0, 500),
                'request_ok' => $curlErrno === 0 && $httpStatus >= 200 && $httpStatus < 300 && $content !== '' ? 1 : 0,
                'error_message' => '',
                'duration_ms' => $durationMs,
                'content_preview' => mb_substr($content, 0, 900),
                'assistant_content' => $content,
            ]);
        } catch (\Throwable) {
        }
    }

    private function redactDataUrls(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, 'data:image/')) {
            return '[image data omitted; bytes=' . strlen($value) . ']';
        }
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $clean[$key] = $this->redactDataUrls($item);
        }

        return $clean;
    }

    private function imageInput(App $app, string $url): string
    {
        $url = trim($url);
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        if (str_starts_with($path, '/storage/')) {
            $root = realpath(rtrim($app->getRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'storage');
            $candidate = $root !== false
                ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim(substr($path, strlen('/storage/')), '/')))
                : false;
            if ($root === false || $candidate === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
                abort(422, '参考图片文件不存在，请重新上传或选择');
            }
            $size = filesize($candidate);
            if ($size === false || $size > self::MAX_LOCAL_IMAGE_BYTES) {
                abort(422, '参考图片过大，视觉检查限制为 15MB');
            }
            $binary = file_get_contents($candidate);
            if ($binary === false || $binary === '') {
                abort(422, '读取参考图片失败');
            }
            $mime = function_exists('mime_content_type') ? (string) mime_content_type($candidate) : 'image/png';
            if (!str_starts_with($mime, 'image/')) {
                abort(422, '参考文件不是有效图片');
            }

            return 'data:' . $mime . ';base64,' . base64_encode($binary);
        }
        if (!preg_match('#^https?://#i', $url)) {
            abort(422, '参考图片地址无效');
        }

        return $url;
    }

    private function decodeJsonObject(string $raw): array
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $match) === 1) {
            $text = trim($match[1]);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $text, $match) === 1) {
            $decoded = json_decode($match[0], true);
        }
        if (!is_array($decoded)) {
            abort(502, 'AI 返回格式无法解析，请重试');
        }

        return $decoded;
    }

    private function confidence(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    private function assertCurrentRevision(int $userId, int $episodeId, int $expectedRevisionId): void
    {
        $current = Episode::where('id', $episodeId)->where('user_id', $userId)->value('current_storyboard_revision_id');
        if ((int) $current !== $expectedRevisionId) {
            abort(409, '分镜在检查后已经变化，旧检查结果和确认令牌已失效，请重新检查');
        }
    }

    private function storeToken(string $key, array $payload, int $ttl): void
    {
        RedisCache::set($key, $payload, $ttl);
        if (RedisCache::get($key, null) === null) {
            abort(503, '确认令牌服务不可用，请稍后再试');
        }
    }

    private function loadToken(string $key, int $userId, string $expiredMessage): array
    {
        $payload = RedisCache::get($key, null);
        if (!is_array($payload)
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || (int) ($payload['expires_at'] ?? 0) < time()) {
            abort(409, $expiredMessage);
        }

        return $payload;
    }

    private function reviewKey(string $token): string
    {
        return 'worker_visual_review:' . trim($token);
    }

    private function confirmationKey(string $token): string
    {
        return 'worker_visual_confirmation:' . trim($token);
    }
}
