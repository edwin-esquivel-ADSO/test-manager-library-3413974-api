<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Domain\Exceptions\BusinessException;
use App\Domain\Exceptions\ErrorCodes;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.jwt' => \App\Http\Middleware\AuthenticateJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // BusinessException handling
        $exceptions->render(function (BusinessException $e, Request $request) {
            return response()->json([
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], $e->getStatusCode());
        });

        // ValidationException handling (spec CA-01 / RNF-4)
        $exceptions->render(function (ValidationException $e, Request $request) {
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $details[] = [
                        'field' => $field,
                        'issue' => $msg,
                    ];
                }
            }

            return response()->json([
                'code' => ErrorCodes::VALIDATION_ERROR,
                'message' => 'Los datos proporcionados no son válidos.',
                'details' => $details,
            ], 422);
        });

        // 404 Not Found handling
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'code' => ErrorCodes::NOT_FOUND,
                'message' => 'El recurso solicitado no fue encontrado.',
                'details' => [],
            ], 404);
        });
    })->create();
