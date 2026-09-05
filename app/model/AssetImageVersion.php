<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AssetImageVersion extends Model
{
    protected $name = 'asset_image_versions';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'asset_id' => 'integer',
        'asset_image_id' => 'integer',
        'model_config_id' => 'integer',
        'job_id' => 'integer',
        'is_selected' => 'boolean',
        'ai_request_log_id' => 'integer',
    ];
}
