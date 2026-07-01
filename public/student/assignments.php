<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$pageTitle = 'My Assignments';
$activeNav = 'modules';
$db = Database::connection();
$me = Auth::user();
// SEMAS Default Assignment Instructions (System-Wide) — fixed block id def_ins_001, applies to every assignment.
$defaultAssignmentInstructionsId = 'def_ins_001';
$defaultAssignmentInstructionsVersion = 'SEMAS-ASSIGN-2026-V1';
$defaultAssignmentInstructions = "📘 Assignment Submission Instructions\n\n• Complete your work individually without using automated writing tools or copied content.\n• Ensure all submissions are made before the stated deadline. Late submissions may not be accepted.\n• Only PDF or ZIP file formats will be accepted for submission.\n• Rename your file properly using your full name and registration number before uploading.\n• Any form of plagiarism or dishonest academic practice will lead to penalties according to university rules.";

$moduleId = (int) ($_GET['module_id'] ?? 0);

if (!$moduleId) {
    // Generic landing: every assignment across every module the student is registered in.
    $allStmt = $db->prepare(
        "SELECT a.*, m.module_title, m.module_id, s.file_path AS my_file, s.submitted_at AS my_submitted_at
         FROM assignments a
         JOIN modules m ON m.module_id = a.module_id AND m.status = 'Ongoing'
         JOIN module_enrollments e ON e.module_id = m.module_id AND e.user_id = :uid
         LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.user_id = :uid2
         WHERE a.deadline > NOW()
         ORDER BY a.deadline ASC"
    );
    $allStmt->execute(['uid' => $me['user_id'], 'uid2' => $me['user_id']]);
    $allAssignments = $allStmt->fetchAll();

    $pageTitle = 'My Assignments';
    $activeNav = 'assignments';
    require __DIR__ . '/../partials/layout_top.php';
    ?>
    <h4 class="display-font mb-1">My Assignments</h4>
    <p class="text-muted small mb-4">Across every module you're registered in. Open a module's page for full instructions and to submit.</p>
    <?php foreach ($allAssignments as $a): $closed = strtotime($a['deadline']) < time(); ?>
      <div class="semas-card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start">
          <div><h6 class="display-font mb-0"><?= e($a['title']) ?></h6><p class="text-muted small mb-0"><?= e($a['module_title']) ?></p></div>
          <span class="badge <?= $closed ? 'bg-secondary' : 'badge-completed' ?>"><?= $closed ? 'Closed' : 'Open' ?></span>
        </div>
        <p class="small mt-2 mb-1">Deadline: <?= e((string) date('d M Y, h:i A', strtotime((string) ($a['deadline'] ?? '')))) ?></p>
        <?php if ($a['my_file']): ?><p class="small text-success mb-1"><i class="bi bi-check-circle-fill"></i> Submitted</p><?php endif; ?>
        <a href="<?= APP_URL ?>/student/assignments.php?module_id=<?= (int) $a['module_id'] ?>" class="small">Open module &rarr;</a>
      </div>
    <?php endforeach; ?>
    <?php if (!$allAssignments): ?><div class="semas-card p-4 text-center text-muted small">No assignments posted in any of your registered modules yet.</div><?php endif; ?>
    <?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
    <?php
    exit;
}

$enrolled = $db->prepare('SELECT m.* FROM modules m JOIN module_enrollments e ON e.module_id = m.module_id WHERE m.module_id = :id AND e.user_id = :uid');
$enrolled->execute(['id' => $moduleId, 'uid' => $me['user_id']]);
$module = $enrolled->fetch();

