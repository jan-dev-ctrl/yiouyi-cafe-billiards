<?php
// Sales report for the admin: one day or ANY date range.   report.php?from=2026-10-01&to=2026-10-20
// The "Sales Report" card on the dashboard uses the same numbers (includes/salesreport.php).
// Only paid (settled) things count as sales. A cafe order counts on the day it was paid, billiards on the reservation date.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/salesreport.php';
date_default_timezone_set('Asia/Manila');

$user = require_role('admin');   // admin only
$pdo  = db();

$today  = date('Y-m-d');
$rg     = sr_range($_GET, $today);
$from   = $rg['from']; $to = $rg['to']; $days = $rg['days'];
$single = $days === 1;
$R      = sales_report($pdo, $from, $to);
$presets = sr_presets($today);
$prev   = date('Y-m-d', strtotime($from . ' -1 day'));
$next   = date('Y-m-d', strtotime($from . ' +1 day'));

function peso($n): string { return '₱' . number_format((float)$n, 2); }
function mins_text(int $m): string { return $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm'; }

// one-day reports also list every billiards session of that day
$sessions = [];
if ($single) {
    $st = $pdo->prepare("SELECT table_name, TIME_FORMAT(res_time, '%l:%i %p') AS t, mins, play_mins, total, status, pay_method
                           FROM reservations WHERE res_date = ? AND status <> 'cancelled' ORDER BY res_time, id");
    $st->execute([$from]);
    $sessions = $st->fetchAll();
}
$cafe = $R['cafe']; $bil = $R['bil']; $pay = $R['pay'];
$admin = $user['email'];
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Sales report <?= e($rg['label']) ?> • YiouYi</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:var(--page);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);padding:16px}
  .sheet{max-width:620px;margin:0 auto;background:var(--sheet);border-radius:14px;padding:22px 20px;box-shadow:0 2px 14px rgba(0,0,0,.08)}
  .head{text-align:center}
  .head img{height:52px;border-radius:12px}
  .head h1{font-size:18px;margin:8px 0 2px}
  .head p{margin:0;font-size:12px;color:var(--muted)}
  .kind{margin:14px 0 2px;text-align:center;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
  .day{text-align:center;font-size:20px;font-weight:700;margin-bottom:4px}
  h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:18px 0 6px}
  hr{border:0;border-top:1px dashed var(--dash);margin:14px 0}
  .row{display:flex;justify-content:space-between;gap:12px;font-size:14px;padding:3px 0}
  .row .l{color:var(--muted)}
  .row.big{font-size:20px;font-weight:700}
  .row.big .l{color:var(--ink)}
  .sub{font-size:12px;color:var(--muted2)}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);padding:4px 0;border-bottom:1px solid var(--rule)}
  td{padding:5px 0;border-bottom:1px solid var(--rule2);vertical-align:top}
  th.r,td.r{text-align:right}
  .warn{background:var(--warn-bg);color:var(--warn-fg);border-radius:10px;padding:9px 11px;font-size:13px;margin-top:8px}
  .muted{color:var(--muted2);font-size:13px;padding:6px 0}
  .foot{font-size:11px;color:var(--muted2);margin-top:14px;line-height:1.5}
  .controls{max-width:620px;margin:0 auto 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .controls a,.controls button,.controls input{font:600 14px system-ui,sans-serif;padding:10px 12px;border-radius:10px;border:0;background:var(--soft-bg);color:var(--ink);text-decoration:none}
  .controls input{background:var(--sheet);border:1px solid var(--line);font-weight:500}
  .controls .go{background:var(--btn-bg);color:var(--btn-fg);cursor:pointer}
  .controls .off{opacity:.4;pointer-events:none}
  .controls .sp{flex:1}
  .controls label{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted)}
  .controls.presets a{padding:7px 11px;font-size:13px}
  .controls.presets a.on{background:var(--btn-bg);color:var(--btn-fg)}
  .big2{font-size:12px;color:var(--muted2)}
  @media print{
    body{background:#fff;padding:0}
    .sheet{box-shadow:none;border-radius:0;max-width:none;padding:0}
    .controls{display:none}
    h2{break-after:avoid}
    tr{break-inside:avoid}
    @page{margin:10mm}
  }
</style></head>
<body>
<form class="controls" method="get" action="report.php">
  <a href="dashboard.php">← Dashboard</a>
  <label>From <input type="date" name="from" value="<?= e($from) ?>" required/></label>
  <label>To <input type="date" name="to" value="<?= e($to) ?>" required/></label>
  <button type="submit" class="go">Show</button>
  <span class="sp"></span>
  <button type="button" class="go" onclick="window.print()">Print</button>
</form>
<div class="controls presets">
  <?php foreach ($presets as $label => [$pf, $pt]): ?>
  <a class="<?= ($from === $pf && $to === $pt) ? 'on' : '' ?>" href="report.php?from=<?= e($pf) ?>&amp;to=<?= e($pt) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <?php if ($single): ?>
  <span class="sp"></span>
  <a href="report.php?from=<?= e($prev) ?>&amp;to=<?= e($prev) ?>">‹ Prev day</a>
  <a href="report.php?from=<?= e($next) ?>&amp;to=<?= e($next) ?>">Next day ›</a>
  <?php endif; ?>
</div>

<div class="sheet">
  <div class="head">
    <img src="logo.png" alt=""/>
    <h1>YiouYi Cafe &amp; Billiards</h1>
    <p>Santa Maria Bypass Rd, Santa Maria, 3022 Bulacan</p>
    <p><em>Creating memories one sip at a time</em></p>
  </div>
  <div class="kind"><?= $single ? 'Daily sales report' : 'Sales report' ?></div>
  <div class="day"><?= e($single ? date('l, F j, Y', strtotime($from)) : $rg['label']) ?></div>
  <?php if (!$single): ?><div class="big2" style="text-align:center"><?= $days ?> days<?= $rg['clamped'] ? ' (the latest ' . SR_MAX_DAYS . ' days of the range you picked)' : '' ?></div><?php endif; ?>

  <hr/>
  <div class="row big"><span class="l">Total sales</span><span><?= e(peso($R['total'])) ?></span></div>
  <div class="row"><span class="l">Café</span><span><?= e(peso($cafe['sales'])) ?></span></div>
  <div class="row"><span class="l">Billiards</span><span><?= e(peso($bil['sales'])) ?></span></div>
  <?php if (!$single): ?><div class="row"><span class="l">Average per day</span><span><?= e(peso($R['total'] / $days)) ?></span></div><?php endif; ?>

  <h2>Payments received</h2>
  <table>
    <tr><th>Method</th><th class="r">Payments</th><th class="r">Amount</th></tr>
    <?php foreach ($pay as $m => $p): ?>
    <tr><td><?= e(strtoupper($m)) ?></td><td class="r"><?= (int)$p['c'] ?></td><td class="r"><?= e(peso($p['t'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="sub"><?= $single ? 'Cash to hand over' : 'Cash received' ?>: <b><?= e(peso($pay['cash']['t'] ?? 0)) ?></b></p>

  <h2>Café: what we sold</h2>
  <div class="row"><span class="l">Paid orders</span><span><?= $cafe['paid'] ?></span></div>
  <div class="row"><span class="l">Items sold</span><span><?= (int)$cafe['units'] ?></span></div>
  <div class="row"><span class="l">Average per paid order</span><span><?= e(peso($cafe['avg'])) ?></span></div>
  <div class="row"><span class="l">Cancelled orders</span><span><?= $cafe['cancelled'] ?></span></div>
  <?php if ($cafe['cats']): ?>
  <table style="margin-top:8px">
    <tr><th>By category</th><th class="r">Sold</th><th class="r">Sales</th></tr>
    <?php foreach ($cafe['cats'] as $c): ?>
    <tr><td><?= e($c['name']) ?></td><td class="r"><?= (int)$c['qty'] ?></td><td class="r"><?= e(peso($c['rev'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <table style="margin-top:12px">
    <tr><th>Every item sold</th><th class="r">Sold</th><th class="r">Sales</th></tr>
    <?php foreach ($cafe['items'] as $it): ?>
    <tr><td><?= e($it['name']) ?></td><td class="r"><?= (int)$it['qty'] ?></td><td class="r"><?= e(peso($it['rev'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">No café sales in this period.</p>
  <?php endif; ?>

  <h2>Billiards</h2>
  <div class="row"><span class="l">Paid sessions</span><span><?= $bil['sessions'] ?></span></div>
  <div class="row"><span class="l">Fixed bookings</span><span><?= $bil['fixed_sessions'] ?> • <?= e(peso($bil['fixed_sales'])) ?></span></div>
  <div class="row"><span class="l">Open time</span><span><?= $bil['open_sessions'] ?> • <?= e(peso($bil['open_sales'])) ?></span></div>
  <div class="row"><span class="l">Time played</span><span><?= e(mins_text((int)$bil['mins'])) ?></span></div>
  <div class="row"><span class="l">Cancelled / no-shows</span><span><?= $bil['cancelled'] ?></span></div>
  <table style="margin-top:8px">
    <tr><th>By table</th><th class="r">Sessions</th><th class="r">Time</th><th class="r">Sales</th></tr>
    <?php foreach ($bil['tables'] as $t): ?>
    <tr><td><?= e($t['table']) ?></td><td class="r"><?= (int)$t['sessions'] ?></td><td class="r"><?= e(mins_text((int)$t['mins'])) ?></td><td class="r"><?= e(peso($t['sales'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($single && $sessions): ?>
  <table style="margin-top:12px">
    <tr><th>Table</th><th>Start</th><th>Length</th><th class="r">Amount</th></tr>
    <?php foreach ($sessions as $s):
        $open = $s['mins'] === null;
        $len  = $open ? ($s['play_mins'] !== null ? mins_text((int)$s['play_mins']) : 'Open') : mins_text((int)$s['mins']);
        $amt  = $s['status'] === 'settled' ? peso($s['total']) . ' ' . strtoupper((string)$s['pay_method'])
              : ($open ? 'by time' : peso($s['total'])); ?>
    <tr>
      <td><?= e($s['table_name']) ?></td><td><?= e($s['t']) ?></td><td><?= e($len) ?></td>
      <td class="r"><?= e($amt) ?><?php if ($s['status'] !== 'settled'): ?><br/><span class="sub"><?= e($s['status']) ?></span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if ($R['daily']): ?>
  <h2>Day by day</h2>
  <table>
    <tr><th>Date</th><th class="r">Café</th><th class="r">Billiards</th><th class="r">Total</th></tr>
    <?php foreach ($R['daily'] as $d): ?>
    <tr><td><?= e(date('D, M j', strtotime($d['date']))) ?></td><td class="r"><?= e(peso($d['cafe'])) ?></td><td class="r"><?= e(peso($d['bil'])) ?></td><td class="r"><b><?= e(peso($d['total'])) ?></b></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if ($cafe['open'] || $bil['waiting']): ?>
  <h2>Still unpaid</h2>
  <div class="warn">
    <?php if ($cafe['open']): ?><?= $cafe['open'] ?> café order<?= $cafe['open'] > 1 ? 's' : '' ?> not yet paid (<?= e(peso($cafe['openAmt'])) ?>).<br/><?php endif; ?>
    <?php if ($bil['waiting']): ?><?= $bil['waiting'] ?> billiards booking<?= $bil['waiting'] > 1 ? 's' : '' ?> not yet settled.<?php endif; ?>
  </div>
  <?php endif; ?>

  <hr/>
  <p class="foot">Only paid orders and settled sessions count as sales. A café order counts on the day it was paid; billiards on the reservation date.<br/>
  Printed <?= e(date('M j, Y g:i A')) ?> by <?= e($admin) ?></p>
</div>
</body></html>
