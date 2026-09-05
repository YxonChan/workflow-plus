<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AssetShare extends Model
{
    protected $name = 'asset_shares';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = false;

    protected $type = [
        'asset_id' => 'integer',
        'owner_user_id' => 'integer',
        'shared_with_user_id' => 'integer',
    ];
}
