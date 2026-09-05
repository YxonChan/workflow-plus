<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\BelongsTo;
use think\model\relation\HasMany;

class Asset extends Model
{
    protected $name = 'assets';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['tags'];

    protected $jsonAssoc = true;

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_id', 'id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AssetImage::class, 'asset_id', 'id')->order(['sort' => 'asc', 'id' => 'asc']);
    }
}

