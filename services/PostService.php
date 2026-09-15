<?php

class PostService
{
  private const MAX_IMAGES = 10;
  private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
  private const MAX_ATTACHMENT_COUNT = 10;
  private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
  private const MAX_HTML_BYTES = 1024 * 1024;
  private const THUMBNAIL_WIDTH = 120;
  private const THUMBNAIL_HEIGHT = 80;
  private const MAX_RESIZED_IMAGE_WIDTH = 2000;
  private const MAX_RESIZED_IMAGE_HEIGHT = 2000;

  public function __construct(
    private PostRepository $repo,
    private string $originalDirectory,
    private string $thumbnailDirectory,
    private string $attachmentDirectory,
  ) {}

  public function list(): array
  {
    return $this->repo->getAllPosts();
  }

  public function listBySearch(string $keyword): array
  {
    return $this->repo->getPostBySearch($keyword);
  }

  public function detail(int $id): array | false
  {
    $post = $this->repo->getPost($id);

    if ($post !== false) {
      $post['attachments'] = $this->repo->getAttachments($id);
    }

    return $post;
  }

  public function attachmentFile(int $id): ?array
  {
    $attachment = $this->repo->getAttachment($id);

    if ($attachment === false) {
      return null;
    }

    $storedName = $attachment['stored_name'];

    if (!preg_match('/\A[a-f0-9]{48}\.(pdf|doc|docx|xml|hwp|hwpx)\z/', $storedName)) {
      return null;
    }

    $path = $this->attachmentDirectory . '/' . $storedName;

    if (!is_file($path) || !is_readable($path)) {
      return null;
    }

    return [
      'path' => $path,
      'name' => $attachment['original_name'],
      'mime' => $attachment['mime_type'],
    ];
  }


  // 첨부파일 미리보기 (LibreOffice pdf로 변환)
  public function attachmentPreviewFile(int $id): ?array
  {
    $file = $this->attachmentFile($id);

    if ($file === null) {
      return null;
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($extension, ['pdf', 'xml'], true)) {
      return [
        'path' => $file['path'],
        'name' => $file['name'],
        'mime' => $extension === 'pdf' ? 'application/pdf' : 'application/xml',
      ];
    }

    if (!in_array($extension, ['doc', 'docx'], true)) {
      return null;
    }

    $converter = trim((string) shell_exec(
      'command -v libreoffice 2>/dev/null || command -v soffice 2>/dev/null'
    ));
    if ($converter === '') {
      return null;
    }

    $previewDirectory = $this->attachmentDirectory . '/previews';
    $previewPath = $previewDirectory . '/' . sha1($file['path']) . '.pdf';
    $convertedPath = $previewDirectory . '/'
      . pathinfo($file['path'], PATHINFO_FILENAME) . '.pdf';

    if (!is_file($previewPath)) {
      $this->ensureDirectory($previewDirectory);
      $profileDirectory = sys_get_temp_dir() . '/lo-preview-' . bin2hex(random_bytes(12));
      if (!mkdir($profileDirectory, 0700, true) && !is_dir($profileDirectory)) {
        return null;
      }

      $profile = 'file://' . $profileDirectory;
      $command = escapeshellarg($converter)
        . ' -env:UserInstallation=' . escapeshellarg($profile)
        . ' --headless --convert-to pdf --outdir ' . escapeshellarg($previewDirectory)
        . ' ' . escapeshellarg($file['path']) . ' 2>&1';
      $output = shell_exec($command);

      if (!is_file($convertedPath) && is_string($output) && trim($output) !== '') {
        error_log('LibreOffice 첨부파일 변환 실패: ' . trim($output));
      }

      if (is_file($convertedPath) && $convertedPath !== $previewPath) {
        rename($convertedPath, $previewPath);
      }
    }

    if (!is_file($previewPath) || !is_readable($previewPath)) {
      return null;
    }

    return [
      'path' => $previewPath,
      'name' => $file['name'],
      'mime' => 'application/pdf',
    ];
  }


  // 신규 작성 요청에서만 원본 이미지들을 저장

