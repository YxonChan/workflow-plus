<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\MediaStorage;
use app\support\WorkerReferenceToken;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;

class UploadController extends BaseController
{
    private const MAX_UPLOAD_SIZE = 100 * 1024 * 1024;

    /**
     * 上传图片。
     * 接收文件并保存到本机 public/storage，返回可访问的 URL。
     */
    public function image()
    {
        $file = $this->request->file('file');
        if (!$file) {
            abort(422, '请选择上传文件');
        }

        $purpose = trim((string) $this->request->param('purpose', ''));
        $isWorkerReference = $purpose === 'worker_chat';
        $isQuickCreateReference = $purpose === 'quick_create';

        try {
            // 速创与作品统一本站存储，避免 ToAPIs 人像入库跨海拉取 Supabase 超时。
            $maxImageBytes = $isWorkerReference ? 10 * 1024 * 1024 : self::MAX_UPLOAD_SIZE;
            validate(['file' => [
                'fileSize' => $maxImageBytes,
                'fileExt'  => 'jpg,jpeg,png,gif,webp',
                'fileMime' => 'image/jpeg,image/png,image/gif,image/webp',
            ]])->check(['file' => $file]);

            $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : 'reference';
            $url = MediaStorage::uploadLocalImage(
                $file->getPathname(),
                $isWorkerReference ? 'uploads/worker-chat' : ($isQuickCreateReference ? 'uploads/quick-create/images' : 'uploads/assets'),
                $originalName,
                [
                    'user_id' => $this->currentUserId(),
                    'source' => $isWorkerReference ? 'worker-reference' : ($isQuickCreateReference ? 'quick-create-reference-image' : 'upload'),
                ]
            );

            $result = ['url' => $url];
            if ($isWorkerReference) {
                $result['reference_token'] = WorkerReferenceToken::issue($this->currentUserId(), $url, $originalName);
                $result['expires_in'] = 7200;
            }

            return successCode($result);
        } catch (ValidateException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable $e) {
            return errorCode([], mb_substr($e->getMessage(), 0, 500), 502);
        }
    }

    /**
     * 上传 MiniMax 多模态参考视频，并在落盘前校验官方格式、编码、时长、尺寸与帧率限制。
     */
    public function video()
    {
        $file = $this->request->file('file');
        if (!$file) {
            abort(422, '请选择上传视频');
        }

        $purpose = trim((string) $this->request->param('purpose', ''));
        $isQuickCreateReference = $purpose === 'quick_create';

        try {
            $maxVideoBytes = 50 * 1024 * 1024;
            validate(['file' => [
                'fileSize' => $maxVideoBytes,
                'fileExt' => 'mp4,mov',
                'fileMime' => 'video/mp4,video/quicktime,application/quicktime',
            ]])->check(['file' => $file]);

            $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : 'reference.mp4';
            $metadata = $this->probeMiniMaxReferenceVideo($file->getPathname());
            $url = MediaStorage::uploadLocalFile(
                $file->getPathname(),
                $isQuickCreateReference ? 'uploads/quick-create/videos' : 'uploads/video-references',
                $originalName,
                [
                    'user_id' => $this->currentUserId(),
                    'source' => $isQuickCreateReference ? 'quick-create-reference-video' : 'minimax-reference-video',
                ]
            );

            return successCode([
                'url' => $url,
                'duration_seconds' => $metadata['duration_seconds'],
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'fps' => $metadata['fps'],
                'video_codec' => $metadata['video_codec'],
                'audio_codec' => $metadata['audio_codec'],
            ]);
        } catch (ValidateException $e) {
            abort(422, $e->getMessage());
        } catch (HttpException | HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return errorCode([], mb_substr($e->getMessage(), 0, 500), 422);
        }
    }

