<?php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

$isLoginPage = $uri === '/login';


?>

<header class="header">
    <nav>
        <?php if (isset($_SESSION['user']['name'])): ?>
        <form action="/logout" method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <button type="submit" class="info_btn">LOGOUT</button>
        </form>
        <?php elseif (!$isLoginPage): ?>
        <button class="info_btn" type="button"><a href="/login">LOGIN</a></button>
        <?php endif; ?>
    </nav>
</header>