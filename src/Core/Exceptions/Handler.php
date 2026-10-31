<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Exceptions;

use ErrorException;
use Throwable;

use NRPro\Pasargad\Core\Http\Response;
use NRPro\Pasargad\Core\Logging\Logger;

final class Handler
{
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        /*
        |--------------------------------------------------------------------------
        | PHP ERROR HANDLER
        |--------------------------------------------------------------------------
        */

        set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line
            ): bool {

                if (!(error_reporting() & $severity)) {
                    return true;
                }

                throw new ErrorException(
                    $message,
                    0,
                    $severity,
                    $file,
                    $line
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | EXCEPTION HANDLER
        |--------------------------------------------------------------------------
        */

        set_exception_handler(
            function (Throwable $exception): void {

                $this->logException($exception);

                if (!headers_sent()) {
                    Response::json([
                        'success' => false,
                        'message' => 'Internal server error',
                    ], 500);
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | FATAL ERROR HANDLER
        |--------------------------------------------------------------------------
        */

        register_shutdown_function(
            function (): void {

                $error = error_get_last();

                if (!is_array($error)) {
                    return;
                }

                $fatalTypes = [
                    E_ERROR,
                    E_PARSE,
                    E_CORE_ERROR,
                    E_COMPILE_ERROR,
                ];

                if (!in_array(
                    $error['type'],
                    $fatalTypes,
                    true
                )) {
                    return;
                }

                try {
                    $this->logger->error(
                        'Fatal PHP error',
                        [
                            'type' => $error['type'],
                            'file' => basename($error['file']),
                            'line' => $error['line'],
                        ]
                    );
                } catch (Throwable) {
                    error_log(
                        'NR PRO PASARGAD: fatal error logging failed.'
                    );
                }

                if (!headers_sent()) {
                    Response::json([
                        'success' => false,
                        'message' => 'Internal server error',
                    ], 500);
                }
            }
        );
    }

    private function logException(
        Throwable $exception
    ): void {
        try {
            $this->logger->error(
                'Unhandled application exception',
                [
                    'type' => get_class($exception),
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine(),
                ]
            );
        } catch (Throwable) {
            error_log(
                'NR PRO PASARGAD: exception logging failed.'
            );
        }
    }
}