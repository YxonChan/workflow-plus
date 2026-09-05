<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\HasMany;

class Series extends Model
{
    protected $name = 'series';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'series_workflow_id' => 'integer',
        'visual_style' => 'string',
        'visual_style_variant' => 'string',
        'region' => 'string',
    ];

    /**
     * @return HasMany
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class, 'series_id', 'id')->order('number', 'asc');
    }
}
