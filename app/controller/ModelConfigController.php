<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\ModelConfig;
use app\model\User;
use app\support\AuthService;
use app\support\ImageGenerationOptions;
use app\support\ModelConfigResolver;
use app\support\RedisCache;
use app\validate\ModelConfigValidate;
use think\facade\Db;

class ModelConfigController extends BaseController
{
    /**
     * 查询模型配置分组列表。
     * 按文本、图片、视频、语音四类分组返回，供模型配置页面展示。
     */
    public function index()
    {
        return AuthService::withDeadlockRetry(
            fn () => successCode($this->doListGrouped(false))
        );
    }

    public function adminIndex()
    {
        $this->requireAdmin();

        return AuthService::withDeadlockRetry(
            fn () => successCode($this->doListGrouped(true))
        );
    }

    /**
     * 新建模型配置。
     * 解析 options 后校验字段，写入模型类型、名称、model_id、endpoint、api_key 和扩展配置。
     */
    public function save()
    {
        $this->requireAdmin();
        $payload = $this->payload();
        $error = $this->validatePayload($payload);
        if ($error !== null) {
            return $error;
        }

        return successCode($this->doCreate($payload), 'success', 201);
    }

    /**
     * 更新模型配置。
     * 从 JSON 请求体读取 id 后更新配置；api_key 为空时保留原密钥。
     */
    public function update()
    {
        $this->requireAdmin();
        $payload = $this->payload();
        $id = $this->requireId($payload, '模型配置 id 不能为空');
        $error = $this->validatePayload($payload);
        if ($error !== null) {
            return $error;
        }

        return successCode($this->doUpdate($id, $payload));
    }

    /**
     * 删除模型配置。
     * 从 JSON 请求体读取 id 后删除记录。
     */
    public function delete()
    {
        $this->requireAdmin();
        $payload = $this->request->param();
        $id = $this->requireId($payload, '模型配置 id 不能为空');
        $this->doDelete($id);

        return successCode();
    }

    /**
     * 探测模型通道可用性。
     * 文本模型执行轻量真实请求；其余类型执行零成本 HTTP 预检。
     */
    public function probe()
    {
        $this->requireAdmin();
        $payload = $this->request->param();
        $id = $this->requireId($payload, '模型配置 id 不能为空');

        return successCode($this->probeModel($this->findOrFail($id)));
    }

