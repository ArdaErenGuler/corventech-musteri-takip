<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$errors = [];
$tab = input('tab', 'profil');

if (is_post()) {
    verify_csrf();
    $action = input('action');

    switch ($action) {
        case 'profile':
            $name = input('name');
            $email = mb_strtolower(input('email'));
            if ($name === '') $errors['name'] = 'Adınızı yazın.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Geçerli bir e-posta yazın.';
            elseif (q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) $errors['email'] = 'Bu e-posta başka bir kullanıcıda kayıtlı.';
            if (!$errors) {
                q('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, $email, $user['id']]);
                flash('success', 'Profil güncellendi.');
                redirect('ayarlar.php?tab=profil');
            }
            break;

        case 'password':
            $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
            $new = $_POST['new_password'] ?? '';
            if (!password_verify($_POST['current_password'] ?? '', $hash)) $errors['current_password'] = 'Mevcut şifre hatalı.';
            if (mb_strlen($new) < 8) $errors['new_password'] = 'Yeni şifre en az 8 karakter olmalı.';
            if ($new !== ($_POST['new_password2'] ?? '')) $errors['new_password2'] = 'Şifreler eşleşmiyor.';
            if (!$errors) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                session_regenerate_id(true);
                flash('success', 'Şifreniz değiştirildi.');
                redirect('ayarlar.php?tab=profil');
            }
            break;

        case 'add_user':
            require_admin();
            $tab = 'kullanicilar';
            $name = input('u_name');
            $email = mb_strtolower(input('u_email'));
            $pass = $_POST['u_password'] ?? '';
            $role = input('u_role') === 'admin' ? 'admin' : 'user';
            if ($name === '') $errors['u_name'] = 'Ad yazın.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['u_email'] = 'Geçerli bir e-posta yazın.';
            elseif (q_val('SELECT id FROM users WHERE email = ?', [$email])) $errors['u_email'] = 'Bu e-posta zaten kayıtlı.';
            if (mb_strlen($pass) < 8) $errors['u_password'] = 'Şifre en az 8 karakter olmalı.';
            if (!$errors) {
                q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
                flash('success', $name . ' eklendi. Giriş bilgilerini kendisine iletebilirsiniz.');
                redirect('ayarlar.php?tab=kullanicilar');
            }
            break;

        case 'toggle_user':
        case 'make_admin':
        case 'make_user':
            require_admin();
            $uid = input_int('user_id') ?? 0;
            if ($uid === (int) $user['id']) {
                flash('error', 'Kendi hesabınızı bu şekilde değiştiremezsiniz.');
            } elseif ($action === 'toggle_user') {
                q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$uid]);
                flash('success', 'Kullanıcı durumu güncellendi.');
            } else {
                q('UPDATE users SET role = ? WHERE id = ?', [$action === 'make_admin' ? 'admin' : 'user', $uid]);
                flash('success', 'Yetki güncellendi.');
            }
            redirect('ayarlar.php?tab=kullanicilar');

        case 'reset_password':
            require_admin();
            $uid = input_int('user_id') ?? 0;
            $pass = $_POST['reset_password'] ?? '';
            if (mb_strlen($pass) < 8) {
                flash('error', 'Yeni şifre en az 8 karakter olmalı.');
            } else {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $uid]);
                flash('success', 'Şifre sıfırlandı.');
            }
            redirect('ayarlar.php?tab=kullanicilar');

        case 'app':
            require_admin();
            $tab = 'uygulama';
            $company = input('company_name');
            $days = input_int('upcoming_days');
            if ($company === '') $errors['company_name'] = 'Firma adını yazın.';
            if (!$days || $days < 1 || $days > 365) $errors['upcoming_days'] = '1 ile 365 arasında bir sayı yazın.';
            if (!$errors) {
                set_setting('company_name', $company);
                set_setting('upcoming_days', (string) $days);
                set_setting('wa_template', input('wa_template'));
                flash('success', 'Uygulama ayarları kaydedildi.');
                redirect('ayarlar.php?tab=uygulama');
            }
            break;
    }
}

$users = is_admin() ? q_all('SELECT * FROM users ORDER BY is_active DESC, role, name') : [];
$me = q_one('SELECT * FROM users WHERE id = ?', [$user['id']]);
$tabs = ['profil' => 'Profil', 'gorunum' => 'Görünüm'];
if (is_admin()) {
    $tabs = ['profil' => 'Profil', 'kullanicilar' => 'Kullanıcılar', 'uygulama' => 'Uygulama', 'gorunum' => 'Görünüm'];
}
$tab = isset($tabs[$tab]) ? $tab : 'profil';

