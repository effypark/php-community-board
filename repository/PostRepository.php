<?php

class PostRepository
{
    public function __construct(private PDO $db) {}

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function getAllPosts(): array
    {
        $statement = $this->db->query(
            'SELECT
                id,
                title,
                content,
                content_format,
                thumbnail_image_key,
                created_at
             FROM posts
             WHERE deleted_at IS NULL
             ORDER BY created_at DESC, id DESC'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPostBySearch(string $keyword): array
    {
        $searchKeyword = '%' . $keyword . '%';

        $statement = $this->db->prepare(
            'SELECT
                id,
                title,
                content,
                content_format,
                thumbnail_image_key,
                created_at
            FROM posts
            WHERE deleted_at IS NULL
              AND (title LIKE :title_keyword OR content LIKE :content_keyword)
            ORDER BY created_at DESC, id DESC'
        );

        $statement->execute([
            'title_keyword' => $searchKeyword,
            'content_keyword' => $searchKeyword,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPost(int $id): array | false
    {
        $statement = $this->db->prepare(
            'SELECT
                id,
                title,
                content,
                content_format,
                username,
                user_id,
                thumbnail_image_key
             FROM posts
             WHERE id = :id
               AND deleted_at IS NULL'
        );

        $statement->execute(['id' => $id]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function getAttachments(int $postId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                id,
                stored_name,
                original_name,
                mime_type,
                file_size,
                created_at
             FROM post_attachments
             WHERE post_id = :post_id
             ORDER BY id ASC'
        );

        $statement->execute(['post_id' => $postId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addAttachment(
        int $postId,
        string $storedName,
        string $originalName,
        string $mimeType,
        int $fileSize
    ): bool {
        $statement = $this->db->prepare(
            'INSERT INTO post_attachments (
                post_id,
                stored_name,
                original_name,
                mime_type,
                file_size
             ) VALUES (
                :post_id,
                :stored_name,
                :original_name,
                :mime_type,
                :file_size
             )'
        );

        return $statement->execute([
            'post_id' => $postId,
            'stored_name' => $storedName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ]);
    }

    public function deleteAttachment(int $postId, int $attachmentId): array | false
    {
        $attachment = $this->getAttachment($attachmentId);

        if (
            $attachment === false ||
            (int) $attachment['post_id'] !== $postId
        ) {
            return false;
        }

        $statement = $this->db->prepare(
            'DELETE FROM post_attachments
             WHERE id = :id AND post_id = :post_id'
        );

        if (!$statement->execute([
            'id' => $attachmentId,
            'post_id' => $postId,
        ])) {
            return false;
        }

        return $attachment;
    }

    public function getAttachment(int $id): array | false
    {
        $statement = $this->db->prepare(
            'SELECT id, post_id, stored_name, original_name, mime_type, file_size
             FROM post_attachments
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function createPost(
        string $userId,
        string $username,
        string $title,
        string $content,
        ?string $thumbnailImageKey
    ): int | false {
        $statement = $this->db->prepare(
            'INSERT INTO posts (
                user_id,
                username,
                title,
                content,
                content_format,
                thumbnail_image_key
             ) VALUES (
                :user_id,
                :username,
                :title,
                :content,
                :content_format,
                :thumbnail_image_key
             )'
        );

        if (!$statement->execute([
            'user_id' => $userId,
            'username' => $username,
            'title' => $title,
            'content' => $content,
            'content_format' => 'html',
            'thumbnail_image_key' => $thumbnailImageKey,
        ])) {
            return false;
        }

        return (int) $this->db->lastInsertId();
    }

    public function updatePost(
        string $userId,
        int $id,
        string $title,
        string $content,
        ?string $thumbnailImageKey
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE posts
             SET title = :title,
                 content = :content,
                 thumbnail_image_key = :thumbnail_image_key
             WHERE id = :id
               AND user_id = :user_id
               AND content_format = :content_format
               AND deleted_at IS NULL'
        );

        $success = $statement->execute([
            'user_id' => $userId,
            'id' => $id,
            'title' => $title,
            'content' => $content,
            'thumbnail_image_key' => $thumbnailImageKey,
            'content_format' => 'html',
        ]);

        if (!$success) {
            return false;
        }

        if ($statement->rowCount() > 0) {
            return true;
        }

        // 변경 사항이 없으면 MySQL에서 rowCount()가 0일 수 있습니다.
        $post = $this->getPost($id);

        return $post !== false
            && (string) $post['user_id'] === $userId
            && $post['content_format'] === 'html'
            && $post['title'] === $title
            && $post['content'] === $content
            && ($post['thumbnail_image_key'] ?? null)
            === $thumbnailImageKey;
    }

    public function deletePost(string $userId, int $id): bool
    {
        $statement = $this->db->prepare(
            'UPDATE posts
             SET deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id
               AND deleted_at IS NULL'
        );

        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }
}
