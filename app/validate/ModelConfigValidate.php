<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class ModelConfigValidate extends Validate
{
    protected $rule = [
        'type' => 'require|in:text,image,video,voice',
        'name' => 'require|max:100',
        'model_id' => 'require|max:120',
        'endpoint' => 'require|url|max:255',
        'api_key' => 'max:255',
        'options' => 'array',
    ];

    protected $message = [
        'type.require' => '请选择模型类型',
        'type.in' => '模型类型不支持',
        'name.require' => '请输入模型名称',
        'name.max' => '模型名称不能超过 100 个字符',
        'model_id.require' => '请输入模型 ID',
        'model_id.max' => '模型 ID 不能超过 120 个字符',
        'endpoint.require' => '请输入接口地址',
        'endpoint.url' => '接口地址格式不正确',
        'endpoint.max' => '接口地址不能超过 255 个字符',
        'api_key.max' => 'API Key 不能超过 255 个字符',
        'options.array' => '高级配置必须是 JSON 对象',
    ];
}
