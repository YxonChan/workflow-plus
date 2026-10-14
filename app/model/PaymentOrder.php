<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class PaymentOrder extends Model
{
    protected $name = 'payment_orders';

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['meta_json'];

    protected $jsonAssoc = true;

    protected $type = [
        'user_id' => 'integer',
        'amount_fen' => 'integer',
        'credit_amount' => 'float',
    ];
}
