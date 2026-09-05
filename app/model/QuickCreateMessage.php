<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class QuickCreateMessage extends Model
{
    protected $name = 'quick_create_messages';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['asset_refs_json', 'options_json', 'result_urls_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'model_config_id' => 'integer',
        'parent_message_id' => 'integer',
        'parent_result_index' => 'integer',
        'ai_request_log_id' => 'integer',
        'attempts' => 'integer',
    ];
}
