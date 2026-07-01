<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Principal', 'Dean', 'HOD', 'Student']);

$pageTitle = 'Polls & Surveys';
$activeNav = 'polls';
$db = Database::connection();
$me = Auth::user();
$role = Auth::role();
$isStaff = in_array($role, ['Principal', 'Dean', 'HOD'], true);

// Resolve the scope a HOD/Dean is allowed to target (mirrors the
// announcement pages: HOD => own department only, Dean => own faculty only).
$myDepartmentId = null;
$myFacultyId = null;
if ($role === 'HOD') {
    $myDepartmentId = (int) $me['department_id'] ?: null;
} elseif ($role === 'Dean') {
    $facStmt = $db->prepare('SELECT faculty_id FROM faculties WHERE dean_user_id = :uid');
    $facStmt->execute(['uid' => $me['user_id']]);
    $myFacultyId = (int) $facStmt->fetchColumn() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_poll' && $isStaff) {
        $audience = $_POST['target_audience'] ?? 'All Students';
        $deptId = null;
        $facultyId = null;
        if ($role === 'HOD') {
            $audience = 'Specific Department';
            $deptId = $myDepartmentId;
        } elseif ($role === 'Dean') {
            $audience = 'Specific Faculty';
            $facultyId = $myFacultyId;
        } else { // Principal
            $deptId = $audience === 'Specific Department' ? (int) $_POST['department_id'] : null;
            $facultyId = $audience === 'Specific Faculty' ? (int) $_POST['faculty_id'] : null;
        }

        $options = array_values(array_filter(array_map('trim', $_POST['options'] ?? [])));
        if (count($options) < 2) {
            flash('error', 'A poll needs at least two options.');
            redirect('/campus/polls.php');
        }

        $db->prepare(
            'INSERT INTO polls (title, description, target_audience, department_id, faculty_id, created_by, closes_at)
             VALUES (:title, :desc, :aud, :dept, :fac, :uid, :closes)'
        )->execute([
            'title' => trim($_POST['title']), 'desc' => trim($_POST['description']) ?: null,
            'aud' => $audience, 'dept' => $deptId, 'fac' => $facultyId, 'uid' => $me['user_id'],
            'closes' => $_POST['closes_at'] ?: null,
        ]);
        $pollId = (int) $db->lastInsertId();
        foreach ($options as $opt) {
            $db->prepare('INSERT INTO poll_options (poll_id, option_text) VALUES (:pid, :txt)')->execute(['pid' => $pollId, 'txt' => $opt]);
        }
        AuditLog::record(Auth::id(), 'POLL_CREATE', 'polls', $pollId);
        flash('success', 'Poll created and visible to the selected audience.');
    } elseif ($action === 'vote') {
        $pollId = (int) $_POST['poll_id'];
        $optionId = (int) $_POST['option_id'];
        $exists = $db->prepare('SELECT vote_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid');
        $exists->execute(['pid' => $pollId, 'uid' => $me['user_id']]);
        if ($exists->fetch()) {
            flash('error', 'You already voted in this poll.');
        } else {
            $db->prepare('INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (:pid, :oid, :uid)')
               ->execute(['pid' => $pollId, 'oid' => $optionId, 'uid' => $me['user_id']]);
            flash('success', 'Vote recorded. Thank you!');
        }
    } elseif ($action === 'close_poll' && $isStaff) {
        $pollId = (int) $_POST['poll_id'];
        $owner = $db->prepare('SELECT created_by FROM polls WHERE poll_id = :id');
        $owner->execute(['id' => $pollId]);
        if ((int) $owner->fetchColumn() === (int) $me['user_id'] || $role === 'Principal') {
            $db->prepare("UPDATE polls SET status='Closed' WHERE poll_id=:id")->execute(['id' => $pollId]);
            AuditLog::record(Auth::id(), 'POLL_CLOSE', 'polls', $pollId);
            flash('success', 'Poll closed.');
        }
    }
    redirect('/campus/polls.php');
}

