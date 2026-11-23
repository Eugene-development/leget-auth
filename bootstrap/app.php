<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\ThrottleRequests::class, \App\Http\Middleware\ResolveFormSiteContext::class);
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\ThrottleRequests::class, \App\Http\Middleware\PrivatePasswordRecoveryResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Отказ от `can:` — в общем для проекта виде {success, message}.
        // Стандартный ответ Laravel ({"message": ...}) выбивался бы из
        // контракта, на который смотрит серверная часть leget-main.
        //
        // Ловим Symfony-исключение, а не AuthorizationException: Handler::render
        // прогоняет prepareException ДО пользовательских колбэков, и к моменту
        // проверки AuthorizationException уже превращён в AccessDeniedHttpException.
        // Колбэк на исходный класс просто не срабатывает — молча, с дефолтным телом.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Личный кабинет клиента недоступен для этой роли.',
            ], Response::HTTP_FORBIDDEN);
        });

        $exceptions->respond(function (HttpResponse $response, \Throwable $_error, Request $request) {
            $json = $request->expectsJson()
                || str_contains($response->headers->get('Content-Type', ''), 'json');
            if ($response->getStatusCode() < 500 || ! $json) {
                return $response;
            }

            $headers = array_diff_key($response->headers->all(), array_flip([
                'content-type', 'content-length', 'content-encoding', 'etag',
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Сервис временно недоступен. Попробуйте чуть позже.',
            ], $response->getStatusCode(), $headers);
        });
    })->create();