  public function create(
    string $userId,
    string $username,
    string $title,
    string $content,
    ?array $images,
    array $previewUrls,
    ?array $attachments = null
  ): array {
    $savedKeys = [];
    $savedAttachmentNames = [];

    try {
      $this->repo->beginTransaction();

      $title = trim($title);
      $this->validateContentInput($title, $content);

      $uploads = $this->normalizeUploads(
        $images,
        $previewUrls
      );
      $attachments = $this->normalizeAttachments($attachments);

      [$document, $body] = $this->parseHtml($content);

      $imageNodes = [];
      $usedUrls = [];

      foreach ($body->getElementsByTagName('img') as $node) {
        $src = $node->getAttribute('src');

        if (!isset($uploads[$src])) {
          throw new InvalidArgumentException(
            '이미지는 파일로 직접 첨부해주세요. '
              . '외부 이미지나 파일이 없는 미리보기는 저장할 수 없습니다.'
          );
        }

        $imageNodes[] = $node;
        $usedUrls[$src] = true;
      }

      $this->assertNotEmpty($body);

      $replacements = [];
      $thumbnailImageKey = null;

      foreach ($uploads as $previewUrl => $file) {
        if (!isset($usedUrls[$previewUrl])) {
          continue;
        }

        $key = $this->uploadImage($file);
        $savedKeys[] = $key;


        // 이미지 중 첫번째 이미지 - 썸네일
        $thumbnailImageKey ??= $key;

        $replacements[$previewUrl] =
          $this->originalUrl($key);
      }

      foreach ($imageNodes as $node) {
        $src = $node->getAttribute('src');

        $node->setAttribute(
          'src',
          $replacements[$src]
        );

        $node->removeAttribute('srcset');
      }


      $savedContent = $this->bodyHtml($document, $body);

      $postId = $this->repo->createPost(
        $userId,
        $username,
        $title,
        $savedContent,
        $thumbnailImageKey
      );

      if ($postId === false) {
        throw new RuntimeException(
          '게시글을 저장하지 못했습니다.'
        );
      }

      foreach ($attachments as $attachment) {
        $storedName = $this->saveAttachment($attachment);
        $savedAttachmentNames[] = $storedName;

        if (!$this->repo->addAttachment(
          $postId,
          $storedName,
          $attachment['original_name'],
          $attachment['mime_type'],
          $attachment['file_size']
        )) {
          throw new RuntimeException('첨부파일 정보를 저장하지 못했습니다.');
        }
      }

      $this->repo->commit();

      return [
        'success' => true,
        'message' => '게시글이 등록되었습니다.',
      ];
    } catch (Throwable $e) {
      $this->repo->rollback();

      // 이번 작성 요청에서 만든 파일만 정리합니다.
      foreach ($savedKeys as $key) {
        $this->deleteImageFiles($key);
      }

      foreach ($savedAttachmentNames as $storedName) {
        $this->deleteAttachmentFile($storedName);
      }

      return $this->failure(
        $e,
        '게시글 등록 중 오류가 발생했습니다.'
      );
    }
  }


