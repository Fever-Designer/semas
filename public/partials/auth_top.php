<?php
/** Include after setting $pageTitle. Mirrors header.php's <head> but for the
 *  centered auth-card layout (login, register, password reset, OTP pages). */
$authFontStacks = [
    'times' => "'Times New Roman', Times, serif",
    'inter' => "Inter, Arial, sans-serif",
    'sora' => "Sora, Arial, sans-serif",
    'arial' => "Arial, Helvetica, sans-serif",
    'georgia' => "Georgia, 'Times New Roman', serif",
    'verdana' => "Verdana, Geneva, sans-serif",
    'tahoma' => "Tahoma, Verdana, sans-serif",
];
$authValidColor = static function (?string $value, string $fallback): string {
    return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $fallback;
};
$authContrastText = static function (string $hex): string {
    $red = hexdec(substr($hex, 1, 2));
    $green = hexdec(substr($hex, 3, 2));
    $blue = hexdec(substr($hex, 5, 2));
    return (($red * 299 + $green * 587 + $blue * 114) / 1000) >= 150 ? '#111827' : '#FFFFFF';
};
$authUniversityName = Settings::get('university_name', 'University of Kigali');
$authLogo = Settings::get('logo_path');
$authFavicon = Settings::get('favicon_path');
$authBackground = Settings::get('login_background_path');
$authBanner = Settings::get('login_banner_path');
$authThemeGold = $authValidColor(Settings::get('theme_gold'), '#D4A24C');
$authThemeInk = $authValidColor(Settings::get('theme_ink'), '#1E2A52');
$authPrimaryButton = $authValidColor(Settings::get('primary_button_color'), $authThemeInk);
$authPrimaryButtonHover = $authValidColor(Settings::get('primary_button_hover_color'), '#2E3D6B');
$authAccentButton = $authValidColor(Settings::get('accent_button_color'), $authThemeGold);
$authAccentButtonHover = $authValidColor(Settings::get('accent_button_hover_color'), '#F0C988');
$authBodyFont = $authFontStacks[Settings::get('font_family', 'times')] ?? $authFontStacks['times'];
$authHeadingFont = $authFontStacks[Settings::get('heading_font', 'times')] ?? $authFontStacks['times'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'SEMAS') ?> / SEMAS</title>
<?php if ($authFavicon): ?><link rel="icon" href="<?= APP_URL . '/' . e($authFavicon) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
:root {
  --semas-gold: <?= $authThemeGold ?>;
  --semas-ink: <?= $authThemeInk ?>;
  --semas-body-font: <?= $authBodyFont ?>;
  --semas-heading-font: <?= $authHeadingFont ?>;
  --semas-primary-button: <?= $authPrimaryButton ?>;
  --semas-primary-button-hover: <?= $authPrimaryButtonHover ?>;
  --semas-primary-button-text: <?= $authContrastText($authPrimaryButton) ?>;
  --semas-primary-button-hover-text: <?= $authContrastText($authPrimaryButtonHover) ?>;
  --semas-accent-button: <?= $authAccentButton ?>;
  --semas-accent-button-hover: <?= $authAccentButtonHover ?>;
  --semas-accent-button-text: <?= $authContrastText($authAccentButton) ?>;
  --semas-accent-button-hover-text: <?= $authContrastText($authAccentButtonHover) ?>;
}
</style>
</head>
<body>
<div class="auth-wrapper"<?= $authBackground ? ' style="background-image:linear-gradient(rgba(30,42,82,.68),rgba(19,26,51,.78)),url(\'' . APP_URL . '/' . e($authBackground) . '\');background-size:cover;background-position:center;"' : '' ?>>
  <div class="auth-card">
    <div class="text-center mb-4">
      <?php if ($authBanner): ?><img src="<?= APP_URL . '/' . e($authBanner) ?>" alt="Login banner" class="img-fluid rounded mb-3" style="width:100%;max-height:130px;object-fit:cover;"><?php endif; ?>
      <?php if ($authLogo): ?>
        <img src="<?= APP_URL . '/' . e($authLogo) ?>" alt="<?= e($authUniversityName) ?> logo" class="img-fluid mb-2" style="max-height:70px;max-width:220px;object-fit:contain;">
      <?php else: ?>
        <div class="brand-mark">SEM<span>AS</span></div>
      <?php endif; ?>
      <p class="text-muted small mt-1">Student Event Management and Announcement System<br><?= e($authUniversityName) ?></p>
    </div>
