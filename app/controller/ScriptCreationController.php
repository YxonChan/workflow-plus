<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\ScriptExternalScoringService;
use app\support\ScriptCreationService;
use app\support\ScriptPdfExporter;

class ScriptCreationController extends BaseController
{
    public function configs()
    {
        $this->requireNonAdmin();
        return successCode(['configs' => ScriptCreationService::configs($this->currentUserId())]);
    }

    public function saveConfigs()
    {
        $this->requireNonAdmin();
        return successCode([
            'configs' => ScriptCreationService::saveConfigs($this->request->param(), $this->currentUserId()),
        ]);
    }

    public function models()
    {
        $this->requireNonAdmin();
        return successCode(['models' => ScriptCreationService::textModels($this->currentUserId())]);
    }

    public function index()
    {
        $this->requireNonAdmin();
        return successCode(['projects' => ScriptCreationService::projects($this->currentUserId())]);
    }

    public function detail()
    {
        $this->requireNonAdmin();
        $projectId = (int) $this->request->param('id', 0);
        return successCode(['project' => ScriptCreationService::detail($projectId, $this->currentUserId())]);
    }

    public function pdf()
    {
        $this->requireNonAdmin();
        $projectId = (int) $this->request->param('id', 0);
        $project = ScriptCreationService::detail($projectId, $this->currentUserId());
        $content = trim((string) ($project['final_content'] ?? ''));
        if ((string) ($project['status'] ?? '') !== 'completed' || $content === '') {
            abort(409, '剧本完成并生成最终内容后才能导出 PDF');
        }

        $user = $this->currentUser();
        $author = trim((string) ($user->getAttr('display_name') ?: $user->getAttr('username')));
        $pdf = (new ScriptPdfExporter())->export($project, $author);

        return successCode([
            'filename' => $this->buildExportFilename((string) $project['title'], $projectId, 'pdf'),
            'mime' => 'application/pdf',
            'base64' => base64_encode($pdf),
        ]);
    }

    public function txt()
    {
        $this->requireNonAdmin();
        $projectId = (int) $this->request->param('id', 0);
        $project = ScriptCreationService::detail($projectId, $this->currentUserId());
        $content = trim((string) ($project['final_content'] ?? ''));
        if ((string) ($project['status'] ?? '') !== 'completed' || $content === '') {
            abort(409, '剧本完成并生成最终内容后才能导出 TXT');
        }

        $content = preg_replace('/^```(?:text|plaintext|markdown)?\s*|\s*```$/iu', '', $content) ?? $content;
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $bytes = "\xEF\xBB\xBF" . $content . "\n";

        return successCode([
            'filename' => $this->buildExportFilename((string) $project['title'], $projectId, 'txt'),
            'mime' => 'text/plain; charset=utf-8',
            'base64' => base64_encode($bytes),
        ]);
    }

    public function externalAccess()
    {
        $this->requireNonAdmin();
        return successCode(['access' => ScriptExternalScoringService::accessStatus($this->currentUserId())]);
    }

    public function rotateExternalAccess()
    {
        $this->requireNonAdmin();
        $name = (string) $this->request->param('name', 'Hermes Agent');
        return successCode([
            'access' => ScriptExternalScoringService::rotateAccessToken($this->currentUserId(), $name),
        ]);
    }

    public function revokeExternalAccess()
    {
        $this->requireNonAdmin();
        return successCode([
            'access' => ScriptExternalScoringService::revokeAccess($this->currentUserId()),
        ]);
    }

    public function create()
    {
        $this->requireNonAdmin();
        $projectId = ScriptCreationService::create($this->request->param(), $this->currentUserId());
        return successCode(['project' => ScriptCreationService::detail($projectId, $this->currentUserId())], 'success', 201);
    }

    public function update()
    {
        $this->requireNonAdmin();
        $payload = $this->request->param();
        $projectId = (int) ($payload['id'] ?? 0);
        ScriptCreationService::updateProject($projectId, $payload, $this->currentUserId());
        return successCode(['project' => ScriptCreationService::detail($projectId, $this->currentUserId())]);
    }

    public function start()
    {
        return $this->projectAction('start');
    }

    public function pause()
    {
        return $this->projectAction('pause');
    }

    public function resume()
    {
        return $this->projectAction('resume');
    }

    public function retry()
    {
        return $this->projectAction('retry');
    }

    public function cancel()
    {
        return $this->projectAction('cancel');
    }

    private function projectAction(string $action)
    {
        $this->requireNonAdmin();
        $projectId = (int) $this->request->param('id', 0);
        ScriptCreationService::{$action}($projectId, $this->currentUserId());
        return successCode(['project' => ScriptCreationService::detail($projectId, $this->currentUserId())]);
    }

    private function buildExportFilename(string $title, int $projectId, string $extension): string
    {
        $title = preg_replace('/[<>:"\/\\|?*\x00-\x1F]+/u', '-', trim($title)) ?? '';
        $title = trim($title, " .-");
        if ($title === '') {
            $title = 'script-' . $projectId;
        }
        $extension = strtolower(trim($extension));
        if (!in_array($extension, ['pdf', 'txt'], true)) {
            $extension = 'txt';
        }
        return mb_substr($title, 0, 100) . '-' . date('Ymd-His') . '.' . $extension;
    }
}
