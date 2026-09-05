<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class PromptTemplate extends Model
{
    protected $name = 'prompt_templates';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['tags'];

    protected $jsonAssoc = true;

    protected $type = [
        'is_system' => 'integer',
        'sort' => 'integer',
    ];
}
