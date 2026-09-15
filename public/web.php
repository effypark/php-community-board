<?php

return [
    [
        'pattern' => '#^/login$#',
        'method' => 'GET',
        'handler' => [$userController, 'showLogin'],
        'middleware' => ['guest'],
        'format' => 'html',
    ],
    [
        'pattern' => '#^/login$#',
        'method' => 'POST',
        'handler' => [$userController, 'submitLogin'],
        'middleware' => ['guest', 'csrf'],
        'format' => 'html',
    ],
    [
        'pattern' => '#^/logout$#',
        'method' => 'POST',
        'handler' => [$userController, 'submitLogout'],
        'middleware' => ['auth', 'csrf'],
        'format' => 'html',
    ],
];