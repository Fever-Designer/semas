<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'System Settings';
$activeNav = 'settings';
$me = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (['university_name', 'theme_gold', 'theme_ink'] as $field) {
        Settings::set($field, trim($_POST[$field] ?? ''), $me['user_id']);
    }

    foreach (['logo_path' => 'logo', 'favicon_path' => 'favicon', 'login_background_path' => 'login_bg', 'login_banner_path' => 'login_banner'] as $settingKey => $fileField) {
        if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES[$fileField]['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/svg+xml' => 'svg'];
            if (isset($allowed[$mime]) && $_FILES[$fileField]['size'] <= 3 * 1024 * 1024) {
                $filename = 'brand_' . $settingKey . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                $dest = __DIR__ . '/../uploads/profile_photos/' . $filename;
                if (move_uploaded_file($_FILES[$fileField]['tmp_name'], $dest)) {
                    Settings::set($settingKey, 'uploads/profile_photos/' . $filename, $me['user_id']);
                }
            }
        }
    }

    AuditLog::record(Auth::id(), 'SYSTEM_SETTINGS_UPDATE', 'system_settings', null);
    flash('success', 'Settings saved.');
    redirect('/admin/settings.php');
}

$settings = Settings::all();
require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">System Settings</h4>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-3">University Branding</h6>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label small">University Name</label><input name="university_name" class="form-control" value="<?= e($settings['university_name'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label small">Theme Gold</label><input type="color" name="theme_gold" class="form-control form-control-color" value="<?= e($settings['theme_gold'] ?? '#D4A24C') ?>"></div>
      <div class="col-md-3"><label class="form-label small">Theme Ink</label><input type="color" name="theme_ink" class="form-control form-control-color" value="<?= e($settings['theme_ink'] ?? '#1E2A52') ?>"></div>
      <div class="col-md-3">
        <label class="form-label small">University Logo</label>
        <?php if (!empty($settings['logo_path'])): ?><div class="mb-1"><img src="<?= APP_URL . '/' . e($settings['logo_path']) ?>" style="height:36px;"></div><?php endif; ?>
        <input type="file" name="logo" class="form-control form-control-sm" accept="image/*">
      </div>
      <div class="col-md-3">
        <label class="form-label small">Favicon</label>
        <?php if (!empty($settings['favicon_path'])): ?><div class="mb-1"><img src="<?= APP_URL . '/' . e($settings['favicon_path']) ?>" style="height:24px;"></div><?php endif; ?>
        <input type="file" name="favicon" class="form-control form-control-sm" accept="image/*">
      </div>
      <div class="col-md-3">
        <label class="form-label small">Login Background</label>
        <?php if (!empty($settings['login_background_path'])): ?><div class="mb-1"><img src="<?= APP_URL . '/' . e($settings['login_background_path']) ?>" style="height:36px;"></div><?php endif; ?>
        <input type="file" name="login_bg" class="form-control form-control-sm" accept="image/*">
      </div>
      <div class="col-md-3">
        <label class="form-label small">Login Banner</label>
        <?php if (!empty($settings['login_banner_path'])): ?><div class="mb-1"><img src="<?= APP_URL . '/' . e($settings['login_banner_path']) ?>" style="height:36px;"></div><?php endif; ?>
        <input type="file" name="login_banner" class="form-control form-control-sm" accept="image/*">
      </div>
    </div>
  </div>

  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-3">Email &amp; SMS</h6>
    <p class="text-muted small mb-2">Configured via <code>.env</code>:</p>
    <ul class="small text-muted">
      <li>Mail host: <?= e(defined('MAIL_HOST') ? MAIL_HOST : '—') ?>, From: <?= e(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '—') ?></li>
      <li>SMS provider: <?= e(defined('SMS_PROVIDER') ? SMS_PROVIDER : '—') ?></li>
    </ul>
  </div>

  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-3">Backup</h6>
    <a href="<?= APP_URL ?>/admin/backup-download.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-download me-1"></i> Download SQL Backup</a>
  </div>

  <button class="btn btn-semas-gold">Save Settings</button>
</form>

<div class="semas-card p-3 mt-4">
  <h6 class="display-font mb-3">Roles &amp; Permissions</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Feature</th><th class="text-center">Principal</th><th class="text-center">HOD</th><th class="text-center">Dean</th><th class="text-center">Lecturer</th><th class="text-center">Student</th></tr></thead>
      <tbody>
        <?php
        $permCell = function ($v) {
            if ($v === true) return '<span class="text-success"><i class="bi bi-check-circle-fill"></i></span>';
            if ($v === false) return '<span class="text-muted"><i class="bi bi-dash"></i></span>';
            return '<span class="badge bg-light text-dark border">' . e($v) . '</span>';
        };
        $permMatrix = [
            ['feature' => 'Create/manage HOD, Dean, Lecturer accounts', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Create Dean accounts', 'Principal' => true, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Manage departments', 'Principal' => true, 'HOD' => 'View only', 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Create/assign modules', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Manage students (own/university scope)', 'Principal' => false, 'HOD' => 'Own dept.', 'Dean' => 'University-wide', 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Academic announcements (students/lecturers)', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => 'Module only', 'Student' => false],
            ['feature' => 'System-wide announcements', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Student announcements (general)', 'Principal' => false, 'HOD' => false, 'Dean' => true, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Event Management', 'Principal' => false, 'HOD' => false, 'Dean' => true, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'CAT/Exam eligibility decisions', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Holidays & Umuganda', 'Principal' => false, 'HOD' => true, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Take/record class attendance', 'Principal' => false, 'HOD' => false, 'Dean' => false, 'Lecturer' => true, 'Student' => 'Self-scan'],
            ['feature' => 'Register for modules', 'Principal' => false, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => true],
            ['feature' => 'Lost & Found: report/claim', 'Principal' => false, 'HOD' => true, 'Dean' => true, 'Lecturer' => true, 'Student' => true],
            ['feature' => 'Lost & Found: approve claims', 'Principal' => false, 'HOD' => false, 'Dean' => true, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Lost & Found: view statistics', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Module & attendance reports (read-only)', 'Principal' => true, 'HOD' => 'Own academic scope', 'Dean' => false, 'Lecturer' => false, 'Student' => false],
            ['feature' => 'Audit log', 'Principal' => true, 'HOD' => false, 'Dean' => false, 'Lecturer' => false, 'Student' => false],
        ];
        foreach ($permMatrix as $row): ?>
          <tr>
            <td><?= e($row['feature']) ?></td>
            <td class="text-center"><?= $permCell($row['Principal']) ?></td>
            <td class="text-center"><?= $permCell($row['HOD']) ?></td>
            <td class="text-center"><?= $permCell($row['Dean']) ?></td>
            <td class="text-center"><?= $permCell($row['Lecturer']) ?></td>
            <td class="text-center"><?= $permCell($row['Student']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
