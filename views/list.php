<?php

$pageTitle = 'LIST';


?>

<div class="list">
    <div class="btn_wrap">
        <h1>LIST</h1>
        <?php if (!empty($_SESSION['user'])): ?>
        <button onclick="location.href='/write'">글쓰기</button>
        <?php endif; ?>
    </div>

    <form class="search" method="get" action="/list">
        <input type="search" id="search" name="keyword" class="search_input" value="<?= e($keyword ?? '') ?>"
            placeholder="검색어를 입력해주세요." />
        <button type="submit" class="search_submit">검색</button>
    </form>

    <?php if (empty($posts)): ?>
    <div class="none_content">
        <p>등록된 게시글이 없습니다.</p>
    </div>
    <?php else: ?>
    <ul class="post_list">
        <?php foreach ($posts as $post): ?>
        <li class="post_item" onclick="location.href='/post/<?= (int) $post['id'] ?>'"
            onkeydown="if (event.key === 'Enter') location.href='/post/<?= (int) $post['id'] ?>'" tabindex="0">

            <time class="date_col" datetime="<?= e($post['created_at']) ?>">
                <?= e(formatDatetime($post['created_at'])) ?>
            </time>


            <div class="post_summary">
                <a href="/post/<?= (int) $post['id'] ?>"><?= e($post['title']) ?></a>
                <p><?= e(htmlToPlainText($post['content'])) ?></p>
            </div>

            <div class="thumbnail_col">
                <?php if (!empty($post['thumbnail_image_key'])): ?>
                <img src="/images/file?key=<?= urlencode($post['thumbnail_image_key']) ?>&amp;variant=thumbnail&amp;w=120&amp;h=80"
                    alt="<?= e($post['title']) ?> 썸네일" loading="lazy">
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>