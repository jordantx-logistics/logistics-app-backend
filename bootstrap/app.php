<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\System\Middleware\AssignRequestId;
use Src\System\Middleware\EnforcePermission;
use Src\System\Middleware\LogHttpRequest;
use Src\System\Middleware\RecordHttpMetrics;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `api/*` nunca redirige a un login web (no existe): así el
        // AuthenticationException llega al handler de abajo y responde 401 JSON
        // aunque el cliente no envíe `Accept: application/json`.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/'
        );

        $middleware->append(AssignRequestId::class);
        $middleware->append(LogHttpRequest::class);
        $middleware->append(RecordHttpMetrics::class);

        // Spatie v7 ya no registra sus alias: se declaran aquí. `permission`
        // pasa por `EnforcePermission`, que respeta el interruptor
        // `AUTHZ_ENFORCE` (rollback de la Fase 1, no permanente).
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => EnforcePermission::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Sesión SPA: para las peticiones que vienen del frontend
        // (`SANCTUM_STATEFUL_DOMAINS`) añade al grupo `api` el arranque de
        // sesión y las cookies; para el resto (apps/PDA con bearer) es un no-op.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                $firstError = collect($e->errors())->first()[0] ?? $e->getMessage();

                return response()->json([
                    'success' => false,
                    'message' => $firstError,
                    'data' => $e->errors(),
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! $e instanceof BusinessRuleException) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
                $isDebug = config('app.debug');
                $message = $status === 500 && ! $isDebug ? 'Internal Server Error' : $e->getMessage();

                $response = [
                    'success' => false,
                    'message' => $message,
                ];

                if ($isDebug) {
                    $response['debug'] = [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'trace' => collect($e->getTrace())->take(10)->toArray(),
                    ];
                }

                return response()->json($response, $status);
            }
        });
    })->create();
