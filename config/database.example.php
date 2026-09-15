<?php

return [
    'host' => getenv('DB_HOST') ?: '127',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') ?: '',
    'user' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '!@#',
    'charset' => 'utf8mb4',
];
