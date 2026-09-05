<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class VoiceAsset extends Model
{
    protected $name = 'voice_assets';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['provider_meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'asset_id' => 'integer',
        'file_size' => 'integer',
        'duration_ms' => 'integer',
        'rights_confirmed' => 'integer',
    ];
}