layout_start('Ayarlar', 'settings', ['fab' => false]);
?>
<div class="tabs" role="tablist">
    <?php foreach ($tabs as $k => $label): ?>
        <a class="tab<?= $tab === $k ? ' is-active' : '' ?>" role="tab" href="ayarlar.php?tab=<?= $k ?>"<?= $tab === $k ? ' aria-selected="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'profil'): ?>
    <div class="grid-2">
        <form method="post" class="card form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <h2>Profil</h2>
            <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
                <label for="name">Ad soyad</label>
                <input id="name" name="name" value="<?= e(old('name', $me['name'])) ?>" autocomplete="name">
                <?= field_error($errors, 'name') ?>
            </div>
            <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
                <label for="email">E-posta</label>
                <input id="email" name="email" type="email" value="<?= e(old('email', $me['email'])) ?>" autocomplete="email">
                <?= field_error($errors, 'email') ?>
            </div>
            <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit">Kaydet</button></div>
        </form>

        <form method="post" class="card form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <h2>Şifre değiştir</h2>
            <div class="field<?= isset($errors['current_password']) ? ' has-error' : '' ?>">
                <label for="current_password">Mevcut şifre</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                <?= field_error($errors, 'current_password') ?>
            </div>
            <div class="field<?= isset($errors['new_password']) ? ' has-error' : '' ?>">
                <label for="new_password">Yeni şifre</label>
                <input id="new_password" name="new_password" type="password" autocomplete="new-password">
                <?= field_error($errors, 'new_password') ?>
            </div>
            <div class="field<?= isset($errors['new_password2']) ? ' has-error' : '' ?>">
                <label for="new_password2">Yeni şifre (tekrar)</label>
                <input id="new_password2" name="new_password2" type="password" autocomplete="new-password">
                <?= field_error($errors, 'new_password2') ?>
            </div>
            <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit">Şifreyi değiştir</button></div>
        </form>
    </div>

