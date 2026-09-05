<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class CreditLedger extends Model
{
    protected $name = 'credit_ledger';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = false;

    protected $json = ['meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'amount' => 'float',
        'balance_after' => 'float',
        'model_config_id' => 'integer',
        'ref_id' => 'integer',
        'operator_id' => 'integer',
    ];
}
