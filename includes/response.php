<?php

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonMethodNotAllowed(array $allowedMethods): never
{
    header('Allow: ' . implode(', ', $allowedMethods));
    jsonResponse(
        ['available' => false, 'message' => '허용되지 않음'],
        405
    );
}