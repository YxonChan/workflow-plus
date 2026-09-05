<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Episode;
use app\model\ModelConfig;
use app\support\CallSheetGenerator;
use app\support\ModelConfigResolver;

class CallSheetController extends BaseController
{
    public function generate()
    {
        $payload = $this->request->param();
        $script = $this->resolveScript($payload);
        $model = $this->resolveTextModel((int) ($payload['model_config_id'] ?? 0));
        if (!$model instanceof ModelConfig) {
            abort(422, '未找到可用文本模型，请联系管理员配置');
        }

        $generator = new CallSheetGenerator();
        $html = $generator->generateHtml($model, $script, [
            'source' => 'call_sheet_api',
            'user_id' => $this->currentUserId(),
            'episode_id' => (int) ($payload['episode_id'] ?? 0),
        ], (string) ($payload['instruction'] ?? ''));

        return successCode([
            'html' => $html,
            'filename' => $this->buildFilename($payload, 'html'),
        ]);
    }

    public function pdf()
    {
        $payload = $this->request->param();
        $html = trim((string) ($payload['html'] ?? ''));
        if ($html === '') {
            abort(422, 'HTML 内容不能为空');
        }

        $generator = new CallSheetGenerator();
        $pdf = $generator->renderPdf($html);

        return successCode([
            'filename' => $this->buildFilename($payload, 'pdf'),
            'mime' => 'application/pdf',
            'base64' => base64_encode($pdf),
        ]);
    }

    private function resolveScript(array $payload): string
    {
        $script = trim((string) ($payload['script'] ?? ''));
        if ($script !== '') {
            return $script;
        }

        $episodeId = (int) ($payload['episode_id'] ?? 0);
        if ($episodeId > 0) {
            $episode = Episode::where('id', $episodeId)->where('user_id', $this->currentUserId())->find();
            if ($episode instanceof Episode) {
                $script = trim((string) $episode->getAttr('plot_input'));
            }
        }

        if ($script === '') {
            abort(422, '请提供剧本正文或 episode_id');
        }

        return $script;
    }

    private function resolveTextModel(int $modelConfigId): ?ModelConfig
    {
        $model = ModelConfigResolver::resolve('text', $this->currentUserId(), $modelConfigId);
        if ($modelConfigId > 0 && !$model instanceof ModelConfig) {
            abort(422, '选择的文本模型不存在或类型不正确');
        }

        return $model;
    }

    private function buildFilename(array $payload, string $extension): string
    {
        $episodeId = (int) ($payload['episode_id'] ?? 0);
        $name = $episodeId > 0 ? "episode-{$episodeId}-call-sheet" : 'call-sheet';

        return $name . '-' . date('Ymd-His') . '.' . $extension;
    }
}
