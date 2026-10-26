<?php

declare(strict_types=1);

namespace app\command;

use app\controller\AssetController;
use app\controller\SeriesController;
use app\support\EcommercePolicy;
use app\support\EcommerceService;
use app\support\PendingImageTaskException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class EcommerceWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('ecommerce:worker')->setDescription('Process private ecommerce generation plans')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one item and exit')
            ->addOption('sleep', null, Option::VALUE_REQUIRED, 'Idle interval', '3');
    }

    protected function execute(Input $input, Output $output): int
    {
        EcommerceService::ensureSchema();
        do {
            try {
                $this->recover();
                $job = $this->claim();
                if ($job) {
                    $output->writeln('Ecommerce job #' . $job['id']);
                    $this->processJob($job);
                }
            } catch (\Throwable $e) {
                Log::error('[EcommerceWorker] ' . $e->getMessage());
                $output->writeln('[EcommerceWorker] ' . $e->getMessage());
                if ($input->getOption('once')) return 1;
            }
            if ($input->getOption('once')) return 0;
            sleep(max(1, (int) $input->getOption('sleep')));
        } while (true);
    }

    private function recover(): void
    {
        $stale = Db::name('ecommerce_jobs')->where('status', 'running')
            ->where('update_time', '<', date('Y-m-d H:i:s', time() - 1200))->select()->toArray();
        foreach ($stale as $row) {
            // The log/credit hook can finish before a worker dies without updating its job.
            if (empty($row['result_url'])) {
                $logs = Db::name('ai_request_logs')->where('user_id', $row['user_id'])->where('request_ok', 1)
                    ->where('create_time', '>=', $row['started_at'])->order('id', 'desc')->limit(300)->select()->toArray();
                foreach ($logs as $log) {
                    $context = EcommerceService::decode($log['context_json']);
                    if ((int) ($context['ecommerce_job_id'] ?? 0) === (int) $row['id']) {
                        $row['result_url'] = trim((string) $log['content_preview']);
                        $row['ai_request_log_id'] = (int) $log['id'];
                        break;
                    }
                }
            }
            $status = EcommercePolicy::recoveryStatus($row['provider_task_id'], (string) $row['result_url']);
            if ($status === 'success') (new EcommerceService())->registerMedia((int) $row['user_id'], (int) $row['project_id'], $row['result_url'], $row['title']);
            Db::name('ecommerce_jobs')->where('id', $row['id'])->where('status', 'running')
                ->where('update_time', $row['update_time'])->update(['status' => $status, 'result_url' => $row['result_url'],
                    'ai_request_log_id' => $row['ai_request_log_id'], 'error_message' => $status === 'unknown' ? '生成进程中断，提交结果待核对；不会自动再次提交' : '',
                    'update_time' => date('Y-m-d H:i:s')]);
            if ($status === 'unknown') Db::name('ecommerce_runs')->where('id', $row['run_id'])->update(['paused' => 1]);
        }
        Db::name('ecommerce_jobs')->where('status', 'queued')->where('provider_task_id', '<>', '')
            ->where('started_at', '<', date('Y-m-d H:i:s', time() - 3600))->update([
                'status' => 'unknown', 'error_message' => '上游任务超过一小时未完成，请核对原任务', 'update_time' => date('Y-m-d H:i:s')]);
    }

    private function claim(): ?array
    {
        if (!\app\support\GenerationQueueLock::acquire()) return null;
        try {
            return Db::transaction(function (): ?array {
                $rows = Db::name('ecommerce_jobs')->alias('j')->join('ecommerce_runs r', 'r.id = j.run_id')
                    ->where('j.status', 'queued')->whereRaw("(r.paused = 0 OR j.provider_task_id <> '')")
                    ->field('j.*')->order('j.update_time', 'asc')->order('j.id', 'asc')->limit(40)->lock(true)->select()->toArray();
                foreach ($rows as $job) {
                    if (Db::name('ecommerce_jobs')->where('user_id', $job['user_id'])->where('status', 'running')->count()) continue;
                    if (Db::name('ecommerce_jobs')->where('run_id', $job['run_id'])->where('id', '<', $job['id'])
                        ->whereIn('status', ['queued', 'running', 'unknown'])->count()) continue;
                    $running = Db::name('ecommerce_jobs')->where('mode', $job['mode'])
                        ->whereRaw("(status = 'running' OR (status = 'queued' AND provider_task_id <> ''))")->count();
                    $running += Db::name('quick_create_messages')->where('mode', $job['mode'])->where('status', 'running')->count();
                    $otherJobs = Db::name($job['mode'] === 'image' ? 'asset_image_jobs' : 'video_jobs');
                    $running += $job['mode'] === 'image' ? $otherJobs->whereIn('status', ['running', 'waiting'])->count() : $otherJobs->where('status', 'running')->count();
                    if ($job['provider_task_id'] === '' && $running >= ($job['mode'] === 'image' ? 2 : 20)) continue;
                    Db::name('ecommerce_jobs')->where('id', $job['id'])->where('status', 'queued')->update([
                        'status' => 'running', 'started_at' => $job['started_at'] ?: date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s')]);
                    return $job;
                }
                return null;
            });
        } finally {
            \app\support\GenerationQueueLock::release();
        }
    }

    private function processJob(array $job): void
    {
        $id = (int) $job['id'];
        $service = new EcommerceService();
        try {
            if ((string) $job['result_url'] !== '') {
                $service->registerMedia((int) $job['user_id'], (int) $job['project_id'], $job['result_url'], $job['title']);
                Db::name('ecommerce_jobs')->where('id', $id)->where('status', 'running')->update([
                    'status' => 'success', 'error_message' => '', 'update_time' => date('Y-m-d H:i:s')]);
                return;
            }
            $request = EcommerceService::decode($job['request_json']);
            $model = $service->model((int) $job['user_id'], $job['mode'], (int) $job['model_config_id']);
            if ((string) $model->model_id !== $request['model_id']) throw new \RuntimeException('模型配置已变更，请重新选择模型后生成');
            if (($request['model_fingerprint'] ?? '') !== hash('sha256', (string) $model->model_id . "\0" . (string) $model->endpoint)) {
                throw new \RuntimeException('模型通道已变更；已有任务请恢复原配置后核对，未提交任务请重新生成');
            }
            $images = $service->providerImages($job, $request);
            $context = ['source' => 'ecommerce_' . $job['mode'], 'user_id' => (int) $job['user_id'], 'ecommerce_job_id' => $id,
                'provider_task_id' => $job['provider_task_id'], 'result_endpoint' => (string) $job['result_endpoint'],
                'idempotency_key' => 'ecommerce-video-' . $id];
            if ($job['mode'] === 'image') {
                $imageOptions = $request['options'];
                if (!\app\support\ImageGenerationOptions::isOfficial25($request['model_id'])) $imageOptions['aspect_ratio'] = $imageOptions['size'];
                $model->setAttr('options', array_merge((array) $model->getAttr('options'), $imageOptions));
                $controller = new AssetController(app());
                $controller->setRuntimeUserId((int) $job['user_id']);
                $url = $controller->callImageGeneration($model, $request['prompt'], $context + [
                    'main_image_urls' => $images, 'allow_pending_yield' => true, 'inline_poll_attempts' => 1]);
            } else {
                $controller = new SeriesController(app());
                $controller->setRuntimeUserId((int) $job['user_id']);
                $assets = array_map(static fn (string $url): array => ['type' => 'prop', 'name' => '参考图', 'images' => [['url' => $url, 'view_type' => 'reference']]], $images);
                $meta = [];
                foreach ($images as $index => $image) $meta['image_' . ($index + 1)] = ['url' => $image, 'role' => 'reference_image'];
                $url = $controller->callWorkflowVideoGeneration($model, $request['prompt'], $images[0] ?? '',
                    (string) $request['options']['duration'], $assets, $context + ['video_options' => $request['options'],
                        'direct_prompt_reference_meta' => $meta], static function () use ($id): void {
                            Db::name('ecommerce_jobs')->where('id', $id)->where('status', 'running')->update(['update_time' => date('Y-m-d H:i:s')]);
                        });
            }
            $service->registerMedia((int) $job['user_id'], (int) $job['project_id'], $url, $job['title']);
            Db::name('ecommerce_jobs')->where('id', $id)->where('status', 'running')->update([
                'status' => 'success', 'result_url' => $url, 'ai_request_log_id' => $controller->lastAiRequestLogId() ?: null,
                'error_message' => '', 'update_time' => date('Y-m-d H:i:s')]);
        } catch (PendingImageTaskException $e) {
            Db::name('ecommerce_jobs')->where('id', $id)->where('status', 'running')->update([
                'status' => 'queued', 'provider_task_id' => $e->taskId(), 'update_time' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            $current = Db::name('ecommerce_jobs')->where('id', $id)->find();
            $definitiveFailure = preg_match('/^AI (?:图片|视频)任务失败：/u', $e->getMessage()) === 1;
            $status = !$definitiveFailure && (!empty($current['provider_task_id']) || preg_match('/超时|timeout|非 JSON|未返回|请求失败|下载|curl|中断/i', $e->getMessage())) ? 'unknown' : 'failed';
            Db::name('ecommerce_jobs')->where('id', $id)->where('status', 'running')->update([
                'status' => $status, 'error_message' => mb_substr($e->getMessage(), 0, 1800), 'update_time' => date('Y-m-d H:i:s')]);
            Db::name('ecommerce_runs')->where('id', $job['run_id'])->update(['paused' => 1]);
        }
    }
}
