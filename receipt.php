<?php
// One printable receipt for both cafe orders and billiards reservations.
//   receipt.php?type=order&id=12   -> cafe order
//   receipt.php?type=resv&id=7     -> billiards reservation
// Customers can only open their own receipts; staff can open any (they run the counter).

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';
date_default_timezone_set('Asia/Manila');

$user    = require_role('member');
$isStaff = in_array($user['role'], ['staff', 'admin'], true);   // staff run the counter; admin can check any receipt
$type    = (string)($_GET['type'] ?? '');
$id      = (int)($_GET['id'] ?? 0);

function peso($n): string {
    return '₱' . number_format((float)$n, 2);
}
function when(?string $dt, string $fmt = 'M j, Y g:i A'): string {
    return $dt ? date($fmt, strtotime($dt)) : '';
}

$pdo  = db();
$rows = [];          // [label, amount|null] lines in the item area
$meta = [];          // [label, value] lines under the header
$notes = [];         // small notes under the total
$payRows = [];       // [label, value] lines under the total (payment details on a paid receipt)
$payNow = null;      // link to the payment page, for the customer's own unpaid order
$orderPaid = false;

if ($type === 'order') {
    $st = $pdo->prepare('SELECT o.*, u.email, u.full_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d || (!$isStaff && (int)$d['user_id'] !== (int)$user['id'])) {
        http_response_code(404);
        exit('Receipt not found.');
    }
    $items = $pdo->prepare('SELECT name, price, qty FROM order_items WHERE order_id = ? ORDER BY id');
    $items->execute([$id]);
    foreach ($items as $it) {
        $rows[] = [(int)$it['qty'] . ' x ' . $it['name'], (float)$it['price'] * (int)$it['qty'], (int)$it['qty'] . ' @ ' . peso($it['price'])];
    }
    $receiptNo = 'YIO-' . $d['order_no'];
    $title     = 'Cafe order';
    $qtyTotal = 0;
    foreach ($rows as $r0) { $qtyTotal += (int)explode(' ', (string)$r0[0])[0]; }
    $meta[]    = ['Ordered', when($d['created_at'])];
    $meta[]    = ['Serve at', $d['location']];
    $total     = (float)$d['total'];
    $orderPaid = $d['paid_at'] !== null || $d['status'] === 'settled';
    if ($orderPaid) {
        $payLabel = ['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card'][(string)$d['pay_method']] ?? strtoupper((string)$d['pay_method']);
        $cashier = '';
        if ($d['cashier_id'] !== null) {
            $cq = $pdo->prepare('SELECT full_name, email FROM users WHERE id = ?');
            $cq->execute([$d['cashier_id']]);
            if ($c = $cq->fetch()) { $cashier = trim((string)$c['full_name']) !== '' ? $c['full_name'] : $c['email']; }
        }
        $meta[] = ['Paid on', when($d['paid_at'] ?? $d['created_at'], 'M j, Y g:i:s A')];
        $payRows[] = ['Payment method', $payLabel];
        if ($d['tendered'] !== null) {
            $payRows[] = ['Cash received', peso($d['tendered'])];
            $payRows[] = ['Change', peso((float)$d['tendered'] - $total)];
        }
        if ($cashier !== '') { $payRows[] = ['Received by', $cashier]; }
        $payRows[] = ['Items', (string)$qtyTotal];
    } elseif ($d['status'] !== 'cancelled' && (int)$d['user_id'] === (int)$user['id']) {
        $payNow = 'pay.php?id=' . (int)$d['id'];
        if ($d['pay_pref'] === 'cash') {
            $notes[] = 'Please pay ' . peso($total) . ' in cash at the cashier and tell them your order number. This slip is not an official receipt.';
        } elseif ($d['pay_pref'] === 'gcash') {
            $notes[] = 'Pay ' . peso($total) . ' by GCash, then show your confirmation screen to the cashier. This slip is not an official receipt.';
        } else {
            $notes[] = 'Not paid yet. Choose Cash or GCash to pay. This slip is not an official receipt.';
        }
    }
    $back      = $isStaff ? ['staff.php', 'Back to orders'] : ['menu.php', 'Back to menu'];
} elseif ($type === 'resv') {
    $st = $pdo->prepare('SELECT r.*, u.email, u.full_name FROM reservations r JOIN users u ON u.id = r.user_id WHERE r.id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d || (!$isStaff && (int)$d['user_id'] !== (int)$user['id'])) {
        http_response_code(404);
        exit('Receipt not found.');
    }
    $open      = $d['mins'] === null;
    $receiptNo = 'RSV-' . str_pad((string)$d['id'], 5, '0', STR_PAD_LEFT);
    $title     = 'Billiards • ' . $d['table_name'];
    $meta[]    = ['Booked for', when($d['res_date'] . ' ' . $d['res_time'])];
    $meta[]    = ['Type', $open ? 'Open time' : 'Fixed • ' . fmt_mins((int)$d['mins'])];

    if (!$open) {
        foreach (billiards_lines((int)$d['mins']) as $l) {
            $rows[] = [$l[0], $l[1], null];
        }
        $total = (float)$d['total'];
    } elseif ($d['play_mins'] !== null) {
        // Open time, finished: show the real clock times and what was billed
        $actual = max(0, strtotime($d['ended_at']) - strtotime($d['started_at']));
        $meta[] = ['Started', when($d['started_at'])];
        $meta[] = ['Ended', when($d['ended_at'])];
        $meta[] = ['Time played', fmt_mins((int)ceil($actual / 60))];
        $meta[] = ['Billed as', fmt_mins((int)$d['play_mins']) . ' (rounded up to ' . BILLING_STEP_MIN . ' min)'];
        if (strtotime($d['ended_at']) === strtotime(substr($d['started_at'], 0, 10)) + CLOSE_MIN * 60) {
            $notes[] = 'The session was still running at closing time, so it was billed only until closing.';
        }
        foreach (billiards_lines((int)$d['play_mins']) as $l) {
            $rows[] = [$l[0], $l[1], null];
        }
        $total = (float)$d['total'];
    } else {
        // Open time, not finished: the bill does not exist yet
        if ($d['started_at']) {
            $meta[] = ['Started', when($d['started_at'])];
        }
        $rows[] = ['Open time: billed by time played', null, null];
        $notes[] = 'Your total is worked out when the session ends: ₱150 first hour, +₱100 each extra hour, +₱75 for an extra half hour, rounded up to the next 30 minutes.';
        $total = null;
    }
    $back = $isStaff ? ['staff.php', 'Back to orders'] : ['billiards.php', 'Back to billiards'];
} else {
    http_response_code(400);
    exit('Unknown receipt type.');
}

