<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\AgentTask;
use app\support\AgentTaskService;
use think\exception\ValidateException;

class AgentTaskController extends BaseController
{
    private const MAX_UPLOAD_SIZE = 100 * 1024 * 1024;

    public function create()
    {
        $this->requireNonAdmin();
        $task = (new AgentTaskService())->createFromPayload($this->payload(), $this->currentUserId(), $this->app);
        $task = (new AgentTaskService())->refresh($task, $this->app);

        return successCode((new AgentTaskService())->serialize($task), 'success', 201);
    }

    public function uploadScript()
    {
        $this->requireNonAdmin();
        $file = $this->request->file('file');
        if (!$file) {
            abort(422, '请选择 TXT 或 PDF 文件');
        }

        try {
            validate(['file' => [
                'fileSize' => self::MAX_UPLOAD_SIZE,
                'fileExt' => 'txt,pdf',
            ]])->check(['file' => $file]);

            $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : 'script';
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $token = bin2hex(random_bytes(16));
            $dir = app()->getRuntimePath() . 'novel_imports' . DIRECTORY_SEPARATOR . date('Ymd');
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                abort(500, '创建导入目录失败');
            }
            $storedPath = $dir . DIRECTORY_SEPARATOR . $token . '.' . $extension;
            if (!move_uploaded_file($file->getPathname(), $storedPath) && !@copy($file->getPathname(), $storedPath)) {
                abort(500, '保存导入文件失败');
            }

            $meta = [
                'user_id' => $this->currentUserId(),
                'token' => $token,
                'filename' => $originalName,
                'extension' => $extension,
                'path' => $storedPath,
                'size' => filesize($storedPath) ?: 0,
                'source' => 'agent',
                'create_time' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($dir . DIRECTORY_SEPARATOR . $token . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return successCode([
                'filename' => $originalName,
                'file_token' => $token,
                'extension' => $extension,
                'size' => $meta['size'],
            ], 'success', 201);
        } catch (ValidateException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function detail()
    {
        $this->requireNonAdmin();
        $task = $this->findTask();
        $service = new AgentTaskService();
        $task = $service->refresh($task, $this->app);

        return successCode($service->serialize($task));
    }

    public function approve()
    {
        $this->requireNonAdmin();
        $payload = $this->payload();
        $task = $this->findTask($payload);
        $service = new AgentTaskService();
        $task = $service->approve($task, trim((string) ($payload['checkpoint'] ?? '')), $payload, $this->app);

        return successCode($service->serialize($task));
    }

    public function resume()
    {
        $this->requireNonAdmin();
        $payload = $this->payload();
        $task = $this->findTask($payload);
        $service = new AgentTaskService();
        $task = $service->resume($task, $payload, $this->app);

        return successCode($service->serialize($task));
    }

    public function cancel()
    {
        $this->requireNonAdmin();
        $task = $this->findTask($this->payload());
        $service = new AgentTaskService();
        $task = $service->cancel($task);

        return successCode($service->serialize($task));
    }

    private function findTask(?array $payload = null): AgentTask
    {
        (new AgentTaskService())->ensureSchema();
        $payload ??= $this->payload();
        $id = (int) ($payload['id'] ?? 0);
        $taskNo = trim((string) ($payload['task_no'] ?? ''));
        $query = AgentTask::where('user_id', $this->currentUserId());
        if ($id > 0) {
            $query->where('id', $id);
        } elseif ($taskNo !== '') {
            $query->where('task_no', $taskNo);
        } else {
            abort(422, '任务 id 或 task_no 不能为空');
        }

        $task = $query->find();
        if (!$task instanceof AgentTask) {
            abort(404, 'Agent 任务不存在');
        }
        return $task;
    }

    private function payload(): array
    {
        $payload = $this->request->param();
        if ($payload !== []) {
            return $payload;
        }
        $raw = (string) $this->request->getContent();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