if (!$module) {
    require __DIR__ . '/../partials/layout_top.php';
    echo '<div class="alert alert-warning small">You are not registered for this module. <a href="' . APP_URL . '/student/modules.php">Go to Module Registration</a></div>';
    require __DIR__ . '/../partials/layout_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $assignmentId = (int) $_POST['assignment_id'];
    $assignStmt = $db->prepare('SELECT * FROM assignments WHERE assignment_id = :id AND module_id = :mid');
    $assignStmt->execute(['id' => $assignmentId, 'mid' => $moduleId]);
    $assignment = $assignStmt->fetch();

    if (!$assignment) {
        flash('error', 'Assignment not found.');
    } elseif (strtotime($assignment['deadline']) < time()) {
        flash('error', 'The deadline for this assignment has passed. Submissions are no longer accepted.');
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a file to submit.');
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
        finfo_close($finfo);
        $allowed = ['application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip'];
        if (!isset($allowed[$mime]) || $_FILES['file']['size'] > 10 * 1024 * 1024) {
            flash('error', 'Only PDF or ZIP files under 10MB are accepted.');
        } else {
            $studentName = preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) ($me['full_name'] ?? 'student')));
            $studentReg  = preg_replace('/[^A-Za-z0-9]+/', '', trim((string) ($me['reg_number'] ?? '')));
            $baseName    = strtolower(trim($studentName . '_' . $studentReg, '_')) ?: 'submission';
            $filename    = $baseName . '.' . $allowed[$mime];
            $filename    = preg_replace('/_{2,}/', '_', $filename);
            $dest = __DIR__ . '/../uploads/assignments/' . $filename;
            if (file_exists($dest)) {
                $filename = $baseName . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                $dest = __DIR__ . '/../uploads/assignments/' . $filename;
            }
            if (!is_dir(dirname($dest))) { mkdir(dirname($dest), 0755, true); }
            move_uploaded_file($_FILES['file']['tmp_name'], $dest);
            $relPath = 'uploads/assignments/' . $filename;

            $hash = hash_file('sha256', $dest);
            $duplicateStmt = $db->prepare(
                'SELECT s.user_id, s.file_path FROM assignment_submissions s WHERE s.assignment_id = :a AND s.user_id != :u'
            );
            $duplicateStmt->execute(['a' => $assignmentId, 'u' => $me['user_id']]);
            $duplicateFound = false;
            foreach ($duplicateStmt->fetchAll() as $dup) {
                $existingPath = __DIR__ . '/../' . ltrim($dup['file_path'], '/');
                if (is_file($existingPath) && hash_file('sha256', $existingPath) === $hash) {
                    $duplicateFound = true;
                    break;
                }
            }
            if ($duplicateFound) {
                unlink($dest);
                flash('error', 'An identical submission already exists for this assignment. Please submit your own original work.');
                redirect('/student/assignments.php?module_id=' . $moduleId);
            }

            if (preg_match('/\b(?:chatgpt|gpt|openai|copilot|bard|ai_generated|ai|artificial intelligence)\b/i', $filename)) {
                flash('warning', 'Your file name contains terms commonly associated with AI-generated work. Please ensure this submission is your own original work.');
            }

            $existing = $db->prepare('SELECT submission_id FROM assignment_submissions WHERE assignment_id = :a AND user_id = :u');
            $existing->execute(['a' => $assignmentId, 'u' => $me['user_id']]);
            if ($existing->fetch()) {
                $db->prepare('UPDATE assignment_submissions SET file_path = :p, submitted_at = NOW() WHERE assignment_id = :a AND user_id = :u')
                   ->execute(['p' => $relPath, 'a' => $assignmentId, 'u' => $me['user_id']]);
                flash('success', 'Your submission has been updated.');
            } else {
                $db->prepare('INSERT INTO assignment_submissions (assignment_id, user_id, file_path) VALUES (:a, :u, :p)')
                   ->execute(['a' => $assignmentId, 'u' => $me['user_id'], 'p' => $relPath]);
                flash('success', 'Assignment submitted.');
            }
            AuditLog::record(Auth::id(), 'ASSIGNMENT_SUBMIT', 'assignments', $assignmentId);
        }
    }
    redirect('/student/assignments.php?module_id=' . $moduleId);
}

