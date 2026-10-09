<?php
/**
 * ذخیره و اجرای دکمه‌های پنل (مدیریت و کاربر) بدون بارگذاری دوباره کل صفحه
 *
 * سمت سرور: وقتی درخواست از طرف این اسکریپت آمده باشد (سرآیند X-PG-Ajax)، دستور
 * «انتقال به صفحه دیگر» (Location) به‌جای انتقال واقعی در سرآیند X-PG-Location
 * برگردانده می‌شود تا مرورگر خودش تصمیم بگیرد (همین صفحه → فقط محتوا عوض شود،
 * صفحه دیگر یا سایت دیگر مثل درگاه پرداخت → انتقال عادی).
 *
 * سمت مرورگر: فرم‌ها و لینک‌هایی که به همین صفحه برمی‌گردند با fetch ارسال می‌شوند،
 * فقط محتوای صفحه (#pgc) جایگزین می‌شود و پیام نتیجه (مثلاً «ذخیره شد») کنار همان دکمه
 * نمایش داده می‌شود. برای مستثنا کردن یک فرم یا لینک: data-noajax
 */

if (!function_exists('panel_ajax_init')) {
    function panel_ajax_init()
    {
        if (($_SERVER['HTTP_X_PG_AJAX'] ?? '') !== '1' || !function_exists('header_register_callback')) return;
        header_register_callback(function () {
            foreach (headers_list() as $h) {
                if (stripos($h, 'Location:') !== 0) continue;
                $loc = trim(substr($h, 9));
                header_remove('Location');
                http_response_code(200);
                header('X-PG-Location: ' . rawurlencode($loc));
                header('Cache-Control: no-store');
                break;
            }
        });
    }
}

