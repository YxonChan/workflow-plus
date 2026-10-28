<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use app\model\AssetImage;
use app\model\ModelConfig;
use think\facade\Db;

final class EcommerceService
{
    public static function ensureSchema(): void
    {
        static $ready = false;
        if ($ready) return;
        foreach (explode(';', file_get_contents(dirname(__DIR__, 2) . '/database/sql/20261020_ecommerce_box.sql')) as $sql) {
            if (trim($sql) !== '') Db::execute($sql);
        }
        $ready = true;
    }

    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode($data): array
    {
        return is_array($data) ? $data : (json_decode((string) $data, true) ?: []);
    }

    public function owned(int $userId, int $id, bool $lock = false): array
    {
        self::ensureSchema();
        $query = Db::name('ecommerce_projects')->where('id', $id)->where('user_id', $userId);
        $row = ($lock ? $query->lock(true) : $query)->find();
        if (!$row) abort(404, '电商项目不存在或不可访问');
        return $row;
    }

    public function projects(int $userId): array
    {
        self::ensureSchema();
        $projects = Db::name('ecommerce_projects')->where('user_id', $userId)->field('id,name,revision,state_json,update_time')
            ->order('update_time', 'desc')->limit(100)->select()->toArray();
        return array_map(static function (array $project): array {
            $state = self::decode($project['state_json'] ?? '{}');
            $product = (array) ($state['product'] ?? []);
            $uploads = array_values((array) ($product['uploads'] ?? []));
            $results = array_values((array) ($product['results'] ?? []));
            $cover = $uploads[0]['src'] ?? $results[0]['src'] ?? '';
            unset($project['state_json']);
            $project['cover_url'] = (string) $cover;
            $project['product_name'] = (string) (($product['config']['name'] ?? '') ?: $project['name']);
            return $project;
        }, $projects);
    }

    public function create(int $userId, array $state, bool $import = false): array
    {
        self::ensureSchema();
        if (count($this->projects($userId)) >= 100) abort(422, '最多保存 100 个电商项目');
        return Db::transaction(function () use ($userId, $state, $import): array {
            $now = date('Y-m-d H:i:s');
            $id = (int) Db::name('ecommerce_projects')->insertGetId(['user_id' => $userId, 'name' => '新的电商项目',
                'revision' => 1, 'state_json' => '{}', 'create_time' => $now, 'update_time' => $now]);
            $map = [];
            $clean = $this->validateState($userId, $id, $state, $import, $map);
            Db::name('ecommerce_projects')->where('id', $id)->update(['name' => $clean['name'], 'state_json' => self::encode($clean)]);
            return $this->detail($userId, $id);
        });
    }

    public function save(int $userId, int $id, int $revision, array $state): array
    {
        $this->owned($userId, $id);
        $map = [];
        $state = $this->validateState($userId, $id, $state, false, $map);
        $updated = Db::name('ecommerce_projects')->where('id', $id)->where('user_id', $userId)->where('revision', $revision)
            ->update(['state_json' => self::encode($state), 'name' => $state['name'], 'revision' => $revision + 1,
                'update_time' => date('Y-m-d H:i:s')]);
        if (!$updated) abort(409, '项目已在其他窗口更新，请刷新后再保存；当前草稿仍保留在本页');
        return ['revision' => $revision + 1];
    }

    public function detail(int $userId, int $id): array
    {
        $row = $this->owned($userId, $id);
        $runs = Db::name('ecommerce_runs')->where('project_id', $id)->where('user_id', $userId)->order('id', 'asc')->select()->toArray();
        $jobs = Db::name('ecommerce_jobs')->where('project_id', $id)->where('user_id', $userId)->order('id', 'asc')->select()->toArray();
        return ['id' => $id, 'name' => $row['name'], 'revision' => (int) $row['revision'], 'state' => self::decode($row['state_json']),
            'available_scene_ids' => array_column($this->scenePresets(), 'id'),
            'runs' => array_map(static fn (array $run): array => ['id' => (int) $run['id'], 'paused' => (bool) $run['paused'],
                'snapshot' => self::decode($run['snapshot_json']), 'create_time' => $run['create_time']], $runs),
            'jobs' => array_map(static function (array $job): array {
                $job['request'] = self::decode($job['request_json']);
                $job['provider_submitted'] = (string) ($job['provider_task_id'] ?? '') !== '';
                unset($job['request_json'], $job['result_endpoint'], $job['provider_task_id'], $job['user_id']);
                return $job;
            }, $jobs)];
    }

