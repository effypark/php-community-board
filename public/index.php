<?php

require_once __DIR__ . '/../includes/bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

$route = dispatch($uri, $postController, $userController);

$page = $route['page'];
extract($route['data'], EXTR_SKIP);

ob_start();
require __DIR__ . '/../views/' . $page . '.php';

// 게시글 본문 변수 $content와 구분합니다.
$renderedContent = ob_get_clean();

$useEditor = !empty($load_editor);


$useLegacyViewer = !$useEditor && (
    !empty($load_viewer) || !empty($load_viewr)
);

?>

<!doctype html>
<html lang="ko">

<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php if ($useEditor): ?>
    <!-- 작성·수정 화면에서는 Summernote Lite를 사용합니다. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.9.1/summernote-lite.min.css">
    <?php elseif ($useLegacyViewer): ?>
    <!-- 기존 Markdown 게시글을 위한 Viewer는 임시로 유지합니다. -->
    <link rel="stylesheet" href="https://uicdn.toast.com/editor/3.2.2/toastui-editor-viewer.min.css">
    <?php endif; ?>

    <!-- 프로젝트 CSS가 라이브러리 스타일을 덮어쓸 수 있도록 뒤에 둡니다. -->
    <link rel="stylesheet" href="/assets/css/style.css">
</head>

<body>
    <?php require __DIR__ . '/../views/layouts/header.php'; ?>

    <main class="container">
        <?= $renderedContent ?>
    </main>

    <?php if ($useEditor): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.9.1/summernote-lite.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.9.1/lang/summernote-ko-KR.min.js"></script>
    <?php elseif ($useLegacyViewer): ?>
    <script src="https://uicdn.toast.com/editor/3.2.2/toastui-editor-viewer.min.js"></script>
    <?php endif; ?>

    <script src="/assets/js/main.js"></script>
    <?php if ($page === 'signup'): ?>
    <script src="/assets/js/signup.js"></script>
    <?php endif; ?>

    <?php if ($page === 'login'): ?>
    <script src="/assets/js/login.js"></script>
    <?php endif; ?>

    <?php if (!empty($load_editor) || !empty($load_viewer)): ?>
    <script src="/assets/js/purify.min.js"></script>
    <script src="/assets/js/editor.js"></script>
    <?php endif; ?>
</body