if (!function_exists('panel_ajax_script')) {
    function panel_ajax_script()
    {
        ?>
<style>
.pg-flash{display:inline-flex;align-items:center;gap:4px;margin:0 8px;padding:3px 10px;border-radius:14px;font-size:12px;font-weight:600;line-height:1.7;vertical-align:middle;animation:pgIn .2s ease-out;max-width:100%;white-space:normal}
.pg-flash.ok{background:#dcfce7;color:#166534}
.pg-flash.err{background:#fee2e2;color:#991b1b}
.pg-toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:99999;max-width:min(92vw,560px);padding:10px 18px;border-radius:12px;font-size:13px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18);line-height:1.9;text-align:center}
.pg-toast.ok{background:#166534;color:#fff}
.pg-toast.err{background:#991b1b;color:#fff}
.pg-busy{opacity:.6;pointer-events:none;cursor:progress}
@keyframes pgIn{from{opacity:0;transform:translateY(4px)}to{opacity:1}}
</style>
<script>
(function () {
  if (window.__pgAjax) return; window.__pgAjax = true;
  var RID = 'pgc';
  var FLASH = '.msg-ok,.msg-err,.alert-success,.alert-danger,.alert-error';
  var busy = false;
  function root() { return document.getElementById(RID); }
  window.pgFlash = function (anchor, text, ok, keep) {
    if (!anchor || !anchor.parentNode) { pgToast(text, ok); return; }
    var old = anchor.parentNode.querySelectorAll('.pg-flash');
    for (var i = 0; i < old.length; i++) old[i].remove();
    var s = document.createElement('span');
    s.className = 'pg-flash ' + (ok ? 'ok' : 'err');
    s.textContent = (ok ? '✓ ' : '⚠ ') + text;
    anchor.parentNode.insertBefore(s, anchor.nextSibling);
    var r = s.getBoundingClientRect();
    if (r.top < 0 || r.bottom > window.innerHeight) { try { s.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {} }
    if (!keep) setTimeout(function () { if (s.parentNode) s.remove(); }, ok ? 4500 : 12000);
    return s;
  };
  window.pgToast = function (text, ok) {
    var t = document.createElement('div');
    t.className = 'pg-toast ' + (ok ? 'ok' : 'err');
    t.textContent = (ok ? '✓ ' : '⚠ ') + text;
    document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.remove(); }, ok ? 4000 : 9000);
  };

  if (!window.fetch || !window.DOMParser || !window.URL || !window.history || !history.pushState) return;

  function abs(u) { try { return new URL(u, location.href); } catch (e) { return null; } }
  function samePage(u) { var x = abs(u); return !!x && x.origin === location.origin && x.pathname === location.pathname; }
  function cleanUrl(u) { var x = abs(u); if (!x) return u; x.searchParams.delete('msg'); var q = x.searchParams.toString(); return x.pathname + (q ? '?' + q : '') + x.hash; }
  function txt(el) { return (el.textContent || '').replace(/\s+/g, ' ').trim(); }

  // امضای یک فرم برای پیدا کردن همان فرم در محتوای جدید
  function formSig(f) {
    if (f.getAttribute('data-pg')) return 'pg:' + f.getAttribute('data-pg');
    var a = [(f.getAttribute('action') || ''), (f.getAttribute('method') || 'get').toLowerCase()];
    var hs = f.querySelectorAll('input[type=hidden]');
    for (var i = 0; i < hs.length; i++) { if (/^(csrf_token|csrf|tok|_token|token)$/i.test(hs[i].name)) continue; a.push(hs[i].name + '=' + hs[i].value); }
    return a.join('|');
  }

  window.__pgReady = function (fn) { setTimeout(function () { try { fn(); } catch (e) { console.error(e); } }, 0); };

  // اجرای دوباره اسکریپت‌های داخل محتوای جدید (به ترتیب)
  function runScripts(box) {
    var list = Array.prototype.slice.call(box.querySelectorAll('script'));
    (function next() {
      var old = list.shift(); if (!old) return;
      var s = document.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) { var at = old.attributes[i]; if (at.name !== 'src') s.setAttribute(at.name, at.value); }
      var type = (old.getAttribute('type') || '').toLowerCase();
      if (old.src) {
        s.src = old.src; s.async = false;
        s.onload = s.onerror = next;
        old.parentNode.replaceChild(s, old);
        return;
      }
      var code = old.textContent;
      if (type === '' || type.indexOf('javascript') !== -1) {
        code = code.replace(/document\.addEventListener\(\s*['"]DOMContentLoaded['"]\s*,/g, '__pgReady(')
                   .replace(/window\.addEventListener\(\s*['"]load['"]\s*,/g, '__pgReady(');
        code = '{\n' + code + '\n}';
      }
      s.text = code;
      try { old.parentNode.replaceChild(s, old); } catch (e) { console.error(e); }
      next();
    })();
  }

  function download(r, cd) {
    return r.blob().then(function (b) {
      var m = /filename\*=UTF-8''([^;]+)/i.exec(cd) || /filename="?([^";]+)"?/i.exec(cd);
      var name = 'download'; try { name = m ? decodeURIComponent(m[1]) : name; } catch (e) {}
      var a = document.createElement('a');
      a.href = URL.createObjectURL(b); a.download = name; a.style.display = 'none';
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
    });
  }

  function apply(html, finalUrl, o) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var nr = doc.getElementById(RID), cur = root();
    if (!nr || !cur) { location.href = finalUrl; return; }

    // پیام نتیجه: از آدرس (msg=) یا پیام‌های تازه‌ای که قبلاً در صفحه نبود
    var urlMsg = ''; try { urlMsg = (abs(finalUrl).searchParams.get('msg') || ''); } catch (e) {}
    var urlOk = !/^error:/.test(urlMsg); urlMsg = urlMsg.replace(/^(ok|error):/, '').trim();
    var oldTexts = []; var of = cur.querySelectorAll(FLASH);
    for (var i = 0; i < of.length; i++) oldTexts.push(txt(of[i]));
    var flashes = [];
    if (o.action) {
      var nf = nr.querySelectorAll(FLASH);
      for (var j = 0; j < nf.length; j++) {
        var el = nf[j], t = txt(el);
        if (!t || el.querySelector('a,button,form')) continue;
        var isUrl = urlMsg && t === urlMsg.replace(/\s+/g, ' ');
        if (isUrl || oldTexts.indexOf(t) === -1) {
          flashes.push({ t: t, ok: el.matches('.msg-ok,.alert-success') });
          el.parentNode.removeChild(el);
        }
      }
      if (!flashes.length && urlMsg) flashes.push({ t: urlMsg, ok: urlOk });
    }

    var y = window.scrollY;
    if (doc.title) document.title = doc.title;
    cur.innerHTML = nr.innerHTML;
    // بخش‌های کوچک بالای صفحه (اعتبار، اعلان‌ها) هم به‌روز شوند
    ['.topbar-right'].forEach(function (sel) { var a = document.querySelector(sel), b = doc.querySelector(sel); if (a && b) a.innerHTML = b.innerHTML; });

    var u = cleanUrl(finalUrl);
    if (o.push) history.pushState({ pg: 1 }, '', u); else history.replaceState({ pg: 1 }, '', u);
    runScripts(cur);

    var hash = abs(finalUrl) ? abs(finalUrl).hash : '';
    if (o.link && hash && hash.length > 1) {
      var target = document.getElementById(decodeURIComponent(hash.slice(1)));
      if (target) { try { target.scrollIntoView({ block: 'start' }); } catch (e) {} } else window.scrollTo(0, y);
    } else if (o.pop || (o.link && !o.action)) {
      if (o.link) window.scrollTo(0, 0); else window.scrollTo(0, y);
    } else window.scrollTo(0, y);

    if (!o.action) return;
    // پیدا کردن همان دکمه در محتوای جدید
    var anchor = null;
    if (o.sig) {
      var fs = cur.querySelectorAll('form');
      for (var k = 0; k < fs.length && !anchor; k++) {
        if (formSig(fs[k]) !== o.sig) continue;
        var bs = fs[k].querySelectorAll('button,input[type=submit]');
        for (var m = 0; m < bs.length; m++) if ((txt(bs[m]) || bs[m].value) === o.btn) { anchor = bs[m]; break; }
        if (!anchor && bs.length) anchor = bs[bs.length - 1];
      }
    } else if (o.href) {
      var ls = cur.querySelectorAll('a[href]');
      for (var n = 0; n < ls.length; n++) if (ls[n].href === o.href) { anchor = ls[n]; break; }
    }
    if (!flashes.length) flashes.push({ t: 'انجام شد', ok: true });
    var f0 = flashes[0], rest = flashes.slice(1);
    if (anchor) pgFlash(anchor, f0.t, f0.ok); else pgToast(f0.t, f0.ok);
    rest.forEach(function (f) { pgToast(f.t, f.ok); });
  }

  function go(url, o) {
    if (busy) return;
    busy = true;
    if (o.el) o.el.classList.add('pg-busy');
    var done = function () { busy = false; if (o.el) o.el.classList.remove('pg-busy'); };
    fetch(url, { method: o.method || 'GET', body: o.body || null, credentials: 'same-origin', headers: { 'X-PG-Ajax': '1' }, cache: 'no-store' })
      .then(function (r) {
        var loc = r.headers.get('X-PG-Location');
        if (loc) {
          try { loc = decodeURIComponent(loc); } catch (e) {}
          var la = abs(loc);
          if (!la || !samePage(la.href)) { location.href = la ? la.href : loc; return; }
          return fetch(la.href, { credentials: 'same-origin', headers: { 'X-PG-Ajax': '1' }, cache: 'no-store' }).then(function (r2) {
            if (r2.headers.get('X-PG-Location')) { var l2 = decodeURIComponent(r2.headers.get('X-PG-Location')); location.href = abs(l2) ? abs(l2).href : l2; return; }
            return r2.text().then(function (h) { apply(h, la.href, o); });
          });
        }
        var cd = r.headers.get('Content-Disposition') || '', ct = (r.headers.get('Content-Type') || '').toLowerCase();
        if (/attachment/i.test(cd)) return download(r, cd);
        if (ct.indexOf('text/html') === -1) {
          if ((o.method || 'GET') === 'GET') { location.href = url; return; }
          return r.text().then(function () { pgToast('انجام شد', true); });
        }
        if (r.redirected && !samePage(r.url)) { location.href = r.url; return; }
        return r.text().then(function (h) { apply(h, url, o); });
      })
      .catch(function (e) {
        console.error(e);
        if ((o.method || 'GET') === 'GET') { location.href = url; return; }
        if (o.el) pgFlash(o.el, 'ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', false); else pgToast('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', false);
      })
      .then(done, done);
  }
  window.pgGo = go;

  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target, box = root();
    if (!box || !(f instanceof HTMLFormElement) || !box.contains(f)) return;
    if (f.hasAttribute('data-noajax') || f.closest('[data-noajax]')) return;
    var tg = f.getAttribute('target'); if (tg && tg !== '_self') return;
    var act = abs(f.getAttribute('action') || location.href);
    if (!act || !samePage(act.href)) return;
    e.preventDefault();
    if (busy) return;
    var sub = e.submitter || f.querySelector('button[type=submit],button:not([type]),input[type=submit]');
    var method = (f.getAttribute('method') || 'get').toLowerCase();
    var fd = new FormData(f);
    if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
    var o = { action: true, sig: formSig(f), btn: sub ? (txt(sub) || sub.value) : '', el: sub || f };
    if (method === 'get') {
      var q = new URLSearchParams();
      fd.forEach(function (v, k) { if (typeof v === 'string') q.append(k, v); });
      act.search = q.toString();
      o.push = true; o.link = true; o.action = false;
      go(act.href, o);
    } else {
      o.method = 'POST'; o.body = fd;
      go(act.href, o);
    }
  });

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null, box = root();
    if (!a || !box || !box.contains(a)) return;
    if (a.hasAttribute('download') || a.hasAttribute('data-noajax') || a.closest('[data-noajax]')) return;
    var tg = a.getAttribute('target'); if (tg && tg !== '_self') return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|sms|data):/i.test(href)) return;
    var u = abs(a.href); if (!u || !samePage(u.href)) return;
    if (u.search === location.search && u.hash) return;
    e.preventDefault();
    // لینک‌هایی که کاری انجام می‌دهند (حذف، تغییر وضعیت ...) پیام نتیجه را کنار خودشان نشان می‌دهند
    var isAction = /[?&](del|delete|toggle|remove|act|action|do|approve|reject|status|on|off)=/i.test(u.search) || /confirm\(/.test(a.getAttribute('onclick') || '');
    go(u.href, { method: 'GET', push: !isAction, link: !isAction, action: isAction, href: a.href, el: a });
  });

  window.addEventListener('popstate', function () { if (root()) go(location.href, { method: 'GET', pop: true }); });

  // پیام ?msg= در بارگذاری اولیه از آدرس حذف شود تا با رفرش تکرار نشود
  try { if (/[?&]msg=/.test(location.search)) history.replaceState({ pg: 1 }, '', cleanUrl(location.href)); } catch (e) {}
})();
</script>
<?php
    }
}
