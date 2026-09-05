<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ModelConfig extends Model
{
    protected $name = 'model_configs';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['options'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'is_default' => 'integer',
        'enabled' => 'integer',
        'sort' => 'integer',
    ];
}
