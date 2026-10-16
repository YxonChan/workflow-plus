<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\PromptAssistantPolicy;
use app\support\PromptAssistantService;

class PromptAssistantController extends BaseController
{
    private function service(): PromptAssistantService
    {
        $this->requireNonAdmin();
        return new PromptAssistantService();
    }

    private function payload(array $allowed): array
    {
        $payload = $this->request->post();
        PromptAssistantPolicy::keys($payload, $allowed);
        return $payload;
    }

    public function index()
    {
        return successCode($this->service()->sessions($this->currentUserId()));
    }

    public function create()
    {
        $service = $this->service();
        $payload = $this->payload(['settings']);
        return successCode($service->create($this->currentUserId(), (array) ($payload['settings'] ?? [])), 'success', 201);
    }

    public function detail()
    {
        $service = $this->service();
        $payload = $this->payload(['id']);
        return successCode($service->detail($this->currentUserId(), PromptAssistantPolicy::id($payload['id'] ?? 0)));
    }

    public function chat()
    {
        $service = $this->service();
        $payload = $this->payload(['id', 'message', 'request_id', 'settings']);
        return successCode($service->turn($this->currentUserId(), PromptAssistantPolicy::id($payload['id'] ?? 0),
            PromptAssistantPolicy::text($payload['message'] ?? '', 6000, true),
            PromptAssistantPolicy::text($payload['request_id'] ?? '', 64, true), (array) ($payload['settings'] ?? [])));
    }

    public function uploadReference()
    {
        $this->requireNonAdmin();
        $file = $this->request->file('file');
        if (!$file instanceof \think\file\UploadedFile) {
            abort(422, '请选择上传图片');
        }
        return successCode(\app\support\PromptAssistantReferences::upload($this->currentUserId(), $file), 'success', 201);
    }

    public function delete()
    {
        $service = $this->service();
        $payload = $this->payload(['id']);
        $service->deleteSession($this->currentUserId(), PromptAssistantPolicy::id($payload['id'] ?? 0));
        return successCode();
    }

    public function saveResult()
    {
        $service = $this->service();
        $payload = $this->payload(['id', 'result_id', 'patch']);
        return successCode($service->saveResult($this->currentUserId(), PromptAssistantPolicy::id($payload['id'] ?? 0),
            PromptAssistantPolicy::id($payload['result_id'] ?? 0), (array) ($payload['patch'] ?? [])));
    }

    public function skills()
    {
        $skills = $this->service()->skills($this->currentUserId());
        return successCode(array_map(static fn (array $skill): array => array_diff_key($skill, array_flip(['instructions'])), $skills));
    }

    public function skillDetail()
    {
        $service = $this->service();
        $payload = $this->payload(['id']);
        return successCode($service->skill($this->currentUserId(), PromptAssistantPolicy::text($payload['id'] ?? '', 80, true)));
    }

    public function validateSkill()
    {
        $service = $this->service();
        $payload = $this->payload(['id', 'text_model_id']);
        @set_time_limit(620);
        ignore_user_abort(true);
        return successCode($service->validateSkill($this->currentUserId(), PromptAssistantPolicy::text($payload['id'] ?? '', 80, true),
            PromptAssistantPolicy::id($payload['text_model_id'] ?? 0)));
    }

    public function deleteSkill()
    {
        $service = $this->service();
        $payload = $this->payload(['id']);
        $service->deleteSkill($this->currentUserId(), PromptAssistantPolicy::text($payload['id'] ?? '', 80, true));
        return successCode();
    }
}
