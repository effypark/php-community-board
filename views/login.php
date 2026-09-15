<?php

$pageTitle = 'LOGIN';


?>

<div class="login">
    <h1>LOGIN</h1>

    <?php if (!empty($errors)): ?>
    <ul role="alert">
        <?php foreach ($errors as $error): ?>
        <li>
            <?= e($error) ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form action="/login" method="post">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="text" id="user_id" name="userid" placeholder="아이디를 입력하세요" required>
        <input type="password" id="password" name="password" placeholder="비밀번호를 입력하세요" autocomplete="current-password"
            required>
        <button type="submit">로그인</button>
    </form>
    <button type='button' class="signup_btn" id="signup_btn">
        <a href="/signup">회원가입</a>
    </button>
</div>