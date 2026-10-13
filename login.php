<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$setup = !users_exist();   // İlk kurulum: henüz kullanıcı yoksa yönetici hesabı oluşturulur
$errors = [];

if (is_post()) {
    verify_csrf();

    if ($setup) {
        $name = input('name');
        $email = mb_strtolower(input('email'));
        $pass = $_POST['password'] ?? '';
        if ($name === '') $errors['name'] = 'Adınızı yazın.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Geçerli bir e-posta yazın.';
        if (mb_strlen($pass) < 8) $errors['password'] = 'Şifre en az 8 karakter olmalı.';
        if ($pass !== ($_POST['password2'] ?? '')) $errors['password2'] = 'Şifreler eşleşmiyor.';

        if (!$errors && !users_exist()) {
            q("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')", [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            attempt_login($email, $pass);
            flash('success', 'Hoş geldiniz! Yönetici hesabınız oluşturuldu.');
            redirect('dashboard.php');
        }
    } elseif (login_locked()) {
        $errors['form'] = 'Çok fazla hatalı deneme yapıldı. ' . LOGIN_LOCK_MINUTES . ' dakika sonra tekrar deneyin.';
    } elseif (attempt_login(mb_strtolower(input('email')), $_POST['password'] ?? '')) {
        redirect('dashboard.php');
    } else {
        $errors['form'] = 'E-posta veya şifre hatalı.';
    }
}

bare_start($setup ? 'Kurulum' : 'Giriş');
?>
<div class="auth-card">
    <div class="brand"><?= logo_svg() ?><span><?= e(setting('company_name') ?: config('app_name')) ?></span></div>
    <h1><?= $setup ? 'Yönetici hesabı oluştur' : 'Panele giriş' ?></h1>
    <p class="muted"><?= $setup
        ? 'İlk kurulum: bu hesap paneli yönetecek. Ortaklarınızı daha sonra Ayarlar\'dan ekleyebilirsiniz.'
        : 'Müşteri ve ödeme takip paneli' ?></p>

    <?php if (!empty($errors['form'])): ?>
        <div class="flash flash-error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div>
    <?php endif; ?>

    <form method="post" class="card form" novalidate>
        <?= csrf_field() ?>
        <?php if ($setup): ?>
            <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
                <label for="name">Ad soyad</label>
                <input id="name" name="name" value="<?= e(old('name')) ?>" autocomplete="name" required>
                <?php if (isset($errors['name'])): ?><span class="error-text"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">E-posta</label>
            <input id="email" name="email" type="email" value="<?= e(old('email')) ?>" autocomplete="username" inputmode="email" required>
            <?php if (isset($errors['email'])): ?><span class="error-text"><?= e($errors['email']) ?></span><?php endif; ?>
        </div>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
            <label for="password">Şifre</label>
            <input id="password" name="password" type="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required>
            <?php if (isset($errors['password'])): ?><span class="error-text"><?= e($errors['password']) ?></span><?php endif; ?>
        </div>
        <?php if ($setup): ?>
            <div class="field<?= isset($errors['password2']) ? ' has-error' : '' ?>">
                <label for="password2">Şifre (tekrar)</label>
                <input id="password2" name="password2" type="password" autocomplete="new-password" required>
                <?php if (isset($errors['password2'])): ?><span class="error-text"><?= e($errors['password2']) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
        <button class="btn block" type="submit"><?= $setup ? 'Hesabı oluştur' : 'Giriş yap' ?></button>
    </form>
</div>
<?php bare_end();