    public function delete(int $userId, int $id): void
    {
        Db::transaction(function () use ($userId, $id): void {
            $this->owned($userId, $id, true);
            if (Db::name('ecommerce_jobs')->where('project_id', $id)->whereIn('status', ['queued', 'running', 'unknown'])->count()) {
                abort(409, '项目仍有未完成任务，请先处理任务');
            }
            if (Db::name('ecommerce_story_requests')->where('project_id', $id)->where('status', 'running')
                ->where('update_time', '>', date('Y-m-d H:i:s', time() - 240))->count()) abort(409, '项目正在扩展剧情，请完成后再删除');
            if (Db::name('ecommerce_copy_requests')->where('project_id', $id)->where('status', 'running')
                ->where('update_time', '>', date('Y-m-d H:i:s', time() - 240))->count()) abort(409, '项目正在拟写文案，请完成后再删除');
            if (Db::name('ecommerce_brief_requests')->where('project_id', $id)->where('status', 'running')
                ->where('update_time', '>', date('Y-m-d H:i:s', time() - 300))->count()) abort(409, '项目正在确认视频需求，请完成后再删除');
            foreach (['ecommerce_jobs', 'ecommerce_runs', 'ecommerce_media', 'ecommerce_story_requests', 'ecommerce_copy_requests', 'ecommerce_brief_requests'] as $table) {
                Db::name($table)->where('project_id', $id)->where('user_id', $userId)->delete();
            }
            Db::name('ecommerce_projects')->where('id', $id)->where('user_id', $userId)->delete();
        });
    }

    public function upload(int $userId, int $projectId, \think\file\UploadedFile $file): array
    {
        $this->owned($userId, $projectId);
        validate(['file' => ['fileSize' => 10 * 1024 * 1024, 'fileExt' => 'jpg,jpeg,png,webp',
            'fileMime' => 'image/jpeg,image/png,image/webp']])->check(['file' => $file]);
        $binary = file_get_contents($file->getPathname());
        return $this->storeImage($userId, $projectId, $binary, mb_substr($file->getOriginalName(), 0, 200));
    }

