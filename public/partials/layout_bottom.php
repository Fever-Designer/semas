    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('sidebarToggle')?.addEventListener('click', function () {
  document.getElementById('semasSidebar')?.classList.toggle('open');
});

(function () {
  const APP_URL = window.SEMAS_BASE_URL;
  const CSRF = '<?= csrf_token() ?>';
  const bell = document.getElementById('notifBell');
  const panel = document.getElementById('notifPanel');
  const list = document.getElementById('notifList');
  const countEl = document.getElementById('notifCount');
  if (!bell) return;

  const categoryIcon = { Event: 'bi-calendar-event-fill', Announcement: 'bi-megaphone-fill',
                          Attendance: 'bi-clipboard-check-fill', System: 'bi-gear-fill' };

  function renderCount(n) {
    if (n > 0) { countEl.style.display = ''; countEl.textContent = n > 99 ? '99+' : n; }
    else { countEl.style.display = 'none'; }
  }

  function renderList(items) {
    if (!items.length) {
      list.innerHTML = '<div class="p-3 text-muted small text-center">No notifications yet.</div>';
      return;
    }
    list.innerHTML = items.map(function (n) {
      const icon = categoryIcon[n.category] || 'bi-bell-fill';
      const unreadClass = n.is_read == 0 ? 'unread' : '';
      return '<div class="notif-item ' + unreadClass + '" data-id="' + n.notification_id + '">' +
        '<div class="d-flex justify-content-between"><div class="fw-semibold"><i class="bi ' + icon + ' me-1"></i>' +
        escapeHtml(n.title) + '</div>' +
        '<div class="d-flex gap-2">' +
        (n.is_read == 0
          ? '<a href="#" class="notif-read-btn" title="Mark read"><i class="bi bi-check2"></i></a>'
          : '<a href="#" class="notif-unread-btn" title="Mark unread"><i class="bi bi-envelope"></i></a>') +
        '<a href="#" class="notif-del-btn text-danger" title="Delete"><i class="bi bi-trash"></i></a>' +
        '</div></div>' +
        '<div class="text-muted" style="font-size:0.78rem;">' + escapeHtml(n.body || '') + '</div>' +
        '<div class="text-muted" style="font-size:0.68rem;">' + n.created_at + '</div>' +
        '</div>';
    }).join('');
  }

  function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function loadNotifications() {
    fetch(APP_URL + '/api/notifications.php?action=list')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        renderCount(data.unread_count);
        renderList(data.items);
      });
  }

  function postAction(action, id) {
    return fetch(APP_URL + '/api/notifications.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + action + '&id=' + (id || '') + '&csrf_token=' + encodeURIComponent(CSRF),
    }).then(function (r) { return r.json(); });
  }

  bell.addEventListener('click', function (e) {
    e.stopPropagation();
    panel.classList.toggle('show');
    if (panel.classList.contains('show')) loadNotifications();
  });
  document.addEventListener('click', function (e) {
    if (!panel.contains(e.target) && e.target !== bell) panel.classList.remove('show');
  });

  list.addEventListener('click', function (e) {
    const item = e.target.closest('.notif-item');
    if (!item) return;
    const id = item.getAttribute('data-id');
    if (e.target.closest('.notif-read-btn')) { e.preventDefault(); postAction('mark_read', id).then(function (d) { renderCount(d.unread_count); loadNotifications(); }); }
    if (e.target.closest('.notif-unread-btn')) { e.preventDefault(); postAction('mark_unread', id).then(function (d) { renderCount(d.unread_count); loadNotifications(); }); }
    if (e.target.closest('.notif-del-btn')) { e.preventDefault(); postAction('delete', id).then(function (d) { renderCount(d.unread_count); loadNotifications(); }); }
  });

  document.getElementById('notifMarkAllRead')?.addEventListener('click', function (e) {
    e.preventDefault();
    postAction('mark_all_read').then(function (d) { renderCount(d.unread_count); loadNotifications(); });
  });

  loadNotifications();
  setInterval(loadNotifications, 20000); // AJAX auto-refresh every 20s
})();

// Navigation progress bar
NProgress.done();
document.addEventListener('click', function(e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript') === 0 || a.target || a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-bs-dismiss') || a.download) return;
    NProgress.start();
});
document.addEventListener('submit', function() { NProgress.start(); });
window.addEventListener('pageshow', function(e) { if (e.persisted) NProgress.done(); });
</script>
</body>
</html>
