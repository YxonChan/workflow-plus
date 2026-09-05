<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\AuthService;

class AuthController extends BaseController
{
    public function login()
    {
        $payload = $this->request->param();
        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $password === '') {
            abort(422, '请输入账号和密码');
        }

        $result = AuthService::login($username, $password);
        if ($result === null) {
            abort(401, '账号或密码错误');
        }

        return successCode($result);
    }

    public function refresh()
    {
        $payload = $this->request->param();
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            abort(422, 'refresh_token 不能为空');
        }

        $result = AuthService::refresh($refreshToken);
        if ($result === null) {
            abort(401, 'refresh_token 无效或已过期');
        }

        return successCode($result);
    }

    public function me()
    {
        return successCode([
            'user' => AuthService::serializeUser($this->currentUser()),
        ]);
    }

}
