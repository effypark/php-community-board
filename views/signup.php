<?php
$pageTitle = 'SIGNUP';


?>

<div class="signup">
    <h1>SIGNUP</h1>

    <?php if (!empty($errors)): ?>
    <ul role="alert">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form action="/signup" method="post">

        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <div>
            <label for="user_id">ID</label>
            <div class="check_btn_wrap">
                <input type="text" id="user_id" name="userid" autocomplete="username" aria-describedby="user_id_message"
                    required>
                <button type="button" id="check_id_btn">
                    중복확인
                </button>
            </div>
        </div>

        <p id="user_id_message" role="status" aria-live="polite"></p>

        <div>
            <label for="password">PASSWORD</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required>
        </div>

        <div>
            <label for="username">NAME</label>
            <input type="text" id="username" name="username" required>
        </div>

        <div>
            <label for="email">EMAIL</label>
            <input type="email" id="email" name="email" required>
        </div>

        <button type="submit" id="submit_btn" disabled>가입하기</button>
    </form>
</div>