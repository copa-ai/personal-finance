<?php

use App\Http\Middleware\ChatInterno;
use App\Services\Chat\ErrorChat;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API interna del chat (docs/chat-api-interna.md): sin grupo «web», con Bearer CHAT_SECRETO; solo escucha en 127.0.0.1:8001.
            Route::middleware(ChatInterno::class)->prefix('interno/chat')->group(base_path('routes/interno.php'));
            // API interna de la revision de gastos (interfaz «cards»): mismo middleware y secreto, puerto 8001.
            Route::middleware(ChatInterno::class)->prefix('interno/cards')->group(base_path('routes/interno-cards.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ErrorChat::class);
        // La API interna del chat (y la de cards) responde siempre JSON con la forma de ErrorChat.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('interno/chat/*', 'interno/cards/*')) {
                return null;
            }
            if ($e instanceof ErrorChat) {
                return $e->respuesta();
            }
            if ($e instanceof NotFoundHttpException) {
                return ErrorChat::notFound('No existe.')->respuesta();
            }
            if ($e instanceof HttpExceptionInterface) {
                return (new ErrorChat('bad_request', 'Petición no válida.', $e->getStatusCode()))->respuesta();
            }
            report($e);

            return ErrorChat::serviceError()->respuesta();
        });
    })->create();