$assignments = $db->prepare(
    "SELECT a.*, s.file_path AS my_file, s.submitted_at AS my_submitted_at
     FROM assignments a LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.user_id = :uid
     WHERE a.module_id = :mid AND a.deadline > NOW() ORDER BY a.deadline ASC"
);
$assignments->execute(['uid' => $me['user_id'], 'mid' => $moduleId]);
$assignments = $assignments->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<h4 class="display-font mb-1">Assignments / <?= e($module['module_title']) ?></h4>
<p class="text-muted small mb-4">Submit PDF or ZIP files only. Rename your file as Firstname_Lastname_RegNo.pdf or .zip before uploading. No submissions are accepted after the deadline.</p>

<?php foreach ($assignments as $a): $closed = strtotime($a['deadline']) < time(); $customNotes = trim((string) $a['instructions']); $hasCustomNotes = $customNotes !== '' && $customNotes !== trim($defaultAssignmentInstructions); ?>
  <div class="semas-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
      <div>
        <h6 class="display-font mb-1"><?= e($a['title']) ?></h6>
        <span class="badge <?= $closed ? 'bg-secondary' : 'badge-completed' ?>"><?= $closed ? 'Closed' : 'Open' ?></span>
      </div>
      <!-- Countdown timer: top-right, beside the instructions block -->
      <?php if (!$closed): ?>
        <div class="text-center px-3 py-2 rounded" style="background:#fff7e0;border:2px solid var(--semas-gold,#c9a227);min-width:180px;">
          <div class="text-uppercase text-muted fw-semibold" style="font-size:.68rem;letter-spacing:.06em;"><i class="bi bi-stopwatch me-1"></i>Time Left</div>
          <div class="js-countdown fw-bold" data-deadline="<?= e((string) date('c', strtotime((string) $a['deadline']))) ?>" style="font-size:1.5rem;line-height:1.15;color:#1E2A52;">—</div>
        </div>
      <?php else: ?>
        <div class="text-center px-3 py-2 rounded bg-light" style="min-width:180px;">
          <div class="text-uppercase text-secondary fw-semibold" style="font-size:.68rem;letter-spacing:.06em;"><i class="bi bi-stopwatch me-1"></i>Time Left</div>
          <div class="fw-bold text-secondary" style="font-size:1.1rem;">Closed</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- 1. System Instructions (DEFAULT) -->
    <div class="alert alert-light border small mb-2" data-instructions-id="<?= e($defaultAssignmentInstructionsId) ?>">
      <p class="text-muted mb-1"><?= nl2br(e($defaultAssignmentInstructions)) ?></p>
      <p class="text-muted mb-0" style="font-size:.75rem;">Policy Version: <?= e($defaultAssignmentInstructionsVersion) ?></p>
    </div>

    <p class="small mb-2">Module: <?= e($module['module_title']) ?> &middot; Deadline: <?= e((string) date('d M Y, h:i A', strtotime((string) ($a['deadline'] ?? '')))) ?></p>

    <!-- 3. Attachment download -->
    <?php if ($a['attachment_path']): ?>
      <p class="small mb-2"><a href="<?= APP_URL . '/' . e($a['attachment_path']) ?>" target="_blank"><i class="bi bi-paperclip me-1"></i>Lecturer's attachment</a></p>
    <?php endif; ?>

    <?php if ($a['my_file']): ?>
      <p class="small text-success mb-2"><i class="bi bi-check-circle-fill"></i> Submitted <?= e((string) date('d M Y, h:i A', strtotime((string) ($a['my_submitted_at'] ?? '')))) ?> / <a href="<?= APP_URL . '/' . e($a['my_file']) ?>" target="_blank">View your file</a></p>
    <?php endif; ?>

    <!-- 5. Submit button -->
    <?php if (!$closed): ?>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end js-submit-form">
        <?= csrf_field() ?><input type="hidden" name="assignment_id" value="<?= (int) $a['assignment_id'] ?>">
        <input type="file" name="file" accept=".pdf,.zip" class="form-control form-control-sm js-file-input" required>
        <button type="button" class="btn btn-sm btn-semas-gold text-nowrap js-preview-btn"><?= $a['my_file'] ? 'Resubmit' : 'Submit' ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$assignments): ?><div class="semas-card p-4 text-center text-muted small">No assignments posted for this module yet.</div><?php endif; ?>

