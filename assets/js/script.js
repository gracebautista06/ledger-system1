
document.addEventListener('DOMContentLoaded', function () {

    /* ----------------------------------------------------------
       1. AUTO-DISMISS ALERTS
       Alerts fade out after 5 seconds automatically.
    ---------------------------------------------------------- */
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            alert.style.opacity    = '0';
            alert.style.transform  = 'translateY(-8px)';
            setTimeout(() => alert.remove(), 600);
        }, 5000);
    });

    /* ----------------------------------------------------------
       2. ACTIVE NAV LINK HIGHLIGHTING
    ---------------------------------------------------------- */
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-links a').forEach(function (link) {
        const href = link.getAttribute('href');
        if (href && currentPath.endsWith(href.replace('../', ''))) {
            link.style.color          = 'var(--gold)';
            link.style.fontWeight     = '800';
            link.style.borderBottom   = '2px solid var(--gold)';
        }
    });

    /* ----------------------------------------------------------
       3. FORM DOUBLE-SUBMIT PREVENTION
    ---------------------------------------------------------- */
    document.querySelectorAll('form[method="POST"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                setTimeout(function () {
                    btn.disabled     = true;
                    btn.textContent  = '⏳ Saving...';
                }, 50);
            }
        });
    });

    /* ----------------------------------------------------------
       4. NUMERIC INPUT SANITIZER — no negatives on min="0" inputs
    ---------------------------------------------------------- */
    document.querySelectorAll('input[type="number"][min="0"]').forEach(function (input) {
        input.addEventListener('blur', function () {
            if (parseInt(this.value) < 0 || isNaN(parseInt(this.value))) {
                this.value = 0;
            }
        });
    });

});

/* ----------------------------------------------------------
   5. GLOBAL CONFIRM HELPER
   Usage: onclick="return confirmAction('Delete this record?')"
---------------------------------------------------------- */
function confirmAction(message) {
    return confirm(message || 'Are you sure you want to proceed?');
}

/* ----------------------------------------------------------
   6. TOGGLE PASSWORD VISIBILITY (shared across pages)
   Usage: togglePassword('input-id', 'icon-span-id')
---------------------------------------------------------- */
function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input || !icon) return;

    input.type = input.type === 'password' ? 'text' : 'password';

    // slashed eye when password is hidden, open eye when visible
    icon.classList.toggle('active', input.type === 'password');
}
function toggleAddPanel() {
    const p = document.getElementById('add-panel');
    if (!p) return;

    const isHidden = getComputedStyle(p).display === 'none';
    p.style.display = isHidden ? 'block' : 'none';

    if (isHidden) {
        const input = p.querySelector('input[name="new_username"]');
        if (input) input.focus();
    }
}


// 🔵 LIVE STATUS FUNCTION
function refreshStatus() {
    fetch('fetch_status.php')
        .then(res => res.json())
        .then(data => {
            Object.keys(data).forEach(userId => {
                const cell = document.getElementById('status-' + userId);
                if (!cell) return;

                const user = data[userId];

                let html = '';

                if (user.is_online === 1) {
                    html = `
                        <div style="display:flex; align-items:center; gap:7px;">
                            <span style="width:9px; height:9px; border-radius:50%;
                                background:var(--success);
                                animation:pulse-online 2s infinite;"></span>
                            <span style="font-size:0.82rem; font-weight:700; color:var(--success);">
                                Active now
                            </span>
                        </div>`;
                } else {
                    html = `<span style="font-size:0.8rem; color:var(--text-muted);">Offline</span>`;
                }

                cell.innerHTML = html;
            });
        })
        .catch(() => {});
}


// 🔄 RUN STATUS UPDATE EVERY 10s
setInterval(refreshStatus, 10000);


// 💓 HEARTBEAT EVERY 30s
setInterval(() => {
    fetch('heartbeat.php').catch(() => {});
}, 30000);


// 🚪 LOGOUT DETECTION
window.addEventListener('beforeunload', () => {
    navigator.sendBeacon('logout_ping.php');
});