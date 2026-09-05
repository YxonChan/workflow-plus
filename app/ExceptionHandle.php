<?php

namespace app;

use app\support\MessageLocalizer;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        if (str_starts_with((string) $request->pathinfo(), 'api/')) {
            if ($e instanceof HttpException) {
                $status = (int) $e->getStatusCode();
                $message = $e->getMessage() ?: ($status === 404 ? '请求的资源不存在' : '请求失败');
                return json([
                    'code' => $status,
                    'message' => MessageLocalizer::translate($message, $request),
                    'data' => [],
                ], $status, [], ['json_encode_param' => $jsonFlags]);
            }

            if ($e instanceof ValidateException) {
                return json([
                    'code' => 422,
                    'message' => MessageLocalizer::translate($e->getMessage() ?: '参数校验失败', $request),
                    'data' => [],
                ], 422, [], ['json_encode_param' => $jsonFlags]);
            }

            // 捕获 JSON 编码失败等未归类异常，避免再次因非法 UTF-8 崩掉
            $message = trim($e->getMessage());
            if ($message === '' || str_contains($message, 'Malformed UTF-8')) {
                $message = '响应数据编码异常，请重试；若反复出现请检查资产描述/提示词是否含异常字符';
            }

            return json([
                'code' => 500,
                'message' => MessageLocalizer::translate($message, $request),
                'data' => [],
            ], 500, [], ['json_encode_param' => $jsonFlags]);
        }

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }
}
