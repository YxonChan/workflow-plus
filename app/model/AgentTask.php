<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AgentTask extends Model
{
    protected $name = 'agent_tasks';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['request_json', 'review_payload_json', 'result_json', 'meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'series_id' => 'integer',
        'series_workflow_id' => 'integer',
        'episode_workflow_id' => 'integer',
        'series_workflow_run_id' => 'integer',
        'progress' => 'integer',
        'episode_count' => 'integer',
    ];
}
