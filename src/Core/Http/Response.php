<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Http;

final class Response
{
    public static function json(
        array $data,
        int $statusCode = 200
    ): void {

        http_response_code($statusCode);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header('X-Content-Type-Options: nosniff');

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );
    }
}