<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use InvalidArgumentException;
use think\facade\Db;

class PromptAssistantReferences
{
    public static function applyUsage(array $result, array $references): array
    {
        $usage = $result['reference_usage'] ?? ($references === [] ? [] : null);
        if (!is_array($usage) || !array_is_list($usage) || count($usage) > count($references)) {
            throw new InvalidArgumentException('请明确选择本次使用的参考图，并为每张图填写 reference_usage 使用说明；不使用时明确返回空数组');
        }
        $selected = [];
        $mapping = [];
        $normalized = [];
        foreach ($usage as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('参考图使用说明格式不正确');
            PromptAssistantPolicy::keys($item, ['reference_index', 'instruction']);
            $index = PromptAssistantPolicy::id($item['reference_index'] ?? 0);
            if ($index < 1 || $index > count($references) || isset($mapping[$index])) {
                throw new InvalidArgumentException('参考图编号无效或重复，请只选择本次提供的图片');
            }
            $instruction = PromptAssistantPolicy::text($item['instruction'] ?? '', 1200, true);
            $mapping[$index] = count($selected) + 1;
            $selected[] = $references[$index - 1];
            $normalized[] = ['reference_index' => $mapping[$index], 'instruction' => $instruction];
        }
        // Compact image numbering together with attachments, including cross-references in instructions.
        $remap = static function (string $text) use ($mapping): string {
            return preg_replace_callback('/@图片\s*(\d+)/u', static function (array $match) use ($mapping): string {
                $index = (int) $match[1];
                if (!isset($mapping[$index])) throw new InvalidArgumentException('提示词引用了未选用的参考图，请修正编号或使用说明');
                return '@图片' . $mapping[$index];
            }, $text);
        };
        $result['prompt_body'] = $remap($result['prompt_body']);
        foreach ($result['shots'] as &$shot) $shot['prompt_body'] = $remap($shot['prompt_body']);
        unset($shot);
        $lines = [];
        foreach ($normalized as &$item) {
            $item['instruction'] = $remap($item['instruction']);
            $lines[] = '@图片' . $item['reference_index'] . '：' . $item['instruction'];
        }
        unset($item);
        if ($lines !== []) $result['prompt_body'] = implode("\n", $lines) . "\n\n" . $result['prompt_body'];
        $result['prompt_body'] = PromptAssistantPolicy::text($result['prompt_body'], 16000, true);
        $result['reference_usage'] = $normalized;
        $result['references'] = $selected;
        return $result;
    }

    public static function upload(int $userId, \think\file\UploadedFile $file): array
    {
        validate(['file' => ['fileSize' => 10 * 1024 * 1024, 'fileExt' => 'jpg,jpeg,png,webp',
            'fileMime' => 'image/jpeg,image/png,image/webp']])->check(['file' => $file]);
        $info = @getimagesize($file->getPathname());
        if ($info === false) {
            throw new InvalidArgumentException('请选择有效的 JPG、PNG 或 WebP 图片');
        }
        if ($info[0] * $info[1] > 12000000) {
            throw new InvalidArgumentException('图片分辨率过高，请缩小至 1200 万像素以内');
        }
        PromptAssistantService::ensureSchema();
        $name = mb_substr($file->getOriginalName(), 0, 200);
        $url = MediaStorage::uploadLocalImage($file->getPathname(), 'uploads/prompt-assistant', $name,
            ['user_id' => $userId, 'source' => 'prompt-reference']);
        $id = Db::name('prompt_assistant_references')->insertGetId(['user_id' => $userId, 'name' => $name,
            'url' => $url, 'create_time' => date('Y-m-d H:i:s')]);
        return ['id' => (int) $id, 'name' => $name, 'url' => $url, 'kind' => 'upload'];
    }

    public static function owned(int $userId, array $settings): array
    {
        $references = [];
        foreach ($settings['reference_ids'] as $id) {
            $row = Db::name('prompt_assistant_references')->where('id', $id)->where('user_id', $userId)->find();
            if (!$row) {
                throw new InvalidArgumentException('参考图不存在或不可访问，请重新上传');
            }
            $references[] = ['id' => (int) $row['id'], 'kind' => 'upload', 'name' => $row['name'], 'url' => $row['url']];
        }
        foreach ($settings['asset_ids'] as $assetId) {
            $asset = Asset::where('id', $assetId)->where('user_id', $userId)->find();
            if (!$asset) {
                throw new InvalidArgumentException('参考素材不存在或不可访问');
            }
            $image = $asset->images()->where('url', '<>', '')->find();
            if ($image) {
                $references[] = ['id' => (int) $image->id, 'kind' => 'asset', 'asset_id' => $assetId,
                    'asset_image_id' => (int) $image->id, 'type' => (string) $asset->type,
                    'name' => (string) $asset->name, 'url' => (string) $image->url];
            }
        }
        return $references;
    }

    public static function modelImages(array $references, int $maxPixels = 12000000): array
    {
        $images = [];
        foreach ($references as $reference) {
            $url = $reference['url'];
            $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: ''));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $localHosts = array_filter([strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),
                strtolower((string) parse_url((string) env('MEDIA_PUBLIC_BASE_URL', ''), PHP_URL_HOST))]);
            $isRemoteAsset = ($reference['kind'] ?? '') === 'asset' && $host !== '' && !in_array($host, $localHosts, true);
            if (str_starts_with($path, '/storage/') && !$isRemoteAsset) {
                $root = realpath(app()->getRootPath() . 'public/storage');
                $file = $root ? realpath($root . '/' . substr($path, strlen('/storage/'))) : false;
                if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)
                    || filesize($file) > 10 * 1024 * 1024) {
                    throw new InvalidArgumentException('参考图文件不可用或超过 10MB，请重新上传');
                }
                $info = @getimagesize($file);
                if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    throw new InvalidArgumentException('参考图格式不支持，请使用 JPG、PNG 或 WebP');
                }
                $images[] = self::inlinePreview($file, $info, $maxPixels);
            } elseif (preg_match('#^https://#i', $url)) {
                // Only stored, owner-checked asset URLs reach here; never fetch a user-supplied URL.
                $images[] = $url;
            } else {
                throw new InvalidArgumentException('参考图地址不可用，请重新上传');
            }
        }
        return $images;
    }

    private static function inlinePreview(string $file, array $info, int $maxPixels = 12000000): string
    {
        if ($info[0] * $info[1] > min(16000000, $maxPixels) || !function_exists('imagecreatefromstring')) {
            throw new InvalidArgumentException('图片无法解析，请缩小图片或联系管理员检查图像处理配置');
        }
        $source = @imagecreatefromstring(file_get_contents($file));
        if (!$source) throw new InvalidArgumentException('参考图无法读取，请重新上传');
        $scale = min(1, 1600 / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $preview = imagecreatetruecolor($width, $height);
        imagefill($preview, 0, 0, imagecolorallocate($preview, 255, 255, 255));
        imagecopyresampled($preview, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        imagedestroy($source);
        ob_start();
        try {
            imagejpeg($preview, null, 80);
            $binary = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($preview);
        }
        if (!is_string($binary) || $binary === '' || strlen($binary) > 3 * 1024 * 1024) {
            throw new InvalidArgumentException('参考图过大，请缩小后重新上传');
        }
        // Analysis uses a bounded preview; original files remain intact for generation.
        return 'data:image/jpeg;base64,' . base64_encode($binary);
    }
}
