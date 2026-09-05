<?php

declare(strict_types=1);

namespace app;

use app\model\User;
use app\model\AdminOperationLog;
use app\support\AuthService;
use think\App;
use think\exception\ValidateException;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    protected ?User $authUser = null;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        if ($this->shouldSkipAuth()) {
            return;
        }

        $user = AuthService::userFromRequest($this->request);
        if (!$user instanceof User) {
            abort(401, '请先登录');
        }

        $this->authUser = $user;
    }

    protected function currentUser(): User
    {
        if ($this->authUser instanceof User) {
            return $this->authUser;
        }

        $user = AuthService::userFromRequest($this->request);
        if (!$user instanceof User) {
            abort(401, '请先登录');
        }

        $this->authUser = $user;
        return $user;
    }

    protected function currentUserId(): int
    {
        return (int) $this->currentUser()->getAttr('id');
    }

    protected function isAdmin(): bool
    {
        return (string) $this->currentUser()->getAttr('role') === 'admin';
    }

    protected function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            abort(403, '仅管理员可访问');
        }
    }

    protected function requireNonAdmin(string $message = '管理员不可使用此功能'): void
    {
        if ($this->isAdmin()) {
            abort(403, $message);
        }
    }

    protected function currentUserCacheSuffix(): string
    {
        return 'u' . $this->currentUserId();
    }

    protected function writeAdminOperationLog(array $data): void
    {
        try {
            $user = $this->authUser instanceof User ? $this->authUser : null;
            $operatorUserId = (int) ($data['operator_user_id'] ?? ($user instanceof User ? $user->getAttr('id') : 0));
            $operatorName = trim((string) ($data['operator_name_snapshot'] ?? ''));
            if ($operatorName === '' && $user instanceof User) {
                $operatorName = trim((string) ($user->getAttr('display_name') ?: $user->getAttr('username')));
            }

            $log = new AdminOperationLog();
            $log->save([
                'operator_user_id' => $operatorUserId,
                'operator_name_snapshot' => $operatorName,
                'action' => (string) ($data['action'] ?? ''),
                'target_type' => (string) ($data['target_type'] ?? ''),
                'target_id' => (int) ($data['target_id'] ?? 0),
                'target_name_snapshot' => (string) ($data['target_name_snapshot'] ?? ''),
                'series_id' => isset($data['series_id']) ? (int) $data['series_id'] : null,
                'episode_id' => isset($data['episode_id']) ? (int) $data['episode_id'] : null,
                'workflow_run_id' => isset($data['workflow_run_id']) ? (int) $data['workflow_run_id'] : null,
                'result' => in_array(($data['result'] ?? 'success'), ['success', 'failed'], true) ? (string) $data['result'] : 'success',
                'error_message' => (string) ($data['error_message'] ?? ''),
                'ip' => (string) ($data['ip'] ?? $this->request->ip()),
                'user_agent' => (string) ($data['user_agent'] ?? $this->request->header('user-agent', '')),
                'request_id' => (string) ($data['request_id'] ?? $this->request->header('x-request-id', '')),
                'meta_json' => $data['meta_json'] ?? [],
                'before_json' => $data['before_json'] ?? [],
                'after_json' => $data['after_json'] ?? [],
            ]);
        } catch (\Throwable) {
            // 审计写入失败不应阻断业务主流程。
        }
    }

    private function shouldSkipAuth(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $path = trim((string) $this->request->pathinfo(), '/');
        if (in_array($path, ['api/auth/login', 'api/auth/refresh'], true)) {
            return true;
        }

        return $this instanceof \app\controller\AuthController && str_ends_with($path, '/login');
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

}
