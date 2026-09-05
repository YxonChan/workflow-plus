<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\BelongsTo;

class AssetImage extends Model
{
    protected $name = 'asset_images';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id', 'id');
    }
}

