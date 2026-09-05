<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class EpisodeWorkflowNodeState extends Model
{
    protected $name = 'episode_workflow_node_states';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['input_json', 'output_json', 'upstream_snapshot_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'series_id' => 'integer',
        'episode_id' => 'integer',
        'workflow_id' => 'integer',
        'workflow_run_id' => 'integer',
        'workflow_run_node_id' => 'integer',
        'sort' => 'integer',
        'duration_ms' => 'integer',
    ];
}
