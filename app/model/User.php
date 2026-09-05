<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class User extends Model
{
    protected $name = 'users';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'status' => 'integer',
    ];
}
