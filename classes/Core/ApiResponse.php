<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

final class ApiResponse
{
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $code, string $message, int $status = 400): void
    {
        self::send(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
