<?php
/**
 * /views/partials/admin_nav_close.php
 * Closes the admin layout and includes footer
 * Must be included after page content to properly close the layout
 */
?>
    </div> <!-- Close page-content -->
    
    <!-- Admin Footer -->
    <footer class="admin-footer" style="
        background: #ffffff;
        border-top: 1px solid #edebe9;
        padding: 16px 24px;
        margin-top: 60px;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        font-size: 12px;
        color: #605e5c;
        text-align: right;
        position: relative;
        clear: both;
    ">
        <div style="
            max-width: 1400px;
            margin: 0 auto;
        ">
            © <?= date('Y') ?>
        </div>
    </footer>

  </div> <!-- Close admin-content -->
</div> <!-- Close admin-layout -->