<?php

class UserService
{
    public function __construct(private UserRepository $userRepo) {}

    public function checkId(string $userId): array
    {

        $userId = trim($userId);

        if ($userId === '') {
            return [
                'available' => false,
                'message' => '아이디를 입력하세요.'
            ];
        }
        $exists = $this->userRepo->existsByUserId($userId);

        return [
            'available' => !$exists,
            'message' => $exists ? '이미 사용 중인 아이디입니다.' : '사용 가능한 아이디입니다.',
        ];
    }

    public function signup(array $input): array
    {
        foreach (['userid', 'password', 'username', 'email'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                return [
                    'success' => false,
                    'errors' => ['입력값을 확인해주세요.'],
                ];
            }
        }

        $userId = trim($input['userid']);
        $password = $input['password'];
        $name = trim($input['username']);
        $email = trim($input['email']);

        $errors = [];

        if (!preg_match('/\A[a-zA-Z0-9_]{4,20}\z/', $userId)) {
            $errors[] = '아이디는 영문, 숫자, 밑줄로 4~20자여야 합니다.';
        }

        if (
            strlen($password) < 4 ||
            strlen($password) > 16 ||
            str_contains($password, "\0")
        ) {
            $errors[] = '비밀번호는 4~16바이트이며 공백을 포함할 수 없습니다.';
        }

        if ($name === '') {
            $errors[] = '이름을 입력해주세요.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '올바른 이메일 주소를 입력해주세요.';
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        // 가입 시 아이디 중복 확인
        if ($this->userRepo->existsByUserId($userId)) {
            return [
                'success' => false,
                'errors' => ['이미 사용 중인 아이디입니다.'],
            ];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->userRepo->create(
                $userId,
                $passwordHash,
                $name,
                $email
            );
        } catch (PDOException $exception) {
            // 동시 가입 요청 등으로 발생한 MySQL 중복 키 오류를 처리
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return [
                    'success' => false,
                    'errors' => ['아이디 등 중복된 가입 정보가 있습니다.'],
                ];
            }

            throw $exception;
        }

        return [
            'success' => true,
            'errors' => [],
        ];
    }

    public function login(array $input): array
    {
        $userId = $input['userid'] ?? '';
        $password = $input['password'] ?? '';

        if (!is_string($userId) || !is_string($password)) {
            return [
                'success' => false,
                'message' => '입력 형식이 올바르지 않습니다.',
            ];
        }

        $userId = trim($userId);

        if ($userId === '' || $password === '') {
            return [
                'success' => false,
                'message' => '아이디와 비밀번호를 입력해주세요.',
            ];
        }

        $user = $this->userRepo->findByUserId($userId);

        if (
            $user === false ||
            !password_verify($password, $user['password'])
        ) {
            return [
                'success' => false,
                'message' => '아이디 또는 비밀번호가 올바르지 않습니다.',
            ];
        }

        return [
            'success' => true,
            'user' => [
                'user_id' => $user['user_id'],
                'name' => $user['name'],
            ],
        ];
    }
}