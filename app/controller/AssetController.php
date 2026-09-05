<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Asset;
use app\model\AssetImage;
use app\model\AssetImageJob;
use app\model\AssetImageVersion;
use app\model\AssetShare;
use app\model\ModelConfig;
use app\model\AiRequestLog;
use app\model\Series;
use app\model\User;
use app\model\VoiceAsset;
use app\support\AssetVisibility;
use app\support\ImageGenerationOptions;
use app\support\ImageJobStatus;
use app\support\ImageProviderTaskState;
use app\support\AssetLookService;
use app\support\ToapisPrivateAvatarService;
use app\support\CharacterAssetClassifier;
use app\support\ModelConfigResolver;
use app\support\MediaStorage;
use app\support\PendingImageTaskException;
use app\support\ProviderJsonResponse;
use app\support\RedisCache;
use app\support\TencentVodAigcImageClient;
use app\support\WorkflowRuntime;
use app\support\VoiceAssetService;
use app\support\WorkerActions;
use think\facade\Db;
use think\facade\Log;

class AssetController extends BaseController
{
    private int $runtimeUserId = 0;
    private int $lastAiRequestLogId = 0;
    private ?AssetLookService $assetLookService = null;

    /**
     * 供 CLI worker（如灵感速创）复用生成链路时设置用户隔离上下文。
     */
    public function setRuntimeUserId(int $userId): void
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
    }

    /**
     * 读取上次 AI 请求日志 id，供外部调用方关联任务记录。
     */
    public function lastAiRequestLogId(): int
    {
        return $this->lastAiRequestLogId;
    }

    protected function initialize()
    {
        if (str_starts_with(trim((string) $this->request->pathinfo(), '/'), 'api/agent/')) {
            return;
        }

        parent::initialize();
    }

    /**
     * 人物核心视图（view_type=main）给用户看：完整人头、正常五官，不做过人脸。
     */
    private const CHARACTER_CORE_VIEW_STYLE = '多视图角色设定参考：正面全身、侧面全身、背面全身 + 面部特写，纯白色背景，专业摄影棚灯光，电影感打光，超现实主义，2K高清，极致细节，摄影作品风格';
    private const CHARACTER_REFERENCE_CLOTHING_RULE = '角色资产硬性规则：无手持物、无剧情道具、无武器、无包、无手机、无工具；双手自然放松且可见。统一穿素色、中性、完整覆盖身体的长袖长裤连体服，搭配简洁平底鞋；保持体型轮廓、五官、脸型、发型、年龄感和气质稳定。';

    /**
     * 人物造型展示图：同一张图左右分栏。
     * 左栏无头三视看服装结构，右栏带头特写看脸和发型。写实造型另任务上传虚拟人像库。
     */
    private const CHARACTER_LOOK_BOARD_LAYOUT = '构图要求：单张完整图片，左右两栏同一角色、同一套服装、同一棚拍白底。'
        . '左栏：正、侧、背三个无头全身，头从衣领处切除，只保留衣领以下的服装与体型，方便看款式、颜色、配饰和剪裁；三个身体必须同一人物、同一套衣服。'
        . '右栏：同一人物放大特写，头部五官完整清晰，可含半身/胸像，用于锁定脸型、五官、发型和年龄感。'
        . '禁止红笔、红线、红网格涂脸；禁止把左栏画成带头全身；禁止右栏无头；禁止服装平铺、衣架、第二个人和剧情场景。';

    /**
     * 仅用于 view_type=main 且 asset_type=scene 时，拼在场景描述之后的多视角参考板要求。
     */
    private const SCENE_CORE_VIEW_STYLE = '多视图场景参考板：同一场景空间的主视图 establishing view、反向广角 reverse angle、侧向广角 side angle、斜向广角 diagonal wide angle，合并在一张完整图片中；无人空场景，空间结构、家具陈设、材质色温与光线方向一致';

    /** 场景生图：禁止人物与清晰人脸（含海报/屏幕/反射），避免后续视频参考失败。 */
    private const SCENE_NO_PEOPLE_FACE_RULE = '无人空场景硬性禁止：不要任何人物、背影、剪影、手、脚、肢体、人形模特、雕像人像；不要清晰人脸或五官；不要海报、照片墙、电视、手机、镜子、玻璃或水面反射中的人脸与人体。English: empty location only, no people, no faces, no portraits, no mannequins, no human figures in posters/screens/reflections.';

    /** 道具生图：禁止手、人脸与包装/屏幕上的人像。 */
    private const PROP_NO_PEOPLE_FACE_RULE = '道具硬性禁止：不要人物、手、肢体、人脸、五官、肖像；不要包装、屏幕、标签、浮雕上的人像。English: isolated prop only, no people, no hands, no faces, no portraits on packaging or screens.';

    /**
     * 查询资产列表。
     * 支持按 series_id、type、keyword 过滤；返回资产及其关联图片，供资产管理页面展示。
     */
    public function index()
    {
        $payload = $this->payload();
        $this->ensureAssetLookState((int) ($payload['series_id'] ?? 0));

        return successCode($this->doList($payload));
    }

    /**
     * 新建资产。
     * 从请求体读取所属剧本、资产类型、名称、描述、生图提示词、标签和图片列表；写入 assets 与 asset_images。
     */
    public function save()
    {
        $payload = $this->payload();
        $this->ensureAssetLookState((int) ($payload['series_id'] ?? 0), false);

        return successCode($this->doCreate($payload), 'success', 201);
    }

    /**
     * 更新资产。
     * 从 JSON 请求体读取 id 后局部更新基础字段；如果传入 images，则整体替换该资产的图片列表。
     */
    public function update()
    {
        $payload = $this->payload();
        $id = $this->requireId($payload, '资产 id 不能为空');
        $asset = $this->findOrFail($id);
        $this->ensureAssetLookState((int) $asset->getAttr('series_id'));

        return successCode($this->doUpdate($id, $payload));
    }

    /**
     * 删除资产。
     * 从 JSON 请求体读取 id 后删除资产，同时删除 asset_images 中的关联图片记录。
     */
    public function delete()
    {
        $payload = $this->payload();
        $id = $this->requireId($payload, '资产 id 不能为空');
        $asset = $this->findOrFail($id);
        $this->ensureAssetLookState((int) $asset->getAttr('series_id'));
        $this->doDelete($id);

        return successCode();
    }

    /**
     * 列出可复用到当前作品的来源作品与资产摘要。
     */
    public function listForReuse()
    {
        $payload = $this->payload();
        $targetSeriesId = (int) ($payload['target_series_id'] ?? 0);
        if ($targetSeriesId <= 0) {
            abort(422, '目标作品 id 不能为空');
        }
        $this->assertSeriesOwned($targetSeriesId);
        $this->assetLookService()->ensureSchema();
        (new VoiceAssetService())->ensureSchema();

        $sourceSeriesId = (int) ($payload['source_series_id'] ?? 0);
        $type = trim((string) ($payload['type'] ?? ''));
        $keyword = trim((string) ($payload['keyword'] ?? ''));

        $seriesRows = Series::where('user_id', $this->effectiveUserId())
            ->where('id', '<>', $targetSeriesId)
            ->order(['id' => 'desc'])
            ->field(['id', 'title'])
            ->select();
        $seriesList = [];
        foreach ($seriesRows as $row) {
            if (!$row instanceof Series) {
                continue;
            }
            $seriesList[] = [
                'id' => (int) $row->getAttr('id'),
                'title' => (string) $row->getAttr('title'),
            ];
        }

        $assets = [];
        if ($sourceSeriesId > 0) {
            $this->assertSeriesOwned($sourceSeriesId);
            $query = Asset::with(['images'])
                ->where('user_id', $this->effectiveUserId())
                ->where('series_id', $sourceSeriesId)
                ->where('is_hidden', 0);
            if (in_array($type, ['character', 'scene', 'prop'], true)) {
                $query->where('type', $type);
            }
            if ($keyword !== '') {
                $query->whereLike('name', '%' . addcslashes($keyword, '%_\\') . '%');
            }
            $rows = $query->order(['type' => 'asc', 'sort' => 'asc', 'id' => 'asc'])->select();
            $characterIds = [];
            foreach ($rows as $row) {
                if ($row instanceof Asset && (string) $row->getAttr('type') === 'character') {
                    $characterIds[] = (int) $row->getAttr('id');
                }
            }
            $voiceAssetIds = [];
            if ($characterIds !== []) {
                $voiceRows = VoiceAsset::where('user_id', $this->effectiveUserId())
                    ->whereIn('asset_id', $characterIds)
                    ->where('status', 'ready')
                    ->whereNull('deleted_at')
                    ->field(['asset_id'])
                    ->select();
                foreach ($voiceRows as $voiceRow) {
                    if ($voiceRow instanceof VoiceAsset) {
                        $voiceAssetIds[(int) $voiceRow->getAttr('asset_id')] = true;
                    }
                }
            }

            foreach ($rows as $row) {
                if (!$row instanceof Asset) {
                    continue;
                }
                $lookCount = 0;
                $coverUrl = '';
                foreach ($row->images as $img) {
                    if (!$img instanceof AssetImage) {
                        continue;
                    }
                    $role = $this->assetLookService()->normalizeReferenceRole((string) ($img->getAttr('reference_role') ?? 'view'));
                    if ($role === 'look' || (string) $img->getAttr('view_type') === 'look') {
                        $lookCount++;
                    }
                    $url = trim((string) $img->getAttr('url'));
                    if ($coverUrl === '' && $url !== '' && $this->isUsableAssetImageUrl($url)) {
                        if ((string) $img->getAttr('view_type') === 'main' || $role === 'view') {
                            $coverUrl = $url;
                        }
                    }
                }
                if ($coverUrl === '') {
                    foreach ($row->images as $img) {
                        if (!$img instanceof AssetImage) {
                            continue;
                        }
                        $url = trim((string) $img->getAttr('url'));
                        if ($url !== '' && $this->isUsableAssetImageUrl($url)) {
                            $coverUrl = $url;
                            break;
                        }
                    }
                }
                $assetId = (int) $row->getAttr('id');
                $assets[] = [
                    'id' => $assetId,
                    'series_id' => (int) $row->getAttr('series_id'),
                    'type' => (string) $row->getAttr('type'),
                    'name' => (string) $row->getAttr('name'),
                    'description' => mb_substr((string) ($row->getAttr('description') ?? ''), 0, 200),
                    'cover_url' => $coverUrl,
                    'look_count' => $lookCount,
                    'has_voice' => isset($voiceAssetIds[$assetId]),
                ];
            }
        }

        return successCode([
            'target_series_id' => $targetSeriesId,
            'series' => $seriesList,
            'assets' => $assets,
        ]);
    }

    /**
     * 从其他作品复制单个资产到当前作品（独立副本，非共用）。
     * 人物默认一并复制造型与音色；写实 ToAPIs 绑定不复制。
     */
    public function copyFrom()
    {
        $payload = $this->payload();
        $sourceAssetId = (int) ($payload['source_asset_id'] ?? 0);
        $targetSeriesId = (int) ($payload['target_series_id'] ?? 0);
        if ($sourceAssetId <= 0) {
            abort(422, '源资产 id 不能为空');
        }
        if ($targetSeriesId <= 0) {
            abort(422, '目标作品 id 不能为空');
        }

        $this->assertSeriesOwned($targetSeriesId);
        $this->assertSeriesWorkflowEditable($targetSeriesId);
        $this->assetLookService()->ensureSchema();
        (new VoiceAssetService())->ensureSchema();

        $source = Asset::with(['images'])
            ->where('id', $sourceAssetId)
            ->where('user_id', $this->effectiveUserId())
            ->where('is_hidden', 0)
            ->find();
        if (!$source instanceof Asset) {
            abort(404, '源资产不存在');
        }
        if ((int) $source->getAttr('series_id') === $targetSeriesId) {
            abort(422, '不能复制到同一作品，请选择其他作品的资产');
        }

        $type = (string) $source->getAttr('type');
        $copyLooks = !array_key_exists('copy_looks', $payload) || (bool) $payload['copy_looks'];
        $copyVoice = !array_key_exists('copy_voice', $payload) || (bool) $payload['copy_voice'];
        if ($type !== 'character') {
            $copyLooks = false;
            $copyVoice = false;
        }

        $requestedName = trim((string) ($payload['name'] ?? ''));
        $baseName = $requestedName !== '' ? $requestedName : trim((string) $source->getAttr('name'));
        if ($baseName === '') {
            $baseName = '未命名资产';
        }
        $newName = $this->uniqueCopiedAssetName($targetSeriesId, $type, $baseName);

        $result = Db::transaction(function () use ($source, $targetSeriesId, $type, $newName, $copyLooks, $copyVoice): array {
            $tags = $source->getAttr('tags');
            if (!is_array($tags)) {
                $tags = [];
            }

            $model = new Asset();
            $model->save([
                'user_id' => $this->effectiveUserId(),
                'series_id' => $targetSeriesId,
                'type' => $type,
                'name' => $newName,
                'description' => (string) ($source->getAttr('description') ?? ''),
                'image_prompt' => (string) ($source->getAttr('image_prompt') ?? ''),
                'tags' => $tags,
                'sort' => $this->nextSort($targetSeriesId, $type),
                'is_hidden' => 0,
                'toapis_group_id' => '',
            ]);
            $newAssetId = (int) $model->getAttr('id');

            $looksCopied = 0;
            $imageIdMap = [];
            foreach ($source->images as $img) {
                if (!$img instanceof AssetImage) {
                    continue;
                }
                $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($img->getAttr('reference_role') ?? 'view'));
                $viewType = (string) ($img->getAttr('view_type') ?: 'main');
                $isLook = $referenceRole === 'look' || $viewType === 'look';
                if ($isLook && !$copyLooks) {
                    continue;
                }

                $variantName = trim((string) ($img->getAttr('variant_name') ?? ''));
                $referenceKey = trim((string) ($img->getAttr('reference_key') ?? ''));
                if ($isLook) {
                    $referenceRole = 'look';
                    $viewType = 'look';
                    if ($variantName === '') {
                        $variantName = '默认造型';
                    }
                    $referenceKey = $this->assetLookService()->makeReferenceKey($newName, $variantName);
                    $looksCopied++;
                }

                $newImage = AssetImage::create([
                    'user_id' => $this->effectiveUserId(),
                    'asset_id' => $newAssetId,
                    'view_type' => $viewType,
                    'url' => (string) ($img->getAttr('url') ?? ''),
                    'note' => (string) ($img->getAttr('note') ?? ''),
                    'image_prompt' => (string) ($img->getAttr('image_prompt') ?? ''),
                    'sort' => (int) ($img->getAttr('sort') ?? 0),
                    'reference_role' => $referenceRole,
                    'variant_name' => $isLook ? $variantName : '',
                    'reference_key' => $isLook ? $referenceKey : '',
                    'toapis_asset_id' => '',
                    'toapis_asset_url' => '',
                    'toapis_status' => '',
                    'video_ref_url' => '',
                ]);
                $oldImageId = (int) $img->getAttr('id');
                $newImageId = (int) $newImage->getAttr('id');
                $imageIdMap[$oldImageId] = $newImageId;

                $versions = AssetImageVersion::where('asset_image_id', $oldImageId)
                    ->where('user_id', $this->effectiveUserId())
                    ->order(['id' => 'asc'])
                    ->select();
                foreach ($versions as $version) {
                    if (!$version instanceof AssetImageVersion) {
                        continue;
                    }
                    $url = trim((string) $version->getAttr('url'));
                    if ($url === '') {
                        continue;
                    }
                    AssetImageVersion::create([
                        'user_id' => $this->effectiveUserId(),
                        'asset_id' => $newAssetId,
                        'asset_image_id' => $newImageId,
                        'model_config_id' => (int) ($version->getAttr('model_config_id') ?? 0),
                        'view_type' => (string) ($version->getAttr('view_type') ?: $viewType),
                        'url' => $url,
                        'prompt' => (string) ($version->getAttr('prompt') ?? ''),
                        'source' => (string) ($version->getAttr('source') ?? 'manual'),
                        'is_selected' => (bool) $version->getAttr('is_selected') ? 1 : 0,
                        'job_id' => null,
                        'ai_request_log_id' => null,
                        'meta_json' => $version->getAttr('meta_json'),
                    ]);
                }
            }

            $voiceCopied = false;
            if ($copyVoice && $type === 'character') {
                $voice = VoiceAsset::where('asset_id', (int) $source->getAttr('id'))
                    ->where('user_id', $this->effectiveUserId())
                    ->where('status', 'ready')
                    ->whereNull('deleted_at')
                    ->order(['id' => 'desc'])
                    ->find();
                if ($voice instanceof VoiceAsset) {
                    VoiceAsset::create([
                        'user_id' => $this->effectiveUserId(),
                        'asset_id' => $newAssetId,
                        'name' => $newName . '的音色',
                        'source_url' => (string) $voice->getAttr('source_url'),
                        'original_filename' => (string) $voice->getAttr('original_filename'),
                        'mime_type' => (string) $voice->getAttr('mime_type'),
                        'extension' => (string) $voice->getAttr('extension'),
                        'file_size' => (int) $voice->getAttr('file_size'),
                        'duration_ms' => (int) $voice->getAttr('duration_ms'),
                        'sha256' => (string) $voice->getAttr('sha256'),
                        'status' => 'ready',
                        'rights_confirmed' => (int) $voice->getAttr('rights_confirmed'),
                        'provider_meta_json' => $voice->getAttr('provider_meta_json'),
                        'error_message' => '',
                    ]);
                    $voiceCopied = true;
                }
            }

            return [
                'asset_id' => $newAssetId,
                'looks_copied' => $looksCopied,
                'voice_copied' => $voiceCopied,
                'images_copied' => count($imageIdMap),
            ];
        });

        $this->clearAssetCache();
        $fresh = $this->findOrFail((int) $result['asset_id']);
        $serialized = $this->serialize($fresh);
        $this->writeAdminOperationLog([
            'action' => 'asset.copy_from',
            'target_type' => 'asset',
            'target_id' => (int) $serialized['id'],
            'target_name_snapshot' => (string) $serialized['name'],
            'series_id' => $targetSeriesId,
            'result' => 'success',
            'before_json' => [],
            'after_json' => $serialized,
            'meta_json' => [
                'route' => 'assets.copy-from',
                'source_asset_id' => $sourceAssetId,
                'source_series_id' => (int) $source->getAttr('series_id'),
                'looks_copied' => (int) $result['looks_copied'],
                'voice_copied' => (bool) $result['voice_copied'],
            ],
        ]);

        return successCode([
            'asset' => $serialized,
            'copied' => [
                'images' => (int) $result['images_copied'],
                'looks' => (int) $result['looks_copied'],
                'voice' => (bool) $result['voice_copied'],
            ],
        ], 'success', 201);
    }

    /**
     * 生成资产图片。
     * 调用 AI 图片模型生成图片，支持基于主图生成其他视图。
     */
    public function generateImage()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $prompt = trim((string) ($payload['image_prompt'] ?? $payload['prompt'] ?? ''));
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $count = 1;
        $assetImageId = (int) ($payload['asset_image_id'] ?? 0);
        $viewType = trim((string) ($payload['view_type'] ?? 'main'));
        $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($payload['reference_role'] ?? ($viewType === 'look' ? 'look' : 'view')));
        $variantName = $this->assetLookService()->normalizeVariantName((string) ($payload['variant_name'] ?? ''));
        $referenceKey = trim((string) ($payload['reference_key'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $mainImageUrl = trim((string) ($payload['main_image_url'] ?? ''));
        $referenceImageUrl = trim((string) ($payload['reference_image_url'] ?? ''));
        if ($referenceImageUrl === '') {
            $referenceImageUrl = $mainImageUrl;
        }
        $modelConfigId = (int) ($payload['model_config_id'] ?? 0);
        $assetType = trim((string) ($payload['asset_type'] ?? ''));
        $asset = null;
        $targetImage = null;

        if ($assetId > 0) {
            $asset = Asset::where('id', $assetId)->where('user_id', $this->effectiveUserId())->find();
            if ($asset instanceof Asset && $assetType === '') {
                $assetType = (string) $asset->type;
            }
        }
        $lockSeriesId = $asset instanceof Asset
            ? (int) $asset->getAttr('series_id')
            : (int) ($payload['series_id'] ?? 0);
        if ($lockSeriesId > 0) {
            $this->assertSeriesWorkflowEditable($lockSeriesId);
        }

        if ($assetId > 0 && $assetImageId > 0) {
            $targetImage = AssetImage::where('id', $assetImageId)
                ->where('asset_id', $assetId)
                ->where('user_id', $this->effectiveUserId())
                ->find();
            if (!$targetImage instanceof AssetImage) {
                abort(404, '目标图片记录不存在');
            }
            $viewType = (string) ($targetImage->getAttr('view_type') ?: $viewType);
        }

        if (!$targetImage instanceof AssetImage && $asset instanceof Asset && $viewType === 'look') {
            $lookup = AssetImage::where('asset_id', $assetId)
                ->where('user_id', $this->effectiveUserId())
                ->where('reference_role', 'look');
            if ($referenceKey !== '') {
                $lookup->where('reference_key', $referenceKey);
            } elseif ($variantName !== '') {
                $lookup->where('variant_name', $variantName);
            } else {
                $lookup->where('view_type', 'look');
            }
            $targetImage = $lookup->order(['sort' => 'asc', 'id' => 'asc'])->find();

            if (!$targetImage instanceof AssetImage) {
                if ($referenceKey === '') {
                    $characterName = trim((string) $asset->getAttr('name'));
                    $referenceKey = $this->assetLookService()->makeReferenceKey($characterName, $variantName !== '' ? $variantName : '默认造型');
                }
                $targetImage = AssetImage::create([
                    'user_id' => $this->effectiveUserId(),
                    'asset_id' => $assetId,
                    'view_type' => 'look',
                    'url' => '',
                    'note' => $note !== '' ? $note : '人物造型',
                    'image_prompt' => $prompt,
                    'sort' => $this->defaultImageSort('look'),
                    'reference_role' => $referenceRole,
                    'variant_name' => $variantName,
                    'reference_key' => $referenceKey,
                ]);
            } else {
                $update = [];
                if ($variantName !== '' && trim((string) $targetImage->getAttr('variant_name')) === '') {
                    $update['variant_name'] = $variantName;
                }
                if ($referenceKey !== '' && trim((string) $targetImage->getAttr('reference_key')) === '') {
                    $update['reference_key'] = $referenceKey;
                }
                if ($note !== '' && trim((string) $targetImage->getAttr('note')) === '') {
                    $update['note'] = $note;
                }
                if ($update !== []) {
                    $targetImage->save($update);
                }
            }
            $assetImageId = (int) $targetImage->getAttr('id');
        }

        if ($prompt === '' && $asset instanceof Asset) {
            if (!$targetImage instanceof AssetImage) {
                $targetImage = AssetImage::where('asset_id', $assetId)
                    ->where('view_type', $viewType)
                    ->order(['sort' => 'asc', 'id' => 'asc'])
                    ->find();
            }
            if ($targetImage instanceof AssetImage) {
                $prompt = trim((string) $targetImage->getAttr('image_prompt'));
            }
        }

        // 如果当前图片没输入提示词，兼容旧数据：尝试从资产级提示词或资产描述生成
        if ($prompt === '' && $asset instanceof Asset) {
            $prompt = $this->buildAssetImagePrompt($asset->toArray(), $asset->type, $asset->description);
        }

        if ($prompt === '') {
            $prompt = trim((string) ($payload['description'] ?? ''));
        }

        if ($prompt === '') {
            abort(422, '提示词或资产描述不能为空');
        }

        // 获取视觉风格
        $visualStyle = 'realistic';
        $visualStyleVariant = '';
        $region = 'china';
        if ($asset instanceof Asset) {
            $series = $asset->series;
            if ($series instanceof Series) {
                $visualStyle = $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'));
                $visualStyleVariant = $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? ''));
                $region = $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china'));
            }
        } elseif (isset($payload['series_id'])) {
            $series = Series::where('id', (int) $payload['series_id'])->where('user_id', $this->effectiveUserId())->find();
            if ($series instanceof Series) {
                $visualStyle = $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'));
                $visualStyleVariant = $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? ''));
                $region = $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china'));
            }
        }

        // 针对不同视图优化提示词
        $prompt = $this->ensureUtf8($prompt);
        $finalPrompt = $this->ensureUtf8(
            $this->refinePromptForView($prompt, $viewType, $referenceImageUrl, $assetType, $visualStyle, $region, $visualStyleVariant, $asset)
        );

        $model = $this->resolveImageModel($modelConfigId);
        if (!$model) {
            abort(422, '未配置图片模型，请联系管理员配置');
        }

        $description = $this->markImageJobDescriptionForAutoSelect(trim((string) ($payload['description'] ?? '')));
        $pendingJobs = [];
        if ($assetId > 0) {
            $existingJobQuery = AssetImageJob::where('asset_id', $assetId)
                ->where('user_id', $this->effectiveUserId())
                ->whereIn('status', ImageJobStatus::active());
            if ($targetImage instanceof AssetImage) {
                $existingJobQuery->where('asset_image_id', (int) $targetImage->getAttr('id'));
            } else {
                $existingJobQuery->where('view_type', $viewType);
            }
            $pendingRows = $existingJobQuery->order(['id' => 'asc'])->select();
            foreach ($pendingRows as $pendingRow) {
                if (!$pendingRow instanceof AssetImageJob) {
                    continue;
                }
                // 人像入库/旧彩铅为后台隐藏任务，不占用展示图槽位；holding 残留一律作废。
                if ($this->isHiddenLookBackgroundJob((string) $pendingRow->getAttr('description'))) {
                    $pendingRow->save([
                        'status' => 'cancelled',
                        'error_message' => '造型已重新生成，旧人像入库任务已取消',
                        'finished_at' => date('Y-m-d H:i:s'),
                    ]);
                    continue;
                }
                if ((string) $pendingRow->getAttr('status') === ImageJobStatus::HOLDING) {
                    $pendingRow->save([
                        'status' => 'cancelled',
                        'error_message' => '造型已重新生成，未入库的展示图已作废',
                        'finished_at' => date('Y-m-d H:i:s'),
                    ]);
                    continue;
                }
                if ((string) $pendingRow->getAttr('status') === 'queued') {
                    $pendingRow->save([
                        'model_config_id' => (int) $model->getAttr('id'),
                        'prompt' => $prompt,
                        'final_prompt' => $finalPrompt,
                        'description' => $description,
                        'main_image_url' => $referenceImageUrl,
                        'error_message' => '',
                    ]);
                }
                $pendingJobs[] = $pendingRow;
            }
        }

        $jobs = $pendingJobs;
        $needCreate = max(0, $count - count($pendingJobs));
        for ($i = 0; $i < $needCreate; $i++) {
            $jobs[] = AssetImageJob::create([
                'user_id' => $this->effectiveUserId(),
                'asset_id' => $assetId > 0 ? $assetId : null,
                'asset_image_id' => $targetImage instanceof AssetImage ? (int) $targetImage->getAttr('id') : null,
                'model_config_id' => (int) $model->getAttr('id'),
                'status' => 'queued',
                'prompt' => $prompt,
                'final_prompt' => $finalPrompt,
                'description' => $description,
                'view_type' => $viewType,
                'main_image_url' => $referenceImageUrl,
                'result_url' => '',
                'error_message' => '',
                'attempts' => 0,
            ]);
        }

        // 造型重生成时清空旧人像绑定，避免视频仍使用过期 pa_。展示图等新结果写入后再入库。
        if (
            $jobs !== []
            && $viewType === 'look'
            && $targetImage instanceof AssetImage
            && $this->isCharacterLookImage($targetImage)
        ) {
            $this->clearLookToapisBinding($targetImage);
        }

        $this->clearAssetCache();

        $serializedJobs = array_map(fn (AssetImageJob $job): array => $this->serializeImageJob($job), $jobs);
        $latestJob = end($serializedJobs);
        return successCode([
            'id' => is_array($latestJob) ? (int) ($latestJob['id'] ?? 0) : 0,
            'count' => count($serializedJobs),
            'created' => $needCreate,
            'jobs' => array_values($serializedJobs),
            'job' => is_array($latestJob) ? $latestJob : null,
        ], 'success', 202);
    }

    /**
     * 批量为当前剧本下缺少核心视图的资产创建图片生成任务。
     * 这里只入队，不直接调用图片接口；并发由 asset-image worker 控制。
     */
    public function batchGenerateCoreImages()
    {
        $payload = $this->payload();
        $seriesId = (int) ($payload['series_id'] ?? 0);
        $this->ensureAssetLookState($seriesId);
        if ($seriesId <= 0) {
            abort(422, '剧本 id 不能为空');
        }
        $this->assertSeriesWorkflowEditable($seriesId);

        $model = $this->resolveImageModel((int) ($payload['model_config_id'] ?? 0));
        if (!$model instanceof ModelConfig) {
            abort(422, '未配置图片模型，请联系管理员配置');
        }
        $promptAddition = $this->normalizePromptAddition($payload['prompt_addition'] ?? '');
        $promptAdditionsByType = $this->normalizePromptAdditionsByType($payload['prompt_additions'] ?? []);
        $referenceImageUrl = trim((string) ($payload['reference_image_url'] ?? ''));

        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        $visualStyle = $series instanceof Series
            ? $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'))
            : 'realistic';
        $visualStyleVariant = $series instanceof Series
            ? $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? ''))
            : '';
        $region = $series instanceof Series
            ? $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china'))
            : 'china';

        $assets = Asset::with(['images'])
            ->where('user_id', $this->effectiveUserId())
            ->where('series_id', $seriesId)
            ->where('is_hidden', 0)
            ->order(['type' => 'asc', 'sort' => 'asc', 'id' => 'asc'])
            ->select();

        $created = 0;
        $skippedWithCore = 0;
        $skippedQueued = 0;
        $jobs = [];

        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $assetId = (int) $asset->getAttr('id');
            if ($this->assetHasCoreImage($asset)) {
                $skippedWithCore++;
                continue;
            }

            $existingJob = AssetImageJob::where('asset_id', $assetId)
                ->where('user_id', $this->effectiveUserId())
                ->where('view_type', 'main')
                ->whereIn('status', ImageJobStatus::active())
                ->order('id', 'desc')
                ->find();

            $prompt = $this->buildAssetImagePrompt($asset->toArray(), (string) $asset->getAttr('type'), (string) $asset->getAttr('description'));
            if ($prompt === '') {
                $prompt = trim((string) $asset->getAttr('description'));
            }
            if ($prompt === '') {
                $prompt = trim((string) $asset->getAttr('name'));
            }
            $prompt = $this->ensureUtf8($prompt);
            if ($prompt === '') {
                continue;
            }
            $typePromptAddition = $promptAdditionsByType[(string) $asset->getAttr('type')] ?? $promptAddition;
            $prompt = $this->ensureUtf8($this->appendPromptAddition($prompt, $typePromptAddition));
            $finalPrompt = $this->ensureUtf8(
                $this->refinePromptForView($prompt, 'main', $referenceImageUrl, (string) $asset->getAttr('type'), $visualStyle, $region, $visualStyleVariant, $asset)
            );
            $assetDescription = $this->ensureUtf8((string) $asset->getAttr('description'));

            if ($existingJob instanceof AssetImageJob) {
                if ((string) $existingJob->getAttr('status') === 'queued') {
                    $existingJob->save([
                        'model_config_id' => (int) $model->getAttr('id'),
                        'prompt' => $prompt,
                        'final_prompt' => $finalPrompt,
                        'description' => $assetDescription,
                        'main_image_url' => $referenceImageUrl,
                        'error_message' => '',
                    ]);
                }
                $skippedQueued++;
                $jobs[] = $this->serializeImageJob($existingJob);
                continue;
            }

            $job = AssetImageJob::create([
                'user_id' => $this->effectiveUserId(),
                'asset_id' => $assetId,
                'model_config_id' => (int) $model->getAttr('id'),
                'status' => 'queued',
                'prompt' => $prompt,
                'final_prompt' => $finalPrompt,
                'description' => $assetDescription,
                'view_type' => 'main',
                'main_image_url' => $referenceImageUrl,
                'result_url' => '',
                'error_message' => '',
                'attempts' => 0,
            ]);
            $created++;
            $jobs[] = $this->serializeImageJob($job);
        }

        if ($created > 0) {
            $this->clearAssetCache();
        }

        return successCode([
            'created' => $created,
            'skipped_with_core' => $skippedWithCore,
            'skipped_queued' => $skippedQueued,
            'jobs' => $jobs,
        ], 'success', 202);
    }

    /**
     * Agent 编排器复用的内部入口。
     * 为指定用户的剧本资产补齐 main 核心图，只入队，不阻塞请求。
     */
    public function queueAgentCoreImageJobs(int $userId, int $seriesId): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        $this->ensureAssetLookState($seriesId);
        if ($seriesId <= 0) {
            return ['created' => 0, 'skipped_with_core' => 0, 'skipped_queued' => 0, 'jobs' => []];
        }

        $model = $this->resolveImageModel(0);
        if (!$model instanceof ModelConfig) {
            return ['created' => 0, 'skipped_with_core' => 0, 'skipped_queued' => 0, 'jobs' => []];
        }

        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        $visualStyle = $series instanceof Series
            ? $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'))
            : 'realistic';
        $visualStyleVariant = $series instanceof Series
            ? $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? ''))
            : '';
        $region = $series instanceof Series
            ? $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china'))
            : 'china';

        $assets = Asset::with(['images'])
            ->where('user_id', $this->effectiveUserId())
            ->where('series_id', $seriesId)
            ->where('is_hidden', 0)
            ->order(['type' => 'asc', 'sort' => 'asc', 'id' => 'asc'])
            ->select();
        $orderedAssets = $this->interleaveAssetsByType($assets);

        $created = 0;
        $skippedWithCore = 0;
        $skippedQueued = 0;
        $jobs = [];

        foreach ($orderedAssets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $assetId = (int) $asset->getAttr('id');
            if ($this->assetHasCoreImage($asset)) {
                $skippedWithCore++;
                continue;
            }

            $existingJob = AssetImageJob::where('asset_id', $assetId)
                ->where('user_id', $this->effectiveUserId())
                ->where('view_type', 'main')
                ->whereIn('status', ImageJobStatus::active())
                ->order('id', 'desc')
                ->find();
            if ($existingJob instanceof AssetImageJob) {
                $skippedQueued++;
                $jobs[] = $this->serializeImageJob($existingJob);
                continue;
            }

            $prompt = $this->buildAssetImagePrompt($asset->toArray(), (string) $asset->getAttr('type'), (string) $asset->getAttr('description'));
            if ($prompt === '') {
                $prompt = trim((string) $asset->getAttr('description'));
            }
            if ($prompt === '') {
                $prompt = trim((string) $asset->getAttr('name'));
            }
            if ($prompt === '') {
                continue;
            }

            $finalPrompt = $this->refinePromptForView($prompt, 'main', '', (string) $asset->getAttr('type'), $visualStyle, $region, $visualStyleVariant, $asset);
            $job = AssetImageJob::create([
                'user_id' => $this->effectiveUserId(),
                'asset_id' => $assetId,
                'model_config_id' => (int) $model->getAttr('id'),
                'status' => 'queued',
                'prompt' => $prompt,
                'final_prompt' => $finalPrompt,
                'description' => (string) $asset->getAttr('description'),
                'view_type' => 'main',
                'main_image_url' => '',
                'result_url' => '',
                'error_message' => '',
                'attempts' => 0,
            ]);
            $created++;
            $jobs[] = $this->serializeImageJob($job);
        }

        $this->clearAssetCache();

        return [
            'created' => $created,
            'skipped_with_core' => $skippedWithCore,
            'skipped_queued' => $skippedQueued,
            'jobs' => $jobs,
        ];
    }

    /**
     * 当剧集资产节点整节点重跑时，旧一轮失败/排队的资产参考图任务不应继续参与
     * 当前轮次统计与入队，否则会出现 6+6 叠成 16 的历史脏队列。
     *
     * 这里只清理未成功的历史记录；已成功产出的图片版本由资产本身继续复用。
     */
    public function purgeSeriesObsoleteImageJobs(int $userId, int $seriesId, array $statuses = ['queued', 'failed', 'cancelled']): int
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        if ($seriesId <= 0) {
            return 0;
        }

        $allowedStatuses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $status): string => trim((string) $status),
            $statuses,
        ))));
        if ($allowedStatuses === []) {
            return 0;
        }

        $assetIds = Asset::where('user_id', $this->effectiveUserId())
            ->where('series_id', $seriesId)
            ->column('id');
        $assetIds = array_values(array_unique(array_map('intval', is_array($assetIds) ? $assetIds : [])));
        if ($assetIds === []) {
            return 0;
        }

        $count = (int) AssetImageJob::where('user_id', $this->effectiveUserId())
            ->whereIn('asset_id', $assetIds)
            ->whereIn('status', $allowedStatuses)
            ->delete();

        if ($count > 0) {
            $this->clearAssetCache();
        }

        return $count;
    }

    public function queueAgentLookImageJobs(int $userId, int $seriesId, int $characterAssetId = 0, int $modelConfigId = 0): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        $this->ensureAssetLookState($seriesId);
        $stats = [
            'created' => 0,
            'skipped_with_image' => 0,
            'skipped_without_main' => 0,
            'skipped_queued' => 0,
            'jobs' => [],
        ];
        if ($seriesId <= 0) {
            return $stats;
        }

        $model = $this->resolveImageModel($modelConfigId);
        if (!$model instanceof ModelConfig) {
            return $stats;
        }

        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        $visualStyle = $series instanceof Series
            ? $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'))
            : 'realistic';
        $visualStyleVariant = $series instanceof Series
            ? $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? ''))
            : '';
        $region = $series instanceof Series
            ? $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china'))
            : 'china';

        $query = Asset::with(['images'])
            ->where('user_id', $this->effectiveUserId())
            ->where('series_id', $seriesId)
            ->where('type', 'character')
            ->where('is_hidden', 0)
            ->order(['sort' => 'asc', 'id' => 'asc']);
        if ($characterAssetId > 0) {
            $query->where('id', $characterAssetId);
        }

        foreach ($query->select() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $result = $this->queuePendingLookJobsForCharacterAsset($asset, $model, $visualStyle, $region, $visualStyleVariant);
            $stats['created'] += (int) ($result['created'] ?? 0);
            $stats['skipped_with_image'] += (int) ($result['skipped_with_image'] ?? 0);
            $stats['skipped_without_main'] += (int) ($result['skipped_without_main'] ?? 0);
            $stats['skipped_queued'] += (int) ($result['skipped_queued'] ?? 0);
            if (!empty($result['jobs']) && is_array($result['jobs'])) {
                $stats['jobs'] = array_merge($stats['jobs'], $result['jobs']);
            }
        }

        if ($stats['created'] > 0) {
            $this->clearAssetCache();
        }

        return $stats;
    }

    public function queueAgentRegenerateCoreImageJob(int $userId, int $assetId, string $promptAddition = ''): array
    {
        $this->runtimeUserId = $userId > 0 ? $userId : 1;
        $this->assetLookService()->ensureSchema();
        if ($assetId <= 0) {
            return ['error' => 'asset_id 必填'];
        }

        $asset = Asset::with(['images'])->where('id', $assetId)->where('user_id', $this->effectiveUserId())->where('is_hidden', 0)->find();
        if (!$asset instanceof Asset) {
            return ['error' => '资产不存在'];
        }

        $runningJob = AssetImageJob::where('asset_id', $assetId)
            ->where('user_id', $this->effectiveUserId())
            ->where('view_type', 'main')
            ->whereIn('status', ImageJobStatus::inFlight())
            ->order('id', 'desc')
            ->find();
        if ($runningJob instanceof AssetImageJob) {
            return ['error' => '这个资产的参考图已经在生成中，请等待当前任务完成'];
        }

        $model = $this->resolveImageModel(0);
        if (!$model instanceof ModelConfig) {
            return ['error' => '未配置图片模型，请联系管理员配置'];
        }

        $series = $asset->series;
        $visualStyle = $series instanceof Series
            ? $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'))
            : 'realistic';
        $visualStyleVariant = $series instanceof Series
            ? $this->normalizeVisualStyleVariant($visualStyle, (string) ($series->getAttr('visual_style_variant') ?? ''))
            : '';
        $region = $series instanceof Series
            ? $this->normalizeSeriesRegion((string) ($series->getAttr('region') ?? 'china'))
            : 'china';

        $prompt = $this->buildAssetImagePrompt($asset->toArray(), (string) $asset->getAttr('type'), (string) $asset->getAttr('description'));
        if ($prompt === '') {
            $prompt = trim((string) $asset->getAttr('description'));
        }
        if ($prompt === '') {
            $prompt = trim((string) $asset->getAttr('name'));
        }
        if ($prompt === '') {
            return ['error' => '资产缺少可用描述，无法生成参考图'];
        }
        $prompt = $this->appendPromptAddition($prompt, $this->normalizePromptAddition($promptAddition));
        $finalPrompt = $this->refinePromptForView($prompt, 'main', '', (string) $asset->getAttr('type'), $visualStyle, $region, $visualStyleVariant, $asset);

        $queuedJob = AssetImageJob::where('asset_id', $assetId)
            ->where('user_id', $this->effectiveUserId())
            ->where('view_type', 'main')
            ->where('status', 'queued')
            ->order('id', 'desc')
            ->find();
        if ($queuedJob instanceof AssetImageJob) {
            $queuedJob->save([
                'model_config_id' => (int) $model->getAttr('id'),
                'prompt' => $prompt,
                'final_prompt' => $finalPrompt,
                'description' => '[agent_regenerate_auto_select]',
                'main_image_url' => '',
                'error_message' => '',
            ]);
            $this->clearAssetCache();
            return ['queued' => 0, 'updated_existing_queue' => true, 'job' => $this->serializeImageJob($queuedJob)];
        }

        $job = AssetImageJob::create([
            'user_id' => $this->effectiveUserId(),
            'asset_id' => $assetId,
            'model_config_id' => (int) $model->getAttr('id'),
            'status' => 'queued',
            'prompt' => $prompt,
            'final_prompt' => $finalPrompt,
            'description' => '[agent_regenerate_auto_select]',
            'view_type' => 'main',
            'main_image_url' => '',
            'result_url' => '',
            'error_message' => '',
            'attempts' => 0,
        ]);
        $this->clearAssetCache();

        return ['queued' => 1, 'updated_existing_queue' => false, 'job' => $this->serializeImageJob($job)];
    }

    private function normalizePromptAddition(mixed $value): string
    {
        $addition = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        return mb_substr($addition, 0, 1000);
    }

    private function normalizePromptAdditionsByType(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach (['character', 'scene', 'prop'] as $type) {
            $addition = $this->normalizePromptAddition($value[$type] ?? '');
            if ($addition !== '') {
                $result[$type] = $addition;
            }
        }
        return $result;
    }

    private function appendPromptAddition(string $prompt, string $addition): string
    {
        $prompt = trim($prompt);
        $addition = trim($addition);
        if ($addition === '') {
            return $prompt;
        }
        return $prompt . '。批量补充提示词：' . $addition;
    }

    /**
     * 查询资产图片生成任务状态。
     */
    public function imageJob()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $id = $this->requireId($payload, '图片生成任务 id 不能为空');
        $job = AssetImageJob::where('id', $id)->where('user_id', $this->effectiveUserId())->find();
        if (!$job instanceof AssetImageJob) {
            abort(404, '图片生成任务不存在');
        }

        return successCode($this->serializeImageJob($job));
    }

    /**
     * 取消排队中的资产生图任务（仅 status=queued；running/waiting 不中断）。
     * 可按作品批量取消，或按单个资产 / 指定 job_ids 取消。
     */
    public function cancelImageJobs()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $seriesId = (int) ($payload['series_id'] ?? 0);
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $jobIds = [];
        if (isset($payload['job_ids']) && is_array($payload['job_ids'])) {
            foreach ($payload['job_ids'] as $jobId) {
                $id = (int) $jobId;
                if ($id > 0) {
                    $jobIds[$id] = $id;
                }
            }
            $jobIds = array_values($jobIds);
        }

        if ($assetId > 0) {
            $asset = $this->findOrFail($assetId);
            $seriesId = (int) $asset->getAttr('series_id');
            $this->assertSeriesWorkflowEditable($seriesId);
        } elseif ($seriesId > 0) {
            $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
            if (!$series instanceof Series) {
                abort(404, '作品不存在');
            }
            $this->assertSeriesWorkflowEditable($seriesId);
        } else {
            abort(422, '请指定作品或资产');
        }

        $cancelled = WorkerActions::cancelQueuedImageJobs(
            $this->effectiveUserId(),
            $seriesId,
            0,
            $jobIds,
            $assetId,
            '任务已由用户取消',
        );
        if ($cancelled > 0) {
            $this->clearAssetCache();
        }

        return successCode([
            'cancelled' => $cancelled,
            'series_id' => $seriesId,
            'asset_id' => $assetId,
        ], $cancelled > 0 ? "已取消 {$cancelled} 个排队任务" : '没有可取消的排队任务');
    }

    /**
     * 选用某个资产图片候选版本作为当前视图。
     */
    public function selectImageVersion()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $versionId = (int) ($payload['version_id'] ?? 0);
        if ($assetId <= 0 || $versionId <= 0) {
            abort(422, '资产 id 和版本 id 不能为空');
        }

        $asset = $this->findOrFail($assetId);
        $this->assertSeriesWorkflowEditable((int) $asset->getAttr('series_id'));

        $version = AssetImageVersion::where('id', $versionId)
            ->where('asset_id', $assetId)
            ->where('user_id', $this->effectiveUserId())
            ->find();
        if (!$version instanceof AssetImageVersion) {
            abort(404, '图片版本不存在或不属于当前资产');
        }

        $url = trim((string) $version->getAttr('url'));
        if ($url === '') {
            abort(422, '图片版本没有可用 URL');
        }

        Db::transaction(function () use ($assetId, $version, $url): void {
            $viewType = (string) ($version->getAttr('view_type') ?: 'reference');
            $imageId = (int) ($version->getAttr('asset_image_id') ?? 0);
            $image = $imageId > 0
                ? AssetImage::where('id', $imageId)->where('asset_id', $assetId)->find()
                : null;

            if (!$image instanceof AssetImage) {
                $image = AssetImage::where('asset_id', $assetId)
                    ->where('view_type', $viewType)
                    ->order(['sort' => 'asc', 'id' => 'asc'])
                    ->find();
            }

            if ($image instanceof AssetImage) {
                $data = [
                    'url' => $url,
                    'note' => (string) ($image->getAttr('note') ?: $this->defaultImageNote($viewType)),
                ];
                $prompt = trim((string) ($version->getAttr('prompt') ?? ''));
                if ($prompt !== '') {
                    $data['image_prompt'] = $prompt;
                }
                if ($this->isCharacterLookImage($image)) {
                    $data = array_merge($data, $this->assetLookService()->emptyToapisBinding());
                }
                $image->save($data);
            } else {
                $image = AssetImage::create(array_merge([
                    'user_id' => $this->effectiveUserId(),
                    'asset_id' => $assetId,
                    'view_type' => $viewType,
                    'url' => $url,
                    'note' => $this->defaultImageNote($viewType),
                    'image_prompt' => (string) ($version->getAttr('prompt') ?? ''),
                    'sort' => $this->defaultImageSort($viewType),
                ], $this->assetLookService()->emptyToapisBinding()));
            }

            AssetImageVersion::where('asset_image_id', (int) $image->getAttr('id'))
                ->update(['is_selected' => 0]);
            $version->save([
                'asset_image_id' => (int) $image->getAttr('id'),
                'is_selected' => 1,
            ]);
        });

        $this->clearAssetCache();

        return successCode($this->serialize($this->findOrFail($assetId)));
    }

    /**
     * 删除某个未选用的资产图片候选版本。
     */
    public function deleteImageVersion()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $versionId = (int) ($payload['version_id'] ?? 0);
        if ($assetId <= 0 || $versionId <= 0) {
            abort(422, '资产 id 和版本 id 不能为空');
        }

        $asset = $this->findOrFail($assetId);
        $this->assertSeriesWorkflowEditable((int) $asset->getAttr('series_id'));

        $version = AssetImageVersion::where('id', $versionId)
            ->where('asset_id', $assetId)
            ->where('user_id', $this->effectiveUserId())
            ->find();
        if (!$version instanceof AssetImageVersion) {
            abort(404, '图片版本不存在或不属于当前资产');
        }
        if ((bool) $version->getAttr('is_selected')) {
            abort(422, '当前使用中的版本不能删除，请先选用其他版本');
        }

        $version->delete();
        $this->clearAssetCache();

        return successCode($this->serialize($this->findOrFail($assetId)));
    }

    /**
     * 删除或清空某个资产图片视图。
     * 核心视图保留记录并清空 URL，避免前端丢失核心视图槽位；造型与补充参考直接删除记录。
     */
    public function deleteImage()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $assetImageId = (int) ($payload['asset_image_id'] ?? ($payload['image_id'] ?? 0));
        if ($assetId <= 0 || $assetImageId <= 0) {
            abort(422, '资产 id 和图片 id 不能为空');
        }

        $asset = $this->findOrFail($assetId);
        $this->ensureAssetLookState((int) $asset->getAttr('series_id'));
        $this->assertSeriesWorkflowEditable((int) $asset->getAttr('series_id'));

        $image = AssetImage::where('id', $assetImageId)
            ->where('asset_id', $assetId)
            ->where('user_id', $this->effectiveUserId())
            ->find();
        if (!$image instanceof AssetImage) {
            abort(404, '图片记录不存在或不属于当前资产');
        }

        if ($this->hasRunningAssetImageJob($assetId, $assetImageId, (string) $image->getAttr('view_type'))) {
            abort(423, '图片正在生成中，请稍后再删除');
        }

        $before = $this->serialize($asset);
        $viewType = (string) ($image->getAttr('view_type') ?: 'reference');
        $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($image->getAttr('reference_role') ?? 'view'));
        $isCoreView = $referenceRole !== 'look' && $viewType === 'main';

        Db::transaction(function () use ($assetId, $assetImageId, $image, $viewType, $isCoreView): void {
            AssetImageVersion::where('asset_id', $assetId)->where('asset_image_id', $assetImageId)->delete();
            AssetImageJob::where('asset_id', $assetId)->where('asset_image_id', $assetImageId)->delete();

            if ($isCoreView) {
                AssetImageVersion::where('asset_id', $assetId)
                    ->where('view_type', 'main')
                    ->whereNull('asset_image_id')
                    ->delete();
                AssetImageJob::where('asset_id', $assetId)
                    ->where('view_type', 'main')
                    ->whereNull('asset_image_id')
                    ->delete();
                $image->save([
                    'url' => '',
                    'note' => (string) ($image->getAttr('note') ?: $this->defaultImageNote($viewType)),
                ]);
                return;
            }

            $image->delete();
        });

        $this->clearAssetCache();
        $after = $this->serialize($this->findOrFail($assetId));
        $this->writeAdminOperationLog([
            'action' => $isCoreView ? 'asset.image.clear' : 'asset.image.delete',
            'target_type' => 'asset_image',
            'target_id' => $assetImageId,
            'target_name_snapshot' => (string) ($after['name'] ?? ''),
            'series_id' => (int) ($after['series_id'] ?? 0),
            'result' => 'success',
            'before_json' => $before,
            'after_json' => $after,
            'meta_json' => [
                'route' => 'assets.delete-image',
                'view_type' => $viewType,
                'reference_role' => $referenceRole,
            ],
        ]);

        return successCode($after);
    }

    /**
     * 写实人物造型：手动触发虚拟人像入库（界面称「真人检测」）。
     * 不自动入库；视频生成按镜头实际用到的那套造型检查是否已过检。
     */
    public function detectLookAvatar()
    {
        $this->assetLookService()->ensureSchema();
        $payload = $this->payload();
        $assetId = (int) ($payload['asset_id'] ?? 0);
        $assetImageId = (int) ($payload['asset_image_id'] ?? 0);
        if ($assetId <= 0 || $assetImageId <= 0) {
            abort(422, '资产 id 和造型 id 不能为空');
        }

        $asset = $this->findOrFail($assetId);
        $this->ensureAssetLookState((int) $asset->getAttr('series_id'));
        $this->assertSeriesWorkflowEditable((int) $asset->getAttr('series_id'));
        if ((string) $asset->getAttr('type') !== 'character') {
            abort(422, '只有人物造型需要真人检测');
        }
        if (!$this->lookNeedsToapisIngest($asset)) {
            abort(422, '当前作品不是写实风格，无需真人检测');
        }

        $image = AssetImage::where('id', $assetImageId)
            ->where('asset_id', $assetId)
            ->where('user_id', $this->effectiveUserId())
            ->find();
        if (!$image instanceof AssetImage || !$this->isCharacterLookImage($image)) {
            abort(404, '人物造型不存在');
        }

        $displayUrl = trim((string) $image->getAttr('url'));
        if ($displayUrl === '' || !$this->isUsableAssetImageUrl($displayUrl)) {
            abort(422, '请先生成或上传这套造型图，再做真人检测');
        }

        if (ToapisPrivateAvatarService::isLookAvatarActive(
            (string) ($image->getAttr('toapis_status') ?? ''),
            (string) ($image->getAttr('toapis_asset_url') ?? ''),
        )) {
            return successCode([
                'already_active' => true,
                'asset' => $this->serialize($this->findOrFail($assetId)),
                'job' => null,
            ], '已过真人检测');
        }

        $existing = $this->findActiveLookIngestJob($assetId, $assetImageId);
        if ($existing instanceof AssetImageJob) {
            return successCode([
                'already_active' => false,
                'asset' => $this->serialize($this->findOrFail($assetId)),
                'job' => $this->serializeImageJob($existing),
            ], '真人检测进行中', 202);
        }

        $this->queueLookToapisIngestJob($asset, $image, $displayUrl);
        $this->clearAssetCache();
        $job = $this->findActiveLookIngestJob($assetId, $assetImageId);

        return successCode([
            'already_active' => false,
            'asset' => $this->serialize($this->findOrFail($assetId)),
            'job' => $job instanceof AssetImageJob ? $this->serializeImageJob($job) : null,
        ], '真人检测已加入队列', 202);
    }

    /**
     * 供队列 worker 调用，执行一个资产图片生成任务。
     */
    public function runQueuedAssetImageJob(int $jobId): array
    {
        $this->assetLookService()->ensureSchema();
        $job = AssetImageJob::find($jobId);
        if (!$job instanceof AssetImageJob) {
            throw new \RuntimeException('图片生成任务不存在');
        }
        $this->runtimeUserId = (int) ($job->getAttr('user_id') ?: 1);
        $providerTaskId = ImageProviderTaskState::extractTaskId((string) $job->getAttr('error_message'));
        $isResume = $providerTaskId !== '';
        $attempts = (int) $job->getAttr('attempts');
        if (!$isResume) {
            $attempts++;
        }

        $job->save([
            'status' => ImageJobStatus::RUNNING,
            'attempts' => $attempts,
            'started_at' => (string) ($job->getAttr('started_at') ?: date('Y-m-d H:i:s')),
            'finished_at' => null,
            'retry_after' => null,
            'error_message' => ImageProviderTaskState::pendingMessage($providerTaskId),
        ]);

        $asset = Asset::where('id', (int) $job->getAttr('asset_id'))->where('user_id', $this->effectiveUserId())->find();
        $series = $asset instanceof Asset
            ? Series::where('id', (int) $asset->getAttr('series_id'))->where('user_id', $this->effectiveUserId())->find()
            : null;
        if (!$asset instanceof Asset || !$series instanceof Series) {
            $job->save([
                'status' => 'cancelled',
                'error_message' => '资产或作品已删除，任务已自动取消。',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->clearAssetCache();

            return $this->serializeImageJob($job);
        }

        $jobDescription = (string) $job->getAttr('description');
        if (ToapisPrivateAvatarService::isLegacyPencilJob($jobDescription)) {
            $job->save([
                'status' => 'cancelled',
                'error_message' => '彩铅参考已停用',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->clearAssetCache();

            return $this->serializeImageJob($job);
        }
        if (ToapisPrivateAvatarService::isIngestJob($jobDescription)) {
            return $this->runLookToapisIngestJob($job, $asset);
        }
        if ((string) $job->getAttr('status') === ImageJobStatus::HOLDING) {
            $job->save([
                'status' => 'cancelled',
                'error_message' => '彩铅等待已停用，请重新生成造型',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->clearAssetCache();

            return $this->serializeImageJob($job);
        }

        try {
            $model = $this->resolveImageModel((int) $job->getAttr('model_config_id'));
            if (!$model instanceof ModelConfig) {
                throw new \RuntimeException('图片模型不存在');
            }

            $assetImageId = (int) ($job->getAttr('asset_image_id') ?? 0);
            $url = $this->callImageGeneration($model, (string) $job->getAttr('final_prompt'), [
                'asset_id' => (int) $job->getAttr('asset_id'),
                'asset_image_id' => $assetImageId,
                'view_type' => (string) $job->getAttr('view_type'),
                'main_image_url' => (string) $job->getAttr('main_image_url'),
                'model_config_id' => (int) $model->getAttr('id'),
                'job_id' => $jobId,
                'provider_task_id' => $providerTaskId,
                'inline_poll_attempts' => 1,
            ]);
            if (!MediaStorage::isStoredMediaAvailable($url)) {
                throw new \RuntimeException('图片已生成但文件未成功落盘，任务不会标记为成功，请稍后重试');
            }

            $assetId = (int) $job->getAttr('asset_id');
            $asset = $assetId > 0
                ? Asset::with(['images'])->where('id', $assetId)->where('user_id', $this->effectiveUserId())->find()
                : null;

            $job->save([
                'status' => 'success',
                'result_url' => $url,
                'finished_at' => date('Y-m-d H:i:s'),
                'retry_after' => null,
                'error_message' => '',
            ]);

            if ($assetId > 0) {
                $selected = $this->imageJobShouldAutoSelect($jobDescription);
                $this->upsertGeneratedAssetImage(
                    $assetId,
                    $assetImageId > 0 ? $assetImageId : null,
                    (string) $job->getAttr('view_type'),
                    $url,
                    (string) $job->getAttr('prompt'),
                    [
                        'model_config_id' => (int) $model->getAttr('id'),
                        'job_id' => $jobId,
                        'ai_request_log_id' => $this->lastAiRequestLogId ?: null,
                        'meta_json' => [],
                        'source' => 'generated',
                        'is_selected' => $selected,
                    ]
                );
                if (
                    $asset instanceof Asset
                    && (string) $asset->getAttr('type') === 'character'
                    && (string) $job->getAttr('view_type') === 'main'
                ) {
                    try {
                        $this->queueAgentLookImageJobs(
                            $this->effectiveUserId(),
                            (int) $asset->getAttr('series_id'),
                            $assetId,
                            (int) $model->getAttr('id'),
                        );
                    } catch (\Throwable $queueError) {
                        \think\facade\Log::warning('auto queue look jobs failed: ' . $queueError->getMessage(), [
                            'asset_id' => $assetId,
                            'job_id' => $jobId,
                        ]);
                    }
                }
            }
            $this->clearAssetCache();

            return $this->serializeImageJob($job);
        } catch (PendingImageTaskException $e) {
            $job->save([
                'status' => ImageJobStatus::WAITING,
                'retry_after' => null,
                'error_message' => ImageProviderTaskState::pendingMessage($e->taskId()),
                'finished_at' => null,
            ]);
            $this->clearAssetCache();

            return $this->serializeImageJob($job);
        } catch (\Throwable $e) {
            // Keep provider task id so workers can resume/recover after platform finishes late.
            $message = $e->getMessage();
            $existingTaskId = ImageProviderTaskState::extractTaskId((string) $job->getAttr('error_message'));
            if ($existingTaskId !== '' && ImageProviderTaskState::extractTaskId($message) === '') {
                $message .= '；可恢复任务：' . $existingTaskId;
            }

            if ($this->shouldRetryImageJob($message, $attempts)) {
                $retryAttempt = max(1, $attempts + 1);
                $delaySeconds = min(120, 5 * (2 ** min(5, max(0, $retryAttempt - 2))));
                $retryAfter = date('Y-m-d H:i:s', time() + $delaySeconds);
                $retryMessage = '图片上游暂时繁忙，已延迟 ' . $delaySeconds . ' 秒自动重试（第 ' . $retryAttempt . ' 次）：'
                    . mb_substr($e->getMessage(), 0, 1200);
                if ($existingTaskId !== '') {
                    $retryMessage .= '；可恢复任务：' . $existingTaskId;
                }
                $job->save([
                    'status' => ImageJobStatus::QUEUED,
                    'attempts' => $retryAttempt,
                    'started_at' => null,
                    'finished_at' => null,
                    'retry_after' => $retryAfter,
                    'error_message' => $retryMessage,
                ]);
                $this->clearAssetCache();
                Log::warning('[AssetImageJob#' . $jobId . '] transient provider error; requeued at ' . $retryAfter . ': ' . $e->getMessage());

                return $this->serializeImageJob($job);
            }

            $job->save([
                'status' => 'failed',
                'retry_after' => null,
                'error_message' => mb_substr($message, 0, 2000),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->clearAssetCache();
            throw $e;
        }
    }

    private function resolveImageModel(int $modelConfigId): ?ModelConfig
    {
        return ModelConfigResolver::resolve('image', $this->effectiveUserId(), $modelConfigId);
    }

    /**
     * 腾讯云并发/限流属于可恢复错误。任务不能直接标记失败，否则批量生成会留下缺图。
     * attempts 是提交/恢复次数，超过上限后才转为最终失败，避免无限重试坏请求。
     */
    private function shouldRetryImageJob(string $message, int $attempts): bool
    {
        $maxRetries = max(1, min(20, (int) env('ASSET_IMAGE_TRANSIENT_RETRIES', 8)));
        if ($attempts >= $maxRetries) {
            return false;
        }

        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        foreach ([
            'maximum concurrency',
            'max concurrency',
            'concurrency limit',
            'too many requests',
            'rate limit',
            'rate_limit',
            'temporarily unavailable',
            'service unavailable',
            'gateway timeout',
            'http 429',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
            '并发上限',
            '最大并发',
            '限流',
            '请求过于频繁',
            '暂时不可用',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function refinePromptForView(
        string $prompt,
        string $viewType,
        string $mainImageUrl,
        string $assetType = '',
        string $visualStyle = 'realistic',
        string $region = 'china',
        string $visualStyleVariant = '',
        ?Asset $asset = null,
    ): string
    {
        $region = $this->normalizeSeriesRegion($region);
        $visualStyle = $this->normalizeVisualStyle($visualStyle);
        $visualStyleVariant = $this->normalizeVisualStyleVariant($visualStyle, $visualStyleVariant);
        $prompt = $this->sanitizeSubjectPromptForVisualStyle(trim($prompt), $visualStyle, $visualStyleVariant);
        $styleRule = $this->assetVisualStylePromptRule($visualStyle, $visualStyleVariant);
        if ($mainImageUrl !== '') {
            $styleRule = '最高优先级视觉规则：以随请求上传的参考图画风为准，参考图的风格优先级高于剧本默认视觉风格、资产类型默认风格和其他风格描述。如果参考图是动漫/卡通/手绘/3D/写实中的任意风格，输出必须匹配参考图的画风、线条、材质、色彩、光影、渲染方式和美术完成度。若参考图是动漫或卡通，禁止生成真人、真实摄影、live-action 或写实人物。Highest priority style rule: match the uploaded reference image style above all other style instructions; if the reference is anime/cartoon, do not generate live-action or photorealistic results.';
        }
        $regionRule = $this->assetImageRegionRule($region, $assetType);
        $eraRule = $this->assetImageEraRule($asset, $prompt);

        $subjectRule = match ($assetType) {
            'character' => '资产类型是人物。主体只能是一个角色本人，头部和五官完整可见；不要出现第二个人物，不要拆成多个背景或多个场景。',
            'scene' => '资产类型是场景。主体只能是同一个完整场景空间、同一个地点、同一套空间关系；可以展示该场景的多个拍摄角度，但所有角度必须属于同一地点，不要把厨房、客厅、卧室等多个地点混成一张图。' . self::SCENE_NO_PEOPLE_FACE_RULE,
            'prop' => '资产类型是物品。主体只能是一个核心道具或同一套道具，必须是白色纯背景或透明感白底棚拍参考图；不要桌面、房间、地面、手、人物、使用场景、剧情场景或环境摆拍；不要出现多个不同物品拼接。' . self::PROP_NO_PEOPLE_FACE_RULE,
            default => '主体只能是一个明确资产，单一画面。',
        };
        $singleImageRule = '硬性构图要求：必须是单张完整图片、单一镜头、单一主体。不要三宫格，不要三分图，不要拼图，不要分屏，不要对比图，不要漫画分镜，不要故事板，不要在一张图里合成多个场景。English constraints: single subject, single frame, one image only, no triptych, no split screen, no storyboard, no multiple scenes, no three scenes.';
        $lookBoardRule = '硬性构图要求：必须是单张完整图片，左右两栏同一角色同一套服装，不是多张图、不是漫画分镜、不是剧情故事板。左栏三个无头全身，右栏一个带头放大特写。';

        $isNonHumanoidCharacter = $assetType === 'character'
            && $asset instanceof Asset
            && CharacterAssetClassifier::isNonHumanoid($asset);

        // 仅核心视图（main）使用「描述 + 专用构图/画风」；正视图/侧视图等走下方分支
        if ($viewType === 'main') {
            $refined = $this->refineCoreViewPrompt($prompt, $assetType, $styleRule, $singleImageRule, $subjectRule, $regionRule, $eraRule, $isNonHumanoidCharacter, $visualStyle);
            if ($mainImageUrl !== '') {
                $refined .= '。参考图优先级规则：参考图的画风是最高优先级，必须覆盖任何“真人、写实、电影感、live-action”等默认风格要求；主体内容、身份、服装、场景或道具仍以当前资产描述和提示词为准。不要照搬参考图里的具体人物、物体、文字或场景结构，除非当前资产描述也明确要求。';
            }
            return $refined;
        }

        if ($viewType === 'look') {
            // 只剥离旧版红笔涂脸；左无头三视 + 右特写是当前构图，不能再洗掉。
            $prompt = $this->stripCharacterLookFacePassInstructions($prompt);
            if ($isNonHumanoidCharacter) {
                $refined = "{$singleImageRule}非人角色参考图专用要求：这必须是一张“同一生物角色本体”的多视图设定参考板，不是拟人时装图，不是强行穿衣的人形改造图，不是多人合照，不是场景剧照。"
                    . "画面必须在同一张完整图片内同时呈现：主体全身主视图、侧视图、背视图，以及一个头部或关键特征特写；所有视角都必须保持同一物种、同一体型结构、同一毛发/鳞片/角翼尾等解剖特征。"
                    . "默认保持原生生物体态，不要强行添加人类服装、鞋子、帽子、包或站姿；除非描述中明确要求拟人化穿搭。"
                    . "使用白色或极浅中性棚拍背景；不要剧情场景，不要第二个主体，不要手持道具，不要多套造型拼接。"
                    . "基于以下描述生成非人角色设定参考图：{$prompt}。视觉风格要求：{$styleRule}。地区与选角要求：{$regionRule}。{$eraRule}";
            } else {
                $refined = "{$lookBoardRule}"
                    . self::CHARACTER_LOOK_BOARD_LAYOUT
                    . '变化点只允许在服装、鞋履、外套、发饰和整体造型细节；右栏脸型五官必须稳定一致，左栏只展示同一套衣服的无头身体。'
                    . '硬性禁止：红笔/红线/红网格/马克笔乱划眼鼻嘴；禁止左栏带头；禁止右栏无头；禁止只出一张正面带头全身。'
                    . "基于以下描述生成人物造型参考图：{$prompt}。视觉风格要求：{$styleRule}。地区与选角要求：{$regionRule}。{$eraRule}";
            }
            if ($mainImageUrl !== '') {
                $refined .= '参考图一致性要求：随请求上传的人物主图只用于锁定同一角色身份与画风，必须延续同一张脸、同一发型走向、同一体型比例和同一年龄感。'
                    . '右栏特写必须带头且五官完整；左栏三视必须无头，只保留衣领以下服装。不要把参考图之外的人物、背景、道具或构图直接搬进结果图。';
            }
            return $refined;
        }

        $viewLabel = match ($viewType) {
            'multi' => '同一主体的多视图参考，包含正、侧、背三个角度，统一白色或中性背景，不要加入剧情场景',
            'front' => '正视图',
            'side' => '侧视图',
            'back' => '背视图',
            'three_view' => '同一主体三视图，正面、侧面、背面并排，仅限同一主体，不要多个场景',
            default => '参考图',
        };

        $stylePriority = $this->assetStylePriorityPrefix($styleRule, $visualStyle);
        $refined = "{$stylePriority}基于以下描述生成{$viewLabel}：{$prompt}。地区与选角要求：{$regionRule}。{$eraRule}";
        if ($assetType === 'character' && in_array($viewType, ['multi', 'three_view'], true) && !$isNonHumanoidCharacter) {
            $refined .= '。' . self::CHARACTER_REFERENCE_CLOTHING_RULE . '。';
        }
        if (!in_array($viewType, ['multi', 'three_view'], true)) {
            $refined = "{$singleImageRule}{$subjectRule}" . $refined;
        }

        if ($mainImageUrl !== '') {
            $refined .= '。参考图优先级规则：参考图的画风是最高优先级，必须覆盖任何“真人、写实、电影感、live-action”等默认风格要求；主体内容、身份、服装、场景或道具仍以当前资产描述和提示词为准。不要照搬参考图里的具体人物、物体、文字或场景结构，除非当前资产描述也明确要求。';
        }

        return $refined;
    }

    /**
     * 核心视图（view_type=main）专用：人物/场景/物品均在一张图内呈现多视角参考。
     */
    private function refineCoreViewPrompt(
        string $prompt,
        string $assetType,
        string $styleRule,
        string $singleImageRule,
        string $subjectRule,
        string $regionRule,
        string $eraRule,
        bool $isNonHumanoidCharacter = false,
        string $visualStyle = 'realistic',
    ): string {
        $visualStyle = $this->normalizeVisualStyle($visualStyle);
        $stylePriority = $this->assetStylePriorityPrefix($styleRule, $visualStyle);

        if ($assetType === 'character') {
            if ($isNonHumanoidCharacter) {
                $characterCoreStyle = '多视图非人角色设定参考：主体全身主视图、侧视图、背视图 + 头部或关键特征特写，纯白色背景，专业棚拍构图；画风、线条、材质、色彩、光影和渲染方式必须稳定一致';
                return "{$stylePriority}{$prompt}。" . $characterCoreStyle
                    . "。硬性要求：同一张完整图片内呈现上述所有视角，必须是同一生物角色，物种特征、头身比例、四肢结构、毛发/鳞片/角翼尾等关键解剖特征完全一致；"
                    . "默认保持原生生物体态，不要强行加入人类服装、鞋履、站姿、手持道具或拟人化肢体，除非资产描述明确要求；"
                    . "不要剧情场景，不要出现第二个不同主体，不要文字标注、水印、分屏、拼图、三宫格。"
                    . "地区与选角要求：{$regionRule}。{$eraRule}";
            }

            if (str_starts_with($styleRule, '最高优先级视觉规则：')) {
                $characterCoreStyle = '多视图角色设定参考：正面全身、侧面全身、背面全身 + 面部特写，纯白色背景，专业棚拍构图；画风、线条、材质、色彩、光影和渲染方式必须严格匹配上传参考图';
            } elseif ($visualStyle === 'anime') {
                $characterCoreStyle = '多视图角色设定参考：正面全身、侧面全身、背面全身 + 面部特写，纯白色背景，动漫手绘/赛璐璐质感，线条清晰，色彩鲜明；严禁写实摄影与真人实拍质感';
            } elseif ($visualStyle === '3d') {
                $characterCoreStyle = '多视图角色设定参考：正面全身、侧面全身、背面全身 + 面部特写，纯白色背景，3D渲染质感，精细建模与材质；非真人实拍照片';
            } else {
                $characterCoreStyle = self::CHARACTER_CORE_VIEW_STYLE;
            }
            return "{$stylePriority}{$prompt}。" . $characterCoreStyle
                . "。硬性要求：同一张完整图片内呈现上述所有视角，必须是同一角色，五官、脸型、发型、服装、体型、年龄感和气质完全一致；头部和五官必须完整可见，禁止去头、禁止脸上画红线或网格。"
                . self::CHARACTER_REFERENCE_CLOTHING_RULE . "；"
                . "不要剧情场景，不要出现第二个不同人物，不要文字标注、水印、分屏、拼图、三宫格。"
                . "地区与选角要求：{$regionRule}。{$eraRule}";
        }
        if ($assetType === 'prop') {
            return "{$stylePriority}生成物品资产核心视图，资产描述：{$prompt}。地区与选角要求：{$regionRule}。"
                . '画面必须是一张完整图片：左侧是该物品的主视图，右侧是同一物品的多角度参考，可包含正面、侧面、背面或打开/关闭等结构视角。'
                . '所有角度必须是同一个物品或同一套道具，外形结构、材质、颜色、纹理、比例完全一致。'
                . '必须使用纯白背景或白底棚拍效果，物品孤立展示，边缘清晰，便于后续作为资产引用。'
                . '严禁生成桌面、房间、地面、手持、人物、使用场景、剧情场景、环境摆拍、阴暗背景、复杂背景或与物品无关的装饰。不要文字标注。'
                . self::PROP_NO_PEOPLE_FACE_RULE;
        }
        if ($assetType === 'scene') {
            return "{$stylePriority}主体内容：{$prompt}。" . self::SCENE_CORE_VIEW_STYLE
                . '。硬性要求：同一张完整图片内呈现上述所有角度，必须是同一场景、同一地点、同一套空间结构与陈设；'
                . '主视图最大最醒目，用来定义布局与关键锚点，其余角度须能对应回主视图中的门窗、家具与动线；'
                . '不要把厨房、客厅、卧室、走廊等不同地点混在一张图，除非资产描述明确是连续开放空间；'
                . self::SCENE_NO_PEOPLE_FACE_RULE
                . '不要文字标注、水印、分镜条或剧情动作（多视角参考板除外，不是分镜叙事）。'
                . "地区与视觉语境：{$regionRule}。";
        }

        return "{$stylePriority}{$singleImageRule}{$subjectRule}生成资产主视图，资产描述：{$prompt}。地区与选角要求：{$regionRule}。画面干净，主体清晰。";
    }

    public function callImageGeneration(ModelConfig $model, string $prompt, array $context = []): string
    {
        $this->lastAiRequestLogId = 0;
        $startedAt = microtime(true);
        $response = '';
        $httpStatus = 0;
        $curlErrno = 0;
        $curlError = '';
        $endpoint = trim((string) $model->getAttr('endpoint'));
        if ($endpoint === '') {
            abort(422, '图片模型 endpoint 为空');
        }

        $apiKey = trim((string) $model->getAttr('api_key'));
        $modelId = trim((string) $model->getAttr('model_id'));
        $options = $model->getAttr('options') ?: [];
        if (!is_array($options)) {
            $options = [];
        }

        $payload = ImageGenerationOptions::applyToPayload([
            'model' => $modelId ?: ImageGenerationOptions::DEFAULT_MODEL_ID,
            'prompt' => $prompt,
        ], $options, $modelId);

        $resumeTaskIdEarly = trim((string) ($context['provider_task_id'] ?? ''));
        if ($resumeTaskIdEarly === '') {
            $quality = (string) ($payload['quality'] ?? $options['quality'] ?? ImageGenerationOptions::defaultQuality());
            $referenceUrlsForCredit = is_array($context['main_image_urls'] ?? null)
                ? $context['main_image_urls']
                : (is_array($context['image_urls'] ?? null) ? $context['image_urls'] : []);
            if ($referenceUrlsForCredit === []) {
                $singleReference = trim((string) ($context['reference_image_url'] ?? ''));
                if ($singleReference !== '') {
                    $referenceUrlsForCredit = [$singleReference];
                }
            }
            $referenceUrlsForCredit = array_values(array_unique(array_filter(array_map(
                static fn ($url): string => trim((string) $url),
                $referenceUrlsForCredit,
            ), static fn (string $url): bool => $url !== '')));
            \app\support\CreditService::assertImageAffordable(
                $this->effectiveUserId(),
                $quality,
                (string) ($payload['model'] ?? $modelId),
                count($referenceUrlsForCredit),
            );
        }

        if ($this->isTencentVodImageProvider($endpoint, $options)) {
            return $this->callTencentVodImageGeneration($model, $prompt, $context, $options, $payload, $startedAt);
        }

        $headers = [];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        // main_image_urls（多图合并参考）优先于单图 main_image_url（历史单参考图调用方，如资产核心图生成）。
        $mainImageUrls = is_array($context['main_image_urls'] ?? null) ? $context['main_image_urls'] : [];
        if ($mainImageUrls === []) {
            $singleUrl = trim((string) ($context['main_image_url'] ?? ''));
            if ($singleUrl !== '') {
                $mainImageUrls = [$singleUrl];
            }
        }
        if ($mainImageUrls !== []) {
            $referenceImageUrls = [];
            foreach ($mainImageUrls as $rawUrl) {
                $rawUrl = trim((string) $rawUrl);
                if ($rawUrl === '') {
                    continue;
                }
                $referenceImageUrl = $this->normalizeImageUrlForToapis($rawUrl);
                if ($referenceImageUrl === '') {
                    abort(422, 'ToAPIs 参考图必须是可访问的 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或使用公网图片地址');
                }
                if (!in_array($referenceImageUrl, $referenceImageUrls, true)) {
                    $referenceImageUrls[] = $referenceImageUrl;
                }
            }
            if ($referenceImageUrls !== []) {
                $payload['image_urls'] = $referenceImageUrls;
            }
        }

        $endpoint = $this->resolveImageEndpoint($endpoint);
        $headers[] = 'Content-Type: application/json';
        $resumeTaskId = trim((string) ($context['provider_task_id'] ?? ''));
        $requestLogPayload = $resumeTaskId !== ''
            ? ['provider_task_id' => $resumeTaskId, 'resume' => true]
            : $payload;

        if ($resumeTaskId !== '') {
            $response = (string) json_encode([
                'id' => $resumeTaskId,
                'object' => 'generation.task',
                'status' => 'in_progress',
            ], JSON_UNESCAPED_UNICODE);
            $httpStatus = 200;
        } else {
            $postFields = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_TIMEOUT => 300,
            ]);

            $response = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
        }

        $errorMessage = '';
        $imageUrl = '';
        $pendingTask = null;

        if ($curlErrno !== 0) {
            $errorMessage = 'AI 请求失败：' . $curlError;
        } elseif ($httpStatus < 200 || $httpStatus >= 300) {
            $errorMessage = 'AI 服务异常：HTTP ' . $httpStatus . ' ' . $response;
        } else {
            $decoded = ProviderJsonResponse::decode((string) $response);
            if (!is_array($decoded)) {
                $errorMessage = 'AI 图片返回非 JSON';
            } else {
                try {
                    $imageUrl = $this->extractImageUrlFromResponse($decoded, $endpoint, $headers, $options, $response, $context);
                } catch (PendingImageTaskException $e) {
                    $pendingTask = $e;
                    $errorMessage = $e->getMessage();
                } catch (\Throwable $e) {
                    $errorMessage = $e->getMessage();
                }
            }
            if ($imageUrl === '') {
                $errorMessage = $errorMessage !== '' ? $errorMessage : 'AI 未返回图片 URL 或 b64_json';
            }
        }

        $requestSnapshot = [
            'method' => $resumeTaskId !== '' ? 'GET' : 'POST',
            'url' => $resumeTaskId !== '' ? $this->resolveImageTaskEndpoint($endpoint, $resumeTaskId) : $endpoint,
            'headers' => array_map(
                static fn (string $h): string => stripos($h, 'authorization:') === 0 ? 'Authorization: Bearer ***' : $h,
                $headers,
            ),
            'body' => $requestLogPayload,
        ];
        // 异步任务的排队/轮询状态由 asset_image_jobs 记录；AI 日志只保留最终结果。
        // 否则同一个任务会产生“初次 PROCESSING + 最终 FINISH”两条日志，且前者会被误显示为红色 200。
        $skipPendingLog = $pendingTask instanceof PendingImageTaskException;
        if (!$skipPendingLog) {
            try {
                $log = AiRequestLog::create([
                    'user_id' => $this->effectiveUserId(),
                    'source' => 'image_generation',
                    'model_config_id' => $model->id,
                    'llm_model' => (string) ($payload['model'] ?? ''),
                    'endpoint' => $endpoint,
                    'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'request_json' => json_encode($requestSnapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'http_status' => $httpStatus,
                    'response_body' => $this->truncateLogText((string) $response, 400000),
                    'curl_errno' => $curlErrno,
                    'curl_error' => $curlError,
                    'request_ok' => $imageUrl !== '' ? 1 : 0,
                    'error_message' => $errorMessage,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'content_preview' => $imageUrl,
                ]);
                $this->lastAiRequestLogId = (int) $log->getAttr('id');
                \think\facade\Log::info('[AiRequestLog#' . (int) $log->getAttr('id') . '] image_generation request_json=' . mb_substr((string) json_encode($requestSnapshot, JSON_UNESCAPED_UNICODE), 0, 4000));
            } catch (\Throwable $e) {
                \think\facade\Log::error('[AiRequestLog] insert failed: ' . $e->getMessage());
            }
        }

        if ($pendingTask instanceof PendingImageTaskException) {
            throw $pendingTask;
        }

        if ($errorMessage !== '') {
            abort(502, $errorMessage);
        }

        return $imageUrl;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isTencentVodImageProvider(string $endpoint, array $options): bool
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if (in_array($provider, ['tencent_vod', 'tencent', 'vod_aigc'], true)) {
            return true;
        }

        return str_contains(strtolower($endpoint), 'vod.tencentcloudapi.com');
    }

    /**
     * 腾讯云点播 AIGC 生图：创建任务后轮询 DescribeTaskDetail。
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function callTencentVodImageGeneration(
        ModelConfig $model,
        string $prompt,
        array $context,
        array $options,
        array $payload,
        float $startedAt,
    ): string {
        $endpoint = 'https://vod.tencentcloudapi.com';
        $secretId = trim((string) ($options['secret_id'] ?? $options['SecretId'] ?? ''));
        $secretKey = trim((string) $model->getAttr('api_key'));
        if ($secretId === '' || $secretKey === '') {
            abort(422, '腾讯云生图缺少 SecretId/SecretKey（options.secret_id + api_key）');
        }

        $mergedOptions = array_merge($options, [
            'quality' => (string) ($payload['quality'] ?? $options['quality'] ?? ImageGenerationOptions::defaultQuality()),
            'aspect_ratio' => (string) ($payload['aspect_ratio'] ?? $options['aspect_ratio'] ?? '16:9'),
        ]);

        $resumeTaskIdEarly = trim((string) ($context['provider_task_id'] ?? ''));
        $referenceUrls = [];
        if ($resumeTaskIdEarly === '') {
            $mainImageUrls = is_array($context['main_image_urls'] ?? null) ? $context['main_image_urls'] : [];
            if ($mainImageUrls === []) {
                $singleUrl = trim((string) ($context['main_image_url'] ?? ''));
                if ($singleUrl !== '') {
                    $mainImageUrls = [$singleUrl];
                }
            }
            foreach ($mainImageUrls as $rawUrl) {
                $rawUrl = trim((string) $rawUrl);
                if ($rawUrl === '') {
                    continue;
                }
                $candidate = $this->normalizeImageUrlForToapis($rawUrl);
                if ($candidate === '' || !preg_match('#^https?://#i', $candidate)) {
                    abort(422, '腾讯云参考图必须是可公网访问的 HTTP(S) URL，请配置 MEDIA_PUBLIC_BASE_URL 或改用公网图片地址');
                }
                if (!in_array($candidate, $referenceUrls, true)) {
                    $referenceUrls[] = $candidate;
                }
            }
        }

        $client = new TencentVodAigcImageClient();
        $subAppId = (int) ($mergedOptions['sub_app_id'] ?? 0);
        $quality = strtolower(trim((string) ($mergedOptions['quality'] ?? $payload['quality'] ?? ImageGenerationOptions::defaultQuality())));
        if (!in_array($quality, ['low', 'medium', 'high'], true)) {
            $quality = ImageGenerationOptions::defaultQuality();
        }
        $mergedOptions['quality'] = $quality;
        $billingModelId = trim((string) ($model->getAttr('model_id') ?: ImageGenerationOptions::DEFAULT_MODEL_ID));
        if ($billingModelId === '') {
            $billingModelId = ImageGenerationOptions::DEFAULT_MODEL_ID;
        }
        $resumeTaskId = trim((string) ($context['provider_task_id'] ?? ''));
        $httpStatus = 0;
        $rawBody = '';
        $errorMessage = '';
        $imageUrl = '';
        $pendingTask = null;
        $requestLogPayload = [];

        try {
            if ($resumeTaskId !== '') {
                $requestLogPayload = [
                    'provider_task_id' => $resumeTaskId,
                    'resume' => true,
                    'provider' => 'tencent_vod',
                    'quality' => $quality,
                    'model' => $billingModelId,
                ];
                $taskId = $resumeTaskId;
            } else {
                $created = $client->createTask($secretId, $secretKey, $prompt, $mergedOptions, $referenceUrls);
                $taskId = (string) $created['task_id'];
                $rawBody = (string) ($created['raw'] ?? '');
                $requestLogPayload = [
                    'provider' => 'tencent_vod',
                    'action' => 'CreateAigcImageTask',
                    'quality' => $quality,
                    'model' => $billingModelId,
                    'payload' => $created['payload'] ?? [],
                    'task_id' => $taskId,
                    'request_id' => $created['request_id'] ?? '',
                ];
                $this->rememberImageProviderTask($context, $taskId);
            }

            $interval = max(1, min(10, (int) ($mergedOptions['poll_interval'] ?? 3)));
            $attempts = $this->resolveInlineImagePollAttempts($mergedOptions, $context);
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                if ($attempt > 1) {
                    sleep($interval);
                }
                $detail = $client->describeTask($secretId, $secretKey, $taskId, $subAppId);
                $rawBody .= "\n\n[POLL {$attempt}]\n" . $detail['raw'];
                $httpStatus = 200;
                if ($detail['finished'] && $detail['image_url'] !== '') {
                    $imageUrl = $this->persistGeneratedImageUrl($detail['image_url'], $context);
                    break;
                }
                if ($detail['finished'] && $detail['image_url'] === '') {
                    throw new \RuntimeException('腾讯云生图失败：' . ($detail['message'] !== '' ? $detail['message'] : '未返回图片'));
                }
                $status = strtoupper((string) ($detail['status'] ?? ''));
                if (in_array($status, ['FAIL', 'FAILED', 'ERROR'], true)) {
                    throw new \RuntimeException('腾讯云生图失败：' . ($detail['message'] !== '' ? $detail['message'] : $status));
                }
            }

            if ($imageUrl === '') {
                if ($this->canYieldPendingImageTask($context)) {
                    throw new PendingImageTaskException($taskId);
                }
                throw new \RuntimeException('腾讯云生图超时：' . $taskId);
            }
        } catch (PendingImageTaskException $e) {
            $pendingTask = $e;
            $errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            if ($httpStatus === 0) {
                $httpStatus = 502;
            }
        }

        // 异步任务的排队/轮询状态由 asset_image_jobs 记录；AI 日志只保留最终结果。
        // 否则同一个任务会产生“初次 PROCESSING + 最终 FINISH”两条日志，且前者会被误显示为红色 200。
        $skipPendingLog = $pendingTask instanceof PendingImageTaskException;
        if (!$skipPendingLog) {
            try {
                $log = AiRequestLog::create([
                    'user_id' => $this->effectiveUserId(),
                    'source' => 'image_generation_tencent_vod',
                    'model_config_id' => $model->id,
                    // 计费主键固定为 model_configs.model_id（OG/image2）；质量走 request body.quality。
                    'llm_model' => $billingModelId,
                    'endpoint' => $endpoint,
                    'context_json' => json_encode(array_merge($context, [
                        'quality' => $quality,
                        'provider_model_version' => $client->resolveModelVersion($mergedOptions),
                    ]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'request_json' => json_encode([
                        'method' => 'POST',
                        'url' => $endpoint,
                        'headers' => ['Authorization: TC3 ***'],
                        'body' => $requestLogPayload,
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'http_status' => $httpStatus,
                    'response_body' => $this->truncateLogText($rawBody, 400000),
                    'curl_errno' => 0,
                    'curl_error' => '',
                    'request_ok' => $imageUrl !== '' ? 1 : 0,
                    'error_message' => $errorMessage,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'content_preview' => $imageUrl,
                ]);
                $this->lastAiRequestLogId = (int) $log->getAttr('id');
                Log::info('[AiRequestLog#' . $this->lastAiRequestLogId . '] image_generation_tencent_vod');
            } catch (\Throwable $e) {
                Log::error('[AiRequestLog] tencent insert failed: ' . $e->getMessage());
            }
        }

        if ($pendingTask instanceof PendingImageTaskException) {
            throw $pendingTask;
        }
        if ($errorMessage !== '') {
            abort(502, $errorMessage);
        }

        return $imageUrl;
    }

    /**
     * ToAPIs gpt-image-2 统一走 /images/generations；参考图通过 image_urls JSON 字段传入。
     */
    private function resolveImageEndpoint(string $endpoint): string
    {
        if (str_contains($endpoint, '/images/edits')) {
            return str_replace('/images/edits', '/images/generations', $endpoint);
        }
        if (str_contains($endpoint, '/images/generations')) {
            return $endpoint;
        }

        return rtrim($endpoint, '/') . '/images/generations';
    }

    private function extractImageUrlFromResponse(array $decoded, string $endpoint, array $headers, array $options, string &$rawBody, array $context = []): string
    {
        $imageUrl = trim((string) ($decoded['data'][0]['url'] ?? $decoded['result']['data'][0]['url'] ?? $decoded['url'] ?? ''));
        $b64 = $decoded['data'][0]['b64_json'] ?? $decoded['result']['data'][0]['b64_json'] ?? '';
        if ($imageUrl === '' && is_string($b64) && $b64 !== '') {
            return $this->saveGeneratedImageFromBase64($b64, $context);
        }
        if ($imageUrl !== '') {
            return $this->persistGeneratedImageUrl($imageUrl, $context);
        }

        $taskId = $this->extractImageTaskId($decoded);
        if ($taskId === '') {
            return '';
        }
        $this->rememberImageProviderTask($context, $taskId);

        return $this->pollImageTaskResult($endpoint, $headers, $taskId, $options, $rawBody, $context);
    }

    private function extractImageTaskId(array $decoded): string
    {
        $object = (string) ($decoded['object'] ?? '');
        $status = (string) ($decoded['status'] ?? '');
        $id = trim((string) ($decoded['id'] ?? $decoded['data']['id'] ?? ''));

        if ($id !== '' && ($object === 'generation.task' || in_array($status, ['queued', 'in_progress', 'completed', 'failed'], true))) {
            return $id;
        }

        return '';
    }

    private function rememberImageProviderTask(array $context, string $taskId): void
    {
        $jobId = (int) ($context['job_id'] ?? 0);
        if ($jobId <= 0 || trim($taskId) === '') {
            return;
        }

        AssetImageJob::where('id', $jobId)
            ->where('user_id', $this->effectiveUserId())
            ->whereIn('status', ImageJobStatus::active())
            ->update([
                'error_message' => ImageProviderTaskState::pendingMessage($taskId),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
    }

    private function pollImageTaskResult(string $submitEndpoint, array $headers, string $taskId, array $options, string &$rawBody, array $context = []): string
    {
        $interval = max(1, min(10, (int) ($options['poll_interval'] ?? 3)));
        $attempts = $this->resolveInlineImagePollAttempts($options, $context);
        $statusEndpoint = $this->resolveImageTaskEndpoint($submitEndpoint, $taskId);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1) {
                sleep($interval);
            }

            $ch = curl_init($statusEndpoint);
            if ($ch === false) {
                throw new \RuntimeException('初始化图片任务查询失败');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            $rawBody .= "\n\n[POLL {$attempt} HTTP {$httpStatus}]\n" . (is_string($response) ? $response : '');
            if ($curlErrno !== 0) {
                if ($this->canYieldPendingImageTask($context)) {
                    throw new PendingImageTaskException($taskId);
                }
                throw new \RuntimeException('AI 图片任务查询失败：' . $curlError);
            }
            if (!is_string($response) || $response === '') {
                continue;
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                if ($this->canYieldPendingImageTask($context) && ($httpStatus === 429 || $httpStatus >= 500)) {
                    throw new PendingImageTaskException($taskId);
                }
                throw new \RuntimeException('AI 图片任务服务异常：HTTP ' . $httpStatus . ' ' . mb_substr($response, 0, 300));
            }

            $decoded = ProviderJsonResponse::decode($response);
            if (!is_array($decoded)) {
                continue;
            }

            $status = strtolower((string) ($decoded['status'] ?? ''));
            if ($status === 'completed') {
                $imageUrl = trim((string) ($decoded['result']['data'][0]['url'] ?? $decoded['data'][0]['url'] ?? $decoded['url'] ?? ''));
                if ($imageUrl !== '') {
                    return $this->persistGeneratedImageUrl($imageUrl, $context);
                }
                throw new \RuntimeException('AI 图片任务完成但未返回图片 URL');
            }
            if (in_array($status, ['failed', 'error', 'expired', 'canceled', 'cancelled'], true)) {
                $message = (string) ($decoded['error']['message'] ?? '图片生成任务失败');
                throw new \RuntimeException('AI 图片任务失败：' . $message);
            }
        }

        if ($this->canYieldPendingImageTask($context)) {
            throw new PendingImageTaskException($taskId);
        }

        throw new \RuntimeException('AI 图片任务超时：' . $taskId);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $context
     */
    private function resolveInlineImagePollAttempts(array $options, array $context): int
    {
        $attempts = max(1, min(120, (int) ($options['poll_attempts'] ?? 80)));
        $inline = (int) ($context['inline_poll_attempts'] ?? 0);
        if ($inline > 0) {
            return max(1, min($attempts, $inline));
        }

        return $attempts;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function canYieldPendingImageTask(array $context): bool
    {
        return (int) ($context['job_id'] ?? 0) > 0 || !empty($context['allow_pending_yield']);
    }

    private function resolveImageTaskEndpoint(string $submitEndpoint, string $taskId): string
    {
        if (preg_match('#^(.*/v1)/images/(?:generations|edits)(?:/.*)?$#', $submitEndpoint, $m) === 1) {
            return rtrim($m[1], '/') . '/images/generations/' . rawurlencode($taskId);
        }

        return rtrim($submitEndpoint, '/') . '/' . rawurlencode($taskId);
    }

    private function normalizeImageUrlForToapis(string $url): string
    {
        return MediaStorage::toPublicHttpUrl($url);
    }

    private function downloadRemoteImageToTemp(string $url, array &$tempFiles): string
    {
        $localPath = $this->resolveLocalImagePath($url);
        if ($localPath !== '') {
            return $localPath;
        }

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, 15 * 1024 * 1024);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300 || !str_starts_with(strtolower($mime), 'image/')) {
            return '';
        }

        $extension = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            default => 'png',
        };
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'malulu-asset-ref-' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (file_put_contents($path, $body) === false) {
            return '';
        }
        $tempFiles[] = $path;

        return $path;
    }

    private function resolveLocalImagePath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $url) === 1 && is_file($url)) {
            return $url;
        }

        if (str_starts_with($url, DIRECTORY_SEPARATOR) && is_file($url)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $url;
        }
        $path = rawurldecode($path);
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));

        $publicCandidate = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . $path;
        if (is_file($publicCandidate)) {
            return $publicCandidate;
        }

        $rootCandidate = app()->getRootPath() . $path;
        return is_file($rootCandidate) ? $rootCandidate : '';
    }

    private function guessMimeType(string $path): string
    {
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    private function persistGeneratedImageUrl(string $url, array $context = []): string
    {
        $context = $this->mediaStorageContext($context, 'asset_image');

        return MediaStorage::persistRemoteUrl($url, 'generated/assets', $context);
    }

    private function saveGeneratedImageFromBase64(string $b64, array $context = []): string
    {
        $binary = base64_decode($b64, true);
        if ($binary === false || $binary === '') {
            abort(502, 'AI 返回的 b64_json 无法解析');
        }

        return MediaStorage::uploadImageBinary($binary, 'png', 'generated/assets', 'image/png', $this->mediaStorageContext($context, 'asset_image'));
    }

    private function mediaStorageContext(array $context = [], string $source = 'media'): array
    {
        $context['user_id'] = (int) ($context['user_id'] ?? 0) ?: $this->effectiveUserId();
        $context['source'] = trim((string) ($context['source'] ?? '')) ?: $source;

        return $context;
    }

    private function serializeImageJob(AssetImageJob $job): array
    {
        return [
            'id' => (int) $job->getAttr('id'),
            'asset_id' => $job->getAttr('asset_id') !== null ? (int) $job->getAttr('asset_id') : null,
            'asset_image_id' => $job->getAttr('asset_image_id') !== null ? (int) $job->getAttr('asset_image_id') : null,
            'model_config_id' => (int) $job->getAttr('model_config_id'),
            'status' => (string) $job->getAttr('status'),
            'view_type' => (string) $job->getAttr('view_type'),
            'url' => $this->isUsableAssetImageUrl((string) $job->getAttr('result_url'))
                ? (string) $job->getAttr('result_url')
                : '',
            'retry_after' => $job->getAttr('retry_after'),
            'error_message' => $this->ensureUtf8((string) $job->getAttr('error_message')),
            'create_time' => $job->getAttr('create_time'),
            'update_time' => $job->getAttr('update_time'),
        ];
    }

    private function truncateLogText(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_substr($text, 0, (int) ($maxBytes / 4)) . "\n...[truncated]";
    }

    /**
     * 核心视图生图的主体文案：优先 image_prompt，其次可用 description，最后用名称兜底。
     * 营销/元描述（如“用于展示场景落地”）不可单独作为生图主体。
     */
    private function resolveAssetSubjectDescription(array $asset, string $fallbackDescription = ''): string
    {
        $imagePrompt = trim((string) ($asset['image_prompt'] ?? ''));
        if ($imagePrompt !== '' && !$this->isMetaOrMarketingAssetBlurb($imagePrompt)) {
            return $imagePrompt;
        }

        $desc = trim((string) ($asset['description'] ?? $fallbackDescription));
        if ($desc !== '' && !$this->isMetaOrMarketingAssetBlurb($desc)) {
            return $desc;
        }

        if ($imagePrompt !== '') {
            return $imagePrompt;
        }

        return trim((string) ($asset['name'] ?? ''));
    }

    private function buildAssetImagePrompt(array $asset, string $type, string $description): string
    {
        $name = trim((string) ($asset['name'] ?? ''));
        $subject = $this->resolveAssetSubjectDescription($asset, $description);
        if ($subject !== '') {
            if ($name !== '' && !$this->subjectAlreadyMentionsName($subject, $name)) {
                $label = match ($type) {
                    'character' => '角色',
                    'scene' => '场景',
                    'prop' => '道具',
                    default => '资产',
                };
                return "{$label}「{$name}」：{$subject}";
            }
            return $subject;
        }

        $prefix = match ($type) {
            'character' => '角色设定参考',
            'scene' => '场景概念图',
            default => '关键道具参考',
        };

        return trim($prefix . ($name !== '' ? "：{$name}" : ''), " ：");
    }

    private function subjectAlreadyMentionsName(string $subject, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }

        return str_contains($subject, $name)
            || str_contains($subject, '「' . $name . '」')
            || str_contains($subject, '"' . $name . '"');
    }

    /** 识别不能单独拿去生图的营销/元描述文案。 */
    private function isMetaOrMarketingAssetBlurb(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match(
            '/用于展示|场景落地|剧情片段画面|功能演示|示例文案|产品展示|落地效果|展示短剧|样例场景|demo\s*scene|showcase\s*(?:scene|shot)/iu',
            $text
        );
    }

    /** 保证字符串可被 JSON 安全编码，避免接口返回 Malformed UTF-8。 */
    private function ensureUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8,GBK,GB2312,BIG5,ISO-8859-1,Windows-1252');
        if (!is_string($converted) || $converted === '') {
            $converted = $text;
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $converted);
        if (!is_string($clean)) {
            return '';
        }

        return $clean;
    }

    /**
     * 生图前按作品画风清洗主体文案中的冲突风格指令（写实↔动漫↔3D 三向互斥）。
     */
    private function sanitizeSubjectPromptForVisualStyle(string $prompt, string $visualStyle, string $visualStyleVariant = ''): string
    {
        $prompt = trim($this->ensureUtf8($prompt));
        $visualStyle = $this->normalizeVisualStyle($visualStyle);
        $visualStyleVariant = $this->normalizeVisualStyleVariant($visualStyle, $visualStyleVariant);
        if ($prompt === '') {
            return $prompt;
        }

        $animePatterns = [
            '/\banime\b/iu',
            '/\bmanga\b/iu',
            '/\bcartoon\b/iu',
            '/\bcel[\s-]?shad(?:ed|ing)\b/iu',
            '/\b2d\s+hand[\s-]?drawn\b/iu',
            '/二次元/u',
            '/赛璐璐/u',
            '/动漫(?:二次元)?(?:风格|画风|质感)?/u',
            '/卡通(?:风格|画风|质感)?/u',
            '/漫画(?:风格|画风|质感|风)?/u',
            '/手绘(?:动画|插画)?(?:风格|画风|质感)?/u',
            '/插画风/u',
            '/日系动画/u',
            '/国漫风/u',
        ];
        $realisticPatterns = [
            '/\bphotorealistic\b/iu',
            '/\blive[\s-]?action\b/iu',
            '/真实摄影/u',
            '/摄影作品(?:风格)?/u',
            '/照片级(?:质感|细节)?/u',
            '/超写实(?:主义|风格|质感)?/u',
            '/写实(?:画风|风格|质感|摄影)?/u',
            '/真人(?:拍摄|实拍|效果|质感)?/u',
            '/电影感真实质感/u',
            '/高度真实/u',
            '/手机实拍/u',
        ];
        $cgi3dPatterns = [
            '/\bunreal\s*engine\b/iu',
            '/\bCGI\b/u',
            '/3D(?:渲染|建模|引擎)?(?:风格|画风|质感)?/u',
            '/三维(?:渲染|建模)?(?:风格|画风|质感)?/u',
            '/游戏引擎(?:质感|画风)?/u',
            '/虚幻引擎/u',
        ];

        $patterns = match ($visualStyle) {
            'anime' => array_merge($realisticPatterns, $cgi3dPatterns),
            '3d' => array_merge(
                $animePatterns,
                $visualStyleVariant === 'unreal' ? [] : [
                    '/\blive[\s-]?action\b/iu',
                    '/真人实拍(?:照片)?/u',
                    '/手机实拍/u',
                    '/摄影作品(?:风格)?/u',
                ]
            ),
            default => $animePatterns,
        };

        foreach ($patterns as $pattern) {
            $prompt = (string) preg_replace($pattern, '', $prompt);
        }

        $prompt = (string) preg_replace('/[，,]{2,}/u', '，', $prompt);
        $prompt = (string) preg_replace('/[；;]{2,}/u', '；', $prompt);
        $prompt = (string) preg_replace('/\s{2,}/u', ' ', $prompt);
        $prompt = (string) preg_replace('/\s*[，,；;]\s*(?=[。．.]|$)/u', '', $prompt);

        return trim($prompt, " \t\n\r\0\x0B，,。．.;；");
    }

    private function assetStylePriorityPrefix(string $styleRule, string $visualStyle): string
    {
        $visualStyle = $this->normalizeVisualStyle($visualStyle);
        $anti = match ($visualStyle) {
            'anime' => '画风最高优先级：必须是动漫/二次元手绘或赛璐璐质感；严禁写实摄影、真人实拍、照片级皮肤与镜头景深；严禁纯3D CGI/游戏引擎质感。',
            '3d' => '画风最高优先级：必须是3D渲染或风格化三维质感；严禁2D动漫平涂、赛璐璐与手绘插画；严禁手机实拍照片质感，除非细分风格明确要求虚幻写实。',
            default => '画风最高优先级：必须是真人实拍/写实摄影质感；严禁动漫、二次元、卡通、赛璐璐、手绘插画、漫画平涂或动画上色。',
        };

        return "{$anti}{$styleRule}。";
    }

    /**
     * 从造型描述中剥离旧版过人脸/红笔/无头板文案（全风格生图前统一清洗）。
     */
    private function stripCharacterLookFacePassInstructions(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }

        $patterns = [
            // 只洗旧版红笔涂脸；左无头三视 + 右特写是当前构图，不能再剥掉。
            '/写实(?:风格|\/真人|\/真人风格)?[^。\n]{0,12}过人脸标准板[（(][^）)]*[）)][^。；;\n]*/u',
            '/写实(?:风格|\/真人|\/真人风格)?[^。；;\n]{0,40}过人脸标准板[^。；;\n]{0,200}/u',
            '/过人脸标准板[（(][^）)]*[）)]/u',
            '/过人脸标准板[^。；;\n]{0,200}/u',
            '/红笔穿过眼鼻嘴[^。；;\n]{0,100}/u',
            '/粗糙红笔[^。；;\n]{0,80}/u',
            '/(?:不要去头、?不要红笔|不要去头不要红笔)[^。；;\n]{0,80}/u',
            '/动漫(?:\/卡通)?(?:\/3D|\/?3D)?[^。；;\n]{0,12}(?:不要去头|不要红笔|出带头)[^。；;\n]{0,80}/u',
            '/(?:；|;)\s*动漫(?:\/卡通)?(?:\/3D)?\s*(?=。|；|;|$)/u',
            '/face-pass\s+board[^.。；;\n]{0,200}/iu',
            '/messy\s+red\s+marker[^.。；;\n]{0,160}/iu',
            '/red\s+(?:marker\s+)?scribbles?[^.。；;\n]{0,120}/iu',
            '/do\s+not\s+draw\s+red\s+marks[^.。；;\n]{0,80}/iu',
        ];

        foreach ($patterns as $pattern) {
            $prompt = (string) preg_replace($pattern, '', $prompt);
        }

        $prompt = (string) preg_replace('/[；;]{2,}/u', '；', $prompt);
        $prompt = (string) preg_replace('/[，,]{2,}/u', '，', $prompt);
        $prompt = (string) preg_replace('/\s{2,}/u', ' ', $prompt);
        $prompt = (string) preg_replace('/[：:]\s*[。．\.；;]/u', '。', $prompt);
        $prompt = (string) preg_replace('/[：:]\s*$/u', '', $prompt);
        $prompt = (string) preg_replace('/\s*[；;]\s*(?=[。．.]|$)/u', '', $prompt);
        $prompt = trim($prompt, " \t\n\r\0\x0B：:；;，,。．.");

        if ($prompt === '' || !preg_match('/[\p{L}\p{N}]/u', $prompt)) {
            return '人物造型参考图：左栏正侧背三个无头全身，右栏同一人物放大特写，白底棚拍，重点变化在服装与整体造型';
        }

        // 清洗后若只剩「人物造型参考图」等前缀，补上正常构图要求
        if (preg_match('/人物造型参考图\s*$/u', $prompt) === 1
            || preg_match('/参考图\s*$/u', $prompt) === 1
        ) {
            return rtrim($prompt, '：: ') . '：左栏正侧背三个无头全身，右栏同一人物放大特写，白底棚拍，重点变化在服装与整体造型';
        }

        return $prompt;
    }

    private function normalizeVisualStyle(string $style): string
    {
        $style = strtolower(trim($style));
        return in_array($style, ['realistic', 'anime', '3d'], true) ? $style : 'realistic';
    }

    private function normalizeVisualStyleVariant(string $style, string $variant): string
    {
        $style = $this->normalizeVisualStyle($style);
        $variant = strtolower(trim($variant));
        if ($variant === '') {
            return '';
        }

        $allowed = [
            'realistic' => ['cinematic', 'short_drama', 'documentary', 'commercial', 'noir'],
            'anime' => ['showa_anime', 'classic_american_cartoon', 'heisei_classic', 'moe', 'kyoto_animation', 'shinkai', 'modern_mobile_game', 'guoman', 'cel_shaded'],
            '3d' => ['animated_feature', 'unreal', 'stylized_3d', 'clay', 'low_poly'],
        ];

        return in_array($variant, $allowed[$style] ?? [], true) ? $variant : '';
    }

    private function assetVisualStylePromptRule(string $style, string $variant = ''): string
    {
        $style = $this->normalizeVisualStyle($style);
        $variant = $this->normalizeVisualStyleVariant($style, $variant);
        $base = match ($style) {
            'anime' => '动漫二次元风格，手绘质感，色彩鲜艳，线条清晰；禁止写实摄影、真人实拍、照片级皮肤与真实镜头景深；禁止纯3D CGI。Anime style, 2D hand-drawn look, vibrant colors, clean lines; no photorealistic live-action; no pure 3D CGI.',
            '3d' => '3D渲染风格，精细建模，电影级光影，高对比度；禁止2D动漫平涂与赛璐璐；非手机实拍照片。3D render style, high detail modeling, cinematic lighting, polished 3D look; no 2D anime cel-shading; not a phone camera photo.',
            default => '电影感真实质感，真人拍摄效果，写实画风；禁止动漫、二次元、卡通、赛璐璐、手绘插画与漫画平涂。Cinematic realistic style, live-action look, highly detailed textures; no anime, cartoon, cel-shading, or hand-drawn illustration.',
        };
        $variantRule = match ($variant) {
            'cinematic' => '细分风格：电影感写实，使用影视剧剧照质感、自然表演、真实镜头景深和克制调色。',
            'short_drama' => '细分风格：短剧写实，强调人物关系、清晰表演、现实生活场景和适合短剧平台的直接叙事。',
            'documentary' => '细分风格：纪实摄影，自然光、生活流、轻修饰、现场感强。',
            'commercial' => '细分风格：商业广告片，画面干净高级，布光精致，材质和人物状态更 polished。',
            'noir' => '细分风格：暗调悬疑，低调光、高反差、阴影层次、紧张神秘氛围。',
            'showa_anime' => '细分风格：昭和年代赛璐璐动画感，复古线条、胶片颗粒、低饱和怀旧色彩、手绘背景。',
            'classic_american_cartoon' => '细分风格：1940-1950 年代经典美式影院手绘卡通感，赛璐璐上色、清晰墨线、夸张肢体表演、弹性形变、复古胶片颗粒、温暖手绘背景和高对比舞台式灯光；借鉴黄金时代美国动画语言，但不要复刻任何既有角色、标志或受版权保护形象。',
            'heisei_classic' => '细分风格：平成经典电视动画感，清晰赛璐璐线稿、90s-00s 配色、稳定角色设定和干净分层上色。',
            'moe' => '细分风格：萌系二次元，圆润脸型、大眼睛、柔软线条、明亮可爱配色、表情细腻。',
            'kyoto_animation' => '细分风格：清爽日系青春动画感，柔和高饱和色彩、干净线条、细腻表情、日常生活光影。',
            'shinkai' => '细分风格：高透明度天空、强烈逆光、细腻城市背景、空气感光晕和电影级日系动画色彩。',
            'modern_mobile_game' => '细分风格：现代手游二次元立绘质感，精致角色设计、高饱和光效、干净数字绘和细密材质层次。',
            'guoman' => '细分风格：现代国漫风，东方审美、细腻角色造型、数字动画渲染、层次丰富背景。',
            'cel_shaded' => '细分风格：赛璐璐动画风，明确色块、硬边阴影、线稿清晰、干净平涂。',
            'animated_feature' => '细分风格：动画电影3D，友好角色比例、柔和材质、电影动画灯光和精致表情。',
            'unreal' => '细分风格：虚幻引擎写实，高精模型、真实材质、体积光、游戏过场级渲染。',
            'stylized_3d' => '细分风格：风格化3D，夸张造型、简化材质、清晰轮廓、鲜明色彩。',
            'clay' => '细分风格：黏土动画，手工塑形材质、轻微指纹纹理、定格动画质感、柔和棚拍光。',
            'low_poly' => '细分风格：低多边形，几何切面、简洁形体、清晰色块和轻量游戏美术感。',
            default => '',
        };

        return $variantRule !== '' ? $base . ' ' . $variantRule : $base;
    }

    private function normalizeSeriesRegion(string $region): string
    {
        $region = strtolower(trim($region));
        return in_array($region, ['china', 'western'], true) ? $region : 'china';
    }

    private function assetImageRegionRule(string $region, string $assetType): string
    {
        $region = $this->normalizeSeriesRegion($region);
        if ($region === 'western') {
            if ($assetType === 'character') {
                return 'Western / European or American casting only, non-Asian facial features unless the asset description explicitly says otherwise; avoid East Asian, Chinese, Korean, Japanese or generic Asian appearance. Use Western wardrobe, grooming and facial structure consistent with a US/European short drama.';
            }
            return 'Western / European or American visual context. Avoid Chinese/East Asian signage, decor, uniforms, documents, architecture, props, and cultural cues unless explicitly required by the asset description.';
        }

        if ($assetType === 'character') {
            return 'Chinese / East Asian casting, contemporary Chinese short-drama visual context unless the asset description explicitly says otherwise.';
        }
        return 'Chinese local visual context unless the asset description explicitly says otherwise.';
    }

    private function assetImageEraRule(?Asset $asset, string $prompt = ''): string
    {
        if (!$asset instanceof Asset) {
            return '';
        }

        $seriesId = (int) ($asset->getAttr('series_id') ?? 0);
        $series = $seriesId > 0
            ? Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find()
            : null;

        $context = implode("\n", array_filter([
            trim((string) ($series instanceof Series ? $series->getAttr('title') : '')),
            trim((string) ($series instanceof Series ? $series->getAttr('description') : '')),
            trim((string) $asset->getAttr('name')),
            trim((string) ($asset->getAttr('description') ?? '')),
            trim((string) ($asset->getAttr('image_prompt') ?? '')),
            trim($prompt),
        ], static fn (string $value): bool => $value !== ''));

        if ($context === '') {
            return '';
        }

        if (preg_match('/古代|古风|仙侠|修仙|武侠|宫廷|王朝|皇帝|皇后|太子|王爷|王妃|嫔妃|将军|丞相|侯府|世子|郡主|江湖|宗门|唐朝|宋朝|明朝|清朝|汉朝|魏晋|隋唐/u', $context) === 1) {
            return '时代要求：古代东方人物语境，发型、服装结构、鞋履、配饰与整体气质必须符合古代/古风/仙侠/武侠世界，不得出现现代服装、现代发型、现代妆容或现代配件。';
        }

        if (preg_match('/民国|近代|旧上海|军阀|租界/u', $context) === 1) {
            return '时代要求：近代民国人物语境，服装、发型、妆容与配饰必须符合民国/近代中国设定，不得出现当代都市穿搭。';
        }

        if (preg_match('/未来|赛博|科幻|星际|末世/u', $context) === 1) {
            return '时代要求：未来/科幻人物语境，服装、材质、配饰与造型必须符合未来世界观，不得退回当代日常穿搭。';
        }

        return '';
    }

    private function assetHasCoreImage(Asset $asset): bool
    {
        foreach ($asset->images as $img) {
            if (!$img instanceof AssetImage) {
                continue;
            }
            if ((string) $img->getAttr('view_type') !== 'main') {
                continue;
            }
            if ($this->isUsableAssetImageUrl((string) $img->getAttr('url'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 外部 URL 交给上游/浏览器自行校验；本地 storage URL 必须真实存在，避免裂图挡住重新生成。
     */
    private function isUsableAssetImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (str_starts_with($path, '/storage/')) {
            return MediaStorage::isStoredMediaAvailable($url);
        }

        return true;
    }

    /**
     * 补齐资产图时按人物/场景/道具轮转，而不是把人物全部排完再到场景。
     *
     * @param iterable<int, Asset> $assets
     * @return array<int, Asset>
     */
    private function interleaveAssetsByType(iterable $assets): array
    {
        $groups = [
            'character' => [],
            'scene' => [],
            'prop' => [],
        ];
        $other = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $type = (string) $asset->getAttr('type');
            if (isset($groups[$type])) {
                $groups[$type][] = $asset;
            } else {
                $other[] = $asset;
            }
        }

        $ordered = [];
        while ($groups['character'] !== [] || $groups['scene'] !== [] || $groups['prop'] !== []) {
            foreach (['character', 'scene', 'prop'] as $type) {
                $asset = array_shift($groups[$type]);
                if ($asset instanceof Asset) {
                    $ordered[] = $asset;
                }
            }
        }

        return array_merge($ordered, $other);
    }

    private function pickAssetMainImageUrl(Asset $asset): string
    {
        foreach ($asset->images as $img) {
            if (!$img instanceof AssetImage) {
                continue;
            }
            if ((string) $img->getAttr('view_type') !== 'main') {
                continue;
            }
            $url = trim((string) $img->getAttr('url'));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function buildCharacterLookPrompt(Asset $asset, AssetImage $image): string
    {
        $prompt = trim((string) ($image->getAttr('image_prompt') ?? ''));
        if ($prompt !== '') {
            return $prompt;
        }

        $characterName = trim((string) $asset->getAttr('name'));
        $variantName = trim((string) ($image->getAttr('variant_name') ?? ''));
        $description = trim((string) $asset->getAttr('description'));
        $variantLabel = $variantName !== '' ? $variantName : '默认造型';
        if (CharacterAssetClassifier::isNonHumanoid($asset)) {
            $base = "参考该角色的主图，生成非人角色「{$characterName}」的稳定设定参考图「{$variantLabel}」，画面必须是一张多视图角色设定板：主体全身主视图、侧视图、背视图，加一个头部或关键特征特写。保持同一物种、同一脸部或头部特征、同一体型结构、同一毛发/鳞片/角翼尾等解剖特征稳定。默认保持原生体态，不要强行添加人类服装、鞋子、站姿或手持道具，除非描述明确要求拟人化穿搭。白底棚拍，不要场景，不要第二个主体。";
            if ($description !== '') {
                $base .= " 角色基础描述：{$description}";
            }

            return $base;
        }
        $base = "参考该角色的主图，生成角色「{$characterName}」的人物造型板「{$variantLabel}」。"
            . "同一张图左右分栏：左栏正侧背三个无头全身看服装，右栏同一人物放大特写看脸和发型。"
            . "保持同一角色的脸部、发型、体型、年龄感和气质一致，变化点只在服装与整体造型。白底棚拍，不要场景，不要服装平铺，不要第二个人，不要手持道具。";
        if ($description !== '') {
            $base .= " 角色基础描述：{$description}";
        }

        return $base;
    }

    private function queuePendingLookJobsForCharacterAsset(Asset $asset, ModelConfig $model, string $visualStyle, string $region, string $visualStyleVariant = ''): array
    {
        $result = [
            'created' => 0,
            'skipped_with_image' => 0,
            'skipped_without_main' => 0,
            'skipped_queued' => 0,
            'jobs' => [],
        ];
        $mainImageUrl = $this->pickAssetMainImageUrl($asset);

        foreach ($asset->images as $image) {
            if (
                !$image instanceof AssetImage
                || $this->assetLookService()->normalizeReferenceRole((string) ($image->getAttr('reference_role') ?? 'view')) !== 'look'
            ) {
                continue;
            }

            if (trim((string) $image->getAttr('url')) !== '') {
                $result['skipped_with_image']++;
                continue;
            }

            if ($mainImageUrl === '') {
                $result['skipped_without_main']++;
                continue;
            }

            $existingJob = null;
            $existingRows = AssetImageJob::where('asset_id', (int) $asset->getAttr('id'))
                ->where('asset_image_id', (int) $image->getAttr('id'))
                ->where('user_id', $this->effectiveUserId())
                ->whereIn('status', ImageJobStatus::active())
                ->order('id', 'desc')
                ->select();
            foreach ($existingRows as $row) {
                if (!$row instanceof AssetImageJob) {
                    continue;
                }
                if ($this->isHiddenLookBackgroundJob((string) $row->getAttr('description'))) {
                    continue;
                }
                $existingJob = $row;
                break;
            }

            $prompt = $this->buildCharacterLookPrompt($asset, $image);
            $finalPrompt = $this->refinePromptForView($prompt, 'look', $mainImageUrl, 'character', $visualStyle, $region, $visualStyleVariant, $asset);

            if ($existingJob instanceof AssetImageJob) {
                if ((string) $existingJob->getAttr('status') === 'queued') {
                    $existingJob->save([
                        'model_config_id' => (int) $model->getAttr('id'),
                        'prompt' => $prompt,
                        'final_prompt' => $finalPrompt,
                        'description' => '[auto_character_look]',
                        'main_image_url' => $mainImageUrl,
                        'error_message' => '',
                    ]);
                }
                $result['skipped_queued']++;
                $result['jobs'][] = $this->serializeImageJob($existingJob);
                continue;
            }

            $job = AssetImageJob::create([
                'user_id' => $this->effectiveUserId(),
                'asset_id' => (int) $asset->getAttr('id'),
                'asset_image_id' => (int) $image->getAttr('id'),
                'model_config_id' => (int) $model->getAttr('id'),
                'status' => 'queued',
                'prompt' => $prompt,
                'final_prompt' => $finalPrompt,
                'description' => '[auto_character_look]',
                'view_type' => 'look',
                'main_image_url' => $mainImageUrl,
                'result_url' => '',
                'error_message' => '',
                'attempts' => 0,
            ]);
            $result['created']++;
            $result['jobs'][] = $this->serializeImageJob($job);
        }

        return $result;
    }

    private const TYPES = ['character', 'scene', 'prop'];

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
     * 统一读取资产接口请求体。
     * ThinkPHP 在不同 Content-Type / 代理转发下对 JSON body 的 param() 解析并不完全一致；
     * 这里保留 param()，同时兜底解析原始 JSON，避免更新资产时 id、name、images 丢失。
     */
    private function payload(): array
    {
        $payload = $this->request->param();
        if ($payload !== []) {
            return $payload;
        }

        $raw = (string) $this->request->getContent();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function effectiveUserId(): int
    {
        if ($this->authUser !== null) {
            return $this->currentUserId();
        }
        return $this->runtimeUserId > 0 ? $this->runtimeUserId : 1;
    }

    /**
     * 执行资产查询。
     * 构造 Asset 查询条件并序列化图片关系，返回前端可直接使用的资产数组。
     */
    /**
     * @param array $query 支持 scope=mine（默认，仅自己）|shared（仅他人分享给我的）|all（自己 + 他人分享给我的）
     */
    private function doList(array $query = []): array
    {
        $seriesId = isset($query['series_id']) && $query['series_id'] !== '' ? (int) $query['series_id'] : null;
        $type = isset($query['type']) ? trim((string) $query['type']) : '';
        $keyword = isset($query['keyword']) ? trim((string) $query['keyword']) : '';
        $scope = in_array($query['scope'] ?? '', ['shared', 'all'], true) ? (string) $query['scope'] : 'mine';
        $version = RedisCache::version('assets');
        $cacheKey = sprintf(
            'assets:list:%s:v%d:s%d:t%s:k%s:sc%s',
            $this->authUser !== null ? $this->currentUserCacheSuffix() : ('u' . $this->effectiveUserId()),
            $version,
            $seriesId ?? 0,
            $type !== '' ? $type : 'all',
            md5($keyword),
            $scope
        );

        return RedisCache::remember($cacheKey, 120, function () use ($seriesId, $type, $keyword, $scope): array {
            $userId = $this->effectiveUserId();
            $builder = AssetVisibility::scopedAssetQuery($userId, $scope)
                ->with(['images'])
                ->order(['type' => 'asc', 'sort' => 'asc', 'id' => 'desc']);
            $builder->where('is_hidden', 0);
            if ($seriesId !== null && $seriesId > 0) {
                $builder->where('series_id', $seriesId);
            }
            if (in_array($type, self::TYPES, true)) {
                $builder->where('type', $type);
            }
            if ($keyword !== '') {
                $builder->whereLike('name', '%' . $keyword . '%');
            }

            $assets = $builder->select();

            $ownerNames = [];
            if ($scope !== 'mine' && count($assets) > 0) {
                $ownerIds = array_values(array_unique(array_map(
                    static fn (Asset $asset): int => (int) $asset->getAttr('user_id'),
                    $assets->all()
                )));
                $ownerNames = User::whereIn('id', $ownerIds)->column('display_name', 'id');
            }

            return $assets->map(function (Asset $asset) use ($scope, $ownerNames): array {
                $row = $this->serialize($asset);
                if ($scope !== 'mine') {
                    $ownerId = (int) $asset->getAttr('user_id');
                    $row['owner_user_id'] = $ownerId;
                    $row['owner_name'] = $ownerNames[$ownerId] ?? '';
                }
                return $row;
            })->toArray();
        });
    }

    /**
     * 执行资产创建事务。
     * 先校验并规范化请求数据，再写入资产主表和图片明细表。
     */
    private function doCreate(array $payload): array
    {
        return Db::transaction(function () use ($payload): array {
            $data = $this->normalizePayload($payload);
            $this->assertSeriesOwned((int) $data['series_id']);
            $this->assertSeriesWorkflowEditable((int) $data['series_id']);
            $model = new Asset();
            $model->save([
                'user_id' => $this->effectiveUserId(),
                'series_id' => $data['series_id'],
                'type' => $data['type'],
                'name' => $data['name'],
                'description' => $data['description'],
                'image_prompt' => $data['image_prompt'] ?? '',
                'tags' => $data['tags'],
                'sort' => $this->nextSort($data['series_id'], $data['type']),
            ]);

            $this->replaceImages((int) $model->id, $data['images']);
            $this->clearAssetCache();
            $fresh = $this->findOrFail((int) $model->id);
            $created = $this->serialize($fresh);
            $this->writeAdminOperationLog([
                'action' => 'asset.create',
                'target_type' => 'asset',
                'target_id' => (int) $created['id'],
                'target_name_snapshot' => (string) $created['name'],
                'series_id' => (int) $created['series_id'],
                'result' => 'success',
                'before_json' => [],
                'after_json' => $created,
                'meta_json' => [
                    'route' => 'assets.create',
                ],
            ]);

            return $created;
        });
    }

    /**
     * 执行资产更新事务。
     * 只更新请求里出现的字段；图片列表按前端提交结果全量替换。
     */
    private function doUpdate(int $id, array $payload): array
    {
        return Db::transaction(function () use ($id, $payload): array {
            $model = $this->findOrFail($id);
            $before = $model->toArray();
            $data = $this->normalizePayload($payload, true);
            $this->assertSeriesWorkflowEditable((int) $model->getAttr('series_id'));
            if (isset($data['series_id'])) {
                $this->assertSeriesOwned((int) $data['series_id']);
                $this->assertSeriesWorkflowEditable((int) $data['series_id']);
            }

            $updateData = [];
            if (
                (string) $model->getAttr('type') === 'character'
                && isset($data['type'])
                && (string) $data['type'] !== 'character'
            ) {
                (new VoiceAssetService())->ensureSchema();
                $hasVoice = VoiceAsset::where('asset_id', $id)
                    ->where('user_id', $this->effectiveUserId())
                    ->where('status', 'ready')
                    ->whereNull('deleted_at')
                    ->count() > 0;
                if ($hasVoice) {
                    abort(422, '该角色已绑定音色，请先删除角色音色后再修改资产类型');
                }
            }
            foreach (['series_id', 'type', 'name', 'description', 'image_prompt', 'tags'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $data[$field];
                }
            }
            if ($updateData !== []) {
                $model->save($updateData);
            }
            $imagesTouched = array_key_exists('images', $data);
            if ($imagesTouched) {
                $this->replaceImages($id, $data['images']);
            }
            $this->clearAssetCache();
            $fresh = $this->findOrFail($id);
            $after = $this->serialize($fresh);
            $this->writeAdminOperationLog([
                'action' => 'asset.update',
                'target_type' => 'asset',
                'target_id' => $id,
                'target_name_snapshot' => (string) $after['name'],
                'series_id' => (int) $after['series_id'],
                'result' => 'success',
                'before_json' => $before,
                'after_json' => $after,
                'meta_json' => [
                    'route' => 'assets.update',
                ],
            ]);

            return $after;
        });
    }

    /**
     * 执行资产删除事务。
     * 删除资产前先清理图片明细，避免残留无主图片记录。
     */
    private function doDelete(int $id): void
    {
        Db::transaction(function () use ($id): void {
            $model = $this->findOrFail($id);
            $before = $model->toArray();
            $this->assertSeriesWorkflowEditable((int) $model->getAttr('series_id'));
            $imageIds = AssetImage::where('asset_id', $id)->column('id');
            AssetImageJob::where('asset_id', $id)->delete();
            AssetImageVersion::where('asset_id', $id)->delete();
            if ($imageIds !== []) {
                AssetImageJob::whereIn('asset_image_id', $imageIds)->delete();
                AssetImageVersion::whereIn('asset_image_id', $imageIds)->delete();
            }
            AssetImage::where('asset_id', $id)->delete();
            AssetShare::where('asset_id', $id)->delete();
            if ((string) $model->getAttr('type') === 'character') {
                (new VoiceAssetService())->ensureSchema();
                VoiceAsset::where('asset_id', $id)
                    ->where('user_id', $this->effectiveUserId())
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => 'deleted',
                        'deleted_at' => date('Y-m-d H:i:s'),
                    ]);
            }
            $model->delete();
            $this->writeAdminOperationLog([
                'action' => 'asset.delete',
                'target_type' => 'asset',
                'target_id' => $id,
                'target_name_snapshot' => (string) ($before['name'] ?? ''),
                'series_id' => (int) ($before['series_id'] ?? 0),
                'result' => 'success',
                'before_json' => $before,
                'after_json' => [],
                'meta_json' => [
                    'route' => 'assets.delete',
                ],
            ]);
        });
        $this->clearAssetCache();
    }

    /**
     * 查找资产。
     * 找不到时直接返回 404，避免后续逻辑处理空模型。
     */
    private function findOrFail(int $id): Asset
    {
        $asset = Asset::with(['images'])->where('id', $id)->where('user_id', $this->effectiveUserId())->find();
        if (!$asset instanceof Asset) {
            abort(404, '资产不存在');
        }
        return $asset;
    }

    private function hasRunningAssetImageJob(int $assetId, int $assetImageId, string $viewType): bool
    {
        $runningRows = AssetImageJob::where('asset_id', $assetId)
            ->where('asset_image_id', $assetImageId)
            ->whereIn('status', ImageJobStatus::active())
            ->select();
        foreach ($runningRows as $running) {
            if (!$running instanceof AssetImageJob) {
                continue;
            }
            return true;
        }

        if ($viewType !== 'main') {
            return false;
        }

        return AssetImageJob::where('asset_id', $assetId)
            ->where('view_type', 'main')
            ->whereNull('asset_image_id')
            ->whereIn('status', ImageJobStatus::inFlight())
            ->count() > 0;
    }

    private function assertSeriesWorkflowEditable(int $seriesId): void
    {
        $this->assertSeriesOwned($seriesId);
        $run = WorkflowRuntime::findBlockingSeriesRun($seriesId);
        if (!$run instanceof \app\model\WorkflowRun) {
            return;
        }

        $status = (string) $run->getAttr('status');
        $message = $status === 'failed'
            ? '剧本解析任务未完成，请先继续执行，或删除剧本后重新创建'
            : '剧本解析任务正在执行，完成后才能修改剧集或资产';
        abort(423, $message);
    }

    private function assertSeriesOwned(int $seriesId): void
    {
        if ($seriesId <= 0) {
            return;
        }
        $series = Series::where('id', $seriesId)->where('user_id', $this->effectiveUserId())->find();
        if (!$series instanceof Series) {
            abort(404, '剧本不存在');
        }
    }

    /**
     * 校验并规范化资产入参。
     * 统一处理创建和更新两种场景，包括类型、名称长度、提示词长度、标签和图片数组格式。
     */
    private function normalizePayload(array $payload, bool $partial = false): array
    {
        $normalized = [];

        if (!$partial || array_key_exists('series_id', $payload)) {
            $seriesId = (int) ($payload['series_id'] ?? 0);
            if ($seriesId <= 0) {
                abort(422, '请选择所属剧本');
            }
            $normalized['series_id'] = $seriesId;
        }

        if (!$partial || array_key_exists('type', $payload)) {
            $type = trim((string) ($payload['type'] ?? ''));
            if (!in_array($type, self::TYPES, true)) {
                abort(422, '资产类型必须是人物/场景/物品');
            }
            $normalized['type'] = $type;
        }

        if (!$partial || array_key_exists('name', $payload)) {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                abort(422, '资产名称不能为空');
            }
            if (mb_strlen($name) > 120) {
                abort(422, '资产名称不能超过 120 个字符');
            }
            $normalized['name'] = $name;
        }

        if (!$partial || array_key_exists('description', $payload)) {
            $description = trim((string) ($payload['description'] ?? ''));
            if (mb_strlen($description) > 1000) {
                abort(422, '资产描述不能超过 1000 个字符');
            }
            $normalized['description'] = $description;
        }

        if (!$partial || array_key_exists('image_prompt', $payload)) {
            $imagePrompt = trim((string) ($payload['image_prompt'] ?? ''));
            if (mb_strlen($imagePrompt) > 5000) {
                abort(422, '生图提示词不能超过 5000 个字符');
            }
            $normalized['image_prompt'] = $imagePrompt;
        }

        if (!$partial || array_key_exists('tags', $payload)) {
            $tags = $payload['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_filter(array_map('trim', explode(',', $tags)), fn ($x) => $x !== '');
            }
            if (!is_array($tags)) {
                abort(422, '标签格式错误');
            }
            $normalized['tags'] = array_values(array_map(fn ($x) => trim((string) $x), $tags));
        }

        if (!$partial || array_key_exists('images', $payload)) {
            $images = $payload['images'] ?? [];
            if (!is_array($images)) {
                abort(422, '资产图片列表格式错误');
            }
            $normalized['images'] = [];
            foreach ($images as $idx => $image) {
                if (!is_array($image)) {
                    continue;
                }
                $url = trim((string) ($image['url'] ?? ''));
                $imagePrompt = trim((string) ($image['image_prompt'] ?? $image['prompt'] ?? ''));
                if (mb_strlen($imagePrompt) > 5000) {
                    abort(422, '单张图片提示词不能超过 5000 个字符');
                }
                $note = trim((string) ($image['note'] ?? ''));
                $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($image['reference_role'] ?? 'view'));
                $variantName = $this->assetLookService()->normalizeVariantName((string) ($image['variant_name'] ?? ''));
                $referenceKey = trim((string) ($image['reference_key'] ?? ''));
                if ($referenceRole === 'look' && $variantName === '') {
                    abort(422, '人物造型必须填写造型名称');
                }
                if ($url === '' && $imagePrompt === '' && $note === '' && $variantName === '') {
                    continue;
                }
                $normalized['images'][] = [
                    'id' => isset($image['id']) ? (int) $image['id'] : 0,
                    'view_type' => $this->normalizeImageViewType(
                        trim((string) ($image['view_type'] ?? 'reference')) ?: 'reference',
                        $referenceRole,
                    ),
                    'url' => $url,
                    'note' => $note,
                    'image_prompt' => $imagePrompt,
                    'reference_role' => $referenceRole,
                    'variant_name' => $variantName,
                    'reference_key' => $referenceKey,
                    'sort' => isset($image['sort']) ? (int) $image['sort'] : ($idx + 1) * 10,
                ];
            }
        }

        return $normalized;
    }

    /**
     * 替换资产图片列表。
     * 先删除旧图片记录，再按当前请求里的 images 重新写入。
     */
    private function replaceImages(int $assetId, array $images): void
    {
        $asset = Asset::where('id', $assetId)->where('user_id', $this->effectiveUserId())->find();
        $assetName = $asset instanceof Asset ? (string) $asset->getAttr('name') : '';
        $assetType = $asset instanceof Asset ? (string) $asset->getAttr('type') : '';
        $existing = AssetImage::where('asset_id', $assetId)->select();
        $existingById = [];
        foreach ($existing as $item) {
            if ($item instanceof AssetImage) {
                $existingById[(int) $item->getAttr('id')] = $item;
            }
        }

        $keepIds = [];
        foreach ($images as $img) {
            $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($img['reference_role'] ?? 'view'));
            if ($referenceRole === 'look' && $assetType !== 'character') {
                abort(422, '人物造型只能挂在人物资产下');
            }
            $variantName = $this->assetLookService()->normalizeVariantName((string) ($img['variant_name'] ?? ''));
            $referenceKey = trim((string) ($img['reference_key'] ?? ''));
            if ($referenceRole === 'look' && $referenceKey === '') {
                $referenceKey = $this->assetLookService()->makeReferenceKey($assetName, $variantName);
            }
            if ($referenceRole !== 'look') {
                $variantName = '';
                $referenceKey = '';
            }

            $data = [
                'user_id' => $this->effectiveUserId(),
                'asset_id' => $assetId,
                'view_type' => $this->normalizeImageViewType((string) $img['view_type'], $referenceRole),
                'url' => (string) $img['url'],
                'note' => (string) ($img['note'] !== '' ? $img['note'] : $this->defaultImageNote((string) $img['view_type'], $referenceRole)),
                'image_prompt' => (string) ($img['image_prompt'] ?? ''),
                'sort' => (int) $img['sort'],
                'reference_role' => $referenceRole,
                'variant_name' => $variantName,
                'reference_key' => $referenceKey,
            ];

            $imageId = (int) ($img['id'] ?? 0);
            $previousUrl = '';
            if ($imageId > 0 && isset($existingById[$imageId])) {
                $image = $existingById[$imageId];
                $previousUrl = trim((string) $image->getAttr('url'));
                if ($referenceRole === 'look' && $previousUrl !== trim((string) $img['url'])) {
                    $data = array_merge($data, $this->assetLookService()->emptyToapisBinding());
                }
                $image->save($data);
            } else {
                if ($referenceRole === 'look') {
                    $data = array_merge($data, $this->assetLookService()->emptyToapisBinding());
                }
                $image = AssetImage::create($data);
            }

            $keepIds[] = (int) $image->getAttr('id');
            if (trim((string) $img['url']) !== '') {
                $this->createAssetImageVersion($image, (string) $img['url'], [
                    'prompt' => (string) ($img['image_prompt'] ?? ''),
                    'source' => 'manual',
                    'is_selected' => true,
                ]);
            } else {
                AssetImageVersion::where('asset_image_id', (int) $image->getAttr('id'))
                    ->update(['is_selected' => 0]);
                if ($referenceRole === 'look') {
                    $this->clearLookToapisBinding($image);
                }
            }
        }

        $deleteIds = array_values(array_diff(array_keys($existingById), $keepIds));
        if ($deleteIds !== []) {
            AssetImageVersion::whereIn('asset_image_id', $deleteIds)->delete();
            AssetImageJob::whereIn('asset_image_id', $deleteIds)->delete();
            AssetImage::destroy($deleteIds);
        }
    }

    private function upsertGeneratedAssetImage(int $assetId, ?int $assetImageId, string $viewType, string $url, string $prompt = '', array $extra = []): void
    {
        $viewType = trim($viewType) !== '' ? trim($viewType) : 'main';
        $prompt = trim($prompt);
        $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($extra['reference_role'] ?? ($viewType === 'look' ? 'look' : 'view')));
        $existing = $assetImageId !== null && $assetImageId > 0
            ? AssetImage::where('id', $assetImageId)->where('asset_id', $assetId)->find()
            : AssetImage::where('asset_id', $assetId)->where('view_type', $viewType)->order(['sort' => 'asc', 'id' => 'asc'])->find();

        if ($existing instanceof AssetImage) {
            $hasCurrentUrl = $this->isUsableAssetImageUrl((string) $existing->getAttr('url'));
            $shouldSelect = (bool) ($extra['is_selected'] ?? false) || !$hasCurrentUrl;
            if (!$hasCurrentUrl || $shouldSelect) {
                $data = [
                    'url' => $url,
                    'note' => (string) ($existing->getAttr('note') ?: $this->defaultImageNote($viewType, $referenceRole)),
                ];
                if ($prompt !== '') {
                    $data['image_prompt'] = $prompt;
                }
                if ($this->isCharacterLookImage($existing) && trim((string) $existing->getAttr('url')) !== trim($url)) {
                    $data = array_merge($data, $this->assetLookService()->emptyToapisBinding());
                }
                $existing->save($data);
            }
            $this->createAssetImageVersion($existing, $url, array_merge($extra, [
                'prompt' => $prompt,
                'is_selected' => $shouldSelect,
            ]));
            return;
        }

        $image = AssetImage::create(array_merge([
            'user_id' => $this->effectiveUserId(),
            'asset_id' => $assetId,
            'view_type' => $viewType,
            'url' => $url,
            'note' => $this->defaultImageNote($viewType, $referenceRole),
            'image_prompt' => $prompt,
            'sort' => $this->defaultImageSort($viewType),
            'reference_role' => $referenceRole,
            'variant_name' => (string) ($extra['variant_name'] ?? ''),
            'reference_key' => (string) ($extra['reference_key'] ?? ''),
        ], $this->assetLookService()->emptyToapisBinding()));
        $this->createAssetImageVersion($image, $url, array_merge($extra, [
            'prompt' => $prompt,
            'is_selected' => true,
        ]));
    }

    private function defaultImageNote(string $viewType, string $referenceRole = 'view'): string
    {
        if ($referenceRole === 'look' || $viewType === 'look') {
            return '人物造型';
        }
        return match ($viewType) {
            'main' => '主视图',
            'multi' => '多视图',
            'front' => '正视图',
            'side' => '侧视图',
            'back' => '背视图',
            'three_view' => '三视图',
            default => '参考图',
        };
    }

    private function defaultImageSort(string $viewType): int
    {
        return match ($viewType) {
            'main' => 10,
            'multi' => 20,
            'front' => 30,
            'side' => 40,
            'back' => 50,
            'three_view' => 60,
            default => 90,
        };
    }

    private function createAssetImageVersion(AssetImage $image, string $url, array $extra = []): ?AssetImageVersion
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $assetId = (int) $image->getAttr('asset_id');
        $assetImageId = (int) $image->getAttr('id');
        $viewType = (string) ($image->getAttr('view_type') ?: 'reference');
        $isSelected = (bool) ($extra['is_selected'] ?? false);
        if ($isSelected) {
            AssetImageVersion::where('asset_image_id', $assetImageId)
                ->update(['is_selected' => 0]);
        }

        $exists = AssetImageVersion::where('asset_image_id', $assetImageId)
            ->where('url', $url)
            ->find();
        if ($exists instanceof AssetImageVersion) {
            $update = [
                'asset_id' => $assetId,
                'asset_image_id' => $assetImageId,
                'view_type' => $viewType,
            ];
            if ($isSelected && !(bool) $exists->getAttr('is_selected')) {
                $update['is_selected'] = 1;
            }
            if ($update !== []) {
                $exists->save($update);
            }
            return $exists;
        }

        $version = new AssetImageVersion();
        $version->save([
            'user_id' => $this->effectiveUserId(),
            'asset_id' => $assetId,
            'asset_image_id' => $assetImageId,
            'model_config_id' => (int) ($extra['model_config_id'] ?? 0),
            'view_type' => $viewType,
            'url' => $url,
            'prompt' => (string) ($extra['prompt'] ?? ''),
            'source' => (string) ($extra['source'] ?? 'generated'),
            'is_selected' => $isSelected ? 1 : 0,
            'job_id' => isset($extra['job_id']) ? (int) $extra['job_id'] : null,
            'ai_request_log_id' => isset($extra['ai_request_log_id']) ? (int) $extra['ai_request_log_id'] : null,
            'meta_json' => $extra['meta_json'] ?? null,
        ]);

        return $version;
    }

    /**
     * 计算同剧本同类型资产的下一个排序值。
     */
    private function nextSort(int $seriesId, string $type): int
    {
        return ((int) Asset::where('series_id', $seriesId)->where('user_id', $this->effectiveUserId())->where('type', $type)->max('sort')) + 10;
    }

    /** 同作品同类型重名时生成「原名（副本）」「原名（副本2）」… */
    private function uniqueCopiedAssetName(int $seriesId, string $type, string $baseName): string
    {
        $baseName = mb_substr(trim($baseName), 0, 100);
        if ($baseName === '') {
            $baseName = '未命名资产';
        }

        $userId = $this->effectiveUserId();
        $nameTaken = static function (string $name) use ($seriesId, $type, $userId): bool {
            return Asset::where('user_id', $userId)
                ->where('series_id', $seriesId)
                ->where('type', $type)
                ->where('name', $name)
                ->where('is_hidden', 0)
                ->count() > 0;
        };

        if (!$nameTaken($baseName)) {
            return $baseName;
        }

        $candidate = $baseName . '（副本）';
        if (!$nameTaken($candidate)) {
            return mb_substr($candidate, 0, 120);
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = $baseName . '（副本' . $i . '）';
            if (!$nameTaken($candidate)) {
                return mb_substr($candidate, 0, 120);
            }
        }

        return mb_substr($baseName . '（副本' . date('mdHis') . '）', 0, 120);
    }

    /**
     * 序列化资产模型。
     * 把 Asset 与 AssetImage 转成前端稳定字段结构。
     */
    private function serialize(Asset $asset): array
    {
        $images = [];
        $assetId = (int) $asset->getAttr('id');
        $versionsByImage = $this->versionsForAsset($assetId);
        $pendingLookAvatarJobs = $this->pendingLookAvatarJobs($assetId);
        $latestLookAvatarJobs = $this->latestLookIngestJobs($assetId);
        $lookCount = 0;
        foreach ($asset->images as $img) {
            if ($img instanceof AssetImage) {
                $imageId = (int) $img->getAttr('id');
                $viewType = (string) $img->getAttr('view_type');
                $referenceRole = $this->assetLookService()->normalizeReferenceRole((string) ($img->getAttr('reference_role') ?? 'view'));
                if ($referenceRole === 'look') {
                    $lookCount++;
                }
                $toapisStatus = strtolower(trim((string) ($img->getAttr('toapis_status') ?? '')));
                $toapisUrl = (string) ($img->getAttr('toapis_asset_url') ?? '');
                $hasAvatar = $referenceRole === 'look'
                    && ToapisPrivateAvatarService::isLookAvatarActive($toapisStatus, $toapisUrl);
                $hasActiveIngestJob = isset($pendingLookAvatarJobs[$imageId]);
                $lookPending = $referenceRole === 'look'
                    && ToapisPrivateAvatarService::isLookAvatarPending($hasAvatar, $hasActiveIngestJob, $toapisStatus);
                $lookJob = $pendingLookAvatarJobs[$imageId] ?? ($lookPending ? ($latestLookAvatarJobs[$imageId] ?? null) : null);
                $images[] = [
                    'id' => $imageId,
                    'asset_id' => (int) $img->getAttr('asset_id'),
                    'view_type' => $viewType,
                    'reference_role' => $referenceRole,
                    'variant_name' => (string) ($img->getAttr('variant_name') ?? ''),
                    'reference_key' => (string) ($img->getAttr('reference_key') ?? ''),
                    'url' => $this->isUsableAssetImageUrl((string) $img->getAttr('url'))
                        ? (string) $img->getAttr('url')
                        : '',
                    'has_toapis_avatar' => $hasAvatar,
                    'toapis_status' => $referenceRole === 'look' ? $toapisStatus : '',
                    'look_avatar_pending' => $lookPending,
                    'look_avatar_job_id' => $lookJob instanceof AssetImageJob
                        ? (int) $lookJob->getAttr('id')
                        : 0,
                    'note' => $this->ensureUtf8((string) $img->getAttr('note')),
                    'image_prompt' => $this->ensureUtf8((string) ($img->getAttr('image_prompt') ?? '')),
                    'sort' => (int) $img->getAttr('sort'),
                    'versions' => $versionsByImage[$imageId] ?? [],
                ];
            }
        }
        usort($images, static function (array $a, array $b): int {
            $rank = static function (array $img): int {
                if (($img['view_type'] ?? '') === 'main') {
                    return 0;
                }
                if (($img['reference_role'] ?? '') === 'look' || ($img['view_type'] ?? '') === 'look') {
                    return 2;
                }
                return 1;
            };
            $cmp = $rank($a) <=> $rank($b);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        });
        return [
            'id' => (int) $asset->getAttr('id'),
            'series_id' => (int) $asset->getAttr('series_id'),
            'type' => (string) $asset->getAttr('type'),
            'name' => $this->ensureUtf8((string) $asset->getAttr('name')),
            'description' => $this->ensureUtf8((string) $asset->getAttr('description')),
            'image_prompt' => $this->ensureUtf8((string) ($asset->getAttr('image_prompt') ?? '')),
            'tags' => $asset->getAttr('tags') ?: [],
            'look_count' => $lookCount,
            'images' => $images,
            'image_jobs' => $this->serializeLatestImageJobs((int) $asset->getAttr('id')),
            'create_time' => $asset->getAttr('create_time'),
            'update_time' => $asset->getAttr('update_time'),
        ];
    }

    private function versionsForAsset(int $assetId): array
    {
        if ($assetId <= 0) {
            return [];
        }

        $imageIdsByView = [];
        $images = AssetImage::where('asset_id', $assetId)->order(['sort' => 'asc', 'id' => 'asc'])->select();
        foreach ($images as $image) {
            if (!$image instanceof AssetImage) {
                continue;
            }
            $viewType = (string) ($image->getAttr('view_type') ?: 'reference');
            if (!isset($imageIdsByView[$viewType])) {
                $imageIdsByView[$viewType] = (int) $image->getAttr('id');
            }
        }

        $versionsByImage = [];
        $versions = AssetImageVersion::where('asset_id', $assetId)
            ->order(['id' => 'asc'])
            ->select();
        foreach ($versions as $version) {
            if (!$version instanceof AssetImageVersion) {
                continue;
            }
            if (!$this->isUsableAssetImageUrl((string) $version->getAttr('url'))) {
                continue;
            }
            $imageId = (int) ($version->getAttr('asset_image_id') ?? 0);
            if ($imageId <= 0) {
                $viewType = (string) ($version->getAttr('view_type') ?: 'reference');
                $imageId = (int) ($imageIdsByView[$viewType] ?? 0);
                if ($imageId <= 0) {
                    continue;
                }
            }
            $versionsByImage[$imageId][] = $this->serializeAssetImageVersion($version);
        }

        return $versionsByImage;
    }

    private function serializeAssetImageVersion(AssetImageVersion $version): array
    {
        return [
            'id' => (int) $version->getAttr('id'),
            'asset_id' => (int) $version->getAttr('asset_id'),
            'asset_image_id' => (int) $version->getAttr('asset_image_id'),
            'model_config_id' => (int) $version->getAttr('model_config_id'),
            'view_type' => (string) $version->getAttr('view_type'),
            'url' => (string) $version->getAttr('url'),
            'prompt' => (string) ($version->getAttr('prompt') ?? ''),
            'source' => (string) $version->getAttr('source'),
            'is_selected' => (bool) $version->getAttr('is_selected'),
            'job_id' => $version->getAttr('job_id') !== null ? (int) $version->getAttr('job_id') : null,
            'ai_request_log_id' => $version->getAttr('ai_request_log_id') !== null ? (int) $version->getAttr('ai_request_log_id') : null,
            'create_time' => $version->getAttr('create_time'),
            'update_time' => $version->getAttr('update_time'),
        ];
    }

    private function serializeLatestImageJobs(int $assetId): array
    {
        $jobs = AssetImageJob::where('asset_id', $assetId)
            ->whereIn('status', ImageJobStatus::active())
            ->order(['id' => 'desc'])
            ->select();

        $latest = [];
        foreach ($jobs as $job) {
            if (!$job instanceof AssetImageJob) {
                continue;
            }
            // 人像入库/旧彩铅为后台隐藏任务，不进入前台 image_jobs。
            if ($this->isHiddenLookBackgroundJob((string) $job->getAttr('description'))) {
                continue;
            }
            $imageId = (int) ($job->getAttr('asset_image_id') ?? 0);
            $key = $imageId > 0 ? 'img:' . $imageId : 'view:' . (string) $job->getAttr('view_type');
            if ($key === 'view:' || isset($latest[$key])) {
                continue;
            }
            $latest[$key] = $this->serializeImageJob($job);
        }

        return array_values($latest);
    }

    private function normalizeImageViewType(string $viewType, string $referenceRole): string
    {
        $viewType = trim($viewType);
        if ($referenceRole === 'look') {
            return 'look';
        }
        return $viewType !== '' ? $viewType : 'reference';
    }

    private function ensureAssetLookState(int $seriesId = 0, bool $migrate = true): void
    {
        $service = $this->assetLookService();
        $service->ensureSchema();
        if ($migrate && $seriesId > 0) {
            $service->ensureSeriesMigration($this->effectiveUserId(), $seriesId);
        }
    }

    private function assetLookService(): AssetLookService
    {
        if (!$this->assetLookService instanceof AssetLookService) {
            $this->assetLookService = new AssetLookService();
        }
        return $this->assetLookService;
    }

    private function markImageJobDescriptionForAutoSelect(string $description): string
    {
        return str_contains($description, '[manual_auto_select]')
            ? $description
            : trim($description . ' [manual_auto_select]');
    }

    private function imageJobShouldAutoSelect(string $description): bool
    {
        return str_contains($description, '[agent_regenerate_auto_select]')
            || str_contains($description, '[manual_auto_select]')
            || str_contains($description, '[auto_character_look]');
    }

    private function isHiddenLookBackgroundJob(string $description): bool
    {
        return ToapisPrivateAvatarService::isHiddenBackgroundJob($description);
    }

    private function isCharacterLookImage(AssetImage $image): bool
    {
        return $this->assetLookService()->normalizeReferenceRole((string) ($image->getAttr('reference_role') ?? 'view')) === 'look'
            || (string) ($image->getAttr('view_type') ?? '') === 'look';
    }

    private function lookNeedsToapisIngest(Asset $asset): bool
    {
        if ((string) $asset->getAttr('type') !== 'character') {
            return false;
        }
        $series = $asset->series;
        if (!$series instanceof Series) {
            $series = Series::where('id', (int) $asset->getAttr('series_id'))
                ->where('user_id', $this->effectiveUserId())
                ->find();
        }
        if (!$series instanceof Series) {
            return false;
        }
        $visualStyle = $this->normalizeVisualStyle((string) ($series->getAttr('visual_style') ?? 'realistic'));

        return $this->assetLookService()->needsLookToapisAvatar($visualStyle);
    }

    private function clearLookToapisBinding(AssetImage $image): void
    {
        (new ToapisPrivateAvatarService())->clearLookBinding($image);
    }

    private function runLookToapisIngestJob(AssetImageJob $job, Asset $asset): array
    {
        $jobId = (int) $job->getAttr('id');
        $assetImageId = (int) ($job->getAttr('asset_image_id') ?? 0);
        $image = $assetImageId > 0
            ? AssetImage::where('id', $assetImageId)->where('asset_id', (int) $asset->getAttr('id'))->find()
            : null;
        $displayUrl = $image instanceof AssetImage ? trim((string) $image->getAttr('url')) : '';
        if (!$image instanceof AssetImage || !$this->isCharacterLookImage($image) || !$this->isUsableAssetImageUrl($displayUrl)) {
            $job->save([
                'status' => 'failed',
                'error_message' => '造型展示图不可用，无法人像入库',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->clearAssetCache();

            return $this->serializeImageJob($job);
        }

        try {
            $result = (new ToapisPrivateAvatarService())->ingestLook(
                $asset,
                $image,
                $displayUrl,
                $this->effectiveUserId(),
            );
            $job->save([
                'status' => 'success',
                'result_url' => (string) ($result['asset_url'] ?? ''),
                'error_message' => '',
                'finished_at' => date('Y-m-d H:i:s'),
                'retry_after' => null,
            ]);
        } catch (\Throwable $e) {
            $job->save([
                'status' => 'failed',
                'error_message' => mb_substr('人像入库失败：' . $e->getMessage(), 0, 2000),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            Log::warning('[AssetImageJob#' . $jobId . '] toapis ingest failed: ' . $e->getMessage());
        }
        $this->clearAssetCache();

        return $this->serializeImageJob($job);
    }

    private function queueLookToapisIngestJob(Asset $asset, AssetImage $image, string $displayUrl): void
    {
        $assetId = (int) $asset->getAttr('id');
        $assetImageId = (int) $image->getAttr('id');
        if ($assetId <= 0 || $assetImageId <= 0 || trim($displayUrl) === '') {
            return;
        }
        if (!$this->lookNeedsToapisIngest($asset)) {
            $this->clearLookToapisBinding($image);
            return;
        }

        $this->cancelPendingLookBackgroundJobs($assetId, $assetImageId);
        (new ToapisPrivateAvatarService())->markLookProcessing($image);

        AssetImageJob::create([
            'user_id' => $this->effectiveUserId(),
            'asset_id' => $assetId,
            'asset_image_id' => $assetImageId,
            'model_config_id' => 0,
            'status' => 'queued',
            'prompt' => '',
            'final_prompt' => '',
            'description' => ToapisPrivateAvatarService::INGEST_JOB_MARKER,
            'view_type' => 'look',
            'main_image_url' => $displayUrl,
            'result_url' => '',
            'error_message' => '',
            'attempts' => 0,
        ]);
    }

    private function cancelPendingLookBackgroundJobs(int $assetId, int $assetImageId): void
    {
        $rows = AssetImageJob::where('asset_id', $assetId)
            ->where('asset_image_id', $assetImageId)
            ->where('user_id', $this->effectiveUserId())
            ->whereIn('status', ImageJobStatus::active())
            ->select();
        foreach ($rows as $row) {
            if (!$row instanceof AssetImageJob) {
                continue;
            }
            if (!$this->isHiddenLookBackgroundJob((string) $row->getAttr('description'))) {
                continue;
            }
            $row->save([
                'status' => 'cancelled',
                'error_message' => '造型已更新，旧人像入库任务已取消',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function findActiveLookIngestJob(int $assetId, int $assetImageId): ?AssetImageJob
    {
        $rows = AssetImageJob::where('asset_id', $assetId)
            ->where('asset_image_id', $assetImageId)
            ->where('user_id', $this->effectiveUserId())
            ->whereIn('status', ImageJobStatus::active())
            ->order(['id' => 'desc'])
            ->select();
        foreach ($rows as $row) {
            if (!$row instanceof AssetImageJob) {
                continue;
            }
            if (ToapisPrivateAvatarService::isIngestJob((string) $row->getAttr('description'))) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<int, AssetImageJob>
     */
    private function pendingLookAvatarJobs(int $assetId): array
    {
        if ($assetId <= 0) {
            return [];
        }
        $jobs = [];
        $rows = AssetImageJob::where('asset_id', $assetId)
            ->whereIn('status', ImageJobStatus::active())
            ->order(['id' => 'desc'])
            ->select();
        foreach ($rows as $row) {
            if (!$row instanceof AssetImageJob) {
                continue;
            }
            if (!ToapisPrivateAvatarService::isIngestJob((string) $row->getAttr('description'))) {
                continue;
            }
            $imageId = (int) ($row->getAttr('asset_image_id') ?? 0);
            if ($imageId > 0 && !isset($jobs[$imageId])) {
                $jobs[$imageId] = $row;
            }
        }

        return $jobs;
    }

    /**
     * @return array<int, AssetImageJob>
     */
    private function latestLookIngestJobs(int $assetId): array
    {
        if ($assetId <= 0) {
            return [];
        }
        $jobs = [];
        $rows = AssetImageJob::where('asset_id', $assetId)
            ->whereLike('description', '%' . ToapisPrivateAvatarService::INGEST_JOB_MARKER . '%')
            ->order(['id' => 'desc'])
            ->select();
        foreach ($rows as $row) {
            if (!$row instanceof AssetImageJob) {
                continue;
            }
            $imageId = (int) ($row->getAttr('asset_image_id') ?? 0);
            if ($imageId > 0 && !isset($jobs[$imageId])) {
                $jobs[$imageId] = $row;
            }
        }

        return $jobs;
    }

    /**
     * 清理资产列表缓存。
     * 资产增删改和 AI 自动写入资产后调用，保证资产管理页面读取最新结果。
     */
    private function clearAssetCache(): void
    {
        RedisCache::bumpVersion('assets');
    }
}