  public function update(
    string $userId,
    int $id,
    string $title,
    string $content,
    ?array $images = null,
    array $previewUrls = [],
    ?array $attachments = null,
    array $deletedAttachmentIds = []
  ): array {
    $savedKeys = [];
    $savedAttachmentNames = [];
    $deletedAttachmentNames = [];
    $deletedImageKeys = [];

    try {
      $this->repo->beginTransaction();

      $post = $this->repo->getPost($id);

      if (
        $post === false ||
        (string) $post['user_id'] !== $userId
      ) {
        throw new InvalidArgumentException(
          '게시글이 없거나 수정 권한이 없습니다.'
        );
      }

      if (($post['content_format'] ?? 'markdown') !== 'html') {
        throw new InvalidArgumentException(
          '기존 Markdown 게시글의 HTML 전환 처리가 필요합니다.'
        );
      }

      $title = trim($title);
      $this->validateContentInput($title, $content);
      $attachments = $this->normalizeAttachments($attachments);
      $deletedAttachmentIds = $this->normalizeDeletedAttachmentIds($deletedAttachmentIds);

      [$oldDocument, $oldBody] =
        $this->parseHtml($post['content']);

      $existingSources = [];
      $oldKeys = [];

      foreach ($oldBody->getElementsByTagName('img') as $node) {
        $source = $node->getAttribute('src');
        $key = $this->imageKeyFromSource($source);

        if ($key === null) {
          throw new InvalidArgumentException(
            '기존 본문에 올바르지 않은 이미지가 있습니다.'
          );
        }

        $existingSources[$source] = $key;
        $oldKeys[$key] = true;
      }

      [$document, $body] = $this->parseHtml($content);
      $uploads = $this->normalizeUploads($images, $previewUrls);
      $referencedKeys = [];
      $newThumbnailImageKey = null;

      foreach ($body->getElementsByTagName('img') as $node) {
        $src = $node->getAttribute('src');
        $key = null;

        if (isset($uploads[$src])) {
          $key = $this->uploadImage($uploads[$src]);
          $savedKeys[] = $key;
          $node->setAttribute('src', $this->originalUrl($key));
        } elseif (isset($existingSources[$src])) {
          $key = $existingSources[$src];
        } else {
          throw new InvalidArgumentException(
            '본문 이미지 정보를 확인할 수 없습니다. 다시 시도해주세요.'
          );
        }

        $referencedKeys[$key] = true;
        $newThumbnailImageKey ??= $key;
        $node->removeAttribute('srcset');
      }

      $this->assertNotEmpty($body);

      foreach ($referencedKeys as $key => $_) {
        if (
          isset($oldKeys[$key]) &&
          $this->imageFile($key, 'original') === null
        ) {
          throw new InvalidArgumentException(
            '본문에 사용된 기존 이미지 원본을 찾을 수 없습니다.'
          );
        }
      }

      $success = $this->repo->updatePost(
        $userId,
        $id,
        $title,
        $this->bodyHtml($document, $body),
        $newThumbnailImageKey
      );

      if (!$success) {
        throw new RuntimeException(
          '게시글을 수정하지 못했습니다.'
        );
      }

      foreach ($oldKeys as $key => $_) {
        if (!isset($referencedKeys[$key])) {
          $deletedImageKeys[] = $key;
        }
      }

      // 첨부파일 추가 시 attachment table 에 저장
      foreach ($attachments as $attachment) {
        $storedName = $this->saveAttachment($attachment);
        $savedAttachmentNames[] = $storedName;

        if (!$this->repo->addAttachment(
          $id,
          $storedName,
          $attachment['original_name'],
          $attachment['mime_type'],
          $attachment['file_size']
        )) {
          throw new RuntimeException('첨부파일 정보를 저장하지 못했습니다.');
        }
      }


      // 삭제 목록 조회하여 attachment table에서 삭제
      foreach ($deletedAttachmentIds as $attachmentId) {
        $deletedAttachment = $this->repo->deleteAttachment($id, $attachmentId);

        if ($deletedAttachment !== false) {
          $deletedAttachmentNames[] = $deletedAttachment['stored_name'];
        }
      }

      $this->repo->commit();

      foreach ($deletedImageKeys as $key) {
        $this->deleteImageFiles($key);
      }

      foreach ($deletedAttachmentNames as $storedName) {
        $this->deleteAttachmentFile($storedName);
      }

      return [
        'success' => $success,
        'message' => $success
          ? '게시글이 수정되었습니다.'
          : '게시글을 수정하지 못했습니다.',
      ];
    } catch (Throwable $e) {
      $this->repo->rollback();

      foreach ($savedKeys as $key) {
        $this->deleteImageFiles($key);
      }

      foreach ($savedAttachmentNames as $storedName) {
        $this->deleteAttachmentFile($storedName);
      }

      return $this->failure(
        $e,
        '게시글 수정 중 오류가 발생했습니다.'
      );
    }
  }

  public function delete(string $userId, int $id): bool
  {
    return $this->repo->deletePost($userId, $id);
  }


