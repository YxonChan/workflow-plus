<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class Workflow extends Model
{
    protected $name = 'workflows';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['graph', 'viewport'];

    protected $jsonAssoc = true;

    protected $type = [
        'is_default' => 'integer',
        'scope' => 'string',
    ];
}