// ---- Polls visible to the current viewer ----
if ($isStaff) {
    // Staff see polls they created, with live results.
    $stmt = $db->prepare('SELECT * FROM polls WHERE created_by = :uid ORDER BY created_at DESC');
    $stmt->execute(['uid' => $me['user_id']]);
    $myPolls = $stmt->fetchAll();
    foreach ($myPolls as &$p) {
        $optStmt = $db->prepare(
            'SELECT po.option_id, po.option_text, COUNT(pv.vote_id) AS votes
             FROM poll_options po LEFT JOIN poll_votes pv ON pv.option_id = po.option_id
             WHERE po.poll_id = :pid GROUP BY po.option_id'
        );
        $optStmt->execute(['pid' => $p['poll_id']]);
        $p['options'] = $optStmt->fetchAll();
        $p['total_votes'] = array_sum(array_column($p['options'], 'votes'));
    }
    unset($p);
} else {
    // Student: show open polls matching their department/faculty/session.
    $myDept = (int) $me['department_id'];
    $deptFacStmt = $db->prepare('SELECT faculty_id FROM departments WHERE department_id = :id');
    $deptFacStmt->execute(['id' => $myDept]);
    $myFaculty = (int) $deptFacStmt->fetchColumn();

    $stmt = $db->query("SELECT * FROM polls WHERE status = 'Open' AND (closes_at IS NULL OR closes_at > NOW()) ORDER BY created_at DESC");
    $allOpenPolls = $stmt->fetchAll();
    $myPolls = [];
    foreach ($allOpenPolls as $p) {
        switch ($p['target_audience']) {
            case 'All Students': $matches = true; break;
            case 'Specific Department': $matches = ((int) $p['department_id'] === $myDept); break;
            case 'Specific Faculty': $matches = ((int) $p['faculty_id'] === $myFaculty); break;
            case 'Day Students': $matches = ($me['session_type'] === 'Day'); break;
            case 'Evening Students': $matches = ($me['session_type'] === 'Evening'); break;
            case 'Weekend Students': $matches = ($me['session_type'] === 'Weekend'); break;
            default: $matches = false;
        }
        if (!$matches) continue;

        $voted = $db->prepare('SELECT option_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid');
        $voted->execute(['pid' => $p['poll_id'], 'uid' => $me['user_id']]);
        $p['my_vote'] = $voted->fetchColumn() ?: null;

        $optStmt = $db->prepare('SELECT option_id, option_text FROM poll_options WHERE poll_id = :pid');
        $optStmt->execute(['pid' => $p['poll_id']]);
        $p['options'] = $optStmt->fetchAll();
        $myPolls[] = $p;
    }
}

$departments = $db->query('SELECT department_id, department_name FROM departments ORDER BY department_name')->fetchAll();
$faculties = $db->query('SELECT faculty_id, faculty_name FROM faculties ORDER BY faculty_name')->fetchAll();

require __DIR__ . '/../partials/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h4 class="display-font mb-1">Polls &amp; Surveys</h4>
    <p class="text-muted small mb-0"><?= $isStaff ? 'Create a quick poll for your audience and watch results live.' : 'Vote in polls relevant to you. One vote per poll.' ?></p></div>
  <?php if ($isStaff): ?>
    <button class="btn btn-semas-gold btn-sm" data-bs-toggle="modal" data-bs-target="#createPollModal"><i class="bi bi-plus-circle me-1"></i> New Poll</button>
  <?php endif; ?>
</div>

<?php if ($isStaff): ?>
  <?php if (!$myPolls): ?>
    <div class="semas-card p-4 text-center text-muted small">You haven't created any polls yet.</div>
  <?php else: foreach ($myPolls as $p): ?>
    <div class="semas-card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-start">
        <h6 class="display-font mb-1"><?= e($p['title']) ?></h6>
        <span class="badge <?= $p['status'] === 'Open' ? 'badge-completed' : 'bg-secondary' ?>"><?= e($p['status']) ?></span>
      </div>
      <p class="text-muted small mb-2"><?= e($p['description'] ?? '') ?> &middot; Audience: <?= e($p['target_audience']) ?> &middot; <?= (int) $p['total_votes'] ?> vote(s)</p>
      <?php foreach ($p['options'] as $opt): $pct = $p['total_votes'] > 0 ? round($opt['votes'] / $p['total_votes'] * 100) : 0; ?>
        <div class="mb-1">
          <div class="d-flex justify-content-between small"><span><?= e($opt['option_text']) ?></span><span><?= $pct ?>% (<?= (int) $opt['votes'] ?>)</span></div>
          <div class="progress" style="height:6px;"><div class="progress-bar" style="width:<?= $pct ?>%;background-color:var(--semas-gold);"></div></div>
        </div>
      <?php endforeach; ?>
      <?php if ($p['status'] === 'Open'): ?>
        <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="poll_id" value="<?= (int) $p['poll_id'] ?>">
          <button class="btn btn-sm btn-outline-dark" name="action" value="close_poll">Close Poll</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
