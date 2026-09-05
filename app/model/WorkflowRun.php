<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\HasMany;

class WorkflowRun extends Model
{
    protected $name = 'workflow_runs';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['payload_json', 'result_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'series_id' => 'integer',
        'workflow_id' => 'integer',
        'episode_workflow_id' => 'integer',
        'target_episode_count' => 'integer',
        'progress' => 'integer',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowRunNode::class, 'run_id', 'id')->order('sort', 'asc');
    }
}
