<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class QuickCreateFaceVerification extends Model
{
    protected $name = 'quick_create_face_verifications';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $json = ['asset_json'];
    protected $jsonAssoc = true;
    protected $type = [
        'user_id' => 'integer',
        'asset_id' => 'integer',
        'asset_image_id' => 'integer',
        'attempts' => 'integer',
    ];
}