<div class="modal fade" id="submitPreviewModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title display-font">Confirm Submission</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Review your file before submitting. Once confirmed, this will be recorded as your submission.</p>
        <div id="submitPreviewSummary" class="mb-3"></div>
        <div id="submitPreviewArea" style="height:50vh; background:#f5f5f5;"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-outline-dark" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-semas-gold" id="submitPreviewConfirmBtn">Confirm &amp; Submit</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var pendingForm = null;
  var previewModalEl = document.getElementById('submitPreviewModal');
  var previewModal = previewModalEl ? new bootstrap.Modal(previewModalEl) : null;
  var previewArea = document.getElementById('submitPreviewArea');

  document.querySelectorAll('.js-preview-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = btn.closest('form');
      var fileInput = form.querySelector('.js-file-input');
      if (!fileInput.files.length) { fileInput.reportValidity(); return; }
      var file = fileInput.files[0];
      previewArea.innerHTML = '';
      var fileName = file.name.replace(/[<>]/g, '');
      var fileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      var warnings = [];
      if (/\b(?:gpt|chatgpt|openai|copilot|bard|ai_generated|ai|artificial intelligence)\b/i.test(fileName)) {
        warnings.push('The filename contains terms commonly associated with AI-generated work. Please ensure this submission is your own original work.');
      }
      if (/\b(?:copy|duplicate)\b/i.test(fileName)) {
        warnings.push('The filename suggests a duplicate file. Double-check that this is your original work.');
      }
      var summaryHtml = '<p><strong>File:</strong> ' + fileName + '</p>' +
                        '<p><strong>Size:</strong> ' + fileSize + '</p>' +
                        '<p class="small text-muted mb-2">SEMAS will also compare this submission against existing assignment submissions for exact duplicates.</p>';
      if (warnings.length) {
        summaryHtml += '<div class="alert alert-warning small">' + warnings.join('<br>') + '</div>';
      }
      document.getElementById('submitPreviewSummary').innerHTML = summaryHtml;
      if (file.type === 'application/pdf') {
        var url = URL.createObjectURL(file);
        previewArea.innerHTML = '<embed src="' + url + '" type="application/pdf" width="100%" height="100%">';
      } else {
        previewArea.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-file-earmark-zip display-4"></i><p class="mt-2 mb-0">' + fileName + '</p><p class="small">Preview is only available for PDF files. The ZIP will still be uploaded as-is.</p></div>';
      }
      pendingForm = form;
      previewModal.show();
    });
  });

  document.getElementById('submitPreviewConfirmBtn').addEventListener('click', function () {
    if (pendingForm) { pendingForm.submit(); }
  });

  previewModalEl.addEventListener('hidden.bs.modal', function () {
    previewArea.innerHTML = '';
    document.getElementById('submitPreviewSummary').innerHTML = '';
    pendingForm = null;
  });
})();

(function () {
  var els = document.querySelectorAll('.js-countdown');
  if (!els.length) { return; }
  function tick() {
    els.forEach(function (el) {
      var deadline = new Date(el.dataset.deadline).getTime();
      var diff = deadline - Date.now();
      if (diff <= 0) { el.textContent = 'Deadline passed'; el.classList.add('text-danger'); return; }
      var d = Math.floor(diff / 86400000);
      var h = Math.floor((diff % 86400000) / 3600000);
      var m = Math.floor((diff % 3600000) / 60000);
      var s = Math.floor((diff % 60000) / 1000);
      var parts = [];
      if (d > 0) { parts.push(d + 'd'); }
      parts.push(h + 'h', m + 'm', s + 's');
      el.textContent = parts.join(' ');
      if (diff < 3600000) { el.classList.add('text-danger'); }
    });
  }
  tick();
  setInterval(tick, 1000);
})();
</script>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
