/* Light / dark theme switch. Load in <head> so the theme is applied before first paint.
   Saved choice lives in localStorage ("yy-theme"); with no saved choice it follows the device setting. */
(function () {
  var KEY = 'yy-theme', root = document.documentElement, mq = window.matchMedia ? matchMedia('(prefers-color-scheme: dark)') : null;
  function saved() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function isDark() { var s = saved(); return s ? s === 'dark' : !!(mq && mq.matches); }
  function apply(dark) {
    root.classList.toggle('dark', dark);
    var m = document.querySelector('meta[name=theme-color]');
    if (!m) { m = document.createElement('meta'); m.name = 'theme-color'; document.head.appendChild(m); }
    m.content = dark ? '#181311' : '#fcf9f4';
    paint(dark);
  }
  var SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  var MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  function paint(dark) {
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].innerHTML = dark ? SUN : MOON;            // icon shows what you will switch to
      btns[i].setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      btns[i].setAttribute('title', dark ? 'Light mode' : 'Dark mode');
      btns[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
  }
  apply(isDark());                                       // before first paint
  window.toggleTheme = function () {
    var dark = !root.classList.contains('dark');
    root.classList.add('theme-anim');
    try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (e) {}
    apply(dark);
    setTimeout(function () { root.classList.remove('theme-anim'); }, 300);
  };
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
    if (t) { e.preventDefault(); window.toggleTheme(); }
  });
  document.addEventListener('DOMContentLoaded', function () { paint(root.classList.contains('dark')); });
  if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (!saved()) apply(isDark()); });
  window.addEventListener('storage', function (e) { if (e.key === KEY) apply(isDark()); });   // keep other tabs in sync
})();