    /**
     * 从请求体读取必填 id。
     * 所有接口参数统一走 JSON body，不再从 URL path 接收 id。
     */
    private function requireId(array $payload, string $message): int
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            abort(422, $message);
        }

        return $id;
    }

    /**
     * 读取并整理模型配置请求体。
     * 把 options JSON 字符串解析为数组；未传 options 时统一为空数组。
     */
    private function payload(): array
    {
        $payload = $this->request->param();
        $options = $payload['options'] ?? [];

        if (is_string($options) && $options !== '') {
            $decoded = json_decode($options, true);
            $payload['options'] = is_array($decoded) ? $decoded : $options;
        }

        if (!isset($payload['options']) || $payload['options'] === '') {
            $payload['options'] = [];
        }

        return $payload;
    }

    /**
     * 校验模型配置请求体。
     * 复用 ModelConfigValidate，失败时返回统一 422 响应。
     */
    private function validatePayload(array $payload)
    {
        $validator = new ModelConfigValidate();
        if ($validator->check($payload)) {
            return null;
        }

        return errorCode([], $validator->getError(), 422);
    }

    private const TYPES = [
        'text' => [
            'title' => '文本模型',
            'description' => '用于剧本拆解、分镜文案生成',
            'icon' => 'file-text',
            ],
        'image' => [
            'title' => '图片模型',
            'description' => '用于关键帧生图、角色设定',
            'icon' => 'image',
            ],
        'video' => [
            'title' => '视频模型',
            'description' => '用于图生视频、动作生成',
            'icon' => 'film',
            ],
        'voice' => [
            'title' => '语音模型',
            'description' => '用于角色配音、旁白生成',
            'icon' => 'mic',
        ],
    ];

    /**
     * 执行模型配置分组查询。
     * 先查询所有模型，再按固定类型元数据组装成前端分组结构。
     */
    private function doListGrouped(bool $admin): array
    {
        $models = $admin ? $this->adminModelRows() : $this->visibleModelRows();

        $groups = [];
        foreach (self::TYPES as $type => $meta) {
            $groups[] = [
                'type' => $type,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'icon' => $meta['icon'],
                'models' => array_values(array_filter(
                    $models,
                    fn (array $model): bool => $model['type'] === $type
                )),
            ];
        }

        return $groups;
    }

    private function visibleModelRows(): array
    {
        $version = RedisCache::version('model_configs');
        $userId = $this->currentUserId();
        return RedisCache::remember("model_configs:visible:{$this->currentUserCacheSuffix()}:v{$version}:ht1", 120, function () use ($userId): array {
            return ModelConfigResolver::visibleModelsQuery($userId)
                ->select()
                ->map(fn (ModelConfig $model): array => $this->serialize($model, false))
                ->toArray();
        });
    }

    private function adminModelRows(): array
    {
        $scope = trim((string) $this->request->param('scope', ''));
        $userId = (int) $this->request->param('user_id', 0);

        $query = ModelConfig::order(['scope' => 'asc', 'user_id' => 'asc', 'type' => 'asc', 'sort' => 'asc', 'id' => 'asc']);
        if (in_array($scope, ['global', 'user'], true)) {
            $query->where('scope', $scope);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        return $query->select()
            ->map(fn (ModelConfig $model): array => $this->serialize($model, true))
            ->toArray();
    }

    /**
     * 执行模型配置创建事务。
     * 规范化字段、计算排序值并写入 model_configs。
     */
    private function doCreate(array $payload): array
    {
        return Db::transaction(function () use ($payload): array {
            $payload = $this->normalizePayload($payload);
            $payload['sort'] = (int) ($payload['sort'] ?? 0) > 0
                ? (int) $payload['sort']
                : $this->nextSort($payload['type'], $payload['scope'], (int) $payload['user_id']);

            $model = new ModelConfig();
            $model->save($payload);
            $this->clearSiblingDefaults($model);
            $this->clearModelConfigCache();

            return $this->serialize($model);
        });
    }

    /**
     * 执行模型配置更新事务。
     * 找到原记录后规范化字段，保存后重新读取并返回脱敏结构。
     */
    private function doUpdate(int $id, array $payload): array
    {
        return Db::transaction(function () use ($id, $payload): array {
            $model = $this->findOrFail($id);
            $payload = $this->normalizePayload($payload, $model);

            $model->save($payload);
            $this->clearSiblingDefaults($model);
            $this->clearModelConfigCache();

            return $this->serialize($this->findOrFail($id));
        });
    }

    /**
     * 执行模型配置删除事务。
     */
    private function doDelete(int $id): void
    {
        Db::transaction(function () use ($id): void {
            $model = $this->findOrFail($id);
            $model->delete();
        });
        $this->clearModelConfigCache();
    }

    /**
     * 规范化模型配置入参。
     * 更新时如果 api_key 留空，则沿用旧密钥，避免误清空。
     */
    private function normalizePayload(array $payload, ?ModelConfig $existing = null): array
    {
        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        $type = (string) ($payload['type'] ?? '');
        $scope = (string) ($payload['scope'] ?? ($existing?->getAttr('scope') ?: 'global'));
        $scope = $scope === 'user' ? 'user' : 'global';
        $userId = $scope === 'user'
            ? (int) ($payload['user_id'] ?? $existing?->getAttr('user_id') ?? 0)
            : 0;
        if ($scope === 'user' && $userId <= 0) {
            abort(422, '请选择专属用户');
        }
        if ($scope === 'user' && !User::where('id', $userId)->where('status', 1)->find()) {
            abort(422, '专属用户不存在或已禁用');
        }
        $options = $payload['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        if ($type === 'image') {
            $payload['name'] = trim((string) ($payload['name'] ?? '')) !== ''
                ? $payload['name']
                : ImageGenerationOptions::DEFAULT_MODEL_NAME;
            $payload['model_id'] = trim((string) ($payload['model_id'] ?? '')) !== ''
                ? $payload['model_id']
                : ImageGenerationOptions::DEFAULT_MODEL_ID;
            $payload['endpoint'] = trim((string) ($payload['endpoint'] ?? '')) !== ''
                ? $payload['endpoint']
                : ImageGenerationOptions::DEFAULT_ENDPOINT;
            $options = array_merge(
                ImageGenerationOptions::defaultModelOptions((string) ($payload['model_id'] ?? '')),
                $options,
                ['resolution' => ImageGenerationOptions::DEFAULT_RESOLUTION]
            );
        }

        return [
            'type' => $type,
            'name' => trim((string) $payload['name']),
            'model_id' => trim((string) $payload['model_id']),
            'endpoint' => trim((string) $payload['endpoint']),
            'api_key' => $apiKey !== '' ? $apiKey : (string) ($existing->api_key ?? ''),
            'options' => $options,
            'scope' => $scope,
            'user_id' => $userId,
            'is_default' => (int) ($payload['is_default'] ?? $existing?->getAttr('is_default') ?? 0) ? 1 : 0,
            'enabled' => (int) ($payload['enabled'] ?? $existing?->getAttr('enabled') ?? 1) ? 1 : 0,
        ];
    }

    /**
     * 序列化模型配置。
     * 返回前端需要的字段，并对 api_key 做掩码处理。
     */
    private function serialize(ModelConfig $model, bool $admin = false): array
    {
        $row = [
            'id' => $model->getAttr('id'),
            'type' => $model->getAttr('type'),
            'name' => $model->getAttr('name'),
            'model_id' => $model->getAttr('model_id'),
            'endpoint' => $model->getAttr('endpoint'),
            'api_key_mask' => $this->maskApiKey((string) $model->getAttr('api_key')),
            'options' => $model->getAttr('options') ?: [],
            'scope' => $model->getAttr('scope') ?: 'global',
            'user_id' => (int) $model->getAttr('user_id'),
            'is_default' => (int) $model->getAttr('is_default'),
            'enabled' => (int) ($model->getAttr('enabled') ?? 1),
            'create_time' => $model->getAttr('create_time'),
            'update_time' => $model->getAttr('update_time'),
        ];
        if ($admin) {
            $row['user'] = $row['user_id'] > 0 ? $this->serializeTargetUser((int) $row['user_id']) : null;
        }

        return $row;
    }

    /**
     * 生成密钥掩码。
     * 未配置时显示“未设置”，已配置时只显示末尾 4 位。
     */
    private function maskApiKey(string $apiKey): string
    {
        if ($apiKey === '') {
            return '未设置';
        }

        return str_repeat('*', 8) . substr($apiKey, -4);
    }

    /**
     * 查找模型配置。
     * 找不到时直接返回 404。
     */
    private function findOrFail(int $id): ModelConfig
    {
        $model = ModelConfig::where('id', $id)->find();
        if (!$model instanceof ModelConfig) {
            abort(404, '模型配置不存在');
        }

        return $model;
    }

    /**
     * 计算同类型模型的下一个排序值。
     */
    private function nextSort(string $type, string $scope, int $userId): int
    {
        return ((int) ModelConfig::where('type', $type)
            ->where('scope', $scope)
            ->where('user_id', $scope === 'global' ? 0 : $userId)
            ->max('sort')) + 10;
    }

    private function clearSiblingDefaults(ModelConfig $model): void
    {
        if ((int) $model->getAttr('is_default') !== 1) {
            return;
        }

        ModelConfig::where('id', '<>', (int) $model->getAttr('id'))
            ->where('type', (string) $model->getAttr('type'))
            ->where('scope', (string) $model->getAttr('scope'))
            ->where('user_id', (int) $model->getAttr('user_id'))
            ->update(['is_default' => 0]);
    }

    private function serializeTargetUser(int $userId): ?array
    {
        $user = User::where('id', $userId)->find();
        if (!$user instanceof User) {
            return null;
        }

        return [
            'id' => (int) $user->getAttr('id'),
            'username' => (string) $user->getAttr('username'),
            'display_name' => (string) $user->getAttr('display_name'),
        ];
    }

    /**
     * 清理模型配置缓存。
     * 模型新增、更新、删除后必须失效，避免工作流节点拿到旧配置。
     */
    private function clearModelConfigCache(): void
    {
        RedisCache::bumpVersion('model_configs');
    }

    /**
     * 按模型类型执行通道检测。
     * 文本走真实轻量请求，其余类型只校验 endpoint 可达性，避免产生费用。
     */
    private function probeModel(ModelConfig $model): array
    {
        return match ((string) $model->getAttr('type')) {
            'text' => $this->probeTextModel($model),
            'image' => $this->probePreflightModel($model, $this->normalizeImageEndpoint((string) $model->getAttr('endpoint'))),
            'video', 'voice' => $this->probePreflightModel($model, trim((string) $model->getAttr('endpoint'))),
            default => $this->buildProbeResult($model, [
                'mode' => 'preflight',
                'method' => 'HEAD',
                'ok' => false,
                'reachable' => false,
                'http_status' => 0,
                'duration_ms' => 0,
                'message' => '暂不支持该模型类型的通道检测',
                'detail' => '当前仅支持 text/image/video/voice 四类模型检测。',
            ]),
        };
    }

    /**
     * 文本模型走一次极小化 chat/completions 实检。
     */
    private function probeTextModel(ModelConfig $model): array
    {
        $endpoint = $this->normalizeTextEndpoint((string) $model->getAttr('endpoint'));
        $modelId = trim((string) $model->getAttr('model_id'));
        if ($endpoint === '') {
            return $this->buildProbeResult($model, [
                'mode' => 'live_request',
                'method' => 'POST',
                'ok' => false,
                'reachable' => false,
                'http_status' => 0,
                'duration_ms' => 0,
                'message' => '文本模型 endpoint 为空',
                'detail' => '请先填写可用的 OpenAI 兼容 chat/completions 地址。',
            ]);
        }
        if ($modelId === '') {
            return $this->buildProbeResult($model, [
                'mode' => 'live_request',
                'method' => 'POST',
                'ok' => false,
                'reachable' => false,
                'http_status' => 0,
                'duration_ms' => 0,
                'message' => '文本模型 model_id 为空',
                'detail' => '请先填写模型 ID。',
            ]);
        }

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $apiKey = trim((string) $model->getAttr('api_key'));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $probe = $this->sendProbeRequest(
            'POST',
            $endpoint,
            $headers,
            [
                'model' => $modelId,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a health check assistant.'],
                    ['role' => 'user', 'content' => 'Reply with OK'],
                ],
                'temperature' => 0,
                'max_tokens' => 1,
                'stream' => false,
            ],
            20
        );

        $decoded = $probe['decoded'];
        $httpStatus = (int) $probe['http_status'];
        $curlErrno = (int) $probe['curl_errno'];
        $curlError = (string) $probe['curl_error'];
        $body = (string) $probe['body'];
        $detail = $curlErrno > 0
            ? $curlError
            : $this->extractProbeDetail($decoded, $body);
        $ok = $curlErrno === 0 && $httpStatus >= 200 && $httpStatus < 300;

        return $this->buildProbeResult($model, [
            'mode' => 'live_request',
            'method' => 'POST',
            'endpoint' => $endpoint,
            'ok' => $ok,
            'reachable' => $curlErrno === 0 && $httpStatus > 0,
            'http_status' => $httpStatus,
            'duration_ms' => (int) $probe['duration_ms'],
            'message' => $ok ? '文本通道可用，已完成轻量实检' : '文本通道检测失败',
            'detail' => $detail !== '' ? $detail : ($ok ? '上游已返回有效响应。' : '上游未返回可识别结果。'),
        ]);
    }

    /**
     * 非文本模型执行零成本预检，仅验证 endpoint 可达性与服务端响应。
     */
    private function probePreflightModel(ModelConfig $model, string $endpoint): array
    {
        if ($endpoint === '') {
            return $this->buildProbeResult($model, [
                'mode' => 'preflight',
                'method' => 'HEAD',
                'ok' => false,
                'reachable' => false,
                'http_status' => 0,
                'duration_ms' => 0,
                'message' => '模型 endpoint 为空',
                'detail' => '请先填写接口地址。',
            ]);
        }

        $headers = ['Accept: application/json'];
        $apiKey = trim((string) $model->getAttr('api_key'));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $probe = $this->sendProbeRequest('HEAD', $endpoint, $headers, null, 15);
        $httpStatus = (int) $probe['http_status'];
        $curlErrno = (int) $probe['curl_errno'];
        $curlError = (string) $probe['curl_error'];
        $body = (string) $probe['body'];
        $ok = $curlErrno === 0 && (
            ($httpStatus >= 200 && $httpStatus < 300)
            || in_array($httpStatus, [401, 403, 405], true)
        );

        $detail = $curlErrno > 0
            ? $curlError
            : ($ok
                ? '预检已收到上游响应；本次未触发真实生成或转码任务。'
                : ($this->extractProbeDetail($probe['decoded'], $body) ?: '上游已响应，但状态不符合可用通道判定。'));

        return $this->buildProbeResult($model, [
            'mode' => 'preflight',
            'method' => 'HEAD',
            'endpoint' => $endpoint,
            'ok' => $ok,
            'reachable' => $curlErrno === 0 && $httpStatus > 0,
            'http_status' => $httpStatus,
            'duration_ms' => (int) $probe['duration_ms'],
            'message' => $ok ? '预检通过，通道已响应' : '预检失败，通道未通过可用性判定',
            'detail' => $detail,
        ]);
    }

    /**
     * 统一发送探测请求。
     *
     * @return array{http_status:int,curl_errno:int,curl_error:string,body:string,duration_ms:int,decoded:array<string,mixed>|null}
     */
    private function sendProbeRequest(string $method, string $endpoint, array $headers, ?array $payload, int $timeout): array
    {
        $startedAt = microtime(true);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
        ]);

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = (int) curl_errno($ch);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        $rawBody = is_string($body) ? $body : '';
        $decoded = null;
        if ($rawBody !== '') {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }

        return [
            'http_status' => $httpStatus,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'body' => $rawBody,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'decoded' => $decoded,
        ];
    }

    private function normalizeTextEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        if (!str_contains($endpoint, '/chat/completions')) {
            $endpoint = rtrim($endpoint, '/') . '/chat/completions';
        }

        return $endpoint;
    }

    private function normalizeImageEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        if (str_contains($endpoint, '/images/edits')) {
            return str_replace('/images/edits', '/images/generations', $endpoint);
        }
        if (str_contains($endpoint, '/images/generations')) {
            return $endpoint;
        }

        return rtrim($endpoint, '/') . '/images/generations';
    }

    /**
     * 提取上游错误/结果摘要，避免把完整响应直接抛给前端。
     */
    private function extractProbeDetail(?array $decoded, string $body): string
    {
        if (is_array($decoded)) {
            $candidates = [
                $decoded['error']['message'] ?? null,
                $decoded['message'] ?? null,
                $decoded['msg'] ?? null,
                $decoded['detail'] ?? null,
                $decoded['choices'][0]['message']['content'] ?? null,
                $decoded['choices'][0]['text'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return $this->excerpt($body);
    }

    private function excerpt(string $text, int $limit = 220): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '...' : $text;
    }

    /**
     * 统一组装探测结果。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildProbeResult(ModelConfig $model, array $data): array
    {
        return [
            'id' => (int) $model->getAttr('id'),
            'name' => (string) $model->getAttr('name'),
            'type' => (string) $model->getAttr('type'),
            'model_id' => (string) $model->getAttr('model_id'),
            'endpoint' => (string) ($data['endpoint'] ?? $model->getAttr('endpoint') ?? ''),
            'mode' => (string) ($data['mode'] ?? 'preflight'),
            'method' => (string) ($data['method'] ?? 'HEAD'),
            'ok' => (bool) ($data['ok'] ?? false),
            'reachable' => (bool) ($data['reachable'] ?? false),
            'http_status' => (int) ($data['http_status'] ?? 0),
            'duration_ms' => (int) ($data['duration_ms'] ?? 0),
            'message' => (string) ($data['message'] ?? ''),
            'detail' => (string) ($data['detail'] ?? ''),
        ];
    }
}
