<?php

declare(strict_types=1);

namespace app\model;

use app\support\CreditService;
use think\Model;

class AiRequestLog extends Model
{
    protected $name = 'ai_request_logs';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = false;

    protected $json = ['usage_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'model_config_id' => 'integer',
        'workflow_run_id' => 'integer',
        'workflow_run_node_id' => 'integer',
        'http_status' => 'integer',
        'curl_errno' => 'integer',
        'request_ok' => 'integer',
        'duration_ms' => 'integer',
        'max_tokens' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'credits_charged' => 'float',
    ];

    protected static function onBeforeInsert(self $model): void
    {
        self::fillTokenUsage($model);
    }

    protected static function onBeforeUpdate(self $model): void
    {
        self::fillTokenUsage($model);
    }

    protected static function onAfterInsert(self $model): void
    {
        CreditService::chargeFromAiRequestLog($model);
    }

    private static function fillTokenUsage(self $model): void
    {
        $usage = $model->getAttr('usage_json');
        if (is_string($usage)) {
            $decoded = json_decode($usage, true);
            $usage = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($usage)) {
            $usage = [];
        }

        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));

        $model->setAttr('prompt_tokens', max(0, $prompt));
        $model->setAttr('completion_tokens', max(0, $completion));
        $model->setAttr('total_tokens', max(0, $total));
    }
}
