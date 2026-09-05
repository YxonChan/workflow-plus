<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\BelongsTo;
use think\model\relation\HasMany;

class StoryboardRevision extends Model
{
    protected $name = 'storyboard_revisions';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'user_id' => 'integer',
        'series_id' => 'integer',
        'episode_id' => 'integer',
        'workflow_run_id' => 'integer',
        'workflow_run_node_id' => 'integer',
        'shot_count' => 'integer',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'episode_id', 'id');
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class, 'storyboard_revision_id', 'id')->order('index', 'asc');
    }
}
