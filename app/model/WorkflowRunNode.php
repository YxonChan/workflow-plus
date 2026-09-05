<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\BelongsTo;

class WorkflowRunNode extends Model
{
    protected $name = 'workflow_run_nodes';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['input_json', 'output_json', 'depends_on_json', 'request_payload_json', 'ai_meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'run_id' => 'integer',
        'sort' => 'integer',
        'duration_ms' => 'integer',
        'ai_request_log_id' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id', 'id');
    }
}
