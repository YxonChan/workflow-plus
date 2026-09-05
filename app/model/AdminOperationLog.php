<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminOperationLog extends Model
{
    protected $name = 'admin_operation_logs';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['meta_json', 'before_json', 'after_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'operator_user_id' => 'integer',
        'target_id' => 'integer',
        'series_id' => 'integer',
        'episode_id' => 'integer',
        'workflow_run_id' => 'integer',
    ];
}
