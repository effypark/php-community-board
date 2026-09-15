<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_created_at'] = time();
}

$appRoot = dirname(__DIR__);


// 공용 설정과 헬퍼
require_once $appRoot . '/config/config.php';
require_once $appRoot . '/includes/db.php';
require_once $appRoot . '/includes/helper.php';
require_once $appRoot . '/includes/function.php';
require_once $appRoot . '/includes/response.php';


// 의존성
require_once $appRoot . '/repository/PostRepository.php';
require_once $appRoot . '/services/PostService.php';
require_once $appRoot . '/controllers/PostController.php';

require_once $appRoot . '/repository/UserRepository.php';
require_once $appRoot . '/services/UserService.php';
require_once $appRoot . '/controllers/UserController.php';
require_once $appRoot . '/includes/router.php';



$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$postRepo = new PostRepository($pdo);
$postService = new PostService(
    $postRepo,
    $appRoot . '/storage/originals',
    $appRoot . '/public/uploads/thumbnails',
    $appRoot . '/storage/attachments'
);
$postController = new PostController($postService);

$userRepo = new UserRepository($pdo);
$userService = new UserService($userRepo);
$userController = new UserController($userService);
