<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;

class Index extends BaseController
{
    /**
     * 默认首页入口。
     * 当前仅保留 ThinkPHP 欢迎页 iframe，用于框架默认访问场景。
     */
    public function index()
    {
        return '<style>*{ padding: 0; margin: 0; }</style><iframe src="https://www.thinkphp.cn/welcome?version=' . \think\facade\App::version() . '" width="100%" height="100%" frameborder="0" scrolling="auto"></iframe>';
    }

    /**
     * 默认 hello 测试入口。
     * 用于快速验证路由和控制器是否正常响应。
     */
    public function hello($name = 'ThinkPHP8')
    {
        return 'hello,' . $name;
    }
}