$status = $d['status'];
$paid   = $status === 'settled' || ($type === 'order' && $orderPaid);
if ($status === 'cancelled') {
    $badge = ['CANCELLED', 'cancel'];
} elseif ($paid) {
    $badge = ['PAID • ' . strtoupper((string)$d['pay_method']), 'paid'];
} elseif ($type === 'resv' && $status === 'pending') {
    $badge = ['AWAITING STAFF CONFIRMATION', 'wait'];
} else {
    $badge = ['NOT YET PAID', 'wait'];
}
$customer = trim((string)$d['full_name']) !== '' ? $d['full_name'] : $d['email'];
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?= e($paid ? 'Receipt' : 'Order slip') ?> <?= e($receiptNo) ?> • YiouYi</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:var(--page);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);padding:16px}
  .sheet{max-width:380px;margin:0 auto;background:var(--sheet);border-radius:14px;padding:22px 20px;box-shadow:0 2px 14px rgba(0,0,0,.08)}
  .head{text-align:center}
  .head img{height:56px;border-radius:12px}
  .head h1{font-size:18px;margin:8px 0 2px}
  .head p{margin:0;font-size:12px;color:var(--muted)}
  .kind{margin:14px 0 2px;text-align:center;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
  .no{text-align:center;font-size:22px;font-weight:700;margin-bottom:10px}
  hr{border:0;border-top:1px dashed var(--dash);margin:12px 0}
  .row{display:flex;justify-content:space-between;gap:12px;font-size:14px;padding:3px 0}
  .row .l{color:var(--muted)}
  .row .r{text-align:right}
  .sub{font-size:12px;color:var(--muted2)}
  .item .l{color:var(--ink)}
  .total{font-size:18px;font-weight:700}
  .badge{display:block;text-align:center;margin:14px 0 4px;padding:8px;border-radius:10px;font-weight:700;font-size:13px;letter-spacing:.04em}
  .paid{background:var(--ok-bg);color:var(--ok-fg)}
  .wait{background:var(--warn-bg);color:var(--warn-fg)}
  .cancel{background:var(--bad-bg);color:var(--bad-fg)}
  .note{font-size:12px;color:var(--muted);margin:8px 0 0}
  .thanks{text-align:center;font-size:12px;color:var(--muted);margin-top:14px}
  .fbl{max-width:380px;margin:12px auto 0;text-align:center;font-size:13px}
  .fbl a{color:var(--muted)}
  .tagline{text-align:center;font-size:12px;font-style:italic;line-height:1.45;color:var(--muted);margin:4px 0 0}
  .actions{max-width:380px;margin:14px auto 0;display:flex;gap:10px}
  .actions a,.actions button{flex:1;text-align:center;padding:12px;border-radius:10px;border:0;font:600 14px system-ui,sans-serif;cursor:pointer;text-decoration:none}
  .actions button{background:var(--btn-bg);color:var(--btn-fg)}
  .actions a{background:var(--soft-bg);color:var(--ink)}
  @media print{
    .fbl{display:none}
    body{background:#fff;padding:0}
    .sheet{box-shadow:none;border-radius:0;max-width:none;padding:0}
    .actions{display:none}
    @page{margin:8mm}
  }
</style></head>
<body><button type="button" data-theme-toggle class="theme-fab"></button>
<div class="sheet">
  <div class="head">
    <img src="logo.png" alt=""/>
    <h1>YiouYi Cafe &amp; Billiards</h1>
    <p>Santa Maria Bypass Rd, Santa Maria, 3022 Bulacan</p>
    <p>+63 44 230 2313</p>
  </div>

  <div class="kind"><?= $paid ? 'Official receipt' : 'Order slip' ?> • <?= e($title) ?></div>
  <div class="no"><?= e($receiptNo) ?></div>

  <hr/>
  <div class="row"><span class="l">Customer</span><span class="r"><?= e($customer) ?></span></div>
  <?php foreach ($meta as $m): ?>
  <div class="row"><span class="l"><?= e($m[0]) ?></span><span class="r"><?= e($m[1]) ?></span></div>
  <?php endforeach; ?>

  <hr/>
  <?php foreach ($rows as $r): ?>
  <div class="row item">
    <span class="l"><?= e($r[0]) ?><?php if (!empty($r[2])): ?><br/><span class="sub"><?= e($r[2]) ?> each</span><?php endif; ?></span>
    <span class="r"><?= $r[1] === null ? '' : e(peso($r[1])) ?></span>
  </div>
  <?php endforeach; ?>

  <hr/>
  <div class="row total"><span>Total</span><span><?= $total === null ? 'By time played' : e(peso($total)) ?></span></div>
  <?php foreach ($payRows as $pr): ?>
  <div class="row"><span class="l"><?= e($pr[0]) ?></span><span class="r"><?= e($pr[1]) ?></span></div>
  <?php endforeach; ?>
  <span class="badge <?= e($badge[1]) ?>"><?= e($badge[0]) ?></span>
  <?php foreach ($notes as $n): ?><p class="note"><?= e($n) ?></p><?php endforeach; ?>

  <p class="thanks">Thank you for visiting YiouYi!</p>
  <p class="tagline">Creating memories<br/>one sip at a time</p>
</div>
<div class="actions">
  <?php if ($payNow): ?><a href="<?= e($payNow) ?>" style="background:var(--btn-bg);color:var(--btn-fg)">Pay now</a><?php endif; ?>
  <a href="<?= e($back[0]) ?>"><?= e($back[1]) ?></a>
  <?php if ($isStaff): ?>
  <button onclick="window.print()">Print</button>
  <?php else: ?>
  <button type="button" id="dlBtn" onclick="downloadReceipt(this)">Download</button>
  <?php endif; ?>
</div>
<?php if (!$isStaff): ?><p class="fbl"><a href="index.php#feedback">How was your visit? Leave feedback</a></p><?php endif; ?>
<?php if (!$isStaff): ?>
<script src="assets/html2canvas.min.js"></script>
<script>
// Download = a PDF of the receipt (always on white paper, even in dark mode). Customers get this; staff still print.
var FILE_NAME = <?= json_encode('YiouYi-' . ($paid ? 'Receipt' : 'OrderSlip') . '-' . $receiptNo . '.pdf') ?>;
var PAPER = {'--sheet':'#ffffff','--ink':'#2b211d','--muted':'#6d5d55','--muted2':'#8a7a72','--dash':'#bfb2a9',
             '--ok-bg':'#e3f4e6','--ok-fg':'#1d6a35','--warn-bg':'#fff1d6','--warn-fg':'#8a5a00','--bad-bg':'#fde4e2','--bad-fg':'#a4261d'};

function pdfFromJpeg(jpeg, w, h) {                       // one page, the picture fills it
  var PW = 300, PH = Math.round(PW * h / w * 100) / 100, enc = new TextEncoder(), parts = [], off = [], size = 0;
  function add(x) { var b = typeof x === 'string' ? enc.encode(x) : x; parts.push(b); size += b.length; }
  function begin(n) { off[n] = size; add(n + ' 0 obj\n'); }
  add('%PDF-1.4\n');
  begin(1); add('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');
  begin(2); add('<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n');
  begin(3); add('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + PW + ' ' + PH + '] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>\nendobj\n');
  begin(4); add('<< /Type /XObject /Subtype /Image /Width ' + w + ' /Height ' + h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpeg.length + ' >>\nstream\n');
  add(jpeg); add('\nendstream\nendobj\n');
  var content = 'q ' + PW + ' 0 0 ' + PH + ' 0 0 cm /Im0 Do Q';
  begin(5); add('<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream\nendobj\n');
  var xref = size; add('xref\n0 6\n0000000000 65535 f \n');
  for (var n = 1; n <= 5; n++) add(String(off[n]).padStart(10, '0') + ' 00000 n \n');
  add('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF');
  return new Blob(parts, {type: 'application/pdf'});
}

function downloadReceipt(btn) {
  var label = btn.textContent;
  if (!window.html2canvas) { alert('The download is not ready yet. Please try again in a moment.'); return; }
  btn.disabled = true; btn.textContent = 'Preparing...';
  var wrap = document.createElement('div');              // an off-screen white copy of the receipt, fixed width
  wrap.style.cssText = 'position:absolute;left:-10000px;top:0;width:380px;background:#fff;color:#2b211d;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
  for (var k in PAPER) wrap.style.setProperty(k, PAPER[k]);
  var copy = document.querySelector('.sheet').cloneNode(true);
  copy.style.cssText = 'width:380px;max-width:380px;margin:0;border-radius:0;box-shadow:none';
  wrap.appendChild(copy); document.body.appendChild(wrap);
  html2canvas(copy, {scale: 3, backgroundColor: '#ffffff', useCORS: true, logging: false}).then(function (c) {
    var bin = atob(c.toDataURL('image/jpeg', 0.95).split(',')[1]), u8 = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    var url = URL.createObjectURL(pdfFromJpeg(u8, c.width, c.height));
    var a = document.createElement('a'); a.href = url; a.download = FILE_NAME;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
  }).catch(function () {
    alert('Sorry, the download did not work. Please take a screenshot of this receipt instead.');
  }).then(function () {
    wrap.remove(); btn.disabled = false; btn.textContent = label;
  });
}
</script>
<?php endif; ?>
<?php if ($payNow): ?>
<script>
// Waiting for the cashier: reload as soon as the payment is recorded, so the official receipt shows up
setInterval(async function(){if(document.hidden)return;try{var r=await fetch('api/pay.php?id=<?= (int)$id ?>');var j=await r.json();if(j.paid)location.reload()}catch(e){}},4000);
</script>
<?php endif; ?>
</body></html>
