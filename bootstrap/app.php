<?php

use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // Same-origin API routes share the web session and CSRF protection.
        $middleware->group('api', ['web']);
        $middleware->alias(['role' => RequireRole::class]);
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->trustProxies(at: ['127.0.0.1', '192.168.0.0/16', '172.19.0.0/16']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Illuminate\Contracts\Cache\LockTimeoutException $exception) {
            return response()->json(['error' => '这个文件正在处理中，请稍后重试。'], 423);
        });
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
            return response()->json(['error' => '没有找到这份资源，回作品列表再看看吧。'], 404);
        });
        $exceptions->render(function (Illuminate\Validation\ValidationException $exception) {
            return response()->json(['error' => $exception->validator->errors()->first(), 'errors' => $exception->errors()], 422);
        });
        $exceptions->render(function (Illuminate\Auth\AuthenticationException $exception) {
            return response()->json(['error' => '先登录，再来一起逛逛。'], 401);
        });
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return response()->json(['error' => $exception->getMessage() ?: '这次请求没有完成。'], $exception->getStatusCode(), $exception->getHeaders());
        });
    })->create();
