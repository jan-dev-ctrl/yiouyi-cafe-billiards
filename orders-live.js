/* Live order status + "your order is ready" alert for customers.
 *
 * Loaded on every customer page (includes/nav.php) and on pay.php. It polls api/my_orders.php, and:
 *   - repaints any element marked  data-live-order="<id>" (with data-status / data-paid / data-loc)
 *       containing optional  [data-live-track]  [data-live-chip]  [data-live-msg]
 *   - when an order turns "ready" it shows a banner, vibrates, beeps, flashes the tab title and (if the customer
 *     allowed it with a  [data-alerts-btn]  button) raises a browser notification. Each order alerts once per browser.
 * Alerts work while a page of the site is open; there is no push server.
 */
(function () {
  'use strict';
  var URL_ = 'api/my_orders.php', FAST = 5000, SLOW = 30000, KEY = 'yio_ready_notified', MAX_AGE = 12 * 3600 * 1000;
  var notified = read(), busy = false, stopped = false, anyActive = false;

  function read() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
  function write() {
    try {
      var k = Object.keys(notified);
      if (k.length > 60) { k.sort(function (a, b) { return a - b; }).slice(0, k.length - 60).forEach(function (x) { delete notified[x]; }); }
      localStorage.setItem(KEY, JSON.stringify(notified));
    } catch (e) {}
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }

  /* ---------- wording ---------- */
  var STEPS = ['Placed', 'Preparing', 'Ready', 'Served'];
  function stage(st) { return st === 'new' ? 1 : st === 'ready' ? 2 : st === 'settled' ? 3 : 0; }

  function trackHtml(st) {
    if (st === 'cancelled') { return '<div style="color:var(--bad-fg);font-weight:600;font-size:14px">Order cancelled</div>'; }
    var cur = stage(st), h = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px">';
    STEPS.forEach(function (n, i) {
      var done = i < cur || st === 'settled', now = i === cur && st !== 'settled';
      var bar = done ? 'var(--ok-fg)' : now ? 'var(--btn-bg)' : 'var(--line)';
      h += '<div><div style="height:6px;border-radius:3px;background:' + bar + (now ? ';animation:yioPulse 1.4s ease-in-out infinite' : '') + '"></div>' +
           '<div style="margin-top:4px;font-size:11px;font-weight:' + (now ? '800' : '600') + ';color:' + (now ? 'var(--ink)' : 'var(--muted)') + '">' + n + '</div></div>';
    });
    return h + '</div>';
  }
  function chipInfo(st, paid) {
    if (st === 'new') { return paid ? ['Paid • preparing', 'paid'] : ['Unpaid', 'wait']; }
    if (st === 'ready') { return paid ? ['Paid • ready', 'info'] : ['Ready • unpaid', 'wait']; }
    if (st === 'settled') { return ['Paid', 'paid']; }
    return ['Cancelled', 'cancel'];
  }
  function msg(st, paid, loc) {
    if (st === 'new') { return 'We are preparing your order.' + (paid ? '' : ' Please pay at the cashier.'); }
    if (st === 'ready') {
      return 'Your order is ready! ' + (loc === 'Takeout' ? 'Please pick it up at the counter.' : 'It will be served to ' + loc + '.') + (paid ? '' : ' Please pay at the cashier.');
    }
    if (st === 'settled') { return 'Enjoy! Thank you for visiting YiouYi.'; }
    return 'This order was cancelled.';
  }

  /* ---------- repaint the page ---------- */
  function paint(el) {
    var st = el.getAttribute('data-status'), paid = el.getAttribute('data-paid') === '1', loc = el.getAttribute('data-loc') || '';
    var t = el.querySelector('[data-live-track]'), c = el.querySelector('[data-live-chip]'), m = el.querySelector('[data-live-msg]');
    if (t) { t.innerHTML = trackHtml(st); }
    if (c) { var ci = chipInfo(st, paid); c.className = 'chip c-' + ci[1]; c.textContent = ci[0]; }
    if (m) { m.textContent = msg(st, paid, loc); }
    // the customer may only cancel while the order is still waiting (not ready) and unpaid
    Array.prototype.forEach.call(el.querySelectorAll('[data-live-cancel]'), function (b) { b.style.display = (st === 'new' && !paid) ? '' : 'none'; });
  }

  /* ---------- the alert ---------- */
  function box() {
    var b = document.getElementById('yio-toasts');
    if (b) { return b; }
    var s = document.createElement('style');
    s.textContent = '@keyframes yioPulse{0%,100%{opacity:1}50%{opacity:.35}}@keyframes yioIn{from{transform:translateY(-12px);opacity:0}to{transform:none;opacity:1}}';
    document.head.appendChild(s);
    b = document.createElement('div');
    b.id = 'yio-toasts';
    b.setAttribute('style', 'position:fixed;top:calc(env(safe-area-inset-top,0px) + 8px);left:8px;right:8px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none');
    document.body.appendChild(b);
    return b;
  }
  function beep() {
    try {
      var C = window.AudioContext || window.webkitAudioContext; if (!C) { return; }
      var c = new C(), o = c.createOscillator(), g = c.createGain();
      o.connect(g); g.connect(c.destination); o.frequency.value = 880; g.gain.value = 0.08; o.start();
      setTimeout(function () { o.frequency.value = 1175; }, 150);
      setTimeout(function () { o.stop(); c.close(); }, 320);
    } catch (e) {}
  }
  var title0 = document.title;
  function notify(o) {
    var text = 'Order #YIO-' + o.no + ' is ready' + (o.where === 'Takeout' ? ' for pickup.' : ' — serving to ' + o.where + '.');
    var t = document.createElement('div');
    t.setAttribute('role', 'alert');
    t.setAttribute('style', 'pointer-events:auto;max-width:460px;margin:0 auto;width:100%;background:var(--btn-bg);color:var(--btn-fg);border-radius:14px;padding:12px 14px;box-shadow:0 6px 24px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;font:600 14px system-ui,sans-serif;animation:yioIn .25s ease-out');
    t.innerHTML = '<span style="font-size:22px">🔔</span><span style="flex:1">' + esc(text) + '</span>' +
      '<a href="mine.php" style="color:inherit;text-decoration:underline">View</a>' +
      '<button type="button" aria-label="Dismiss" style="background:none;border:0;color:inherit;font-size:20px;line-height:1;cursor:pointer">×</button>';
    t.querySelector('button').onclick = function () { t.remove(); };
    box().appendChild(t);
    setTimeout(function () { if (t.parentNode) { t.remove(); } }, 30000);
    try { if (navigator.vibrate) { navigator.vibrate([200, 100, 200]); } } catch (e) {}
    beep();
    if (document.hidden) {
      document.title = '🔔 Order ready • ' + title0;
      var back = function () { document.title = title0; document.removeEventListener('visibilitychange', back); };
      document.addEventListener('visibilitychange', back);
    }
    if ('Notification' in window && Notification.permission === 'granted') {
      try {
        var n = new Notification('Order #YIO-' + o.no + ' is ready', { body: text, icon: 'logo.png', tag: 'yio-order-' + o.id });
        n.onclick = function () { window.focus(); location.href = 'mine.php'; n.close(); };
      } catch (e) { /* some mobile browsers only allow notifications through a service worker: the banner still shows */ }
    }
  }

  function wireAlerts() {
    var btns = document.querySelectorAll('[data-alerts-btn]');
    var ok = 'Notification' in window && Notification.permission === 'default';
    Array.prototype.forEach.call(btns, function (b) {
      var holder = b.closest('[data-alerts-box]') || b;
      holder.style.display = ok ? '' : 'none';
      b.onclick = function () { try { Notification.requestPermission().then(wireAlerts); } catch (e) { wireAlerts(); } };
    });
  }

  /* ---------- polling ---------- */
  var known = {};
  function apply(orders) {
    var reload = false, wantsReload = !!document.querySelector('[data-live-reload]');
    anyActive = false;
    orders.forEach(function (o) {
      if (o.status === 'new' || o.status === 'ready') { anyActive = true; }
      known[o.id] = o.status;
      var el = document.querySelector('[data-live-order="' + o.id + '"]');
      if (el) {
        var wasPaid = el.getAttribute('data-paid') === '1', was = el.getAttribute('data-status');
        el.setAttribute('data-status', o.status);
        el.setAttribute('data-paid', o.paid ? '1' : '0');
        paint(el);
        if (wantsReload && (was !== o.status && (o.status === 'settled' || o.status === 'cancelled') || (!wasPaid && o.paid))) { reload = true; }
      }
      if (o.status === 'ready' && !notified[o.id]) {
        notified[o.id] = 1; write();
        if (Date.now() - o.at < MAX_AGE) { notify(o); }
      }
    });
    if (reload) { location.reload(); }
  }
  function poll() {
    if (busy || stopped) { return; }
    busy = true;
    fetch(URL_, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401 || r.status === 403) { stopped = true; return null; } return r.json(); })
      .then(function (j) { if (j && j.orders) { apply(j.orders); } })
      .catch(function () {})
      .then(function () { busy = false; if (!stopped) { setTimeout(poll, anyActive ? FAST : SLOW); } });
  }

  window.yioLive = { apply: apply, trackHtml: trackHtml, chipInfo: chipInfo, msg: msg };   // exposed for testing
  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-live-order]'), paint);
    wireAlerts();
    poll();
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
