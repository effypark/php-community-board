<?php

class PostController
{
    public function __construct(
        private PostService $service
    ) {}

    public function index(): array
    {
        return $this->service->list();
    }

    public function search(string $keyword): array
    {
        return $this->service->listBySearch($keyword);
    }

    public function detail(int $id): array | false
    {
        return $this->service->detail($id);
    }

    public function attachmentFile(int $id): ?array
    {
        return $this->service->attachmentFile($id);
    }

    public function attachmentPreviewFile(int $id): ?array
    {
        return $this->service->attachmentPreviewFile($id);
    }


    public function create(
        array $user,
        array $input,
        array $files
    ): array {
        $title = $input['title'] ?? '';
        $content = $input['content'] ?? '';

        $images = $files['images'] ?? null;
        $previewUrls = $input['image_preview_urls'] ?? [];
        $attachments = $files['file_upload'] ?? null;

        if (
            !is_string($title) ||
            !is_string($content) ||
            !is_array($previewUrls) ||
            ($images !== null && !is_array($images))
        ) {
            return [
                'success' => false,
                'message' => '잘못된 요청 형식입니다.',
            ];
        }

        if (count($previewUrls) > 10) {
            return [
                'success' => false,
                'message' => '이미지는 최대 10장까지 첨부할 수 있습니다.',
            ];
        }

        foreach ($previewUrls as $previewUrl) {
            if (
                !is_string($previewUrl) ||
                strlen($previewUrl) > 2048 ||
                !str_starts_with($previewUrl, 'blob:')
            ) {
                return [
                    'success' => false,
                    'message' => '이미지 미리보기 정보가 올바르지 않습니다.',
                ];
            }
        }

        /*
         * 실제 파일 형식·용량·개수 대응 검증은 **서비스에서 수행
         *
         * thumbnail_image_key는 클라이언트에서 받지 않음
         * 서비스가 이번 요청의 파일들을 저장하면서 결정
         *
         * 신규 게시글은 서버에서 HTML 형식으로 저장하도록
         * 서비스와 Repository를 연결
         */
        return $this->service->create(
            (string) $user['user_id'],
            (string) $user['name'],
            $title,
            $content,
            $images,
            $previewUrls,
            $attachments
        );
    }

    public function update(
        array $user,
        int $id,
        array $input,
        array $files = []
    ): array {
        $title = $input['title'] ?? '';
        $content = $input['content'] ?? '';
        $images = $files['images'] ?? null;
        $previewUrls = $input['image_preview_urls'] ?? [];
        $attachments = $files['file_upload'] ?? null;
        $deletedAttachmentIds = $input['deleted_attachment_ids'] ?? [];

        if (
            !is_string($title) ||
            !is_string($content) ||
            !is_array($previewUrls) ||
            !is_array($deletedAttachmentIds) ||
            ($images !== null && !is_array($images))
        ) {
            return [
                'success' => false,
                'message' => '잘못된 요청 형식입니다.',
            ];
        }

        $userId = (string) $user['user_id'];
        $post = $this->service->detail($id);

        if (
            $post === false ||
            (string) $post['user_id'] !== $userId
        ) {
            return [
                'success' => false,
                'message' => '게시글이 없거나 수정 권한이 없습니다.',
            ];
        }


        if (($post['content_format'] ?? 'markdown') !== 'html') {
            return [
                'success' => false,
                'message' => '기존 Markdown 게시글의 HTML 전환 처리가 필요합니다.',
            ];
        }

        return $this->service->update(
            $userId,
            $id,
            $title,
            $content,
            $images,
            $previewUrls,
            $attachments,
            $deletedAttachmentIds
        );
    }

    public function delete(
        array $user,
        int $id
    ): array {
        $success = $this->service->delete(
            (string) $user['user_id'],
            $id
        );

        return [
            'success' => $success,
            'message' => $success
                ? '게시글이 삭제되었습니다.'
                : '게시글이 없거나 삭제 권한이 없거나 이미 삭제되었습니다.',
        ];
    }

    /**
     * 저장된 원본 또는 썸네일의 파일 정보를 반환
     */
    public function imageFile(
        string $key,
        string $variant,
        ?int $requestedWidth = null,
        ?int $requestedHeight = null
    ): ?array {
        return $this->service->imageFile(
            $key,
            $variant,
            $requestedWidth,
            $requestedHeight
        );
    }
}
