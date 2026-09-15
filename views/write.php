<?php

$load_editor = true;

$modifyId = $postId ?? null;
$isEdit = $modifyId !== null;

$pageTitle = $isEdit ? 'EDIT' : 'WRITE';

$title = $title ?? '';
$content = $content ?? '';
$errors = $errors ?? [];

$thumbnailImageKey = $thumbnailImageKey
    ?? ($post['thumbnail_image_key'] ?? '');


$contentFormat = 'html'

?>

<div class="write">
    <div class="btn_wrap">
        <h1><?= e($pageTitle) ?></h1>

        <button type="button" onclick="location.href='/list'">
            목록
        </button>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="write-errors" role="alert">
        <?php foreach ($errors as $error): ?>
        <p><?= e($error) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form id="write-form" action="/write" method="post" enctype="multipart/form-data"
        data-content-format="<?= e($contentFormat) ?>" <?php if ($isEdit): ?> data-edit-form <?php endif; ?>>
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">

        <input type="hidden" name="content_format" value="<?= e($contentFormat) ?>">

        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= e((string) $modifyId) ?>">

        <input type="hidden" id="thumbnail-image-key" name="thumbnail_image_key" value="<?= e($thumbnailImageKey) ?>">
        <?php endif; ?>


        <input type="text" id="post-title" name="title" placeholder="제목을 입력하세요" value="<?= e($title) ?>" required>

        <textarea id="content" name="content" hidden><?= e($content) ?></textarea>

        <div id="editor"></div>

        <p id="content-error" role="alert" hidden>
            내용을 입력하세요.
        </p>

        <div class="file_upload_box" data-file-dropzone>
            <input type="file" id="file_upload" name="file_upload[]"
                accept=".pdf,.doc,.docx,.xml,.hwp,.hwpx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                multiple hidden>
            <label class="file_dropzone" for="file_upload" tabindex="0">
                <strong>첨부할 파일을 여기에 끌어다 놓거나, 클릭해서 파일을 선택하세요</strong>
                <small>PDF, DOC, DOCX, XML, HWP, HWPX</small>
            </label>
            <ul class="file_list" data-file-list aria-live="polite"></ul>
            <?php if ($isEdit && !empty($post['attachments'])): ?>
            <ul class="file_list existing_file_list">
                <?php foreach ($post['attachments'] as $attachment): ?>
                <li data-existing-attachment data-attachment-id="<?= (int) $attachment['id'] ?>">
                    <span><?= e($attachment['original_name']) ?></span>
                    <button type="button" data-delete-attachment
                        aria-label="<?= e($attachment['original_name']) ?> 삭제">삭제</button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <div class="submit_btn_wrap">
            <button type="submit" class="submit_btn">
                <?= $isEdit ? '수정' : '작성' ?>
            </button>
        </div>
    </form>
</div>