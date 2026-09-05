<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class WorkflowBundle extends Model
{
    protected $name = 'workflow_bundles';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'user_id' => 'integer',
        'series_workflow_id' => 'integer',
        'episode_workflow_id' => 'integer',
        'is_system' => 'integer',
        'sort' => 'integer',
    ];
}
