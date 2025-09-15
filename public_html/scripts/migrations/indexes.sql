-- 2025_09_10_seed_aggregator_commissions_demo.sql
-- Purpose: set an aggregator on the latest closed+paid order so vw_aggregator_commissions shows data.

START TRANSACTION;

-- Use an existing aggregator if present; otherwise fall back to NULL (no-op)
-- If your table is named differently, change 'aggregators' below.
SELECT id INTO @agg_id
FROM aggregators
ORDER BY id ASC
LIMIT 1;

-- Choose an order to tag
SELECT id INTO @order_id
FROM orders
WHERE status='closed' AND payment_status IN ('paid','partial')
ORDER BY created_at DESC
LIMIT 1;

-- Only proceed if we have both an order and an aggregator
SET @ok := IF(@order_id IS NOT NULL AND @agg_id IS NOT NULL, 1, 0);

SET @q := IF(@ok,
  CONCAT('UPDATE orders
           SET aggregator_id = ', @agg_id, ',
               commission_amount = COALESCE(commission_amount,0) + 10.00
           WHERE id = ', @order_id, ' LIMIT 1'),
  'SELECT 1');

PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

COMMIT;

-- Show that the view now has rows
SELECT * FROM vw_aggregator_commissions ORDER BY order_date DESC LIMIT 10;
