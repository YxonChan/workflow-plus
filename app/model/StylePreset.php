<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class StylePreset extends Model
{
    protected $name = 'style_presets';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['parameters', 'visual_styles'];

    protected $jsonAssoc = true;

    protected $type = [
        'is_system' => 'integer',
        'sort' => 'integer',
    ];
}
