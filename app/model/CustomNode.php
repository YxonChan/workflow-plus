<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class CustomNode extends Model
{
    protected $name = 'custom_nodes';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'is_fixed' => 'integer',
        'scope' => 'string',
    ];
}
