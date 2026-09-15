<?php

class UserRepository
{
    public function __construct(private PDO $db) {}

    // 아이디 중복 확인
    public function existsByUserId(string $userId): array | false
    {
        $sql = 'SELECT user_id FROM users WHERE user_id = :user_id LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $userId
        ]);

        //fetch(PDO::FETCH_ASSOC)는 사용자 정보를 배열로 반환하고, 조회된 행이 없으면 false를 반환
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function findByUserId(string $userId): array|false
    {
        $sql = '
        SELECT user_id, password, name
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
        ]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }


    // 회원가입
    public function create(string $userId, string $passwordHash, string $name, string $email): void
    {
        $sql = 'INSERT INTO users (user_id, password, name, email) VALUES (:user_id, :password, :name, :email)';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'password' => $passwordHash,
            'name' => $name,
            'email' => $email
        ]);
    }

    // 로그인
    public function login(string $userId): array | false
    {
        $sql = 'SELECT user_id, password FROM users WHERE user_id = :user_id LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $userId
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}