    private function storeImage(int $userId, int $projectId, string $binary, string $name): array
    {
        $info = @getimagesizefromstring($binary);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!$info || !isset($extensions[$info['mime']]) || strlen($binary) > 10 * 1024 * 1024 || $info[0] * $info[1] > 16000000) {
            abort(422, '图片需为 JPG、PNG 或 WebP，大小不超过 10MB、1600 万像素');
        }
        $url = MediaStorage::uploadImageBinary($binary, $extensions[$info['mime']], 'uploads/ecommerce', $info['mime'],
            ['user_id' => $userId, 'source' => 'ecommerce', 'original_name' => $name]);
        return $this->registerMedia($userId, $projectId, $url, $name, $info[0], $info[1]);
    }

    public function registerMedia(int $userId, int $projectId, string $url, string $name, int $width = 1, int $height = 1): array
    {
        $row = Db::name('ecommerce_media')->where('user_id', $userId)->where('project_id', $projectId)->where('url', $url)->find();
        $id = $row ? (int) $row['id'] : (int) Db::name('ecommerce_media')->insertGetId(['user_id' => $userId,
            'project_id' => $projectId, 'url' => $url, 'name' => mb_substr($name, 0, 200), 'width' => $width, 'height' => $height,
            'create_time' => date('Y-m-d H:i:s')]);
        return ['id' => 'media-' . $id, 'name' => $name, 'src' => $url, 'width' => $width, 'height' => $height];
    }

    public function useAsset(int $userId, int $projectId, int $assetId, int $imageId): array
    {
        $this->owned($userId, $projectId);
        $asset = Asset::where('id', $assetId)->where('user_id', $userId)->find();
        if (!$asset) abort(404, '资产不存在或不可访问');
        $image = $asset->images()->where('id', $imageId)->where('url', '<>', '')->find();
        if (!$image) abort(404, '资产图片不存在');
        return $this->registerMedia($userId, $projectId, (string) $image->url, (string) $asset->name);
    }

    public function scenePresets(): array
    {
        $catalog = self::decode(file_get_contents(dirname(__DIR__) . '/resources/ecommerce_scenes.json'));
        $available = [];
        foreach ($catalog as $scene) {
            $file = $this->presetFile('/admin/ecommerce-box/scenes/' . $scene['id'] . '.jpg');
            $info = $file ? @getimagesize($file) : false;
            if (!$info || min($info[0], $info[1]) < 300 || max($info[0], $info[1]) > 6000) continue;
            $available[] = $scene + ['src' => '/admin/ecommerce-box/scenes/' . $scene['id'] . '.jpg', 'width' => $info[0], 'height' => $info[1]];
        }
        return $available;
    }

    private function presetFile(string $src): ?string
    {
        if (!preg_match('#^/admin/ecommerce-box/(?:styles|scenes)/[a-z-]+\.jpg$#', $src)) return null;
        $root = dirname(__DIR__, 2);
        foreach ([$root . '/public' . $src, $root . '/frontend/public' . substr($src, strlen('/admin'))] as $file) {
            if (is_file($file)) return $file;
        }
        return null;
    }

    private function source(int $userId, string $src): string
    {
        if ($this->presetFile($src) !== null) return $src;
        $row = Db::name('ecommerce_media')->where('user_id', $userId)->where('url', $src)->find();
        if ($row) return (string) $row['url'];
        $path = (string) parse_url($src, PHP_URL_PATH);
        if (preg_match('#^https?://#i', $src) && str_starts_with($path, '/storage/')) {
            // The frontend expands local URLs with its own origin. Resolve back to a registered local file.
            $row = Db::name('ecommerce_media')->where('user_id', $userId)->where('url', $path)->find();
            if ($row) return (string) $row['url'];
        }
        abort(422, '参考图片不属于当前用户或尚未上传，请重新添加素材');
    }

    private function validateState(int $userId, int $projectId, array $state, bool $import, array &$map): array
    {
        if (($state['schema'] ?? null) !== 1) abort(422, '不支持的电商项目版本');
        if (strlen(self::encode($state)) > ($import ? 80 : 10) * 1024 * 1024) abort(422, '工作区文件过大');
        foreach (['model', 'product', 'scene', 'creative'] as $kind) {
            if (!is_array($state[$kind] ?? null)) abort(422, '工作区缺少必要配置：' . $kind);
            foreach (['uploads' => 12, 'results' => 100] as $field => $max) {
                if (count((array) ($state[$kind][$field] ?? [])) > $max) abort(422, '工作区素材或结果数量超限');
            }
        }
        $state['name'] = EcommercePolicy::text($state['name'] ?? '', 160, true);
        if ($import) {
            // Old provider tasks cannot authorize paid requests or import their remote result URLs.
            $state['creative']['video']['tasks'] = [];
            $state['creative']['video']['activeTaskId'] = '';
            $state['creative']['campaign']['activeRun'] = null;
        }
        $nodes = 0;
        $walk = function ($value, string $key = '', int $depth = 0) use (&$walk, &$map, &$nodes, $userId, $projectId, $import) {
            if (++$nodes > 25000 || $depth > 28) abort(422, '工作区结构过于复杂');
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    if (in_array((string) $k, ['__proto__', 'constructor', 'prototype', 'avatar', 'recoveries'], true)) continue;
                    $out[$k] = $walk($v, is_int($k) ? $key : (string) $k, $depth + 1);
                }
                return $out;
            }
            if (!is_string($value)) return $value;
            $isMedia = in_array($key, ['src', 'url', 'annotatedSrc', 'images', 'references', 'productImages', 'styleReference'], true);
            if (!$isMedia || $value === '') return $value;
            if ($import) {
                if (isset($map[$value])) return $map[$value];
                if (preg_match('#^data:image/(?:png|jpeg|webp);base64,(.+)$#s', $value, $m)) {
                    if (count($map) >= 450) abort(422, '导入图片数量超过上限');
                    $binary = base64_decode($m[1], true);
                    if ($binary === false) abort(422, '导入图片数据损坏');
                    $media = $this->storeImage($userId, $projectId, $binary, '导入图片');
                    return $map[$value] = $media['src'];
                }
                if (preg_match('#^/scene-previews/([a-z-]+)\.jpg$#', $value, $m)) {
                    $value = '/admin/ecommerce-box/scenes/' . $m[1] . '.jpg';
                } elseif (str_starts_with($value, '/examples/styles/')) {
                    $value = str_replace('/examples/styles/', '/admin/ecommerce-box/styles/', $value);
                }
            }
            return $this->source($userId, $value);
        };
        return $walk($state);
    }

    public function model(int $userId, string $mode, int $id): ModelConfig
    {
        $model = ModelConfigResolver::resolve($mode, $userId, $id);
        if (!$model || $id <= 0 || (int) $model->id !== $id) abort(422, '模型不存在、已停用或不可访问');
        return $model;
    }

    public function plan(int $userId, int $projectId, array $items): array
    {
        $this->owned($userId, $projectId);
        if (!array_is_list($items) || count($items) < 1 || count($items) > 14) abort(422, '每次可生成 1 至 14 个输出');
        $hasTemplate = array_filter($items, static function ($item): bool {
            if (!is_array($item)) return false;
            $request = $item['request'] ?? null;
            if (!is_array($request)) return false;
            $original = $request['original'] ?? null;
            if (!is_array($original)) return false;
            $design = $original['design'] ?? null;
            return is_array($design) && ($design['layout'] ?? '') === 'template';
        });
        if (count($items) > 1 && $hasTemplate) {
            abort(422, '商品详情模板一次只生成一张完整图片');
        }
        $jobs = [];
        $total = 0.0;
        $estimated = false;
        foreach ($items as $item) {
            $mode = (string) ($item['mode'] ?? 'image');
            $kind = (string) ($item['kind'] ?? 'creative');
            if (!in_array($mode, ['image', 'video'], true) || !in_array($kind, ['model', 'product', 'scene', 'creative'], true)) abort(422, '生成类型无效');
            $model = $this->model($userId, $mode, (int) ($item['model_config_id'] ?? 0));
            $request = EcommercePolicy::request((array) ($item['request'] ?? []), $mode);
            $request['images'] = array_map(fn (string $src): string => $this->source($userId, $src), $request['images']);
            // Validate nested edit/campaign snapshots as well; no unowned source may re-enter a future edit.
            $nested = $request['original'];
            $this->validateSnapshotSources($userId, $nested);
            $request = EcommerceSkills::apply($request, $mode, $kind, (string) ($item['page_key'] ?? ''));
            $modelId = (string) $model->model_id;
            if ($mode === 'image') {
                $options = EcommercePolicy::imageOptions($request['options'], $modelId);
                $credits = CreditService::quoteImage($options['quality'], $modelId, count($request['images']),
                    $options + ['estimated_text_tokens' => max(1, (int) ceil(mb_strlen($request['prompt']) / 2))]);
                $estimated = $estimated || ImageGenerationOptions::isOfficial25($modelId);
                $request['options'] = $options;
            } else {
                $credits = CreditService::quoteVideo((int) $request['options']['duration'], $request['options']['resolution'], $modelId);
            }
            $request['model_id'] = $modelId;
            $request['model_fingerprint'] = hash('sha256', $modelId . "\0" . (string) $model->endpoint);
            $jobs[] = ['mode' => $mode, 'kind' => $kind, 'model_config_id' => (int) $model->id,
                'page_key' => EcommercePolicy::text((string) ($item['page_key'] ?? ''), 80),
                'title' => EcommercePolicy::text((string) ($item['title'] ?? '电商图片'), 200, true), 'request' => $request];
            $total += $credits;
        }
        return ['credits' => round($total, 4), 'estimated' => $estimated, 'count' => count($jobs), 'items' => $jobs];
    }

    private function validateSnapshotSources(int $userId, array $data, string $key = '', int $depth = 0): void
    {
        if ($depth > 24) abort(422, '请求快照层级过多');
        foreach ($data as $k => $value) {
            $field = is_int($k) ? $key : (string) $k;
            if (is_array($value)) $this->validateSnapshotSources($userId, $value, $field, $depth + 1);
            elseif (is_string($value) && $value !== '' && in_array($field, ['src', 'url', 'images', 'references', 'productImages', 'styleReference', 'annotatedSrc'], true)) $this->source($userId, $value);
        }
    }

    public function generate(int $userId, int $projectId, string $requestKey, array $items): array
    {
        self::ensureSchema();
        if (!preg_match('/^[A-Za-z0-9_-]{12,80}$/', $requestKey)) abort(422, '生成请求编号无效');
        $existing = Db::name('ecommerce_runs')->where('user_id', $userId)->where('request_key', $requestKey)->find();
        if ($existing) {
            if ((int) $existing['project_id'] !== $projectId) abort(409, '生成请求编号已使用');
            return $this->detail($userId, $projectId);
        }
        $plan = $this->plan($userId, $projectId, $items);
        CreditService::assertAffordable($userId, $plan['credits'], '生成电商素材');
        Db::transaction(function () use ($userId, $projectId, $requestKey, $plan): void {
            $this->owned($userId, $projectId, true);
            if (Db::name('ecommerce_runs')->where('user_id', $userId)->where('request_key', $requestKey)->find()) return;
            foreach ($plan['items'] as $item) if ($item['mode'] === 'video') (new EcommerceBriefService())->assertApproved($userId, $projectId, $item['request']);
            if (Db::name('ecommerce_jobs')->where('user_id', $userId)->whereIn('status', ['queued', 'running'])->count() + count($plan['items']) > 60) {
                abort(422, '待处理任务过多，请等待现有任务完成');
            }
            $now = date('Y-m-d H:i:s');
            $runId = (int) Db::name('ecommerce_runs')->insertGetId(['user_id' => $userId, 'project_id' => $projectId,
                'request_key' => $requestKey, 'snapshot_json' => self::encode($plan), 'paused' => 0, 'create_time' => $now]);
            foreach ($plan['items'] as $item) {
                $request = $item['request'];
                unset($item['request']);
                Db::name('ecommerce_jobs')->insert($item + ['run_id' => $runId, 'user_id' => $userId, 'project_id' => $projectId,
                    'request_json' => self::encode($request), 'status' => 'queued', 'create_time' => $now, 'update_time' => $now]);
            }
        });
        return $this->detail($userId, $projectId);
    }

    public function control(int $userId, int $runId, string $action): array
    {
        self::ensureSchema();
        if (!in_array($action, ['pause', 'resume', 'cancel'], true)) abort(422, '任务操作无效');
        $projectId = Db::transaction(function () use ($userId, $runId, $action): int {
            $run = Db::name('ecommerce_runs')->where('id', $runId)->where('user_id', $userId)->lock(true)->find();
            if (!$run) abort(404, '生成计划不存在');
            Db::name('ecommerce_runs')->where('id', $runId)->update(['paused' => $action === 'resume' ? 0 : 1]);
            if ($action === 'cancel') Db::name('ecommerce_jobs')->where('run_id', $runId)->where('status', 'queued')
                ->where('provider_task_id', '')->update(['status' => 'cancelled', 'update_time' => date('Y-m-d H:i:s')]);
            return (int) $run['project_id'];
        });
        return $this->detail($userId, $projectId);
    }

    public function checkOriginal(int $userId, int $jobId): array
    {
        $job = Db::name('ecommerce_jobs')->where('id', $jobId)->where('user_id', $userId)->find();
        if (!$job) abort(404, '任务不存在');
        if ((string) $job['result_url'] !== '' && in_array($job['status'], ['unknown', 'failed', 'success'], true)) {
            $this->registerMedia($userId, (int) $job['project_id'], $job['result_url'], $job['title']);
            Db::name('ecommerce_jobs')->where('id', $jobId)->whereIn('status', ['unknown', 'failed'])->update([
                'status' => 'success', 'error_message' => '', 'update_time' => date('Y-m-d H:i:s')]);
            return $this->detail($userId, (int) $job['project_id']);
        }
        if ($job['provider_task_id'] === '') abort(409, '未收到上游任务编号，无法核对；请先在 AI 日志中确认本次请求');
        Db::name('ecommerce_jobs')->where('id', $jobId)->whereIn('status', ['unknown', 'failed'])
            ->update(['status' => 'queued', 'error_message' => '', 'update_time' => date('Y-m-d H:i:s')]);
        return $this->detail($userId, (int) $job['project_id']);
    }

    public function saveAsset(int $userId, int $jobId, string $imageUrl = ''): array
    {
        return Db::transaction(function () use ($userId, $jobId, $imageUrl): array {
            $job = Db::name('ecommerce_jobs')->where('id', $jobId)->where('user_id', $userId)->lock(true)->find();
            if (!$job || $job['status'] !== 'success' || $job['mode'] !== 'image') abort(422, '请选择已完成的图片');
            $url = $job['result_url'];
            if ($imageUrl !== '') {
                $media = Db::name('ecommerce_media')->where('user_id', $userId)->where('project_id', $job['project_id'])->where('url', $imageUrl)->find();
                if (!$media) abort(422, '排版成品不属于当前商品，请重新上传');
                $url = $media['url'];
            }
            if ((int) $job['asset_id'] > 0 && AssetImage::where('asset_id', $job['asset_id'])->where('user_id', $userId)->where('url', $url)->find()) return ['asset_id' => (int) $job['asset_id']];
            $request = self::decode($job['request_json']);
            $asset = Asset::create(['user_id' => $userId, 'series_id' => 0, 'type' => $job['kind'] === 'model' ? 'character' : ($job['kind'] === 'scene' ? 'scene' : 'prop'),
                'name' => mb_substr($job['title'], 0, 100), 'description' => '电商宝盒', 'image_prompt' => $request['prompt'], 'tags' => ['电商宝盒']]);
            AssetImage::create(['user_id' => $userId, 'asset_id' => $asset->id, 'view_type' => 'reference', 'url' => $url,
                'note' => $job['title'], 'image_prompt' => $request['prompt'], 'sort' => 0]);
            Db::name('ecommerce_jobs')->where('id', $jobId)->update(['asset_id' => $asset->id]);
            RedisCache::bumpVersion('assets');
            return ['asset_id' => (int) $asset->id];
        });
    }

    public static function rememberTask(int $jobId, string $taskId, string $endpoint = ''): void
    {
        if ($jobId <= 0) return;
        Db::name('ecommerce_jobs')->where('id', $jobId)->where('status', 'running')->update([
            'provider_task_id' => $taskId, 'result_endpoint' => $endpoint, 'update_time' => date('Y-m-d H:i:s')]);
    }

    public static function rememberResult(int $jobId, string $url, int $logId): void
    {
        if ($jobId <= 0 || $url === '') return;
        Db::name('ecommerce_jobs')->where('id', $jobId)->where('status', 'running')->update([
            'result_url' => $url, 'ai_request_log_id' => $logId ?: null, 'update_time' => date('Y-m-d H:i:s')]);
    }

    public function providerImages(array $job, array $request): array
    {
        $urls = [];
        foreach ($request['images'] as $src) {
            $src = $this->source((int) $job['user_id'], $src);
            if (str_starts_with($src, '/admin/ecommerce-box/')) {
                $preset = $this->presetFile($src);
                if ($preset === null) abort(422, '内置参考图不存在，请重新选择场景');
                $binary = file_get_contents($preset);
                if ($binary === false || $binary === '') abort(422, '内置参考图读取失败，请重新选择场景');
                $info = @getimagesizefromstring($binary);
                if (!$info || min($info[0], $info[1]) < 300 || max($info[0], $info[1]) > 6000) {
                    abort(422, '内置参考图尺寸不合格，请更换场景或上传自己的图片');
                }
                $media = $this->storeImage((int) $job['user_id'], (int) $job['project_id'], $binary, basename($src));
                $src = $media['src'];
            }
            $urls[] = $src;
        }
        return $urls;
    }

}
