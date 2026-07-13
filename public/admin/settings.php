<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal']);

$pageTitle = 'System Settings';
$activeNav = 'settings';
$me = Auth::user();
$fontOptions = [
    'times' => 'Times New Roman',
    'inter' => 'Inter',
    'sora' => 'Sora',
    'arial' => 'Arial',
    'georgia' => 'Georgia',
    'verdana' => 'Verdana',
    'tahoma' => 'Tahoma',
];
$colorDefaults = [
    'theme_gold' => '#D4A24C',
    'theme_ink' => '#1E2A52',
    'primary_button_color' => '#1E2A52',
    'primary_button_hover_color' => '#2E3D6B',
    'accent_button_color' => '#D4A24C',
    'accent_button_hover_color' => '#F0C988',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $errors = [];

    $universityName = trim((string) ($_POST['university_name'] ?? ''));
    if ($universityName === '') {
        $errors[] = 'University name is required.';
    } elseif (mb_strlen($universityName) > 150) {
        $errors[] = 'University name must be 150 characters or fewer.';
    } else {
        Settings::set('university_name', $universityName, (int) $me['user_id']);
    }

    foreach (['font_family', 'heading_font'] as $field) {
        $font = (string) ($_POST[$field] ?? '');
        if (!isset($fontOptions[$font])) {
            $errors[] = 'Please select a valid font.';
            continue;
        }
        Settings::set($field, $font, (int) $me['user_id']);
    }

    foreach ($colorDefaults as $field => $default) {
        $color = strtoupper(trim((string) ($_POST[$field] ?? '')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $errors[] = 'One or more selected colors are invalid.';
            continue;
        }
        Settings::set($field, $color, (int) $me['user_id']);
    }

    foreach (['logo_path' => 'logo', 'favicon_path' => 'favicon', 'login_background_path' => 'login_bg', 'login_banner_path' => 'login_banner'] as $settingKey => $fileField) {
        if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'An image upload failed. Please choose the image again.';
            continue;
        }
        if ($_FILES[$fileField]['size'] > 3 * 1024 * 1024) {
            $errors[] = 'Branding images must be 3 MB or smaller.';
            continue;
        }

        if ($_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES[$fileField]['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
            if (!isset($allowed[$mime])) {
                $errors[] = 'Branding images must be JPG, PNG, WEBP, or ICO files.';
                continue;
            }

            $uploadDirectory = __DIR__ . '/../uploads/profile_photos';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                $errors[] = 'The branding upload directory could not be created.';
                continue;
            }
            $filename = 'brand_' . $settingKey . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
            $dest = $uploadDirectory . '/' . $filename;
            if (move_uploaded_file($_FILES[$fileField]['tmp_name'], $dest)) {
                Settings::set($settingKey, 'uploads/profile_photos/' . $filename, (int) $me['user_id']);
            } else {
                $errors[] = 'A branding image could not be saved.';
            }
        }
    }

    AuditLog::record(Auth::id(), 'SYSTEM_SETTINGS_UPDATE', 'system_settings', null);
    if ($errors) {
        flash('error', implode(' ', array_unique($errors)));
    } else {
        flash('success', 'System appearance and branding settings saved successfully.');
    }
    redirect('/admin/settings.php');
}

$settings = Settings::all();
$settingColor = static function (array $values, string $key, string $fallback): string {
    $value = $values[$key] ?? $fallback;
    return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $fallback;
};
require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">System Settings</h4>
<p class="text-muted small mb-4">Manage the university identity and the appearance used throughout SEMAS.</p>

