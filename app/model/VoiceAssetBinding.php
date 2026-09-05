<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class VoiceAssetBinding extends Model
{
    protected $name = 'voice_asset_bindings';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['provider_payload_json'];

    protected $jsonAssoc = true;
}
