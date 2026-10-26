<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\EcommerceService;

class EcommerceController extends BaseController
{
    private function service(): EcommerceService
    {
        $this->requireNonAdmin();
        EcommerceService::ensureSchema();
        return new EcommerceService();
    }

    public function index() { return successCode($this->service()->projects($this->currentUserId())); }
    public function create() { return successCode($this->service()->create($this->currentUserId(), (array) $this->request->post('state', []), (bool) $this->request->post('import', false))); }
    public function detail() { return successCode($this->service()->detail($this->currentUserId(), (int) $this->request->post('id'))); }
    public function save() { return successCode($this->service()->save($this->currentUserId(), (int) $this->request->post('id'), (int) $this->request->post('revision'), (array) $this->request->post('state', []))); }
    public function delete() { $this->service()->delete($this->currentUserId(), (int) $this->request->post('id')); return successCode(); }
    public function upload()
    {
        $service = $this->service();
        $file = $this->request->file('file');
        if (!$file instanceof \think\file\UploadedFile) abort(422, '请选择图片');
        return successCode($service->upload($this->currentUserId(), (int) $this->request->post('id'), $file));
    }
    public function useAsset() { return successCode($this->service()->useAsset($this->currentUserId(), (int) $this->request->post('id'), (int) $this->request->post('asset_id'), (int) $this->request->post('image_id'))); }
    public function quote() { return successCode($this->service()->plan($this->currentUserId(), (int) $this->request->post('id'), (array) $this->request->post('items', []))); }
    public function expandStory()
    {
        $this->service();
        return successCode((new \app\support\EcommerceStoryService())->expand($this->currentUserId(),
            (int) $this->request->post('id'), (string) $this->request->post('request_key', ''), (array) $this->request->post('input', [])));
    }
    public function draftCopy()
    {
        $this->service();
        return successCode((new \app\support\EcommerceCopyService())->draft($this->currentUserId(),
            (int) $this->request->post('id'), (string) $this->request->post('request_key', '')));
    }
    public function brief()
    {
        $this->service();
        return successCode((new \app\support\EcommerceBriefService())->detail($this->currentUserId(), (int) $this->request->post('id')));
    }
    public function briefTurn()
    {
        $this->service();
        return successCode((new \app\support\EcommerceBriefService())->turn($this->currentUserId(), (int) $this->request->post('id'),
            (string) $this->request->post('request_key', ''), (int) $this->request->post('version', 0),
            (string) $this->request->post('action', ''), (array) $this->request->post('input', [])));
    }
    public function generate() { return successCode($this->service()->generate($this->currentUserId(), (int) $this->request->post('id'), (string) $this->request->post('request_key', ''), (array) $this->request->post('items', []))); }
    public function control() { return successCode($this->service()->control($this->currentUserId(), (int) $this->request->post('run_id'), (string) $this->request->post('action'))); }
    public function checkOriginal() { return successCode($this->service()->checkOriginal($this->currentUserId(), (int) $this->request->post('job_id'))); }
    public function saveAsset() { return successCode($this->service()->saveAsset($this->currentUserId(), (int) $this->request->post('job_id'), (string) $this->request->post('image_url', ''))); }
}
