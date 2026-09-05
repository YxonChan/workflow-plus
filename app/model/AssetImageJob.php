<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AssetImageJob extends Model
{
    protected $name = 'asset_image_jobs';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'asset_id' => 'integer',
        'asset_image_id' => 'integer',
        'model_config_id' => 'integer',
        'attempts' => 'integer',
    ];
}
