<?php

function dispatch(string $uri, PostController $postController, UserController $userController): array
{
    $method = $_SERVER['REQUEST_METHOD'];
    $isLoggedIn = isset($_SESSION['user']);

    switch (true) {

        case $uri === '/':
            if (!$isLoggedIn) {
                header(
                    'Location: /login'
                );
                exit;
            } else {
                header('Location: /list');
                exit;
            }

        case $uri === '/404':
            return [
                'page' => '404',
                'data' => [],
            ];

        case $uri === '/list':
            if (!$isLoggedIn) {
                header(
                    'Location: /login'
                );
                exit;
            }
            $keyword = $_GET['keyword'] ?? '';
            $keyword = is_string($keyword) ? trim($keyword) : '';

            $posts = $keyword === ''
                ? $postController->index()
                : $postController->search($keyword);

            return [
                'page' => 'list',
                'data' => [
                    'posts' => $posts,
                    'keyword' => $keyword,
                ],
            ];

        case $uri === '/write':
            if (!$isLoggedIn) {
                header('Location: /login', true, 303);
                exit;
            }

            $data = [
                'errors' => [],
                'title' => '',
                'content' => '',
                'post' => false,
            ];

            if ($method === 'GET' && isset($_GET['id'])) {
                $editId = filter_var($_GET['id'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $data['post'] = $editId ? $postController->detail($editId) : false;

                if ($data['post'] === false) {
                    http_response_code(404);
                } else {
                    $data['postId'] = $editId;
                    if ((string) ($data['post']['user_id'] ?? '') !== (string) $_SESSION['user']['user_id']) {
                        http_response_code(403);
                        return ['page' => '404', 'data' => []];
                    }
                    $data['title'] = $data['post']['title'];
                    $data['content'] = $data['post']['content'];
                }
            }

            if ($method === 'POST') {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');

                $csrfToken = $_POST['csrf_token'] ?? null;

                if (
                    !is_string($csrfToken) ||
                    !validCsrfToken($csrfToken)
                ) {
                    http_response_code(403);

                    echo json_encode(
                        [
                            'success' => false,
                            'message' => '유효하지 않은 요청입니다. 페이지를 새로고침해주세요.',
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    );

                    exit;
                }

                try {
                    if (isset($_POST['id'])) {
                        $editId = filter_var($_POST['id'], FILTER_VALIDATE_INT, [
                            'options' => ['min_range' => 1],
                        ]);

                        if (!$editId) {
                            jsonResponse(['success' => false, 'message' => '수정할 게시글 ID가 없습니다.'], 400);
                        }

                        $result = $postController->update(
                            $_SESSION['user'],
                            $editId,
                            $_POST,
                            $_FILES
                        );
                    } else {
                        $result = $postController->create(
                            $_SESSION['user'],
                            $_POST,
                            $_FILES
                        );
                    }

                    http_response_code(
                        !empty($result['success'])
                            ? (isset($_POST['id']) ? 200 : 201)
                            : 422
                    );

                    echo json_encode(
                        $result,
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                } catch (Throwable $e) {
                    error_log((string) $e);

                    http_response_code(500);

                    echo json_encode(
                        [
                            'success' => false,
                            'message' => '게시글 처리 중 서버 오류가 발생했습니다.',
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                }

                exit;
            }


            if ($method === 'PATCH') {
                if (!$isLoggedIn) {
                    jsonResponse(['success' => false, 'message' => '로그인이 필요합니다.'], 401);
                }

                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                if (!validCsrfToken($input['csrf_token'] ?? null)) {
                    jsonResponse(['success' => false, 'message' => '유효하지 않은 요청입니다.'], 403);
                }

                $editId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if (!$editId) {
                    jsonResponse(['success' => false, 'message' => '수정할 게시글 ID가 없습니다.'], 400);
                }

                $result = $postController->update($_SESSION['user'], $editId, $input);
                jsonResponse($result, $result['success'] ? 200 : 422);
            }

            return ['page' => 'write', 'data' => $data];

        case preg_match('#^/post/(\d+)$#', $uri, $matches):
            $postId = (int) $matches[1];

            if ($method === 'DELETE') {
                if (!$isLoggedIn) {
                    jsonResponse(['success' => false, 'message' => '로그인이 필요합니다.'], 401);
                }

                if (!validCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                    jsonResponse(['success' => false, 'message' => '유효하지 않은 요청입니다.'], 403);
                }

                $result = $postController->delete($_SESSION['user'], $postId);
                jsonResponse($result, $result['success'] ? 200 : 404);
            }

            $post = $postController->detail($postId);
            if ($post === false) {
                http_response_code(404);
            }

            return [
                'page' => 'post',
                'data' => [
                    'params' => ['id' => $postId],
                    'post' => $post,
                ],
            ];

        case $uri === '/login':
            requireMethod($method, ['GET', 'HEAD', 'POST']);

            if ($isLoggedIn) {
                header('Location: /list', true, 303);
                exit;
            }

            $data = ['errors' => [], 'loginUserId' => ''];
            if ($method === 'POST') {
                $data['loginUserId'] = is_string($_POST['userid'] ?? null)
                    ? trim($_POST['userid'])
                    : '';

                if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                    http_response_code(403);
                    $data['errors'] = ['유효하지 않은 요청입니다. 페이지를 새로고침해주세요.'];
                    return ['page' => 'login', 'data' => $data];
                }

                $result = $userController->login($_POST);
                if ($result['success']) {
                    header('Location: /list', true, 303);
                    exit;
                }

                http_response_code(422);
                $data['errors'] = [$result['message']];
            }

            return ['page' => 'login', 'data' => $data];

        case $uri === '/logout':
            requireMethod($method, ['POST']);

            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                exit('유효하지 않은 요청입니다.');
            }

            $userController->logout();

        case $uri === '/signup':
            requireMethod($method, ['GET', 'HEAD', 'POST']);

            $data = ['errors' => []];

            if ($isLoggedIn) {
                header('Location: /list', true, 303);
                exit;
            }

            if ($method === 'POST') {
                if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                    http_response_code(403);
                    $data['errors'] = ['유효하지 않은 요청입니다. 다시 제출해주세요.'];
                    return ['page' => 'signup', 'data' => $data];
                }

                $result = $userController->signup($_POST);
                if ($result['success']) {
                    header('Location: /login', true, 303);
                    exit;
                }

                http_response_code(422);
                $data['errors'] = $result['errors'];
            }

            return ['page' => 'signup', 'data' => $data];

        case $uri === '/users/check-id':
            if ($method !== 'POST') {
                jsonMethodNotAllowed(['POST']);
            }

            $userId = $_POST['user_id'] ?? '';
            if (!is_string($userId)) {
                jsonResponse(['available' => false, 'message' => '입력 형식 오류'], 400);
            }

            jsonResponse($userController->checkId(trim($userId)));


        case $uri === '/images/file':
            if (!$isLoggedIn) {
                header('Location: /404', true, 303);
                exit;
            }
            if ($method !== 'GET') {
                header('Allow: GET');

                jsonResponse([
                    'success' => false,
                    'message' => 'GET 요청만 허용합니다.',
                ], 405);
                exit;
            }

            $key = $_GET['key'] ?? '';
            $variant = $_GET['variant'] ?? 'original';
            $width = $_GET['w'] ?? $_GET['width'] ?? null;
            $height = $_GET['h'] ?? $_GET['height'] ?? null;

            if (
                !is_string($key) ||
                !is_string($variant) ||
                ($width !== null && !is_string($width)) ||
                ($height !== null && !is_string($height))
            ) {
                jsonResponse([
                    'success' => false,
                    'message' => '잘못된 이미지 요청입니다.',
                ], 400);
                exit;
            }

            $requestedWidth = $width === null || $width === ''
                ? null
                : filter_var($width, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
            $requestedHeight = $height === null || $height === ''
                ? null
                : filter_var($height, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

            if (
                ($width !== null && $width !== '' && $requestedWidth === false) ||
                ($height !== null && $height !== '' && $requestedHeight === false) ||
                ($width !== null && $width !== '' && $height === null) ||
                ($height !== null && $height !== '' && $width === null)
            ) {
                jsonResponse([
                    'success' => false,
                    'message' => 'width와 height는 함께 사용하는 양의 정수여야 합니다.',
                ], 400);
                exit;
            }

            $file = $postController->imageFile(
                $key,
                $variant,
                $requestedWidth === false ? null : $requestedWidth,
                $requestedHeight === false ? null : $requestedHeight
            );

            if ($file === null) {
                http_response_code(404);
                exit;
            }

            header('Content-Type: ' . $file['mime']);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: inline');

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            readfile($file['path']);
            exit;

        case preg_match('#^/uploads/thumbnails(?:/|$)#', $uri):
            http_response_code(404);
            return ['page' => '404', 'data' => []];

        case $uri === '/attachments/file':
            if ($method !== 'GET') {
                jsonMethodNotAllowed(['GET']);
            }

            $attachmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $isPreviewRequest = ($_GET['preview'] ?? '') === '1';
            $file = $attachmentId
                ? ($isPreviewRequest
                    ? $postController->attachmentPreviewFile($attachmentId)
                    : $postController->attachmentFile($attachmentId))
                : null;

            if ($file === null) {
                http_response_code($isPreviewRequest ? 501 : 404);
                exit;
            }

            header('Content-Type: ' . $file['mime']);

            header('Content-Disposition: ' . ($isPreviewRequest ? 'inline' : 'attachment')
                . '; filename="'
                . rawurlencode($file['name']) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($file['path']);
            exit;

        default:
            http_response_code(404);
            return ['page' => '404', 'data' => []];
    }
}

function requireMethod(string $method, array $allowedMethods): void
{
    if (!in_array($method, $allowedMethods, true)) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowedMethods));
        exit;
    }
}

function validCsrfToken(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