<?php elseif ($tab === 'kullanicilar'): ?>
    <p class="muted small">Ortaklarınızı ekleyin; herkes kendi e-posta ve şifresiyle giriş yapar. Yöneticiler kullanıcıları ve uygulama ayarlarını yönetebilir.</p>
    <div class="list">
        <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $user['id']; ?>
            <div class="item<?= $u['is_active'] ? '' : ' is-done' ?>">
                <span class="avatar"><?= e(initials($u['name'])) ?></span>
                <div class="item-main">
                    <span class="item-title"><?= e($u['name']) ?><?= $self ? ' <span class="muted small">(siz)</span>' : '' ?></span>
                    <div class="item-sub">
                        <span><?= e($u['email']) ?></span>
                        <span>Son giriş: <?= datetime_tr($u['last_login_at']) ?></span>
                    </div>
                    <div class="item-sub">
                        <?= badge($u['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı', $u['role'] === 'admin' ? 'accent' : 'neutral') ?>
                        <?php if (!$u['is_active']): ?><?= badge('Pasif', 'neutral') ?><?php endif; ?>
                    </div>
                    <?php if (!$self): ?>
                        <details class="mt" style="margin-top:8px">
                            <summary class="small" style="cursor:pointer;color:var(--accent-text);font-weight:600">İşlemler</summary>
                            <div class="row mt" style="margin-top:10px">
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn sm ghost" type="submit" name="action" value="toggle_user"><?= $u['is_active'] ? 'Pasif yap' : 'Aktif yap' ?></button>
                                    <button class="btn sm ghost" type="submit" name="action" value="<?= $u['role'] === 'admin' ? 'make_user' : 'make_admin' ?>"><?= $u['role'] === 'admin' ? 'Yöneticiliği kaldır' : 'Yönetici yap' ?></button>
                                </form>
                            </div>
                            <form method="post" class="row mt" style="margin-top:10px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <input name="reset_password" type="password" autocomplete="new-password" placeholder="Yeni şifre (en az 8)" style="flex:1;min-width:180px" aria-label="Yeni şifre">
                                <button class="btn sm soft" type="submit">Şifreyi sıfırla</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post" class="card form mt" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <h2>Kullanıcı ekle</h2>
        <div class="form-grid">
            <div class="field<?= isset($errors['u_name']) ? ' has-error' : '' ?>">
                <label for="u_name">Ad soyad</label>
                <input id="u_name" name="u_name" value="<?= e(old('u_name')) ?>">
                <?= field_error($errors, 'u_name') ?>
            </div>
            <div class="field<?= isset($errors['u_email']) ? ' has-error' : '' ?>">
                <label for="u_email">E-posta</label>
                <input id="u_email" name="u_email" type="email" value="<?= e(old('u_email')) ?>" autocomplete="off">
                <?= field_error($errors, 'u_email') ?>
            </div>
            <div class="field<?= isset($errors['u_password']) ? ' has-error' : '' ?>">
                <label for="u_password">Geçici şifre</label>
                <input id="u_password" name="u_password" type="password" autocomplete="new-password">
                <span class="hint">Kişi giriş yaptıktan sonra Ayarlar'dan değiştirebilir.</span>
                <?= field_error($errors, 'u_password') ?>
            </div>
            <fieldset class="field">
                <legend class="label">Yetki</legend>
                <div class="segmented">
                    <label><input type="radio" name="u_role" value="user"<?= old('u_role', 'user') === 'user' ? ' checked' : '' ?>><span>Kullanıcı</span></label>
                    <label><input type="radio" name="u_role" value="admin"<?= old('u_role') === 'admin' ? ' checked' : '' ?>><span>Yönetici</span></label>
                </div>
            </fieldset>
        </div>
        <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit"><?= icon('plus') ?>Kullanıcı ekle</button></div>
    </form>

<?php elseif ($tab === 'uygulama'): ?>
    <form method="post" class="card form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="app">
        <div class="form-grid">
            <div class="field<?= isset($errors['company_name']) ? ' has-error' : '' ?>">
                <label for="company_name">Firma / panel adı</label>
                <input id="company_name" name="company_name" value="<?= e(old('company_name', setting('company_name') ?: 'Corventech')) ?>">
                <?= field_error($errors, 'company_name') ?>
            </div>
            <div class="field<?= isset($errors['upcoming_days']) ? ' has-error' : '' ?>">
                <label for="upcoming_days">"Yaklaşan ödeme" penceresi (gün)</label>
                <input id="upcoming_days" name="upcoming_days" type="number" inputmode="numeric" min="1" max="365" value="<?= e(old('upcoming_days', setting('upcoming_days') ?: '30')) ?>">
                <span class="hint">Dashboard ve Yenilemeler kaç gün ilerisini gösterecek.</span>
                <?= field_error($errors, 'upcoming_days') ?>
            </div>
            <div class="field span-2">
                <label for="wa_template">WhatsApp hatırlatma mesajı</label>
                <textarea id="wa_template" name="wa_template" rows="4"><?= e(old('wa_template', setting('wa_template') ?? '')) ?></textarea>
                <span class="hint">Kullanılabilir alanlar: {musteri} {firma} {hizmet} {tarih} {tutar} {donem}</span>
            </div>
        </div>
        <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit">Kaydet</button></div>
    </form>

<?php else: ?>
    <section class="card form">
        <h2>Tema</h2>
        <div class="segmented">
            <label><input type="radio" name="theme" value="system" data-theme-choice><span>Sistem</span></label>
            <label><input type="radio" name="theme" value="light" data-theme-choice><span>Açık</span></label>
            <label><input type="radio" name="theme" value="dark" data-theme-choice><span>Koyu</span></label>
        </div>
        <p class="muted small">Seçim bu cihazda saklanır.</p>
    </section>
    <section class="card form">
        <h2>Uygulama olarak yükle</h2>
        <p class="muted small">Paneli telefonunuzun ana ekranına ekleyerek uygulama gibi tam ekran kullanabilirsiniz.</p>
        <button class="btn" type="button" data-install hidden><?= icon('download') ?>Ana ekrana ekle</button>
        <div class="callout"><?= icon('phone') ?><div><b>iPhone:</b> Safari'de Paylaş <span aria-hidden="true">→</span> "Ana Ekrana Ekle".<br><b>Android:</b> Chrome menüsü <span aria-hidden="true">→</span> "Uygulamayı yükle".</div></div>
    </section>
    <p class="muted small">Corventech Panel · sürüm <?= APP_VERSION ?></p>
<?php endif; ?>
<?php layout_end();
