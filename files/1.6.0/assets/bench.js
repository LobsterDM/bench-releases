/* Bench CMS — small front-end helpers shared by all themes (no dependencies).
   Written to WCAG 2.2 AAA: keyboard operable, focus managed, errors described in text. */
(function () {
  'use strict';

  /* ---------- Wording in the page's language ----------
     On pages in a second language, the server prints the translations bench.js needs
     in <script type="application/json" id="bench-i18n">. English text is the key. */
  var STRINGS = {};
  try { var src = document.getElementById('bench-i18n'); if (src) STRINGS = JSON.parse(src.textContent) || {}; } catch (e) { STRINGS = {}; }
  function t(s) {
    var out = STRINGS[s] || s, args = Array.prototype.slice.call(arguments, 1), i = 0;
    return out.replace(/%(?:(\d)\$)?([sd])/g, function (m, n) { var v = n ? args[n - 1] : args[i++]; return v == null ? '' : String(v); });
  }
  window.benchT = t; // for plugin scripts

  /* ---------- Mobile menu ---------- */
  document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
    var nav = document.getElementById(btn.getAttribute('aria-controls'));
    if (!nav) return;
    function set(open) {
      btn.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('is-open', open);
      document.body.classList.toggle('nav-open', open);
    }
    btn.addEventListener('click', function () { set(btn.getAttribute('aria-expanded') !== 'true'); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
    // Escape closes the menu and returns focus to the button
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') { set(false); btn.focus(); }
    });
  });

  /* ---------- Header shadow on scroll ---------- */
  var header = document.querySelector('[data-sticky-header]');
  if (header) {
    var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 10); };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }

  /* ---------- Contact forms ----------
     Validation messages are written as text next to each field, listed in a summary
     at the top, and focus moves to the summary (3.3.1, 3.3.3, 3.3.6). */
  function labelText(field) {
    var l = field.id && document.querySelector('label[for="' + field.id + '"]');
    return l ? l.textContent.replace(/\s*\*?\s*\(required\)|\s*\(optional\)|\*/g, '').trim() : field.name;
  }
  function problem(field) {
    var v = field.value.trim();
    if (field.required && !v) return field.getAttribute('data-missing') || t('Enter your %s', labelText(field).toLowerCase().replace(/^your /, ''));
    if (field.type === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return t('Enter an email address in the format name@example.com');
    if (field.type === 'tel' && v && !/^[0-9+()\-\s]{6,}$/.test(v)) return t('Enter a phone number using only numbers, spaces and + ( ) -');
    return '';
  }
  function showError(field, msg) {
    var slot = document.getElementById(field.id + '-err');
    if (slot) slot.textContent = msg ? t('Error: %s', msg) : '';
    if (msg) field.setAttribute('aria-invalid', 'true'); else field.removeAttribute('aria-invalid');
  }
  document.querySelectorAll('.js-ajax-form').forEach(function (form) {
    var status = form.querySelector('.form__status');
    var summary = form.querySelector('.form__errors');
    var button = form.querySelector('[type=submit]');
    var fields = Array.prototype.slice.call(form.querySelectorAll('input:not([type=hidden]):not([tabindex="-1"]), select, textarea'))
      .filter(function (f) { return !f.closest('.hp-wrap'); });

    // Re-check a field once the user leaves it, so corrections are confirmed straight away
    fields.forEach(function (f) {
      f.addEventListener('blur', function () { if (f.getAttribute('aria-invalid')) showError(f, problem(f)); });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var errors = [];
      fields.forEach(function (f) { var m = problem(f); showError(f, m); if (m) errors.push({ field: f, msg: m }); });
      if (summary) {
        summary.innerHTML = '';
        if (errors.length) {
          var h = document.createElement('p');
          var strong = document.createElement('strong');
          strong.textContent = errors.length === 1 ? t('There is a problem with the form:') : t('There are %d problems with the form:', errors.length);
          h.appendChild(strong);
          var ul = document.createElement('ul');
          errors.forEach(function (er) {
            var li = document.createElement('li'), a = document.createElement('a');
            a.href = '#' + er.field.id; a.textContent = er.msg;
            a.addEventListener('click', function (ev) { ev.preventDefault(); er.field.focus(); });
            li.appendChild(a); ul.appendChild(li);
          });
          summary.appendChild(h); summary.appendChild(ul);
          summary.focus();
          return;
        }
      }
      if (errors.length) { errors[0].field.focus(); return; }

      var label = button.textContent;
      button.disabled = true; button.textContent = t('Sending…');
      status.className = 'form__status'; status.textContent = t('Sending your message…');

      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
        .then(function (res) { return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (r) {
          var ok = r.ok && r.data.success !== false;
          if (ok) {
            form.reset();
            status.classList.add('is-success');
            status.textContent = form.dataset.success || t('Thanks — message sent.');
          } else {
            status.classList.add('is-error');
            status.textContent = t('Sorry, your message could not be sent') + ((r.data && r.data.message) ? ' (' + r.data.message + ')' : '') + '. ' + t('Please try again, or call us.');
          }
          status.setAttribute('tabindex', '-1'); status.focus();
        })
        .catch(function () {
          // Network/CORS problem: fall back to a plain form post
          form.classList.remove('js-ajax-form'); HTMLFormElement.prototype.submit.call(form);
        })
        .finally(function () { button.disabled = false; button.textContent = label; });
    });
  });

  /* ---------- Lightbox (accessible modal dialog) ---------- */
  var links = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
  if (links.length) {
    var box = document.createElement('div');
    box.className = 'lightbox'; box.hidden = true;
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', t('Photo viewer'));
    box.innerHTML = '<button type="button" class="lightbox__close">&times;</button>'
      + '<button type="button" class="lightbox__prev">&#8249;</button>'
      + '<img alt="">'
      + '<button type="button" class="lightbox__next">&#8250;</button>'
      + '<p class="lightbox__caption" aria-live="polite"></p>';
    document.body.appendChild(box);
    box.querySelector('.lightbox__close').setAttribute('aria-label', t('Close photo viewer'));
    box.querySelector('.lightbox__prev').setAttribute('aria-label', t('Previous photo'));
    box.querySelector('.lightbox__next').setAttribute('aria-label', t('Next photo'));
    var img = box.querySelector('img'), cap = box.querySelector('.lightbox__caption'), closeBtn = box.querySelector('.lightbox__close');
    var current = 0, opener = null;
    var single = links.length < 2;
    if (single) { box.querySelector('.lightbox__prev').hidden = true; box.querySelector('.lightbox__next').hidden = true; }

    var show = function (i) {
      current = (i + links.length) % links.length;
      var a = links[current], thumb = a.querySelector('img');
      img.src = a.href;
      img.alt = (thumb && thumb.alt) || '';
      cap.textContent = (a.getAttribute('data-caption') || img.alt || '') + (single ? '' : ' (' + t('photo %1$d of %2$d', current + 1, links.length) + ')');
    };
    var open = function (i) {
      opener = document.activeElement;
      show(i); box.hidden = false;
      document.body.style.overflow = 'hidden';
      closeBtn.focus();
    };
    var close = function () {
      box.hidden = true; document.body.style.overflow = '';
      if (opener && opener.focus) opener.focus();
    };
    links.forEach(function (a, i) { a.addEventListener('click', function (e) { e.preventDefault(); open(i); }); });
    box.addEventListener('click', function (e) {
      if (e.target.closest('.lightbox__prev')) return show(current - 1);
      if (e.target.closest('.lightbox__next')) return show(current + 1);
      if (e.target.closest('.lightbox__close') || e.target === box) close();
    });
    document.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key === 'ArrowRight' && !single) show(current + 1);
      if (e.key === 'ArrowLeft' && !single) show(current - 1);
      if (e.key === 'Tab') { // keep focus inside the dialog
        var f = Array.prototype.filter.call(box.querySelectorAll('button'), function (b) { return !b.hidden; });
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
  }
})();
