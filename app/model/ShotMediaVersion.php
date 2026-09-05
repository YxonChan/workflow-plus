<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ShotMediaVersion extends Model
{
    protected $name = 'shot_media_versions';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'series_id' => 'integer',
        'episode_id' => 'integer',
        'storyboard_revision_id' => 'integer',
        'shot_id' => 'integer',
        'workflow_run_node_id' => 'integer',
        'video_job_id' => 'integer',
        'parent_version_id' => 'integer',
        'model_config_id' => 'integer',
        'is_selected' => 'boolean',
        'orphaned' => 'boolean',
        'ai_request_log_id' => 'integer',
        'shot_key' => 'string',
    ];
}