  private function normalizeUploads(
    ?array $images,
    array $previewUrls
  ): array {
    if ($images === null) {
      if ($previewUrls !== []) {
        throw new InvalidArgumentException(
          '이미지 파일이 전달되지 않았습니다.'
        );
      }

      return [];
    }

    $errors = $images['error'] ?? null;
    $paths = $images['tmp_name'] ?? null;

    if (
      !is_array($errors) ||
      !is_array($paths) ||
      count($previewUrls) > self::MAX_IMAGES ||
      count($errors) !== count($previewUrls) ||
      count($paths) !== count($previewUrls)
    ) {
      throw new InvalidArgumentException(
        '이미지 요청이 올바르지 않거나 최대 10장을 초과했습니다.'
      );
    }

    $uploads = [];

    foreach ($previewUrls as $index => $url) {
      if (
        !is_string($url) ||
        strlen($url) > 2048 ||
        !str_starts_with($url, 'blob:') ||
        isset($uploads[$url]) ||
        !array_key_exists($index, $errors) ||
        !array_key_exists($index, $paths) ||
        !is_int($errors[$index]) ||
        !is_string($paths[$index])
      ) {
        throw new InvalidArgumentException(
          '이미지 파일과 미리보기 정보가 올바르지 않습니다.'
        );
      }

      $uploads[$url] = [
        'error' => $errors[$index],
        'tmp_name' => $paths[$index],
      ];
    }

    return $uploads;
  }

  private function normalizeAttachments(?array $files): array
  {
    if ($files === null) {
      return [];
    }

    $errors = $files['error'] ?? [];
    $temporaryPaths = $files['tmp_name'] ?? [];
    $names = $files['name'] ?? [];
    $types = $files['type'] ?? [];
    $sizes = $files['size'] ?? [];

    if (
      !is_array($errors) ||
      !is_array($temporaryPaths) ||
      !is_array($names) ||
      !is_array($types) ||
      !is_array($sizes) ||
      count($errors) > self::MAX_ATTACHMENT_COUNT
    ) {
      throw new InvalidArgumentException(
        '첨부파일은 최대 10개까지 업로드할 수 있습니다.'
      );
    }

    $attachments = [];

    foreach ($errors as $index => $error) {
      if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
      }

      if (
        !array_key_exists($index, $temporaryPaths) ||
        !array_key_exists($index, $names) ||
        !array_key_exists($index, $types) ||
        !array_key_exists($index, $sizes) ||
        !is_int($error) ||
        !is_string($temporaryPaths[$index]) ||
        !is_string($names[$index]) ||
        !is_string($types[$index]) ||
        !is_int($sizes[$index])
      ) {
        throw new InvalidArgumentException('첨부파일 정보가 올바르지 않습니다.');
      }

      $attachment = [
        'error' => $error,
        'tmp_name' => $temporaryPaths[$index],
        'original_name' => basename($names[$index]),
        'mime_type' => $types[$index],
        'file_size' => $sizes[$index],
      ];

      $this->validateAttachment($attachment);
      $attachments[] = $attachment;
    }

