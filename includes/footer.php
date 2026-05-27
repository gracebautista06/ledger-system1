</div> </main> <footer class="main-footer">
        <div class="footer-content">
            <p> &copy; <?php echo date("Y"); ?> Egg Ledger System &mdash; Freshness &amp; Efficiency</p>
            <small style="opacity: 0.8;">Version 1.1 — Farm Operations</small>

            <?php if (isset($_SESSION['username'])): ?>
                <small style="margin-top: 8px; opacity: 0.7;">
                    Currently Active: <span class="user-badge"><?php echo htmlspecialchars($_SESSION['username']); ?></span> 
                    [<?php echo htmlspecialchars($_SESSION['role']); ?>]
                </small>
            <?php endif; ?>
        </div>
    </footer>

    <script>
    (function () {
        var toggle  = document.getElementById('sidebarToggle');
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay');

        if (!toggle || !sidebar) return;

        function openSidebar() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        toggle.addEventListener('click', function () {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        if (overlay) overlay.addEventListener('click', closeSidebar);

        sidebar.querySelectorAll('.sidebar-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });
    })();
    </script>

    <script src="<?php echo $root; ?>assets/js/script.js"></script>
</body>
</html>