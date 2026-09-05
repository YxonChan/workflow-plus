<?php

// 应用公共文件

use app\support\MessageLocalizer;

if (!function_exists('successCode')) {
    /**
     * 统一成功响应。
     */
    function successCode(array $data = [], string $message = 'success', int $httpCode = 200)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        return json([
            'code' => 0,
            'message' => MessageLocalizer::translate($message),
            'data' => $data,
        ], $httpCode, [], ['json_encode_param' => $flags]);
    }
}

if (!function_exists('errorCode')) {
    /**
     * 统一错误响应。
     */
    function errorCode(array $data = [], string $message = 'error', int $code = 400)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        return json([
            'code' => $code,
            'message' => MessageLocalizer::translate($message),
            'data' => $data,
        ], $code, [], ['json_encode_param' => $flags]);
    }
}