    /** @return array{duration_seconds:float,width:int,height:int,fps:float,video_codec:string,audio_codec:string} */
    private function probeMiniMaxReferenceVideo(string $path): array
    {
        $command = [
            'ffprobe',
            '-v',
            'error',
            '-show_streams',
            '-show_format',
            '-of',
            'json',
            $path,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('未检测到 ffprobe，无法校验参考视频');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException('参考视频解析失败：' . mb_substr(trim((string) $stderr), 0, 300));
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('参考视频元数据无效');
        }
        $videoStream = null;
        $audioCodec = '';
        foreach ((array) ($decoded['streams'] ?? []) as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            if (($stream['codec_type'] ?? '') === 'video' && $videoStream === null) {
                $videoStream = $stream;
            }
            if (($stream['codec_type'] ?? '') === 'audio' && $audioCodec === '') {
                $audioCodec = strtolower(trim((string) ($stream['codec_name'] ?? '')));
            }
        }
        if (!is_array($videoStream)) {
            throw new \RuntimeException('参考文件没有可用视频轨道');
        }

        $videoCodec = strtolower(trim((string) ($videoStream['codec_name'] ?? '')));
        if (!in_array($videoCodec, ['h264', 'hevc'], true)) {
            throw new \RuntimeException('参考视频编码仅支持 H.264/AVC 或 H.265/HEVC');
        }
        if ($audioCodec !== '' && !in_array($audioCodec, ['aac', 'mp3'], true)) {
            throw new \RuntimeException('参考视频音轨仅支持 AAC 或 MP3');
        }

        $duration = (float) ($videoStream['duration'] ?? $decoded['format']['duration'] ?? 0);
        if ($duration < 2 || $duration > 15) {
            throw new \RuntimeException('单段参考视频时长必须在 2–15 秒之间');
        }
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);
        if ($width < 256 || $width > 5760 || $height < 256 || $height > 5760) {
            throw new \RuntimeException('参考视频宽高必须在 256–5760 像素之间');
        }
        $aspectRatio = $height > 0 ? $width / $height : 0;
        if ($aspectRatio < 0.4 || $aspectRatio > 2.5) {
            throw new \RuntimeException('参考视频宽高比必须在 0.4–2.5 之间');
        }

        $fps = $this->parseFrameRate((string) ($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? '0'));
        if ($fps < 23.976 || $fps > 60) {
            throw new \RuntimeException('参考视频帧率必须在 23.976–60 FPS 之间');
        }

        return [
            'duration_seconds' => round($duration, 3),
            'width' => $width,
            'height' => $height,
            'fps' => round($fps, 3),
            'video_codec' => $videoCodec,
            'audio_codec' => $audioCodec,
        ];
    }

    private function parseFrameRate(string $value): float
    {
        if (str_contains($value, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '0');
            return (float) $denominator !== 0.0 ? (float) $numerator / (float) $denominator : 0.0;
        }

        return (float) $value;
    }

    /**
     * 暂存小说文件。
     * 这里只上传并返回 file_token，不调用 AI；真正解析在创建并排队后的 worker 中执行。
     */
    public function novel()
    {
        $file = $this->request->file('file');
        if (!$file) {
            abort(422, '请选择 TXT 或 PDF 文件');
        }

        try {
            validate(['file' => [
                'fileSize' => self::MAX_UPLOAD_SIZE,
                'fileExt'  => 'txt,pdf',
            ]])->check(['file' => $file]);

            $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : 'novel';
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $token = bin2hex(random_bytes(16));
            $dir = app()->getRuntimePath() . 'novel_imports' . DIRECTORY_SEPARATOR . date('Ymd');
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                abort(500, '创建导入目录失败');
            }
            $storedName = $token . '.' . $extension;
            $storedPath = $dir . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file->getPathname(), $storedPath)) {
                if (!@copy($file->getPathname(), $storedPath)) {
                    abort(500, '保存导入文件失败');
                }
            }
            $meta = [
                'user_id' => $this->currentUserId(),
                'token' => $token,
                'filename' => $originalName,
                'extension' => $extension,
                'path' => $storedPath,
                'size' => filesize($storedPath) ?: 0,
                'create_time' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($dir . DIRECTORY_SEPARATOR . $token . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return successCode([
                'filename' => $originalName,
                'file_token' => $token,
                'extension' => $extension,
                'title' => '',
                'description' => '',
                'text' => '',
                'chars' => 0,
            ]);
        } catch (ValidateException $e) {
            abort(422, $e->getMessage());
        } catch (HttpException | HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return errorCode([], mb_substr($e->getMessage(), 0, 500), 502);
        }
    }
}
