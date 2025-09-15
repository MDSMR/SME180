<?php
/**
 * Auto-create POS API & Controller files if missing
 * Path: /home/customer/www/mohamedk10.sg-host.com/scripts/setup/create_pos_files.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2); // go up from /scripts/setup/ → project root

$files = [
    // POS APIs (JSON)
    "pos/api/auth/pin_login.php",
    "pos/api/auth/validate_pin.php",
    "pos/api/auth/logout.php",
    "pos/api/stations/register.php",
    "pos/api/stations/heartbeat.php",
    "pos/api/stations/capabilities.php",
    "pos/api/shifts/open.php",
    "pos/api/shifts/close.php",
    "pos/api/shifts/reconcile.php",
    "pos/api/shifts/current.php",
    "pos/api/session/open.php",
    "pos/api/session/close.php",
    "pos/api/session/movements.php",
    "pos/api/session/active.php",
    "pos/api/session/x_report.php",
    "pos/api/session/z_report.php",
    "pos/api/order/create.php",
    "pos/api/order/update.php",
    "pos/api/order/park.php",
    "pos/api/order/resume.php",
    "pos/api/order/fire.php",
    "pos/api/order/status.php",
    "pos/api/order/pay.php",
    "pos/api/order/refund.php",
    "pos/api/order/apply_discount.php",
    "pos/api/order/add_tip.php",
    "pos/api/order/set_service_charge.php",
    "pos/api/order/void_item.php",
    "pos/api/order/void_order.php",
    "pos/api/kds/feed.php",
    "pos/api/kds/update_item_status.php",
    "pos/api/kds/update_order_status.php",
    "pos/api/kds/screens/heartbeat.php",
    "pos/api/approvals/request.php",
    "pos/api/approvals/respond.php",
    "pos/api/approvals/pending.php",
    "pos/api/print/queue_job.php",
    "pos/api/print/status.php",
    "pos/api/print/requeue.php",
    "pos/api/offline/push.php",
    "pos/api/offline/pull.php",
    "pos/api/offline/ack.php",
    "pos/api/offline/resolve_conflict.php",
    "pos/api/customers/search.php",
    "pos/api/customers/create.php",
    "pos/api/loyalty/earn.php",
    "pos/api/loyalty/redeem.php",
    "pos/api/reports/today_summary.php",
    "pos/api/reports/shift_summary.php",
    "pos/api/reports/session_summary.php",
    "pos/api/settings/get.php",
    "pos/api/health.php",

    // Admin / Backoffice Controllers
    "controllers/admin/pos_stations/index.php",
    "controllers/admin/pos_stations/edit.php",
    "controllers/admin/pos_kds_screens/index.php",
    "controllers/admin/pos_kds_screens/edit.php",
    "controllers/admin/pos_shifts/index.php",
    "controllers/admin/pos_shifts/view.php",
    "controllers/admin/cash_sessions/index.php",
    "controllers/admin/cash_sessions/view.php",
    "controllers/admin/pos_approvals/index.php",
    "controllers/admin/pos_approvals/view.php",
    "controllers/admin/pos_print_queue/index.php",
    "controllers/admin/pos_print_queue/view.php",
    "controllers/admin/pos_report_templates/index.php",
    "controllers/admin/pos_report_templates/edit.php",
    "controllers/admin/settings/pos.php",

    // Cron Workers
    "scripts/cron/print_queue_worker.php",
    "scripts/cron/offline_sync_worker.php",
];

$created = [];
$existing = [];

foreach ($files as $relPath) {
    $fullPath = $basePath . "/" . $relPath;
    $dir = dirname($fullPath);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    if (!file_exists($fullPath)) {
        file_put_contents($fullPath, "<?php\n// Auto-generated skeleton: {$relPath}\n");
        $created[] = $relPath;
    } else {
        $existing[] = $relPath;
    }
}

// Report
echo "✅ Script finished.\n";
echo "Created files:\n" . implode("\n", $created) . "\n\n";
echo "Already existing (skipped):\n" . implode("\n", $existing) . "\n";
