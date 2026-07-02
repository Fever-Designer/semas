    </div>
  </div>
</div>
<style>
@keyframes notifSlideIn { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:translateX(0); } }
@keyframes notifBellPulse { 0%, 100% { transform:scale(1); } 35% { transform:scale(1.16); } 70% { transform:scale(0.96); } }
</style>
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
      const unreadClass = Number(n.is_read) === 0 ? ' unread' : '';
      return '<div class="notif-item' + unreadClass + '" data-id="' + n.notification_id + '">' +
        '<div class="d-flex justify-content-between align-items-start">' +
        '<div class="fw-semibold"><i class="bi ' + icon + ' me-1"></i>' + escapeHtml(n.title) + '</div>' +
        '</div>' +
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

  let lastSeenId = null;
  let toastBox = null;

  function ensureToastBox() {
    if (toastBox) return toastBox;
    toastBox = document.createElement('div');
    toastBox.id = 'notifToastBox';
    toastBox.style.cssText = 'position:fixed;top:70px;right:16px;z-index:2000;max-width:320px;display:flex;flex-direction:column;gap:8px;';
    document.body.appendChild(toastBox);
    return toastBox;
  }

  function popToast(n) {
    const icon = categoryIcon[n.category] || 'bi-bell-fill';
    const box = ensureToastBox();
    const el = document.createElement('div');
    el.className = 'semas-card p-2 px-3 shadow-sm';
    el.style.cssText = 'background:#fff;border-left:4px solid var(--semas-gold, #c9a227);animation:notifSlideIn .25s ease-out;';
    el.innerHTML = '<div class="fw-semibold small"><i class="bi ' + icon + ' me-1"></i>' + escapeHtml(n.title) + '</div>' +
      '<div class="text-muted" style="font-size:0.78rem;">' + escapeHtml(n.body || '') + '</div>';
    box.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .3s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 300);
    }, 6000);
  }

  function pulseBell() {
    bell.classList.remove('notif-live-pulse');
    void bell.offsetWidth;
    bell.classList.add('notif-live-pulse');
    setTimeout(function () { bell.classList.remove('notif-live-pulse'); }, 900);
  }

  function loadNotifications() {
    fetch(APP_URL + '/api/notifications.php?action=list')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        renderCount(data.unread_count);
        renderList(data.items);

        if (lastSeenId !== null) {
          data.items
            .filter(function (n) { return n.notification_id > lastSeenId; })
            .reverse()
            .forEach(function (n) {
              popToast(n);
              pulseBell();
            });
        }
        if (data.items.length) {
          lastSeenId = Math.max.apply(null, data.items.map(function (n) { return n.notification_id; }));
        }
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
    const wasHidden = !panel.classList.contains('show');
    panel.classList.toggle('show');
    if (wasHidden) {
      loadNotifications();
      postAction('mark_all_read').then(function (d) {
        renderCount(d.unread_count);
        list.querySelectorAll('.notif-item.unread').forEach(function (item) {
          item.classList.remove('unread');
        });
      });
    }
  });
  document.addEventListener('click', function (e) {
    if (!panel.contains(e.target) && e.target !== bell) panel.classList.remove('show');
  });

  loadNotifications();
  setInterval(loadNotifications, 4000);
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
