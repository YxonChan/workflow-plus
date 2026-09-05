<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class VideoJob extends Model
{
    protected $name = 'video_jobs';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['shot_data_json', 'assets_json', 'video_options_json', 'request_context_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'series_id' => 'integer',
        'episode_id' => 'integer',
        'storyboard_revision_id' => 'integer',
        'shot_id' => 'integer',
        'workflow_id' => 'integer',
        'workflow_run_id' => 'integer',
        'workflow_run_node_id' => 'integer',
        'model_config_id' => 'integer',
        'shot_index' => 'integer',
        'total_shots' => 'integer',
        'depends_on_job_id' => 'integer',
        'chain_shots' => 'boolean',
        'attempts' => 'integer',
        'ai_request_log_id' => 'integer',
    ];
}