<?php else: ?>
  <?php if (!$myPolls): ?>
    <div class="semas-card p-4 text-center text-muted small">No open polls for you right now.</div>
  <?php else: foreach ($myPolls as $p): ?>
    <div class="semas-card p-3 mb-3">
      <h6 class="display-font mb-1"><?= e($p['title']) ?></h6>
      <p class="text-muted small mb-2"><?= e($p['description'] ?? '') ?></p>
      <?php if ($p['my_vote']): ?>
        <span class="badge badge-completed">You voted</span>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="vote">
          <input type="hidden" name="poll_id" value="<?= (int) $p['poll_id'] ?>">
          <?php foreach ($p['options'] as $opt): ?>
            <div class="form-check"><input type="radio" name="option_id" value="<?= (int) $opt['option_id'] ?>" class="form-check-input" required>
              <label class="form-check-label small"><?= e($opt['option_text']) ?></label></div>
          <?php endforeach; ?>
          <button class="btn btn-sm btn-semas mt-2">Submit Vote</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
<?php endif; ?>

<?php if ($isStaff): ?>
<div class="modal fade" id="createPollModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_poll">
        <div class="modal-header"><h6 class="modal-title display-font">New Poll</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label small">Question / Title</label><input name="title" class="form-control form-control-sm" required></div>
          <div class="mb-2"><label class="form-label small">Description (optional)</label><textarea name="description" class="form-control form-control-sm" rows="2"></textarea></div>
          <?php if ($role === 'Principal'): ?>
            <div class="mb-2">
              <label class="form-label small">Audience</label>
              <select name="target_audience" id="pollAudienceSelect" class="form-select form-select-sm" onchange="togglePollAudience()">
                <?php foreach (['All Students', 'Specific Department', 'Specific Faculty', 'Day Students', 'Evening Students', 'Weekend Students'] as $a): ?><option><?= e($a) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2" id="pollDeptField">
              <select name="department_id" class="form-select form-select-sm">
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2" id="pollFacultyField" style="display:none;">
              <select name="faculty_id" class="form-select form-select-sm">
                <?php foreach ($faculties as $f): ?><option value="<?= (int) $f['faculty_id'] ?>"><?= e($f['faculty_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          <?php elseif ($role === 'HOD'): ?>
            <p class="small text-muted">This poll will automatically be scoped to your department's students only.</p>
          <?php else: ?>
            <p class="small text-muted">This poll will automatically be scoped to your faculty's students only.</p>
          <?php endif; ?>
          <div class="mb-2"><label class="form-label small">Closes At (optional)</label><input type="datetime-local" name="closes_at" class="form-control form-control-sm"></div>
          <label class="form-label small">Options (at least 2)</label>
          <input name="options[]" class="form-control form-control-sm mb-1">
          <input name="options[]" class="form-control form-control-sm mb-1">
          <input name="options[]" class="form-control form-control-sm mb-1">
          <input name="options[]" class="form-control form-control-sm mb-1">
        </div>
        <div class="modal-footer"><button class="btn btn-semas-gold btn-sm">Create Poll</button></div>
      </form>
    </div>
  </div>
</div>
<script>
function togglePollAudience() {
  const v = document.getElementById('pollAudienceSelect').value;
  document.getElementById('pollDeptField').style.display = (v === 'Specific Department') ? '' : 'none';
  document.getElementById('pollFacultyField').style.display = (v === 'Specific Faculty') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function () { if (document.getElementById('pollAudienceSelect')) togglePollAudience(); });
</script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/layout_bottom.php'; ?>
