<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class SeriesShare extends Model
{
    protected $name = 'series_shares';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = false;

    protected $type = [
        'series_id' => 'integer',
        'owner_user_id' => 'integer',
        'shared_with_user_id' => 'integer',
    ];
}
