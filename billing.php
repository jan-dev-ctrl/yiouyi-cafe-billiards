<?php
// Billiards pricing, shared by api/reservations.php (fixed bookings) and api/staff.php (open time).

const BOOKABLE_TABLES  = ['Table 1', 'Table 2', 'Table 3', 'Table 4'];   // tables customers can reserve
const OPEN_MIN          = 11 * 60;   // 11:00 AM
const CLOSE_MIN         = 24 * 60;   // 12:00 AM (midnight). Open-time sessions are never billed past this.
const BILLING_STEP_MIN = 30;   // open time is billed in 30-minute steps, rounded up
const MIN_BILLABLE_MIN = 60;   // the first hour is always charged

/** P150 first hour, +P100 each extra hour, +P75 for an extra half hour. */
function billiards_price(int $mins): int {
    $extra = $mins - 60;
    return 150 + intdiv($extra, 60) * 100 + (($extra % 60) ? 75 : 0);
}

/** Minutes actually played (in seconds) -> billable minutes: round up to the next 30, minimum 60. */
function billable_minutes(int $seconds): int {
    $steps = (int)ceil(max(0, $seconds) / (BILLING_STEP_MIN * 60));
    return max(MIN_BILLABLE_MIN, $steps * BILLING_STEP_MIN);
}

/** "1h 30m" / "2h" / "45m" */
function fmt_mins(int $m): string {
    $h = intdiv($m, 60);
    $r = $m % 60;
    return ($h ? $h . 'h' : '') . ($h && $r ? ' ' : '') . ($r || !$h ? $r . 'm' : '');
}

/** Line items that add up to billiards_price(): [[label, amount], ...] (used on the receipt). */
function billiards_lines(int $mins): array {
    $mins  = max(60, $mins);
    $extra = $mins - 60;
    $hours = intdiv($extra, 60);
    $lines = [['First hour', 150]];
    if ($hours > 0) {
        $lines[] = [$hours . ' extra hour' . ($hours > 1 ? 's' : '') . ' x ₱100', $hours * 100];
    }
    if ($extra % 60) {
        $lines[] = ['Extra half hour', 75];
    }
    return $lines;
}

/**
 * SQL for "which day does this cafe order belong to in the reports?"
 *   - paid (settled) orders count on the day the payment was received (paid_at), so an order placed at
 *     11:50 PM and paid after midnight is next day's sales;
 *   - orders that are still open or cancelled count on the day they were placed (created_at).
 * Old rows without paid_at fall back to created_at. Pass the table alias, e.g. order_day_sql('o').
 */
function order_day_sql(string $alias = ''): string {
    $p = $alias === '' ? '' : $alias . '.';
    return "DATE(CASE WHEN {$p}status = 'settled' THEN COALESCE({$p}paid_at, {$p}created_at) ELSE {$p}created_at END)";
}
