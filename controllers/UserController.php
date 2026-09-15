<?php

class UserController
{
    public function __construct(private UserService $service) {}

    public function checkId(string $userId): array
    {

        return $this->service->checkId($userId);
    }

    public function signup(array $input): array
    {
        return $this->service->signup($input);
    }

    public function login(array $input): array
    {
        $result = $this->service->login($input);

        if ($result['success']) {
            session_regenerate_id(true);

            $_SESSION['user'] = $result['user'];

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $result;
    }

    public function logout(): void
    {
        $_SESSION = [];

        // if (ini_get('session.use_cookies')) {
        //     $params = session_get_cookie_params();

        //     setcookie(
        //         session_name(),
        //         '',
        //         time() - 42000,
        //         $params['path'],
        //         $params['domain'],
        //         $params['secure'],
        //         $params['httponly']
        //     );
        // }

        session_destroy();

        header('Location: /login', true, 303);
        exit;
    }
}