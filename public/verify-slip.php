<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$token = trim($_GET['t'] ?? '');
$pageTitle = 'Verify Slip';

function slip_b64u_decode(string $value)
{
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function slip_token_data(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['ok' => false, 'data' => null, 'message' => 'Invalid verification token.'];
    }
    [$iv64, $cipher64, $hmac64] = $parts;
    $iv = slip_b64u_decode($iv64);
    $cipher = slip_b64u_decode($cipher64);
    $hmac = slip_b64u_decode($hmac64);
    if ($iv === false || $cipher === false || $hmac === false) {
        return ['ok' => false, 'data' => null, 'message' => 'Invalid verification token.'];
    }
    $secret = APP_KEY !== '' ? APP_KEY : 'fallback-key';
    $expected = hash_hmac('sha256', $iv . $cipher, $secret, true);
    if (!hash_equals($expected, $hmac)) {
        return ['ok' => false, 'data' => null, 'message' => 'Slip signature is invalid.'];
    }
    $key = hash('sha256', $secret, true);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $data = $plain ? json_decode($plain, true) : null;
    if (!is_array($data)) {
        return ['ok' => false, 'data' => null, 'message' => 'Slip payload cannot be read.'];
    }
    if (!empty($data['exp']) && time() > (int) $data['exp']) {
        return ['ok' => false, 'data' => null, 'message' => 'This verification token has expired.'];
    }
    return ['ok' => true, 'data' => $data, 'message' => 'Verified'];
}

$result = $token !== '' ? slip_token_data($token) : ['ok' => false, 'data' => null, 'message' => 'Missing verification token.'];
$data = $result['data'] ?? [];
$db = Database::connection();
$details = null;
$brandLogo = Settings::get('logo_path');
$uniName = mb_strtoupper(Settings::get('university_name', 'University of Kigali'), 'UTF-8');

if ($result['ok'] && isset($data['schedule_id'], $data['user_id'])) {
    $stmt = $db->prepare(
        "SELECT cs.exam_type, cs.scheduled_date, cs.start_time, cs.end_time, cs.room,
                m.module_title, d.department_name, u.full_name, u.reg_number
         FROM cat_exam_schedules cs
         JOIN modules m ON m.module_id = cs.module_id
         LEFT JOIN departments d ON d.department_id = m.department_id
         JOIN users u ON u.user_id = :uid
         WHERE cs.schedule_id = :sid"
    );
    $stmt->execute(['sid' => (int) $data['schedule_id'], 'uid' => (int) $data['user_id']]);
    $details = $stmt->fetch();
} elseif ($result['ok'] && isset($data['module_id'], $data['user_id'], $data['exam_type'])) {
    $stmt = $db->prepare(
        "SELECT m.module_title, m.cat_date, m.exam_date, m.session_type, d.department_name,
                u.full_name, u.reg_number
         FROM modules m
         JOIN users u ON u.user_id = :uid
         LEFT JOIN departments d ON d.department_id = m.department_id
         WHERE m.module_id = :mid"
    );
    $stmt->execute(['mid' => (int) $data['module_id'], 'uid' => (int) $data['user_id']]);
    $details = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify Slip</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Times New Roman', Times, serif; background: #FBF7EE; margin: 0; padding: 28px 0; color: #1B1F2A; }
  .page { max-width: 700px; margin: 0 auto; background: #ffffff; border: 2px solid #D4A24C; border-radius: 10px; overflow: hidden; }
  .header { background: #D4A24C; color: #1E2A52; padding: 18px 28px; display: flex; justify-content: space-between; align-items: center; }
  .brand { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
  .brand span { color: #1E2A52; }
  .meta { text-align: right; font-size: 12px; }
  .title { text-align: center; padding: 20px 28px 14px; border-bottom: 2px solid #D4A24C; }
  .title h2 { font-size: 18px; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 2px; color: #1E2A52; }
  .title .sub { color: #6B7280; font-size: 12px; }
  .body { padding: 22px 28px 28px; }
  .status { margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; text-align: center; font-weight: 700; font-size: 13px; }
  .status.ok { background: #ECFDF5; border: 1px solid #6EE7B7; color: #065F46; }
  .status.bad { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; }
  table.info { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.info td { padding: 8px 6px; border-bottom: 1px solid #F0E3C6; vertical-align: top; }
  table.info td.label { color: #6B7280; width: 38%; }
  table.info td.value { font-weight: 600; }
  .footer { background: #FFF7D9; padding: 12px 28px; border-top: 1px solid #F0E3C6; font-size: 12px; color: #6B7280; text-align: center; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <div class="brand">
      <?php if (!empty($brandLogo)): ?>
        <img src="<?= e(APP_URL . '/' . ltrim($brandLogo, '/')) ?>" alt="SEMAS Logo" style="height:36px;max-height:40px;">
      <?php else: ?>
        SEM<span>AS</span>
      <?php endif; ?>
    </div>
    <div class="meta"><?= e($uniName) ?><br><?= e(date('d F Y, h:i A')) ?></div>
  </div>
  <div class="title">
    <h2><?= $result['ok'] ? 'Slip Verified' : 'Slip Not Verified' ?></h2>
    <div class="sub">SEMAS CAT / Exam Slip Verification</div>
  </div>
  <div class="body">
  <div class="status <?= $result['ok'] ? 'ok' : 'bad' ?>"><?= e($result['message']) ?></div>
  <?php if ($details): ?>
    <table class="info">
      <tr><td class="label">Student</td><td class="value"><?= e($details['full_name'] ?? '') ?></td></tr>
      <tr><td class="label">Registration Number</td><td class="value"><?= e($details['reg_number'] ?? '') ?></td></tr>
      <tr><td class="label">Module</td><td class="value"><?= e($details['module_title'] ?? '') ?></td></tr>
      <tr><td class="label">Department</td><td class="value"><?= e($details['department_name'] ?? '') ?></td></tr>
      <tr><td class="label">Assessment</td><td class="value"><?= e($data['exam_type'] ?? ($details['exam_type'] ?? '')) ?></td></tr>
      <tr><td class="label">Date</td><td class="value"><?= e($details['scheduled_date'] ?? (($data['exam_type'] ?? '') === 'CAT' ? ($details['cat_date'] ?? '') : ($details['exam_date'] ?? ''))) ?></td></tr>
      <tr><td class="label">Room</td><td class="value"><?= e($details['room'] ?? '-') ?></td></tr>
    </table>
  <?php elseif ($result['ok']): ?>
    <p style="color:#6B7280;font-size:13px;margin:0;">The signature is valid, but the related record was not found in this SEMAS database.</p>
  <?php endif; ?>
  </div>
  <div class="footer">Verification URL: <?= e(APP_URL . '/verify-slip.php?t=' . $token) ?></div>
</div>
</body>
</html>
