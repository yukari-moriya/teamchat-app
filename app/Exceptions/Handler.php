<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /** 公開APIのエラーは、呼ぶ側が Accept を付けなくても JSON で返す（docs/design/permissions-api.md 3-1） */
    protected function shouldReturnJson($request, Throwable $e)
    {
        return $this->isApiRequest($request) || parent::shouldReturnJson($request, $e);
    }

    /**
     * 公開APIのエラーの本文は message だけにして、ステータスで決める（permissions-api.md 3-4）。
     * 例外の文言は使わない。404 の理由の違いから、プライベートチャンネルがあることが分かるため（Q-27）。
     * APP_DEBUG が true でも同じにする。ルートが無い URL はミドルウェアを通らないので、ここで判定する。
     */
    protected function prepareJsonResponse($request, Throwable $e)
    {
        if (! $this->isApiRequest($request)) {
            return parent::prepareJsonResponse($request, $e);
        }

        $status = $this->isHttpException($e) ? $e->getStatusCode() : 500;

        return new JsonResponse(
            ['message' => $this->apiErrorMessage($status)],
            $status,
            $this->isHttpException($e) ? $e->getHeaders() : [],
        );
    }

    /**
     * URL の最初の区切りで見分ける。is('api/*') は、パスに改行や文字として読めないバイトがあると
     * 当たらず、無い URL の 404 が画面用の HTML になる。
     */
    private function isApiRequest($request): bool
    {
        return $request->segment(1) === 'api';
    }

    private function apiErrorMessage(int $status): string
    {
        return match (true) {
            $status >= 500 => 'Server Error',
            // HTTP の標準の言葉（Too Many Requests）ではなく、3-4 の表の言葉
            $status === 429 => 'Too Many Attempts.',
            default => Response::$statusTexts[$status] ?? 'Error',
        };
    }
}
