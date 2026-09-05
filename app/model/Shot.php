<?php

declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\relation\BelongsTo;

class Shot extends Model
{
    protected $name = 'shots';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $type = [
        'user_id' => 'integer',
        'episode_id' => 'integer',
        'storyboard_revision_id' => 'integer',
        'index' => 'integer',
        'shot_key' => 'string',
    ];

    /**
     * @return BelongsTo
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'episode_id', 'id');
    }

    /**
     * @return BelongsTo
     */
    public function storyboardRevision(): BelongsTo
    {
        return $this->belongsTo(StoryboardRevision::class, 'storyboard_revision_id', 'id');
    }
}