    return $attachments;
  }

  private function normalizeDeletedAttachmentIds(array $ids): array
  {
    $normalized = [];

    foreach ($ids as $id) {
      if (
        (!is_string($id) && !is_int($id)) ||
        filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
      ) {
        throw new InvalidArgumentException('삭제할 첨부파일 정보가 올바르지 않습니다.');
      }

      $normalized[(int) $id] = true;
    }

    return array_keys($normalized);
  }

  private function saveAttachment(array $attachment): string
  {
    $this->validateAttachment($attachment);

    $temporaryPath = $attachment['tmp_name'];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extension = strtolower(pathinfo($attachment['original_name'], PATHINFO_EXTENSION));

    $this->ensureDirectory($this->attachmentDirectory);
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;

    if (!move_uploaded_file(
      $temporaryPath,
      $this->attachmentDirectory . '/' . $storedName
    )) {
      throw new RuntimeException('첨부파일을 저장하지 못했습니다.');
    }

    @chmod($this->attachmentDirectory . '/' . $storedName, 0640);

    return $storedName;
  }

  private function validateAttachment(array $attachment): void
  {
    if (($attachment['error'] ?? null) !== UPLOAD_ERR_OK) {
      throw new InvalidArgumentException('첨부파일 업로드에 실패했습니다.');
    }

    $temporaryPath = $attachment['tmp_name'] ?? null;

    if (
      !is_string($temporaryPath) ||
      !is_uploaded_file($temporaryPath) ||
      ($attachment['file_size'] ?? 0) <= 0 ||
      $attachment['file_size'] > self::MAX_ATTACHMENT_BYTES
    ) {
      throw new InvalidArgumentException(
        '첨부파일은 10MiB 이하이어야 합니다.'
      );
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extension = strtolower(pathinfo($attachment['original_name'], PATHINFO_EXTENSION));

    $allowedMimeTypes = match ($extension) {
      'pdf' => ['application/pdf'],
      'doc' => ['application/msword', 'application/octet-stream'],
      'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
      ],
      'xml' => ['application/xml', 'text/xml', 'application/octet-stream'],
      'hwp' => [
        'application/x-hwp',
        'application/haansoft-hwp',
        'application/vnd.hancom.hwp',
        'application/octet-stream',
      ],
      'hwpx' => [
        'application/zip',
        'application/vnd.hancom.hwpx',
        'application/x-hwpx',
        'application/octet-stream',
      ],
      default => [],
    };

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
      throw new InvalidArgumentException(
        'PDF, DOC, DOCX, XML, HWP, HWPX 파일만 첨부할 수 있습니다.'
      );
    }
  }

  private function deleteAttachmentFile(string $storedName): void
  {
    $path = $this->attachmentDirectory . '/' . $storedName;

    if (is_file($path) && !@unlink($path)) {
      error_log('첨부파일 정리 실패: ' . $path);
    }
  }

  private function validateContentInput(
    string $title,
    string $content
  ): void {
    if ($title === '' || trim($content) === '') {
      throw new InvalidArgumentException(
        '제목과 내용을 입력해주세요.'
      );
    }

    if (strlen($content) > self::MAX_HTML_BYTES) {
      throw new InvalidArgumentException(
        '본문 HTML은 1MiB 이하여야 합니다.'
      );
    }
  }

  private function parseHtml(string $html): array
  {
    if (!class_exists(DOMDocument::class)) {
      throw new RuntimeException(
        'PHP DOM 확장이 필요합니다.'
      );
    }

    $previous = libxml_use_internal_errors(true);

    try {
      $document = new DOMDocument('1.0', 'UTF-8');

      $loaded = $document->loadHTML(
        '<!doctype html><html><head>'
          . '<meta charset="utf-8">'
          . '</head><body>'
          . $html
          . '</body></html>',
        LIBXML_NONET
      );

      $body = $document->getElementsByTagName('body')->item(0);

      if (!$loaded || !($body instanceof DOMElement)) {
        throw new InvalidArgumentException(
          '본문 HTML을 읽지 못했습니다.'
        );
      }

      return [$document, $body];
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  private function assertNotEmpty(DOMElement $body): void
  {
    $text = preg_replace(
      '/[\s\x{00A0}\x{200B}]+/u',
      '',
      $body->textContent
    );

    if (
      ($text === null || $text === '') &&
      $body->getElementsByTagName('img')->length === 0
    ) {
      throw new InvalidArgumentException(
        '내용 또는 이미지를 입력해주세요.'
      );
    }
  }

  private function bodyHtml(
    DOMDocument $document,
    DOMElement $body
  ): string {
    $html = '';

    foreach ($body->childNodes as $node) {
      $part = $document->saveHTML($node);

      if ($part === false) {
        throw new RuntimeException(
          '본문 HTML을 변환하지 못했습니다.'
        );
      }

      $html .= $part;
    }

    return $html;
  }

  private function originalUrl(string $key): string
  {
    return '/images/file?variant=original&key='
      . rawurlencode($key);
  }

  /**
   * 실제 업로드 파일을 검증하고 원본만 저장
   */
  private function uploadImage(array $image): string
  {
    $uploadError = $image['error'] ?? null;

    if ($uploadError !== UPLOAD_ERR_OK) {
      $message = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => '이미지 파일이 서버 업로드 제한을 초과했습니다.',
        UPLOAD_ERR_PARTIAL => '이미지 업로드가 중간에 중단되었습니다.',
        UPLOAD_ERR_NO_FILE => '업로드된 이미지 파일이 없습니다.',
        default => '이미지 업로드에 실패했습니다.',
      };

      throw new InvalidArgumentException(
        $message . ' 파일 용량을 확인해주세요.'
      );
    }

    $temporaryPath = $image['tmp_name'] ?? null;

    if (
      !is_string($temporaryPath) ||
      !is_uploaded_file($temporaryPath)
    ) {
      throw new InvalidArgumentException(
        '정상적으로 업로드된 파일이 아닙니다.'
      );
    }

    $size = filesize($temporaryPath);

    if (
      $size === false ||
      $size <= 0 ||
      $size > self::MAX_IMAGE_BYTES
    ) {
      throw new InvalidArgumentException(
        '이미지는 비어 있지 않은 5MiB 이하 파일이어야 합니다.'
      );
    }

    if (
      !extension_loaded('gd') ||
      !extension_loaded('fileinfo')
    ) {
      throw new RuntimeException(
        'GD와 Fileinfo 확장이 필요합니다.'
      );
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))
      ->file($temporaryPath);

    $extension = match ($mime) {
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      default => throw new InvalidArgumentException(
        'JPEG와 PNG 이미지만 첨부할 수 있습니다.'
      ),
    };

    $info = @getimagesize($temporaryPath);

    if (
      $info === false ||
      ($info['mime'] ?? null) !== $mime
    ) {
      throw new InvalidArgumentException(
        '올바른 이미지 파일이 아닙니다.'
      );
    }

    [$width, $height] = $info;

    if (
      $width < 1 ||
      $height < 1 ||
      $width > 8000 ||
      $height > 8000 ||
      $width * $height > 4_000_000
    ) {
      throw new InvalidArgumentException(
        '이미지는 최대 400만 픽셀이며 각 변은 8000px 이하여야 합니다.'
      );
    }

    // 실제 디코딩 검증만 하며 썸네일은 생성X
    $decoded = match ($mime) {
      'image/jpeg' => @imagecreatefromjpeg($temporaryPath),
      'image/png' => @imagecreatefrompng($temporaryPath),
    };

    if ($decoded === false) {
      throw new InvalidArgumentException(
        '손상되었거나 처리할 수 없는 이미지입니다.'
      );
    }

    unset($decoded);

    $this->ensureDirectory($this->originalDirectory);

    $key = bin2hex(random_bytes(16)) . '.' . $extension;
    $originalPath = $this->originalDirectory . '/' . $key;

    try {
      if (!move_uploaded_file($temporaryPath, $originalPath)) {
        throw new RuntimeException(
          '원본 이미지를 저장하지 못했습니다.'
        );
      }

      return $key;
    } catch (Throwable $e) {
      $this->deleteImageFiles($key);
      throw $e;
    }
  }

  public function imageFile(
    string $key,
    string $variant,
    ?int $requestedWidth = null,
    ?int $requestedHeight = null
  ): ?array {
    if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png)\z/', $key)) {
      return null;
    }

    if (!in_array($variant, ['original', 'thumbnail'], true)) {
      return null;
    }

    $originalPath = $this->originalDirectory . '/' . $key;

    if (
      !is_file($originalPath) ||
      !is_readable($originalPath)
    ) {
      return null;
    }

    $mime = str_ends_with($key, '.png')
      ? 'image/png'
      : 'image/jpeg';

    if ($variant === 'original') {
      return [
        'path' => $originalPath,
        'mime' => $mime,
      ];
    }

    if ($requestedWidth !== null || $requestedHeight !== null) {
      if (
        $requestedWidth === null ||
        $requestedHeight === null ||
        $requestedWidth < 1 ||
        $requestedHeight < 1 ||
        $requestedWidth > self::MAX_RESIZED_IMAGE_WIDTH ||
        $requestedHeight > self::MAX_RESIZED_IMAGE_HEIGHT ||
        $requestedWidth * $requestedHeight > 4_000_000
      ) {
        return null;
      }

      $resizedPath = $this->thumbnailDirectory . '/'
        . pathinfo($key, PATHINFO_FILENAME) . '-'
        . $requestedWidth . 'x' . $requestedHeight
        . '.' . pathinfo($key, PATHINFO_EXTENSION);

      try {
        $this->ensureResizedImage(
          $originalPath,
          $resizedPath,
          $mime,
          $requestedWidth,
          $requestedHeight
        );

        return [
          'path' => $resizedPath,
          'mime' => $mime,
        ];
      } catch (Throwable $e) {
        error_log((string) $e);
        return null;
      }
    }

    return null;
  }

  /**
   * 썸네일 조회 시에만 호출
   * 같은 파일에 대한 동시 생성을 잠금으로 제한
   */
  private function ensureThumbnail(
    string $originalPath,
    string $thumbnailPath,
    string $mime
  ): void {
    if ($this->hasThumbnail($thumbnailPath)) {
      return;
    }

    $this->ensureDirectory($this->thumbnailDirectory);

    $lock = @fopen($thumbnailPath . '.lock', 'c');

    if ($lock === false) {
      throw new RuntimeException(
        '썸네일 잠금 파일을 열지 못했습니다.'
      );
    }

    $temporaryPath = null;
    $locked = false;

    try {
      $locked = flock($lock, LOCK_EX);

      if (!$locked) {
        throw new RuntimeException(
          '썸네일 생성 잠금을 얻지 못했습니다.'
        );
      }

      if ($this->hasThumbnail($thumbnailPath)) {
        return;
      }

      $temporaryPath = tempnam(
        $this->thumbnailDirectory,
        '.thumb-'
      );

      if ($temporaryPath === false) {
        $temporaryPath = null;

        throw new RuntimeException(
          '썸네일 임시 파일을 만들지 못했습니다.'
        );
      }

      $this->createThumbnail(
        $originalPath,
        $temporaryPath,
        $mime
      );

      if (!rename($temporaryPath, $thumbnailPath)) {
        throw new RuntimeException(
          '썸네일을 최종 경로에 저장하지 못했습니다.'
        );
      }

      // Apache와 localhost 개발 서버가 모두 썸네일을 읽을 수 있게 함
      @chmod($thumbnailPath, 0644);

      $temporaryPath = null;
    } finally {
      if ($temporaryPath !== null && is_file($temporaryPath)) {
        @unlink($temporaryPath);
      }

      if ($locked) {
        flock($lock, LOCK_UN);
      }

      fclose($lock);
    }
  }

  private function hasThumbnail(string $path): bool
  {
    clearstatcache(true, $path);

    return is_file($path)
      && is_readable($path)
      && filesize($path) > 0;
  }

  private function createThumbnail(
    string $originalPath,
    string $thumbnailPath,
    string $mime
  ): void {
    if (!extension_loaded('gd')) {
      throw new RuntimeException(
        'GD 확장이 필요합니다.'
      );
    }

    $source = match ($mime) {
      'image/jpeg' => @imagecreatefromjpeg($originalPath),
      'image/png' => @imagecreatefrompng($originalPath),
      default => false,
    };

    if ($source === false) {
      throw new InvalidArgumentException(
        '원본 이미지를 읽지 못했습니다.'
      );
    }

    $thumbnail = null;

    try {
      $width = imagesx($source);
      $height = imagesy($source);

      $targetWidth = self::THUMBNAIL_WIDTH;
      $targetHeight = self::THUMBNAIL_HEIGHT;

      $thumbnail = imagecreatetruecolor(
        $targetWidth,
        $targetHeight
      );

      if ($thumbnail === false) {
        throw new RuntimeException(
          '썸네일 작업 이미지를 만들지 못했습니다.'
        );
      }

      if ($mime === 'image/png') {
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);

        $transparent = imagecolorallocatealpha(
          $thumbnail,
          0,
          0,
          0,
          127
        );

        imagefill($thumbnail, 0, 0, $transparent);
      }

      if (!imagecopyresampled(
        $thumbnail,
        $source,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $width,
        $height
      )) {
        throw new RuntimeException(
          '이미지를 축소하지 못했습니다.'
        );
      }

      $saved = match ($mime) {
        'image/jpeg' => imagejpeg(
          $thumbnail,
          $thumbnailPath,
          85
        ),
        'image/png' => imagepng(
          $thumbnail,
          $thumbnailPath,
          6
        ),
      };

      if (!$saved || !$this->hasThumbnail($thumbnailPath)) {
        throw new RuntimeException(
          '썸네일 파일을 저장하지 못했습니다.'
        );
      }
    } finally {
      unset($thumbnail, $source);
    }
  }

  private function ensureResizedImage(
    string $originalPath,
    string $resizedPath,
    string $mime,
    int $width,
    int $height
  ): void {
    if ($this->hasThumbnail($resizedPath)) {
      return;
    }

    $this->ensureDirectory($this->thumbnailDirectory);

    $lock = @fopen($resizedPath . '.lock', 'c');
    if ($lock === false) {
      throw new RuntimeException('리사이징 잠금 파일을 열지 못했습니다.');
    }

    $temporaryPath = null;
    $locked = false;

    try {
      $locked = flock($lock, LOCK_EX);
      if (!$locked) {
        throw new RuntimeException('리사이징 잠금을 얻지 못했습니다.');
      }

      if ($this->hasThumbnail($resizedPath)) {
        return;
      }

      $temporaryPath = tempnam($this->thumbnailDirectory, '.resize-');
      if ($temporaryPath === false) {
        $temporaryPath = null;
        throw new RuntimeException('리사이징 임시 파일을 만들지 못했습니다.');
      }

      $this->createResizedImage(
        $originalPath,
        $temporaryPath,
        $mime,
        $width,
        $height
      );

      if (!rename($temporaryPath, $resizedPath)) {
        throw new RuntimeException('리사이징 파일을 저장하지 못했습니다.');
      }

      @chmod($resizedPath, 0644);
      $temporaryPath = null;
    } finally {
      if ($temporaryPath !== null && is_file($temporaryPath)) {
        @unlink($temporaryPath);
      }
      if ($locked) {
        flock($lock, LOCK_UN);
      }
      fclose($lock);
    }
  }

  private function createResizedImage(
    string $originalPath,
    string $resizedPath,
    string $mime,
    int $targetWidth,
    int $targetHeight
  ): void {
    $source = match ($mime) {
      'image/jpeg' => @imagecreatefromjpeg($originalPath),
      'image/png' => @imagecreatefrompng($originalPath),
      default => false,
    };

    if ($source === false) {
      throw new InvalidArgumentException('원본 이미지를 읽지 못했습니다.');
    }

    $resized = null;

    try {
      $resized = imagecreatetruecolor($targetWidth, $targetHeight);
      if ($resized === false) {
        throw new RuntimeException('리사이징 작업 이미지를 만들지 못했습니다.');
      }

      if ($mime === 'image/png') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
      }

      if (!imagecopyresampled(
        $resized,
        $source,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        imagesx($source),
        imagesy($source)
      )) {
        throw new RuntimeException('이미지를 리사이징하지 못했습니다.');
      }

      $saved = match ($mime) {
        'image/jpeg' => imagejpeg($resized, $resizedPath, 85),
        'image/png' => imagepng($resized, $resizedPath, 6),
      };

      if (!$saved || !$this->hasThumbnail($resizedPath)) {
        throw new RuntimeException('리사이징 파일을 저장하지 못했습니다.');
      }
    } finally {
      unset($resized, $source);
    }
  }

  private function ensureDirectory(string $directory): void
  {
    if (
      !is_dir($directory) &&
      !@mkdir($directory, 0755, true) &&
      !is_dir($directory)
    ) {
      throw new RuntimeException(
        '이미지 저장 폴더를 만들지 못했습니다.'
      );
    }

    if (!is_writable($directory)) {
      throw new RuntimeException(
        '이미지 저장 폴더에 쓰기 권한이 없습니다.'
      );
    }
  }

  /**
   * 이번 요청에서 생성한 키에 대해서만 호출
   */
  private function deleteImageFiles(string $key): void
  {
    foreach (
      [$this->originalDirectory, $this->thumbnailDirectory]
      as $directory
    ) {
      $path = $directory . '/' . $key;

      if (is_file($path) && !@unlink($path)) {
        error_log('이미지 파일 정리 실패: ' . $path);
      }

      $lockPath = $path . '.lock';

      if (is_file($lockPath) && !@unlink($lockPath)) {
        error_log('이미지 잠금 파일 정리 실패: ' . $lockPath);
      }
    }
  }

  private function imageKeyFromSource(string $source): ?string
  {
    $parts = parse_url($source);

    if (($parts['path'] ?? null) !== '/images/file') {
      return null;
    }

    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $key = $query['key'] ?? null;

    return is_string($key) && preg_match(
      '/\A[a-f0-9]{32}\.(jpg|png)\z/',
      $key
    ) ? $key : null;
  }

  private function failure(
    Throwable $e,
    string $fallbackMessage
  ): array {
    error_log(sprintf(
      '[PostService] %s: %s in %s:%d',
      get_class($e),
      $e->getMessage(),
      $e->getFile(),
      $e->getLine()
    ));

    return [
      'success' => false,
      'message' => $e instanceof InvalidArgumentException
        ? $e->getMessage()
        : $fallbackMessage,
    ];
  }
}
