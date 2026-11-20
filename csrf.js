/* YiouYi: CSRF helper. Sends the session's token with every POST so the server can tell our own pages
   from a forged request made by another website. The token lives in the yy_csrf cookie (set by includes/auth.php).
   - every fetch() POST/PUT/PATCH/DELETE to this site gets an X-CSRF-Token header
   - every <form method="post"> gets a hidden _csrf field */
(function () {
  function token() {
    var m = document.cookie.match(/(?:^|;\s*)yy_csrf=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  var realFetch = window.fetch;
  if (realFetch) {
    window.fetch = function (input, init) {
      try {
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        var url = typeof input === 'string' ? input : ((input && input.url) || '');
        var sameSite = !/^[a-z][a-z0-9+.\-]*:/i.test(url) || url.indexOf(location.origin) === 0;
        if (method !== 'GET' && method !== 'HEAD' && sameSite) {
          init = init || {};
          var h = new Headers(init.headers || (input && input.headers) || {});
          if (!h.has('X-CSRF-Token')) { h.set('X-CSRF-Token', token()); }
          init.headers = h;
        }
      } catch (e) { /* never block the request */ }
      return realFetch.call(this, input, init);
    };
  }
  function stamp(form) {
    if (!form || !/^post$/i.test(form.getAttribute('method') || '')) { return; }
    var f = form.querySelector('input[name="_csrf"]');
    if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = '_csrf'; form.appendChild(f); }
    f.value = token() || f.value;
  }
  document.addEventListener('submit', function (e) { stamp(e.target); }, true);
  document.addEventListener('DOMContentLoaded', function () {
    [].forEach.call(document.querySelectorAll('form'), stamp);
  });
})();
