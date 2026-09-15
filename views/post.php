<?php

$load_viewer = true;

$pageTitle = 'POST';
$post = $post ?? false;
$postId = $params['id'] ?? null;


?>

<div class="detail">
    <div class="btn_wrap">
        <h1>DETAIL</h1>
        <button onclick="location.href='/list'">목록</button>
    </div>
    <?php if ($post === false): ?>
    <p>게시글을 찾을 수 없습니다.</p>
    <?php else: ?>


    <div class="content">
        <ul>
            <li>
                <div class='th'>제목</div>
                <span><?= e($post['title']) ?></span>
            </li>
            <li>
                <div class='th'>작성자</div>
                <span><?= e($post['username']) ?></span>
            </li>
            <li>
                <textarea id="post-markdown" hidden><?= e($post['content']) ?></textarea>

                <!-- 본문이 렌더링되는 영역입니다. -->
                <div id="viewer"></div>


                <textarea id="post-html" hidden><?= e($post['content']) ?></textarea>

                <div id="html-viewer"></div>
            </li>
            <?php if (!empty($post['attachments'])): ?>
            <li>
                <div class="th">첨부파일</div>
                <ul class="attachment_list">
                    <?php foreach ($post['attachments'] as $attachment): ?>
                    <li>
                        <?php
                                    $attachmentUrl = '/attachments/file?id=' . (int) $attachment['id'];
                                    $attachmentExtension = strtolower(pathinfo(
                                        $attachment['original_name'],
                                        PATHINFO_EXTENSION
                                    ));
                                    $canPreview = in_array(
                                        $attachmentExtension,
                                        ['pdf', 'doc', 'docx', 'xml'],
                                        true
                                    );
                                    ?>
                        <span class="attachment_name">
                            <?= e($attachment['original_name']) ?>
                        </span>
                        <span class="attachment_actions">
                            <?php if ($canPreview): ?>
                            <button type="button" class="attachment_preview_button"
                                data-attachment-preview="<?= e($attachmentUrl . '&preview=1') ?>"
                                data-attachment-name="<?= e($attachment['original_name']) ?>"
                                aria-label="<?= e($attachment['original_name']) ?> 미리보기">
                                <span class="svg-icon icon-view" aria-hidden="true"></span>
                            </button>
                            <?php endif; ?>
                            <a class="attachment_download" href="<?= e($attachmentUrl) ?>"
                                aria-label="<?= e($attachment['original_name']) ?> 다운로드">
                                <span class="svg-icon icon-download" aria-hidden="true"></span>
                            </a>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php if (isset($_SESSION['user']['user_id']) && (string) $_SESSION['user']['user_id'] === (string) ($post['user_id'] ?? '')): ?>
    <div class="edit_btn_wrap">
        <button type="button" onclick="location.href='/write?id=<?= $post['id'] ?>'">수정</button>
        <button type="button" data-delete-id="<?= $post['id'] ?>"
            data-csrf-token="<?= e($_SESSION['csrf_token']) ?>">삭제</button>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?php if ($post !== false && !empty($post['attachments'])): ?>
<dialog class="attachment_preview_modal" data-attachment-modal>
    <div class="attachment_preview_header">
        <strong data-attachment-modal-title>첨부파일 미리보기</strong>
        <button type="button" data-attachment-modal-close aria-label="미리보기 닫기">닫기</button>
    </div>
    <iframe data-attachment-modal-frame title="첨부파일 미리보기"></iframe>
</dialog>
<?php endif; ?>