<form method="post" enctype="multipart/form-data" id="systemSettingsForm">
  <?= csrf_field() ?>

  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-3">University Branding</h6>
    <div class="row g-3">
      <div class="col-12"><label class="form-label small">University Name</label><input name="university_name" class="form-control" maxlength="150" required value="<?= e($settings['university_name'] ?? '') ?>"></div>
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
    <div class="form-text mt-3">Accepted images: JPG, PNG, WEBP, or ICO; maximum 3 MB each. Leave a file field empty to retain the current image.</div>
  </div>

  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <h6 class="display-font mb-1">Fonts &amp; Interface Colors</h6>
        <p class="text-muted small mb-0">Choose fonts and colors used across authenticated SEMAS pages.</p>
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="resetAppearance"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore Defaults</button>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label small" for="fontFamily">Interface Font</label>
        <select name="font_family" id="fontFamily" class="form-select">
          <?php foreach ($fontOptions as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= ($settings['font_family'] ?? 'times') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Used for paragraphs, forms, tables, and navigation.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label small" for="headingFont">Heading Font</label>
        <select name="heading_font" id="headingFont" class="form-select">
          <?php foreach ($fontOptions as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= ($settings['heading_font'] ?? 'times') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Used for page titles, headings, statistics, and branding.</div>
      </div>

      <?php
      $colorLabels = [
          'theme_gold' => 'Theme Gold / Accent',
          'theme_ink' => 'Theme Ink / Sidebar',
          'primary_button_color' => 'Primary Button',
          'primary_button_hover_color' => 'Primary Button Hover',
          'accent_button_color' => 'Accent Button',
          'accent_button_hover_color' => 'Accent Button Hover',
      ];
      foreach ($colorLabels as $key => $label): ?>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label small" for="<?= e($key) ?>"><?= e($label) ?></label>
          <div class="d-flex align-items-center gap-2">
            <input type="color" name="<?= e($key) ?>" id="<?= e($key) ?>" class="form-control form-control-color appearance-color" value="<?= $settingColor($settings, $key, $colorDefaults[$key]) ?>" title="Choose <?= e($label) ?>">
            <code class="small color-value"><?= $settingColor($settings, $key, $colorDefaults[$key]) ?></code>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="border rounded p-3 mt-4" id="appearancePreview">
      <div class="small text-muted mb-2">Live preview</div>
      <h5 class="mb-1" id="previewHeading">University system heading</h5>
      <p class="mb-3" id="previewBody">This sample shows how normal interface text will appear.</p>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn" id="previewPrimary">Primary Button</button>
        <button type="button" class="btn" id="previewAccent">Accent Button</button>
      </div>
    </div>
  </div>

  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-3">Email &amp; SMS</h6>
    <p class="text-muted small mb-2">Configured via <code>.env</code>:</p>
    <ul class="small text-muted">
      <li>Mail host: <?= e(defined('MAIL_HOST') ? MAIL_HOST : '/') ?>, From: <?= e(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '/') ?></li>
      <li>SMS provider: <?= e(defined('SMS_PROVIDER') ? SMS_PROVIDER : '/') ?></li>
    </ul>
  </div>

  <div class="semas-card p-3 mb-3">
    <h6 class="display-font mb-3">Backup</h6>
    <a href="<?= APP_URL ?>/admin/backup-download.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-download me-1"></i> Download SQL Backup</a>
  </div>

  <button class="btn btn-semas-gold" id="saveSettingsButton"><i class="bi bi-check2-circle me-1"></i>Save Settings</button>
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

<script>
(function () {
  var fontStacks = {
    times: "'Times New Roman', Times, serif",
    inter: "Inter, Arial, sans-serif",
    sora: "Sora, Arial, sans-serif",
    arial: "Arial, Helvetica, sans-serif",
    georgia: "Georgia, 'Times New Roman', serif",
    verdana: "Verdana, Geneva, sans-serif",
    tahoma: "Tahoma, Verdana, sans-serif"
  };
  var defaults = {
    font_family: 'times',
    heading_font: 'times',
    theme_gold: '#D4A24C',
    theme_ink: '#1E2A52',
    primary_button_color: '#1E2A52',
    primary_button_hover_color: '#2E3D6B',
    accent_button_color: '#D4A24C',
    accent_button_hover_color: '#F0C988'
  };
  var form = document.getElementById('systemSettingsForm');
  var bodyFont = document.getElementById('fontFamily');
  var headingFont = document.getElementById('headingFont');
  var previewHeading = document.getElementById('previewHeading');
  var previewBody = document.getElementById('previewBody');
  var previewPrimary = document.getElementById('previewPrimary');
  var previewAccent = document.getElementById('previewAccent');

  function contrastColor(hex) {
    var value = hex.replace('#', '');
    var red = parseInt(value.substring(0, 2), 16);
    var green = parseInt(value.substring(2, 4), 16);
    var blue = parseInt(value.substring(4, 6), 16);
    return ((red * 299 + green * 587 + blue * 114) / 1000) >= 150 ? '#111827' : '#FFFFFF';
  }

  function updatePreview() {
    previewBody.style.fontFamily = fontStacks[bodyFont.value] || fontStacks.times;
    previewHeading.style.fontFamily = fontStacks[headingFont.value] || fontStacks.times;
    previewPrimary.style.backgroundColor = form.elements.primary_button_color.value;
    previewPrimary.style.borderColor = form.elements.primary_button_color.value;
    previewPrimary.style.color = contrastColor(form.elements.primary_button_color.value);
    previewAccent.style.backgroundColor = form.elements.accent_button_color.value;
    previewAccent.style.borderColor = form.elements.accent_button_color.value;
    previewAccent.style.color = contrastColor(form.elements.accent_button_color.value);
    document.querySelectorAll('.appearance-color').forEach(function (input) {
      input.closest('.d-flex').querySelector('.color-value').textContent = input.value.toUpperCase();
    });
  }

  bodyFont.addEventListener('change', updatePreview);
  headingFont.addEventListener('change', updatePreview);
  document.querySelectorAll('.appearance-color').forEach(function (input) {
    input.addEventListener('input', updatePreview);
  });
  document.getElementById('resetAppearance').addEventListener('click', function () {
    Object.keys(defaults).forEach(function (name) {
      if (form.elements[name]) form.elements[name].value = defaults[name];
    });
    updatePreview();
  });
  form.addEventListener('submit', function () {
    var button = document.getElementById('saveSettingsButton');
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
  });
  updatePreview();
})();
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
