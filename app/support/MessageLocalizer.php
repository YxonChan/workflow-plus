<?php

declare(strict_types=1);

namespace app\support;

use think\Request;

class MessageLocalizer
{
    public static function translate(string $message, ?Request $request = null): string
    {
        return trim($message);
    }
}
