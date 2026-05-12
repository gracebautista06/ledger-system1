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

    <script 
    
    src="<?php echo $root; ?>assets/js/script.js">
    
    document.addEventListener("DOMContentLoaded", function () {

    const sidebar = document.querySelector(".sidebar");

    // Restore scroll position
    const savedScroll = sessionStorage.getItem("sidebarScroll");

    if (savedScroll !== null) {
        sidebar.scrollTop = savedScroll;
    }

    // Save scroll position before leaving page
    sidebar.addEventListener("scroll", function () {
        sessionStorage.setItem("sidebarScroll", sidebar.scrollTop);
    });

});

    </script>
</body>
</html>