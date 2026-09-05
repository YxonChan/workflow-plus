<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\BelongsTo;
use think\model\relation\HasMany;

class Episode extends Model
{
    protected $name = 'episodes';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'user_id' => 'integer',
        'series_id' => 'integer',
        'number' => 'integer',
        'workflow_id' => 'integer',
        'current_storyboard_revision_id' => 'integer',
        'promo_segment_count' => 'integer',
    ];

    /**
     * @return BelongsTo
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_id', 'id');
    }

    /**
     * @return BelongsTo
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id', 'id');
    }

    /**
     * @return HasMany
     */
    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class, 'episode_id', 'id')->order('index', 'asc');
    }

    /**
     * @return BelongsTo
     */
    public function currentStoryboardRevision(): BelongsTo
    {
        return $this->belongsTo(StoryboardRevision::class, 'current_storyboard_revision_id', 'id');
    }
}
