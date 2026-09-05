<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\support\AuthService;
use app\support\ScriptExternalScoringService;

/**
 * Hermes Agent 独立鉴权接口，不使用站内用户 JWT。
 */
class HermesScriptController extends BaseController
{
    protected function initialize()
    {
    }

    public function projects()
    {
        $this->guardRequestSize();
        $client = $this->externalClient();
        return successCode(ScriptExternalScoringService::listProjects($client, $this->request->param()));
    }

    public function detail()
    {
        $this->guardRequestSize();
        $client = $this->externalClient();
        $projectId = (int) $this->request->param('id', 0);
        if ($projectId <= 0) {
            abort(422, 'id 必须是有效的剧本项目 ID');
        }
        return successCode(['project' => ScriptExternalScoringService::projectDetail($client, $projectId)]);
    }

    public function score()
    {
        $this->guardRequestSize();
        $client = $this->externalClient();
        $payload = $this->request->param();
        $projectId = (int) ($payload['id'] ?? 0);
        if ($projectId <= 0) {
            abort(422, 'id 必须是有效的剧本项目 ID');
        }
        return successCode([
            'score' => ScriptExternalScoringService::submitScore($client, $projectId, $payload),
        ]);
    }

    private function externalClient(): array
    {
        $user = AuthService::userFromRequest($this->request);
        if ($user instanceof User) {
            $displayName = trim((string) ($user->getAttr('display_name') ?: $user->getAttr('username')));
            return ScriptExternalScoringService::accountClient((int) $user->getAttr('id'), $displayName);
        }

        return ScriptExternalScoringService::authenticate((string) $this->request->header('authorization', ''));
    }

    private function guardRequestSize(): void
    {
        $contentLength = (int) $this->request->header('content-length', 0);
        if ($contentLength > 1024 * 1024) {
            abort(413, '请求体不能超过 1 MB');
        }
        $body = (string) $this->request->getContent();
        if (strlen($body) > 1024 * 1024) {
            abort(413, '请求体不能超过 1 MB');
        }
    }
}
