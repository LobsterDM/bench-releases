/* Bench CMS — admin app (vanilla JS, no build step) */
(function () {
  'use strict';

  var B = window.BENCH;
  var app = document.getElementById('app');

  /* ------------------------------------------------------------ *
   * Helpers
   * ------------------------------------------------------------ */

  // h('div.class#id', {attrs}, children...)
  function h(sel, attrs) {
    var parts = sel.split(/(?=[.#])/);
    var el = document.createElement(parts[0] || 'div');
    parts.slice(1).forEach(function (p) {
      if (p[0] === '.') el.classList.add(p.slice(1)); else el.id = p.slice(1);
    });
    var kids = Array.prototype.slice.call(arguments, 2);
    if (attrs && (attrs.nodeType || typeof attrs !== 'object' || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    (function add(list) {
      list.forEach(function (c) {
        if (c == null || c === false) return;
        if (Array.isArray(c)) return add(c);
        el.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
      });
    })(kids);
    // Symbol-only buttons (↑ ↓ ✕) are named by their title for screen readers (4.1.2)
    if (el.tagName === 'BUTTON' && attrs && attrs.title && !attrs['aria-label']) el.setAttribute('aria-label', attrs.title);
    // Links that open a new tab say so (3.2.5)
    if (el.tagName === 'A' && attrs && attrs.target === '_blank') el.appendChild(h('span.sr-only', ' (opens in a new tab)'));
    return el;
  }

  function icon(name, cls) {
    var s = B.icons[name];
    if (!s) return h('span');
    var span = h('span.' + (cls || 'ic'));
    span.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + s + '</svg>';
    return span;
  }

  function api(action, data) {
    return fetch(B.api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': B.csrf },
      body: JSON.stringify(Object.assign({ action: action }, data || {})),
      credentials: 'same-origin'
    }).then(handle);
  }

  function handle(res) {
    return res.json().catch(function () { return { error: 'Unexpected server response (' + res.status + ')' }; }).then(function (d) {
      if (!res.ok || d.error) { if (res.status === 401) sessionEnded(); throw new Error(d.error || 'Request failed'); }
      return d;
    });
  }

  function upload(file) {
    if (file.size > B.maxMb * 1024 * 1024) return Promise.reject(new Error('Image is over ' + B.maxMb + 'MB'));
    var fd = new FormData();
    fd.append('action', 'upload'); fd.append('file', file); fd.append('csrf', B.csrf);
    return fetch(B.api + '?action=upload', { method: 'POST', body: fd, headers: { 'X-CSRF': B.csrf }, credentials: 'same-origin' }).then(handle);
  }

  // Toasts: confirmations fade after a few seconds (the result is also shown on the page),
  // errors stay until dismissed so there's no time limit on reading them (2.2.3).
  var toastTimer;
  function toast(msg, type) {
    var t = document.getElementById('toast');
    var alertRegion = document.getElementById('toast-alert');
    clearTimeout(toastTimer);
    t.className = 'toast is-show' + (type ? ' toast--' + type : '');
    t.replaceChildren(h('span', msg));
    if (type === 'error') {
      if (alertRegion) alertRegion.textContent = msg;
      t.appendChild(h('button.toast__close', { type: 'button', 'aria-label': 'Dismiss message', onclick: function () { t.className = 'toast'; if (alertRegion) alertRegion.textContent = ''; } }, '✕'));
      return;
    }
    var hide = function () { if (!t.matches(':hover') && !t.contains(document.activeElement)) t.className = 'toast'; else toastTimer = setTimeout(hide, 2000); };
    toastTimer = setTimeout(hide, 5000);
  }

  // Re-authenticating without losing work (2.2.5, 2.2.6): the page is never reloaded on
  // an expired session, and a keep-alive stops the session timing out while it's open.
  var sessionShown = false;
  function sessionEnded() {
    if (sessionShown) return; sessionShown = true;
    var box = h('div.session-alert', { role: 'alert' },
      h('strong', 'You\'ve been logged out. '),
      'Your changes are still on this page. ',
      h('a', { href: './', target: '_blank' }, 'Log in again'),
      ', then come back to this tab and press Save.',
      h('button.button.button--small', { type: 'button', style: 'margin-left:10px', onclick: function () { box.remove(); sessionShown = false; } }, 'OK'));
    document.body.appendChild(box);
  }
  setInterval(function () {
    fetch(B.api, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': B.csrf }, body: '{"action":"ping"}', credentials: 'same-origin' })
      .then(function (r) { if (r.status === 401) sessionEnded(); }).catch(function () {});
  }, 4 * 60 * 1000);

  function mediaUrl(p) {
    if (!p) return '';
    if (/^(https?:)?\/\//.test(p) || p[0] === '/') return p;
    return B.base + '/' + p.replace(/^\/+/, '');
  }

  // "Saved · 3 photos moved to the trash" — tells the editor when unused photos were tidied up
  function trashNote(msg, r) {
    var n = r && r.trashed ? r.trashed : 0;
    return n ? msg + ' · ' + n + (n === 1 ? ' photo' : ' photos') + ' nothing else uses moved to the trash (Media → Trash)' : msg;
  }

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  function defaults(fields) {
    var o = {};
    fields.forEach(function (f) {
      if (f.type === 'heading') return;
      o[f.key] = f.default !== undefined ? f.default : (['list', 'gallery', 'multiselect', 'tags'].indexOf(f.type) > -1 ? [] : (f.type === 'checkbox' ? false : ''));
    });
    return o;
  }

  function fmtDate(v) {
    if (!v) return '—';
    var d = typeof v === 'number' ? new Date(v * 1000) : new Date(v);
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  }

  function autosize(t) { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 600) + 'px'; }

  /* ------------------------------------------------------------ *
   * Dirty state (unsaved changes)
   * ------------------------------------------------------------ */

  var dirty = false, onDirty = function () {};
  function markDirty() { if (!dirty) { dirty = true; onDirty(true); } }
  function clean() { dirty = false; onDirty(false); }
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* ------------------------------------------------------------ *
   * Field renderers — each binds directly to obj[field.key]
   * ------------------------------------------------------------ */

  var fieldSeq = 0;

  // Shows when Bench will nudge a chosen colour so text stays at WCAG AAA contrast (mirrors core/bootstrap.php)
  function lum(hex) { var c = hex.replace('#', ''); if (c.length === 3) c = c.replace(/(.)/g, '$1$1');
    return [0, 2, 4].map(function (i) { var v = parseInt(c.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); })
      .reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function hsl(hex) { var c = hex.replace('#', ''); if (c.length === 3) c = c.replace(/(.)/g, '$1$1');
    var r = parseInt(c.substr(0, 2), 16) / 255, g = parseInt(c.substr(2, 2), 16) / 255, b = parseInt(c.substr(4, 2), 16) / 255;
    var mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2, hh = 0, ss = 0;
    if (mx !== mn) { var d = mx - mn; ss = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
      hh = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; hh /= 6; }
    return [hh, ss, l]; }
  function fromHsl(hh, ss, l) { function f(p, q, t) { if (t < 0) t += 1; if (t > 1) t -= 1; if (t < 1 / 6) return p + (q - p) * 6 * t; if (t < 1 / 2) return q; if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6; return p; }
    var r, g, b; if (!ss) { r = g = b = l; } else { var q = l < 0.5 ? l * (1 + ss) : l + ss - l * ss, p = 2 * l - q; r = f(p, q, hh + 1 / 3); g = f(p, q, hh); b = f(p, q, hh - 1 / 3); }
    return '#' + [r, g, b].map(function (v) { return ('0' + Math.round(Math.max(0, Math.min(1, v)) * 255).toString(16)).slice(-2); }).join(''); }
  function adjust(hex, against, target, dir) { var x = hsl(hex), l = x[2];
    for (var i = 0; i <= 200; i++) { var c = fromHsl(x[0], x[1], Math.max(0, Math.min(1, l)));
      if (Math.min.apply(null, against.map(function (a) { return ratio(c, a); })) >= target) return c;
      if ((dir < 0 && l <= 0) || (dir > 0 && l >= 1)) return null; l += 0.005 * dir; } return null; }
  function contrastNote(key, obj) {
    var note = h('p.contrast-note', { 'aria-live': 'polite' });
    function upd() {
      var v = (obj[key] || '').toLowerCase();
      if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v)) { note.hidden = true; return; }
      note.hidden = false; note.className = 'contrast-note';
      if (key === 'accent_color') {
        var blk = ratio(v, '#000000') >= 7 ? v : adjust(v, ['#000000'], 7, 1), wht = ratio(v, '#ffffff') >= 7 ? v : adjust(v, ['#ffffff'], 7, -1);
        var dist = function (c) { return c ? Math.abs(hsl(c)[2] - hsl(v)[2]) : 9; };
        var pick = dist(blk) <= dist(wht) ? [blk, 'black'] : [wht, 'white'];
        if (pick[0] === v) { note.className += ' is-ok'; note.textContent = 'Buttons use ' + pick[1] + ' text on this colour: ' + ratio(v, pick[1] === 'black' ? '#000' : '#fff').toFixed(1) + ':1 contrast (WCAG AAA needs 7:1).'; }
        else note.textContent = 'Buttons will use ' + pick[0] + ' with ' + pick[1] + ' text — the nearest shade that reaches the 7:1 contrast WCAG AAA needs.';
      } else {
        var safe = adjust(v, ['#ffffff', '#efe9dd'], 7, -1) || '#1a1a1a';
        if (safe === v) { note.className += ' is-ok'; note.textContent = 'Readable as text on light backgrounds and behind white text: ' + ratio(v, '#ffffff').toFixed(1) + ':1 contrast (AAA).'; }
        else note.textContent = 'This colour is too light for text. The site will use ' + safe + ' instead, the nearest shade that reaches 7:1 contrast (WCAG AAA).';
      }
    }
    note.update = upd; setTimeout(upd); document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('[data-key="' + key + '"]')) setTimeout(upd); });
    return note;
  }

  function renderFields(fields, obj, opts) {
    opts = opts || {};
    return fields.map(function (f) { return renderField(f, obj, opts); });
  }

  /* ---------- Translations (bilingual sites) ----------
     Each text field gets a second box per extra language, editing obj._t[code][key].
     The server keeps only fields that are translatable (see core/i18n.php). */
  var LANGS = (B.languages && B.languages.others) || [];
  function translatable(f) {
    if (f.translate !== undefined) return !!f.translate;
    return ['text', 'textarea', 'richtext'].indexOf(f.type) > -1;
  }
  function translationFields(f, obj, opts) {
    if (!LANGS.length || opts.translation || !translatable(f) || (f.key === 'slug' && obj.slug === 'home')) return [];
    if (!obj._t || typeof obj._t !== 'object' || Array.isArray(obj._t)) obj._t = {};
    return LANGS.map(function (l) {
      if (!obj._t[l.code] || typeof obj._t[l.code] !== 'object' || Array.isArray(obj._t[l.code])) obj._t[l.code] = {};
      var tf = {};
      Object.keys(f).forEach(function (k) { tf[k] = f[k]; });
      tf.label = f.label + ' (' + l.english_name + ')';
      tf.help = f.translate_help || (f.key === 'slug' ? null : 'Leave blank to show the ' + (B.languages.main === 'en' ? 'English' : 'main-language') + ' text here too.');
      tf.placeholder = null;
      tf.width = null;
      var sub = renderField(tf, obj._t[l.code], { translation: l, onChange: opts.onChange });
      sub.classList.add('field--translation');
      sub.setAttribute('data-key', f.key + '@' + l.code); // e.g. heading@cy, so [data-key=heading] means the main field
      sub.setAttribute('lang', l.html); // spellcheck and screen readers use the right language
      sub.querySelector('.field__label').setAttribute('lang', 'en');
      var help = sub.querySelector('.field__help'); if (help) help.setAttribute('lang', 'en');
      return sub;
    });
  }

  function renderField(f, obj, opts) {
    opts = opts || {};
    if (f.type === 'heading') return h('h2.field-heading', f.label);
    // Empty translations stay empty (no defaults), so the main text shows until one is written
    if (obj[f.key] === undefined) obj[f.key] = opts.translation ? '' : defaults([f])[f.key];
    var set = function (v) { obj[f.key] = v; markDirty(); if (opts.onChange) opts.onChange(f.key, v); };
    var input, wrap = h('div.field.field--' + f.type + (f.width ? '.w-' + f.width : ''), { 'data-key': f.key });
    var label = h('label.field__label', f.label);

    switch (f.type) {
      case 'checkbox':
        input = h('input', { type: 'checkbox', checked: obj[f.key], onchange: function () { set(this.checked); } });
        wrap.appendChild(h('label.switch', input, h('span.switch__track'), h('span.switch__text', f.label)));
        break;

      case 'textarea':
      case 'code':
        input = h('textarea' + (f.type === 'code' ? '.mono' : ''), {
          rows: f.type === 'code' ? 6 : 3, value: obj[f.key] || '', spellcheck: f.type === 'code' ? 'false' : null,
          oninput: function () { set(this.value); autosize(this); }
        });
        wrap.append(label, input);
        requestAnimationFrame(function () { autosize(input); });
        break;

      case 'richtext':
        input = h('textarea', { rows: 6, value: obj[f.key] || '', oninput: function () { set(this.value); autosize(this); } });
        var wrapSel = function (before0, after0) {
          return function (e) {
            e.preventDefault();
            var before = before0, after = after0;
            var s = input.selectionStart, en = input.selectionEnd, v = input.value, sel = v.slice(s, en);
            if (before === '<abbr>') {
              var full = prompt('What does "' + (sel || 'the abbreviation') + '" stand for? (e.g. Ministry of Transport)', '');
              if (!full) return;
              before = '<abbr title="' + full.replace(/"/g, '') + '">'; after = '</abbr>';
            }
            if (before === '<a>') {
              var href = prompt('Link to (a page slug like "contact", or a full URL):', 'contact');
              if (!href) return;
              before = '<a href="' + href.replace(/"/g, '') + '">';
            }
            if (before === '<ul>') { sel = (sel || 'List item').split(/\n/).map(function (l) { return '<li>' + l + '</li>'; }).join('\n'); before = '<ul>\n'; after = '\n</ul>'; }
            input.value = v.slice(0, s) + before + sel + after + v.slice(en);
            input.focus(); input.selectionStart = s + before.length; input.selectionEnd = s + before.length + sel.length;
            set(input.value); autosize(input);
          };
        };
        var tb = h('div.rt-toolbar',
          h('button', { type: 'button', title: 'Bold', onclick: wrapSel('<strong>', '</strong>') }, h('b', 'B')),
          h('button', { type: 'button', title: 'Italic', onclick: wrapSel('<em>', '</em>') }, h('i', 'I')),
          h('button', { type: 'button', title: 'Sub-heading', onclick: wrapSel('<h3>', '</h3>') }, 'H3'),
          h('button', { type: 'button', title: 'Bulleted list (one item per line)', onclick: wrapSel('<ul>', '') }, '• List'),
          h('button', { type: 'button', title: 'Link', onclick: wrapSel('<a>', '</a>') }, 'Link'),
          h('button', { type: 'button', title: 'Explain an abbreviation (select it first)', onclick: wrapSel('<abbr>', '</abbr>') }, 'Abbr'),
          f.images ? h('button', { type: 'button', title: 'Insert an image', onclick: function (e) {
            e.preventDefault();
            var at = input.selectionEnd;
            mediaPicker(function (p) {
              var alt = prompt('Describe the image for people who can\'t see it (e.g. "New kitchen with oak worktops"). Leave empty only if it\'s purely decorative.', '');
              if (alt === null) return;
              var tag = '\n\n<img src="' + p + '" alt="' + alt.replace(/["<>]/g, '') + '">\n\n';
              input.value = input.value.slice(0, at) + tag + input.value.slice(at);
              input.focus(); input.selectionStart = input.selectionEnd = at + tag.length;
              set(input.value); autosize(input);
            });
          } }, 'Image') : null,
          h('span.rt-hint', 'Blank line = new paragraph')
        );
        wrap.append(label, tb, input);
        requestAnimationFrame(function () { autosize(input); });
        break;

      case 'select':
        input = h('select', { onchange: function () { set(this.value); if (prev) prev.replaceWith(prev = iconPreview(this.value)); } },
          (f.options || []).map(function (o) { return h('option', { value: o, selected: String(obj[f.key]) === String(o) ? 'selected' : null }, (f.labels && f.labels[o]) || (o === '' ? '— none —' : o)); }));
        var prev = f.key === 'icon' ? iconPreview(obj[f.key]) : null;
        wrap.append(label, prev ? h('div.with-icon', prev, input) : input);
        break;

      case 'color':
        var txt = h('input', { type: 'text', value: obj[f.key] || '', placeholder: 'Theme default', maxlength: 7, oninput: function () { if (/^#[0-9a-f]{6}$/i.test(this.value)) pick.value = this.value; sw(); set(this.value); } });
        var pick = h('input', { type: 'color', value: obj[f.key] || '#888888', oninput: function () { txt.value = this.value; sw(); set(this.value); } });
        var sw = function () { pick.classList.toggle('is-empty', !txt.value); };
        sw();
        wrap.append(label, h('div.color-row', pick, txt, h('button.button.button--small', { type: 'button', onclick: function () { txt.value = ''; sw(); set(''); } }, 'Reset')));
        break;

      case 'image':
        wrap.append(label, imageField(obj[f.key], set));
        break;

      case 'number':
        input = h('input', { type: 'text', inputmode: 'decimal', value: obj[f.key] === '' || obj[f.key] == null ? '' : obj[f.key], placeholder: f.placeholder || null, oninput: function () { set(this.value.replace(/[^0-9.\-]/g, '')); } });
        wrap.append(label, (f.prefix || f.suffix) ? h('div.affix', f.prefix ? h('span.affix__pre', f.prefix) : null, input, f.suffix ? h('span.affix__suf', f.suffix) : null) : input);
        break;

      case 'date':
        input = h('input', { type: 'date', value: obj[f.key] || '', onchange: function () { set(this.value); } });
        wrap.append(label, input);
        break;

      case 'datetime':
        input = h('input', { type: 'datetime-local', value: obj[f.key] || '', step: 60, onchange: function () { set(this.value); } });
        wrap.append(label, input);
        break;

      case 'multiselect':
        var picked = Array.isArray(obj[f.key]) ? obj[f.key].slice() : [];
        var opts = (f.options || []).filter(function (o) { return o !== ''; });
        var boxes = h('div.checks', opts.length ? opts.map(function (o) {
          return h('label.check', h('input', { type: 'checkbox', value: o, checked: picked.indexOf(o) > -1, onchange: function () {
            var on = this.checked;
            picked = picked.filter(function (x) { return x !== o; });
            if (on) picked.push(o);
            set(opts.filter(function (x) { return picked.indexOf(x) > -1; }));
          } }), h('span', (f.labels && f.labels[o]) || o));
        }) : h('p.muted', f.empty || 'Nothing to choose from yet.'));
        wrap.append(label, boxes);
        break;

      case 'tags':
        wrap.append(label, tagsField(f, obj, set));
        break;

      case 'gallery':
        wrap.append(label, galleryField(obj, f.key));
        break;

      case 'list':
        wrap.append(label, listField(f, obj));
        break;

      default: // text, link
        var sugg = f.suggest && f.suggest.length ? suggestList(f.suggest) : null;
        input = h('input', { type: 'text', value: obj[f.key] || '', placeholder: f.placeholder || null, list: f.type === 'link' ? 'bench-links' : (sugg ? sugg.id : null), oninput: function () { set(this.value); } });
        wrap.append(label, input);
        if (sugg) wrap.append(sugg);
    }
    // Tie the label (and help text) to the control so screen readers announce them (1.3.1, 4.1.2)
    var uid = 'fld' + (++fieldSeq);
    label.id = uid + '-label';
    var ctl = wrap.querySelector(':scope > input:not([type=file]), :scope > textarea, :scope > select, :scope > .with-icon > select, :scope > .affix > input, :scope > .color-row > input[type=text], :scope > .img-field input[type=text], :scope > .switch > input, :scope > .tags > .tags__row > input');
    if (['list', 'gallery', 'image', 'multiselect', 'tags'].indexOf(f.type) > -1) { wrap.setAttribute('role', 'group'); wrap.setAttribute('aria-labelledby', label.id); }
    if (ctl) {
      ctl.id = uid;
      if (f.type !== 'checkbox') label.setAttribute('for', uid);
    }
    var picker = wrap.querySelector('.color-row input[type=color]');
    if (picker) picker.setAttribute('aria-label', 'Pick ' + f.label.toLowerCase());
    var tb = wrap.querySelector('.rt-toolbar');
    if (tb) { tb.setAttribute('role', 'toolbar'); tb.setAttribute('aria-label', 'Formatting for ' + f.label); }
    if (f.help) {
      wrap.appendChild(h('p.field__help', { id: uid + '-help' }, f.help));
      if (ctl) ctl.setAttribute('aria-describedby', uid + '-help');
    }
    if (f.type === 'color') wrap.appendChild(contrastNote(f.key, obj));
    var tr = translationFields(f, obj, opts);
    if (tr.length) { wrap.classList.add('has-translation'); tr.forEach(function (el) { wrap.appendChild(el); }); }
    return wrap;
  }

  var suggestSeq = 0;
  function suggestList(values) {
    return h('datalist#bench-suggest-' + (++suggestSeq), values.map(function (v) { return h('option', { value: v }); }));
  }

  /* Tags: each tag is a chip with a remove button; type a tag and press Enter or comma to add it.
     Existing tags are offered as suggestions while typing. */
  function tagsField(f, obj, set) {
    var tags = Array.isArray(obj[f.key]) ? obj[f.key].slice() : [];
    var sugg = suggestList(f.suggest || []);
    var chips = h('ul.tags__list', { 'aria-label': 'Current ' + f.label.toLowerCase() });
    var status = h('span.sr-only', { role: 'status' });
    var input = h('input', { type: 'text', list: sugg.id, placeholder: f.placeholder || 'Type and press Enter', autocomplete: 'off',
      onkeydown: function (e) {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(this.value); }
        else if (e.key === 'Backspace' && !this.value && tags.length) remove(tags.length - 1, true);
      },
      oninput: function () { if (this.value.indexOf(',') > -1) add(this.value); },
      onblur: function () { if (this.value.trim()) add(this.value); }
    });
    function save() { set(tags.slice()); draw(); }
    function add(text) {
      text.split(',').forEach(function (t) {
        t = t.replace(/\s+/g, ' ').trim().slice(0, 60);
        if (t && !tags.some(function (x) { return x.toLowerCase() === t.toLowerCase(); })) { tags.push(t); status.textContent = 'Added ' + t; }
      });
      input.value = '';
      save();
    }
    function remove(i, keepFocus) {
      var t = tags.splice(i, 1)[0];
      status.textContent = 'Removed ' + t;
      save();
      (keepFocus ? input : (chips.querySelectorAll('button')[i] || chips.querySelectorAll('button')[i - 1] || input)).focus();
    }
    function draw() {
      chips.replaceChildren.apply(chips, tags.map(function (t, i) {
        return h('li.tag', h('span', t), h('button.tag__x', { type: 'button', title: 'Remove ' + t, onclick: function () { remove(i); } }, '✕'));
      }));
      chips.hidden = !tags.length;
    }
    draw();
    return h('div.tags', chips, h('div.tags__row', input, h('button.button.button--small', { type: 'button', onclick: function () { add(input.value); input.focus(); } }, 'Add', h('span.sr-only', ' ' + f.label.toLowerCase()))), sugg, status);
  }

  function iconPreview(name) { return h('span.icon-preview', name ? icon(name) : ''); }

  function imageField(value, set) {
    var thumb = h('div.img-thumb');
    var path = h('input', { type: 'text', value: value || '', placeholder: 'No image', oninput: function () { set(this.value); paint(); } });
    var file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true, onchange: function () {
      if (!this.files[0]) return;
      thumb.classList.add('is-loading');
      upload(this.files[0]).then(function (r) { path.value = r.path; set(r.path); paint(); toast('Image uploaded'); })
        .catch(function (e) { toast(e.message, 'error'); }).finally(function () { thumb.classList.remove('is-loading'); file.value = ''; });
    } });
    function paint() {
      thumb.innerHTML = '';
      if (path.value) thumb.appendChild(h('img', { src: mediaUrl(path.value), alt: '' })); else thumb.appendChild(h('span', 'No image'));
    }
    paint();
    return h('div.img-field', thumb, h('div.img-field__side',
      path,
      h('div.btn-row',
        h('button.button.button--small', { type: 'button', onclick: function () { file.click(); } }, 'Upload'),
        h('button.button.button--small', { type: 'button', onclick: function () { mediaPicker(function (p) { path.value = p; set(p); paint(); }); } }, 'Library'),
        h('button.button.button--small.button--ghost', { type: 'button', onclick: function () { path.value = ''; set(''); paint(); } }, 'Remove')
      ), file));
  }

  function galleryField(obj, key) {
    if (!Array.isArray(obj[key])) obj[key] = [];
    var arr = obj[key];
    var grid = h('div.gallery-field');
    var file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', multiple: true, hidden: true, onchange: function () {
      var files = Array.prototype.slice.call(this.files); this.value = '';
      grid.classList.add('is-loading');
      files.reduce(function (p, f) { return p.then(function () { return upload(f).then(function (r) { arr.push(r.path); markDirty(); draw(); }); }); }, Promise.resolve())
        .then(function () { toast(files.length > 1 ? files.length + ' photos added' : 'Photo added'); })
        .catch(function (e) { toast(e.message, 'error'); }).finally(function () { grid.classList.remove('is-loading'); });
    } });
    function move(i, d) { var t = arr[i]; arr[i] = arr[i + d]; arr[i + d] = t; markDirty(); draw(); }
    function draw() {
      grid.innerHTML = '';
      arr.forEach(function (p, i) {
        grid.appendChild(h('div.gal-tile' + (i === 0 ? '.is-main' : ''),
          h('img', { src: mediaUrl(p), alt: '' }),
          i === 0 ? h('span.gal-tile__main', 'Main photo') : null,
          h('div.gal-tile__tools',
            h('button.tool', { type: 'button', title: 'Move left', disabled: i === 0 ? true : null, onclick: function () { move(i, -1); } }, '←'),
            h('button.tool', { type: 'button', title: 'Move right', disabled: i === arr.length - 1 ? true : null, onclick: function () { move(i, 1); } }, '→'),
            h('button.tool.tool--danger', { type: 'button', title: 'Remove', onclick: function () { arr.splice(i, 1); markDirty(); draw(); } }, '✕'))));
      });
      grid.appendChild(h('div.gal-add',
        h('button.button.button--small', { type: 'button', onclick: function () { file.click(); } }, '+ Upload photos'),
        h('button.button.button--small.button--ghost', { type: 'button', onclick: function () { mediaPicker(function (p) { arr.push(p); markDirty(); draw(); }); } }, 'From library')));
    }
    draw();
    return h('div', grid, file, h('p.field__help', 'The first photo is used on listings. Use the arrows to reorder.'));
  }

  function listField(f, obj) {
    if (!Array.isArray(obj[f.key])) obj[f.key] = [];
    var arr = obj[f.key];
    var box = h('div.list');
    var single = f.fields.length === 1;

    function summary(item, i) {
      for (var j = 0; j < f.fields.length; j++) {
        var ff = f.fields[j];
        if (['text', 'textarea', 'link'].indexOf(ff.type) > -1 && item[ff.key]) return String(item[ff.key]).slice(0, 60);
        if (ff.type === 'image' && item[ff.key]) return item[ff.key].split('/').pop();
      }
      return 'Item ' + (i + 1);
    }

    function draw(openIndex) {
      box.innerHTML = '';
      arr.forEach(function (item, i) {
        var tools = h('div.list__tools',
          h('button.tool', { type: 'button', title: 'Move item ' + (i + 1) + ' up', disabled: i === 0 ? true : null, onclick: function () { move(i, -1); } }, '↑'),
          h('button.tool', { type: 'button', title: 'Move item ' + (i + 1) + ' down', disabled: i === arr.length - 1 ? true : null, onclick: function () { move(i, 1); } }, '↓'),
          h('button.tool.tool--danger', { type: 'button', title: 'Remove item ' + (i + 1), onclick: function () { arr.splice(i, 1); markDirty(); draw(); } }, '✕')
        );
        if (single) {
          box.appendChild(h('div.list__row', h('div.list__fields', renderFields(f.fields, item)), tools));
        } else {
          var title = h('span.list__title', summary(item, i));
          var det = h('details.list__item', { open: i === openIndex ? true : null, ontoggle: function () { if (this.open) this.querySelectorAll('textarea').forEach(autosize); } },
            h('summary', h('span.list__num', { 'aria-hidden': 'true' }, String(i + 1)), h('span.sr-only', 'Item ' + (i + 1) + ': '), title),
            h('div.list__fields', renderFields(f.fields, item, { onChange: function () { title.textContent = summary(item, i); } })));
          tools.setAttribute('role', 'group'); tools.setAttribute('aria-label', 'Item ' + (i + 1) + ' actions');
          box.appendChild(h('div.list__wrap', det, tools));
        }
      });
      box.appendChild(h('button.button.button--small.button--dashed', { type: 'button', onclick: function () { arr.push(defaults(f.fields)); markDirty(); draw(arr.length - 1); } }, '+ Add ' + (single ? f.fields[0].label.toLowerCase() : 'item')));
    }
    function move(i, d) { var t = arr[i]; arr[i] = arr[i + d]; arr[i + d] = t; markDirty(); draw(i + d); }
    draw();
    return box;
  }

  /* ------------------------------------------------------------ *
   * Media picker modal
   * ------------------------------------------------------------ */

  // Modal dialog: focus moves in, Tab stays inside, Escape closes, focus returns (2.1.2, 2.4.3)
  var modalSeq = 0;
  function modal(title, body, wide) {
    var opener = document.activeElement, tid = 'modal-title-' + (++modalSeq);
    var m = h('div.modal', { onclick: function (e) { if (e.target === m) close(); } },
      h('div.modal__box' + (wide ? '.modal__box--wide' : ''), { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': tid },
        h('div.modal__head', h('h2', { id: tid }, title), h('button.tool', { type: 'button', 'aria-label': 'Close dialog', onclick: function () { close(); } }, '✕')),
        h('div.modal__body', body)));
    function focusables() { return Array.prototype.filter.call(m.querySelectorAll('button, a[href], input:not([type=hidden]):not([hidden]), select, textarea, [tabindex]:not([tabindex="-1"])'), function (el) { return !el.disabled && el.offsetParent !== null; }); }
    function close() { m.remove(); document.removeEventListener('keydown', key); if (opener && opener.focus) opener.focus(); }
    function key(e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key === 'Tab') { var f = focusables(); if (!f.length) return; var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } }
    }
    document.addEventListener('keydown', key);
    document.body.appendChild(m);
    setTimeout(function () { var f = focusables(); (f[1] || f[0]).focus(); });
    return close;
  }

  function mediaPicker(choose) {
    var grid = h('div.media-grid', h('p.muted', 'Loading…'));
    var close = modal('Choose an image', [uploadZone(function () { load(); }), grid], true);
    function load() {
      api('media').then(function (r) {
        grid.innerHTML = '';
        if (!r.files.length) grid.appendChild(h('p.muted', 'No images yet — upload one above.'));
        r.files.forEach(function (f) {
          grid.appendChild(h('button.media-tile', { type: 'button', onclick: function () { choose(f.path); close(); } },
            h('img', { src: f.url, alt: '', loading: 'lazy' }), h('span', f.name)));
        });
      }).catch(function (e) { toast(e.message, 'error'); });
    }
    load();
  }

  function uploadZone(done) {
    var input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', multiple: true, hidden: true, onchange: function () { send(this.files); this.value = ''; } });
    var zone = h('button.dropzone', { type: 'button', onclick: function () { input.click(); } }, icon('arrow'), h('strong', 'Upload images'), h('span', 'Press to choose photos, or drag them here · JPG, PNG, WebP, GIF · max ' + B.maxMb + 'MB'));
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) { send(e.dataTransfer.files); });
    function send(files) {
      var list = Array.prototype.slice.call(files);
      zone.classList.add('is-loading');
      list.reduce(function (p, f) { return p.then(function () { return upload(f); }); }, Promise.resolve())
        .then(function () { toast(list.length > 1 ? list.length + ' images uploaded' : 'Image uploaded'); done(); })
        .catch(function (e) { toast(e.message, 'error'); })
        .finally(function () { zone.classList.remove('is-loading'); });
    }
    return h('div.dropzone-wrap', zone, input);
  }

  /* ------------------------------------------------------------ *
   * Shared page chrome
   * ------------------------------------------------------------ */

  function topbar(title, sub, actions) {
    return h('header.topbar', h('div.topbar__title', h('h1', title), sub ? h('p', sub) : null), h('div.topbar__actions', actions));
  }

  function saveButton(fn) {
    var status = h('span.save-status', '');
    var btn = h('button.button.button--primary', { type: 'button', onclick: run }, 'Save changes');
    function run() {
      btn.disabled = true; btn.textContent = 'Saving…';
      Promise.resolve(fn()).then(function (r) { clean(); status.textContent = 'Saved ' + (r && r.saved ? r.saved : ''); toast(trashNote('Saved', r)); })
        .catch(function (e) { toast(e.message, 'error'); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Save changes'; });
    }
    onDirty = function (d) { status.textContent = d ? 'Unsaved changes' : status.textContent; status.classList.toggle('is-dirty', d); };
    document.addEventListener('keydown', function (e) { if ((e.metaKey || e.ctrlKey) && e.key === 's') { e.preventDefault(); if (!btn.disabled) run(); } });
    return [status, btn];
  }

  /* ------------------------------------------------------------ *
   * Views
   * ------------------------------------------------------------ */

  var views = {};

  views.pages = function () {
    var pages = B.pages.slice();
    var list = h('div.page-list');
    function draw() {
      list.innerHTML = '';
      pages.forEach(function (p, i) {
        list.appendChild(h('div.page-row',
          h('div.page-row__order',
            h('button.tool', { type: 'button', title: 'Move ' + p.title + ' up in menu', disabled: i === 0 ? true : null, onclick: function () { move(i, -1); } }, '↑'),
            h('button.tool', { type: 'button', title: 'Move ' + p.title + ' down in menu', disabled: i === pages.length - 1 ? true : null, onclick: function () { move(i, 1); } }, '↓')),
          h('a.page-row__main', { href: '?view=edit&page=' + encodeURIComponent(p.slug) },
            h('strong', p.title),
            h('span.muted', '/' + (p.slug === 'home' ? '' : p.slug) + ' · ' + p.blocks + ' section' + (p.blocks === 1 ? '' : 's') + ' · edited ' + fmtDate(p.updated))),
          h('div.page-row__tags',
            h('span.pill' + (p.published ? '.pill--ok' : '.pill--warn'), p.published ? 'Published' : 'Draft'),
            p.in_nav ? h('span.pill', 'In menu') : null,
            LANGS.map(function (l) {
              var t = (p.translated || {})[l.code]; if (!t || !t.total) return null;
              var full = t.done >= t.total;
              return h('span.pill' + (full ? '.pill--ok' : '.pill--warn'), { title: t.done + ' of ' + t.total + ' text fields translated into ' + l.english_name },
                l.english_name + ' ' + (full ? '✓' : t.done + '/' + t.total), h('span.sr-only', full ? ' (all translated)' : ' fields translated'));
            })),
          h('div.page-row__actions',
            h('a.button.button--small', { href: '?view=edit&page=' + encodeURIComponent(p.slug) }, 'Edit', h('span.sr-only', ' ' + p.title)),
            h('a.button.button--small.button--ghost', { href: p.url, target: '_blank' }, 'View', h('span.sr-only', ' ' + p.title)),
            p.slug !== 'home' ? h('button.tool.tool--danger', { type: 'button', title: 'Delete page ' + p.title, onclick: function () { del(p); } }, '✕') : null)
        ));
      });
    }
    function move(i, d) {
      var t = pages[i]; pages[i] = pages[i + d]; pages[i + d] = t; draw();
      api('reorder_pages', { order: pages.map(function (p) { return p.slug; }) }).then(function () { toast('Menu order saved'); }).catch(function (e) { toast(e.message, 'error'); });
    }
    function del(p) {
      if (!confirm('Delete "' + p.title + '"? A backup is kept, so it can be restored from Backups. Photos only this page uses go to the trash and come back if you restore it.')) return;
      api('delete_page', { slug: p.slug }).then(function (r) { pages = pages.filter(function (x) { return x !== p; }); draw(); toast(trashNote('Page deleted', r)); }).catch(function (e) { toast(e.message, 'error'); });
    }
    function create() {
      var title = prompt('New page title:', '');
      if (!title) return;
      api('create_page', { title: title }).then(function (r) { location.href = '?view=edit&page=' + encodeURIComponent(r.slug); }).catch(function (e) { toast(e.message, 'error'); });
    }
    draw();
    app.replaceChildren(
      topbar('Pages', 'Arrows set the order pages appear in the menu.', h('button.button.button--primary', { type: 'button', onclick: create }, '+ New page')),
      h('div.content', list)
    );
  };

  views.edit = function () {
    var page = clone(B.page);
    var S = B.schema;
    var original = page.slug;
    var blocksBox = h('div.blocks');
    var openSet = {};

    // Links datalist for link fields
    var dl = h('datalist#bench-links', ['home'].concat(B.pagesSlugs || []).map(function (s) { return h('option', { value: s }); }), h('option', { value: '#quote' }), h('option', { value: 'contact#quote' }));

    function blockSummary(b) {
      return b.heading || b.eyebrow || (b.items && b.items[0] && (b.items[0].text || b.items[0].title || b.items[0].name)) || '';
    }

    function drawBlocks() {
      blocksBox.innerHTML = '';
      if (!page.blocks.length) blocksBox.appendChild(h('div.empty', h('p', 'This page has no sections yet.'), h('p.muted', 'Use “Add section” below to start building it.')));
      page.blocks.forEach(function (b, i) {
        var def = S.blocks[b.type];
        if (!def) return;
        var sum = h('span.block__sum', blockSummary(b));
        var body = h('div.block__body',
          renderFields(def.fields, b, { onChange: function () { sum.textContent = blockSummary(b); } }),
          h('details.block__adv', h('summary', 'Section options'), renderFields(S.common, b)));
        var name = def.label + ' (section ' + (i + 1) + ')';
        var card = h('details.block' + (b.hidden ? '.is-hidden' : ''), { open: openSet[i] ? true : null, ontoggle: function () { openSet[i] = card.open; if (card.open) card.querySelectorAll('textarea').forEach(autosize); } },
          h('summary.block__head',
            h('span.block__type', def.label), b.hidden ? h('span.sr-only', ' (hidden from site)') : null, sum),
          body);
        var tools = h('div.block__tools', { role: 'group', 'aria-label': name + ' actions' },
          h('button.tool', { type: 'button', title: 'Move ' + name + ' up', disabled: i === 0 ? true : null, onclick: function () { move(i, -1); } }, '↑'),
          h('button.tool', { type: 'button', title: 'Move ' + name + ' down', disabled: i === page.blocks.length - 1 ? true : null, onclick: function () { move(i, 1); } }, '↓'),
          h('button.tool', { type: 'button', title: (b.hidden ? 'Show ' : 'Hide ') + name + (b.hidden ? ' on site' : ' from site'), 'aria-pressed': b.hidden ? 'true' : 'false', onclick: function () { b.hidden = !b.hidden; markDirty(); drawBlocks(); } }, b.hidden ? '◌' : '◉'),
          h('button.tool', { type: 'button', title: 'Duplicate ' + name, onclick: function () { page.blocks.splice(i + 1, 0, clone(b)); shiftOpen(i + 1, 1); markDirty(); drawBlocks(); } }, '⧉'),
          h('button.tool.tool--danger', { type: 'button', title: 'Delete ' + name, onclick: function () { if (confirm('Delete this ' + def.label + ' section?')) { page.blocks.splice(i, 1); shiftOpen(i, -1); markDirty(); drawBlocks(); } } }, '✕'));
        blocksBox.appendChild(h('div.block-wrap', card, tools));
      });
    }
    function shiftOpen(from, d) {
      var n = {};
      Object.keys(openSet).forEach(function (k) { k = +k; if (k < from) n[k] = openSet[k]; else if (k + d >= from) n[k + d] = openSet[k]; });
      openSet = n;
    }
    function move(i, d) {
      var t = page.blocks[i]; page.blocks[i] = page.blocks[i + d]; page.blocks[i + d] = t;
      var o = openSet[i]; openSet[i] = openSet[i + d]; openSet[i + d] = o;
      markDirty(); drawBlocks();
    }
    function addBlock() {
      var close = modal('Add a section', h('div.block-picker', Object.keys(S.blocks).map(function (type) {
        var d = S.blocks[type];
        return h('button.block-pick', { type: 'button', onclick: function () {
          var nb = Object.assign({ type: type, hidden: false }, defaults(S.common), defaults(d.fields));
          page.blocks.push(nb); openSet[page.blocks.length - 1] = true; markDirty(); drawBlocks(); close();
          setTimeout(function () { blocksBox.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
        } }, h('strong', d.label), h('span', d.description));
      })), true);
    }

    var metaFields = S.page.filter(function (f) { return ['seo_title', 'meta_description'].indexOf(f.key) < 0; });
    var seoFields = S.page.filter(function (f) { return ['seo_title', 'meta_description'].indexOf(f.key) > -1; });
    if (original === 'home') metaFields = metaFields.filter(function (f) { return f.key !== 'slug'; });

    var checker = a11yPanel(page, S);
    var viewLink = h('a.button.button--ghost', { href: B.viewUrl, target: '_blank' }, 'View page ↗');
    // Bilingual sites: a link to the page in each other language too
    var langLinks = LANGS.map(function (l) { return h('a.button.button--ghost', { href: (B.viewUrls || {})[l.code] || B.viewUrl, target: '_blank', lang: 'en' }, 'View in ' + l.english_name + ' ↗'); });
    var actions = saveButton(function () {
      if (checker) checker.run();
      return api('save_page', { original_slug: original, page: page }).then(function (r) {
        if (r.slug !== original) { original = r.slug; history.replaceState(null, '', '?view=edit&page=' + encodeURIComponent(r.slug)); }
        viewLink.href = r.url;
        return r;
      });
    });

    drawBlocks();
    app.replaceChildren(
      topbar(page.title || 'Untitled', null, [h('a.button.button--ghost', { href: './' }, '← Pages'), viewLink].concat(langLinks, actions)),
      h('div.content.editor', dl,
        h('section.editor__blocks', h('div.editor__label', 'Page sections'), blocksBox,
          h('button.button.button--dashed.button--block', { type: 'button', onclick: addBlock }, '+ Add section')),
        h('div.editor__side', checker, h('div.panel', h('h2', 'Page settings'), renderFields(metaFields, page)),
          h('div.panel', h('h2', 'Search engines'), renderFields(seoFields, page))))
    );
  };

  /* ------------------------------------------------------------ *
   * Content accessibility checker (things only the editor can get right)
   * ------------------------------------------------------------ */
  function plain(html) { var d = document.createElement('div'); d.innerHTML = String(html || '').replace(/<\/(p|li|h\d)>/g, '. '); return d.textContent || ''; }
  function syllables(w) { w = w.toLowerCase().replace(/[^a-z]/g, ''); if (w.length <= 3) return 1;
    w = w.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '').replace(/^y/, ''); var m = w.match(/[aeiouy]{1,2}/g); return m ? m.length : 1; }
  function readingGrade(text) {
    var words = text.match(/[A-Za-z][A-Za-z'’-]*/g) || [];
    var sentences = Math.max(1, (text.match(/[.!?]+(\s|$)/g) || []).length);
    if (words.length < 80) return null;
    var syl = words.reduce(function (a, w) { return a + syllables(w); }, 0);
    return { grade: 0.39 * (words.length / sentences) + 11.8 * (syl / words.length) - 15.59, words: words.length };
  }
  function checkPage(page, S) {
    var out = [], texts = [], vague = /^(click here|here|read more|more|link|this|go|learn more|find out more)$/i;
    var abbrSeen = {};
    page.blocks.forEach(function (b, i) {
      if (b.hidden) return;
      var name = (S.blocks[b.type] ? S.blocks[b.type].label : b.type) + ' (section ' + (i + 1) + ')';
      var collect = function (obj, fields) { fields.forEach(function (f) {
        var v = obj[f.key];
        if (f.type === 'list') (v || []).forEach(function (it) { collect(it, f.fields); });
        else if (f.type === 'image' && v && (f.key + '_alt') in obj && !String(obj[f.key + '_alt'] || '').trim()) out.push(['error', 'Image has no description in ' + name, 'Add alt text describing the photo, unless it\'s purely decorative.']);
        else if (['text', 'textarea'].indexOf(f.type) > -1 && f.key.indexOf('link') < 0 && f.key !== 'anchor' && typeof v === 'string') texts.push(v);
        else if (f.type === 'richtext') {
          texts.push(plain(v));
          var d = document.createElement('div'); d.innerHTML = v || '';
          d.querySelectorAll('a').forEach(function (a) { if (vague.test(a.textContent.trim())) out.push(['warn', 'Vague link text in ' + name, '"' + a.textContent.trim() + '" doesn\'t say where the link goes. Use words that make sense on their own, e.g. "See our boiler prices".']); });
          d.querySelectorAll('img').forEach(function (im) { if (!(im.getAttribute('alt') || '').trim()) out.push(['warn', 'Image in the text of ' + name + ' has no description', 'Edit the <img> tag\'s alt="" to say what the picture shows, unless it\'s purely decorative.']); });
          if (d.querySelector('h4') && !d.querySelector('h3')) out.push(['warn', 'Heading levels skip in ' + name, 'An H4 is used without an H3 before it. Use H3 for sub-headings inside a section.']);
          (plain(v).match(/\b[A-Z]{2,6}s?\b/g) || []).forEach(function (ab) { if (!d.querySelector('abbr') || !Array.prototype.some.call(d.querySelectorAll('abbr'), function (x) { return x.textContent === ab; })) abbrSeen[ab] = name; });
        }
      }); };
      if (S.blocks[b.type]) collect(b, S.blocks[b.type].fields);
      ['button_text', 'button2_text'].forEach(function (k) { if (b[k] && vague.test(b[k].trim())) out.push(['warn', 'Vague button text in ' + name, '"' + b[k] + '" — say what happens, e.g. "Get a free quote".']); });
      if (b.type === 'gallery') (b.items || []).forEach(function (it, n) { if (it.image && !(it.alt || '').trim() && !(it.caption || '').trim()) out.push(['error', 'Photo ' + (n + 1) + ' in ' + name + ' has no description', 'Add a caption or alt text saying what the photo shows.']); });
      if (b.type === 'html' && (b.code || '').trim()) out.push(['info', 'Check the embedded content in ' + name, 'Bench can\'t check embeds. Videos need captions and audio description (and, for AAA, sign language); maps need the address written as text nearby; third-party widgets must be keyboard-usable.']);
      if (b.type === 'hero' && b.image) out.push(['info', 'Hero background image in ' + name, 'Background photos are treated as decoration. Don\'t put important information only in the photo.']);
    });
    var first = page.blocks.filter(function (b) { return !b.hidden; })[0];
    if (page.isItem) { /* a collection item's title is its H1 */ }
    else if (!first || first.type !== 'hero' || !(first.heading || '').trim()) out.push(['error', 'No main heading', 'Start the page with a Hero section that has a heading. It becomes the page\'s H1.']);
    var all = texts.join('. ');
    var caps = all.match(/\b[A-Z][A-Z ,'&-]{24,}\b/g); if (caps) out.push(['info', 'Long text in capitals', '"' + caps[0].slice(0, 40) + '…" — long runs of capitals are harder to read. Themes can make headings look uppercase without typing them that way.']);
    var ab = Object.keys(abbrSeen).filter(function (x) { return ['OK', 'UK', 'TV', 'PM', 'AM'].indexOf(x) < 0; });
    if (ab.length) out.push(['info', 'Explain abbreviations (WCAG 3.1.4)', ab.slice(0, 6).join(', ') + (ab.length > 6 ? '…' : '') + ' — write them out the first time, or select one and press "Abbr" in the text toolbar.']);
    var rg = readingGrade(all);
    if (rg) {
      var g = Math.max(1, Math.round(rg.grade));
      if (g > 9) out.push(['warn', 'Reading level is high (about US grade ' + g + ')', 'WCAG AAA (3.1.5) asks for text readable after lower secondary school (around age 12–14), or a simpler summary alongside. Try shorter sentences and everyday words.']);
      else out.push(['ok', 'Reading level is fine (about US grade ' + g + ')', 'Clear enough for most readers (' + rg.words + ' words checked).']);
    }
    if (!out.filter(function (o) { return o[0] === 'error' || o[0] === 'warn'; }).length) out.unshift(['ok', 'No problems found', 'These checks cover content. The layout, colours and controls are handled by Bench.']);
    return out;
  }
  /* The same checks for a collection item (e.g. a blog post), when its collection asks for them */
  function itemA11yPanel(C, data) {
    var S = { blocks: { _item: { label: 'this ' + C.singular, fields: C.fields } } };
    var wrap = { isItem: true, blocks: [] };
    Object.defineProperty(wrap, 'blocks', { get: function () { return [Object.assign({ type: '_item' }, data)]; } });
    return a11yPanel(wrap, S, function (r) {
      return r.map(function (x) { return [x[0], x[1].replace(' (section 1)', ''), x[2]]; });
    });
  }
  function a11yPanel(page, S, tweak) {
    var list = h('ul.a11y-list');
    var run = function () {
      var r = checkPage(page, S);
      if (tweak) r = tweak(r);
      list.replaceChildren.apply(list, r.map(function (x) { return h('li.is-' + x[0], h('strong', x[1]), x[2]); }));
    };
    run();
    var panel = h('div.panel.a11y-check', h('h2', 'Accessibility check', h('button.button.button--small', { type: 'button', onclick: run }, 'Check again')),
      h('p.field__help', 'Things in your content that affect WCAG AAA. Updates when you save.'), list);
    panel.run = run;
    return panel;
  }

  views.settings = function () {
    var s = clone(B.settings);
    var groups = Object.keys(B.schema);
    var tabs = h('div.tabs', { role: 'tablist', 'aria-label': 'Settings sections' });
    var panels = h('div.panels');
    var vis = {
      form_endpoint: ['formspree', 'basin', 'custom'],
      form_access_key: ['web3forms'],
      form_subject: ['web3forms', 'formspree', 'basin', 'custom'],
      form_services: ['web3forms', 'formspree', 'basin', 'custom'],
      form_success: ['web3forms', 'formspree', 'basin', 'custom'],
      form_embed: ['embed']
    };
    function applyVis() {
      Object.keys(vis).forEach(function (k) {
        var el = panels.querySelector('[data-key="' + k + '"]');
        if (el) el.hidden = vis[k].indexOf(s.form_provider) < 0;
      });
    }

    groups.forEach(function (g, gi) {
      var fields = B.schema[g];
      var body = [];
      fields.forEach(function (f) {
        if (f.key === 'theme') body.push(themePicker(s));
        else body.push(renderField(f, s, { onChange: function (k) { if (k === 'form_provider') applyVis(); } }));
      });
      if (g === 'Contact form') body.unshift(formHelp());
      var panel = h('div.panel.settings-panel', { hidden: gi ? true : null, role: 'tabpanel', id: 'tabpanel-' + gi, 'aria-labelledby': 'tab-' + gi, tabindex: '0' }, h('h2', g), body);
      panels.appendChild(panel);
      var select = function (idx, focus) {
        Array.prototype.forEach.call(panels.children, function (p, i) { p.hidden = i !== idx; });
        Array.prototype.forEach.call(tabs.children, function (t, i) { t.classList.toggle('is-active', i === idx); t.setAttribute('aria-selected', i === idx ? 'true' : 'false'); t.tabIndex = i === idx ? 0 : -1; });
        panels.children[idx].querySelectorAll('textarea').forEach(autosize);
        if (focus) tabs.children[idx].focus();
      };
      tabs.appendChild(h('button.tab' + (gi ? '' : '.is-active'), { type: 'button', role: 'tab', id: 'tab-' + gi, 'aria-controls': 'tabpanel-' + gi, 'aria-selected': gi ? 'false' : 'true', tabindex: gi ? '-1' : '0',
        onclick: function () { select(gi); },
        onkeydown: function (e) { // arrow keys move between tabs
          var n = groups.length, to = e.key === 'ArrowRight' ? (gi + 1) % n : e.key === 'ArrowLeft' ? (gi - 1 + n) % n : e.key === 'Home' ? 0 : e.key === 'End' ? n - 1 : -1;
          if (to > -1) { e.preventDefault(); select(to, true); }
        } }, g));
    });
    applyVis();

    app.replaceChildren(
      topbar('Settings', 'Business details, look and the contact form.', saveButton(function () {
        var langChanged = s.language !== B.settings.language || s.second_language !== B.settings.second_language;
        return api('save_settings', { settings: s }).then(function (r) {
          // Turning a second language on or off changes the editor's boxes: reload to show them
          if (langChanged) { toast('Saved — reloading to update the language boxes'); setTimeout(function () { location.reload(); }, 900); }
          return r;
        });
      })),
      h('div.content', tabs, panels)
    );
  };

  function themePicker(s) {
    var box = h('div.themes');
    function draw() {
      box.innerHTML = '';
      Object.keys(B.themes).forEach(function (id) {
        var t = B.themes[id];
        var sw = (t.swatches || []).map(function (c) { return h('span', { style: 'background:' + c }); });
        box.appendChild(h('button.theme-card' + (s.theme === id ? '.is-active' : ''), { type: 'button', 'aria-pressed': s.theme === id ? 'true' : 'false', onclick: function () { s.theme = id; markDirty(); draw(); } },
          h('span.theme-card__sw', sw), h('strong', t.name), h('span', t.description), s.theme === id ? h('em', 'Active') : null));
      });
    }
    draw();
    return h('div.field', h('label.field__label', 'Theme'), box, h('p.field__help', 'Themes only change the look. Your content stays the same, so you can switch any time.'));
  }

  function formHelp() {
    return h('div.callout',
      h('p', h('strong', 'Your website never sends email itself. '), 'Messages go straight to a form service, which emails them to you. Pick one:'),
      h('ul',
        h('li', h('strong', 'Web3Forms'), ' — free tier, just needs an access key from web3forms.com. Easiest to set up.'),
        h('li', h('strong', 'Formspree'), ' — create a form at formspree.io and paste its https://formspree.io/f/… URL as the endpoint.'),
        h('li', h('strong', 'Basin'), ' — paste your https://usebasin.com/f/… URL as the endpoint.'),
        h('li', h('strong', 'Embed'), ' — build the form in Tally, Jotform, Fillout or Google Forms and paste their embed code.')),
      h('p.muted', 'With the first three, the form uses your theme\'s styling. Test it once after saving — some services ask you to confirm the first submission.'));
  }

  function fmtSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  // Admin → Media: the library (with where each image is used) and the trash.
  views.media = function () {
    var data = { files: [], trash: [] }, onlyUnused = false;
    var tabNames = ['Library', 'Trash'];
    var startTab = /[?&]tab=trash\b/.test(location.search) ? 1 : 0;
    var tablist = h('div.tabs', { role: 'tablist', 'aria-label': 'Media sections' });
    var panels = [h('div.panel', { role: 'tabpanel', id: 'media-panel-0', 'aria-labelledby': 'media-tab-0', tabindex: '0' }),
                  h('div.panel', { role: 'tabpanel', id: 'media-panel-1', 'aria-labelledby': 'media-tab-1', tabindex: '0', hidden: true })];
    var tabs = tabNames.map(function (name, i) {
      return h('button.tab', { type: 'button', role: 'tab', id: 'media-tab-' + i, 'aria-controls': 'media-panel-' + i, 'aria-selected': 'false', tabindex: '-1',
        onclick: function () { select(i, false); },
        onkeydown: function (e) {
          var k = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
          if (k) { e.preventDefault(); select((i + k + tabs.length) % tabs.length, true); }
          if (e.key === 'Home') { e.preventDefault(); select(0, true); }
          if (e.key === 'End') { e.preventDefault(); select(tabs.length - 1, true); }
        } }, name);
    });
    tabs.forEach(function (t) { tablist.appendChild(t); });
    function select(i, focus) {
      tabs.forEach(function (t, j) { t.classList.toggle('is-active', i === j); t.setAttribute('aria-selected', i === j ? 'true' : 'false'); t.tabIndex = i === j ? 0 : -1; });
      panels.forEach(function (p, j) { p.hidden = i !== j; });
      history.replaceState(null, '', '?view=media' + (i ? '&tab=trash' : ''));
      if (focus) tabs[i].focus();
    }
    function labelTabs() {
      tabs[1].textContent = 'Trash' + (data.trash.length ? ' (' + data.trash.length + ')' : '');
    }

    function load() {
      return api('media').then(function (r) { data = r; labelTabs(); drawLibrary(); drawTrash(); }).catch(function (e) { toast(e.message, 'error'); });
    }

    function drawLibrary() {
      var p = panels[0];
      var unused = data.files.filter(function (f) { return !f.used.length; });
      var shown = onlyUnused ? unused : data.files;
      var filter = h('label.check', h('input', { type: 'checkbox', checked: onlyUnused, onchange: function () { onlyUnused = this.checked; drawLibrary(); p.querySelector('#media-only-unused').focus(); }, id: 'media-only-unused' }), ' Only show images that aren\'t used anywhere');
      var bulk = unused.length ? h('button.button', { type: 'button', onclick: function () {
        if (!confirm('Move ' + unused.length + (unused.length === 1 ? ' image' : ' images') + ' that aren\'t used anywhere to the trash? You can restore them from the Trash tab until it\'s emptied.')) return;
        api('trash_unused').then(function (r) { toast(r.trashed + (r.trashed === 1 ? ' image' : ' images') + ' moved to the trash'); load(); }).catch(function (e) { toast(e.message, 'error'); });
      } }, 'Move ' + unused.length + ' unused to trash') : null;
      var grid = h('div.media-grid');
      if (!shown.length) grid.appendChild(h('p.muted', onlyUnused ? 'Every image is in use.' : 'No images yet.'));
      shown.forEach(function (f) {
        var where = f.used.length
          ? h('p.media-tile__use', h('span.sr-only', 'Used on: '), f.used.slice(0, 2).join(', ') + (f.used.length > 2 ? ' and ' + (f.used.length - 2) + ' more' : ''))
          : h('p.media-tile__use', h('span.pill.pill--plain', 'Not used'));
        grid.appendChild(h('div.media-tile',
          h('img', { src: f.url, alt: '', loading: 'lazy' }),
          h('span', f.name),
          h('small.muted', fmtSize(f.size)),
          where,
          h('div.btn-row',
            h('button.button.button--small', { type: 'button', onclick: function () { (navigator.clipboard ? navigator.clipboard.writeText(f.path) : Promise.reject()).then(function () { toast('Path copied'); }, function () { prompt('Image path:', f.path); }); } }, 'Copy path', h('span.sr-only', ' for ' + f.name)),
            h('button.tool.tool--danger', { type: 'button', title: 'Move ' + f.name + ' to the trash', 'aria-label': 'Move ' + f.name + ' to the trash', onclick: function () {
              var msg = f.used.length
                ? 'This image is used on: ' + f.used.join(', ') + '.\n\nMove it to the trash anyway? Those pages will show a broken image until you restore it.'
                : 'Move this image to the trash? You can restore it from the Trash tab until it\'s emptied.';
              if (!confirm(msg)) return;
              api('delete_media', { path: f.path }).then(function () { toast('Moved to the trash'); load(); }).catch(function (e) { toast(e.message, 'error'); });
            } }, '✕'))));
      });
      p.replaceChildren(
        h('div.media-tools', filter, bulk),
        h('p.muted', data.files.length + (data.files.length === 1 ? ' image' : ' images') + ', ' + unused.length + ' not used anywhere.'),
        uploadZone(load), grid);
    }

    function drawTrash() {
      var p = panels[1];
      var total = data.trash.reduce(function (n, t) { return n + (t.size || 0); }, 0);
      var empty = data.trash.length ? h('button.button.button--danger', { type: 'button', onclick: function () {
        if (!confirm('Empty the trash? This permanently deletes ' + data.trash.length + (data.trash.length === 1 ? ' image' : ' images') + ' (' + fmtSize(total) + '). It can\'t be undone, and restoring a backup won\'t bring them back.')) return;
        api('trash_empty').then(function (r) { toast('Trash emptied · ' + r.count + (r.count === 1 ? ' image' : ' images') + ' deleted'); load().then(function () { tabs[1].focus(); }); }).catch(function (e) { toast(e.message, 'error'); });
      } }, 'Empty trash') : null;
      var intro = h('p.muted', data.trash.length
        ? data.trash.length + (data.trash.length === 1 ? ' image' : ' images') + ' (' + fmtSize(total) + '). Images in the trash aren\'t on your website. Restore one to put it back where it was, or empty the trash to delete them all for good.'
        : 'The trash is empty. Photos from deleted vehicles and pages, and photos removed from them, come here when nothing else uses them.');
      var list = h('div.media-grid');
      data.trash.forEach(function (t) {
        list.appendChild(h('div.media-tile.media-tile--trash',
          h('img', { src: t.preview, alt: '', loading: 'lazy' }),
          h('span', t.name),
          h('small.muted', fmtSize(t.size) + ' · ' + fmtDate(t.trashed)),
          h('p.media-tile__use', h('span.sr-only', 'From: '), t.from),
          h('div.btn-row',
            h('button.button.button--small', { type: 'button', onclick: function () {
              api('trash_restore', { id: t.id }).then(function () { toast('Restored to ' + t.path); load(); }).catch(function (e) { toast(e.message, 'error'); });
            } }, 'Restore', h('span.sr-only', ' ' + t.name)),
            h('button.button.button--small.button--danger-ghost', { type: 'button', onclick: function () {
              if (!confirm('Delete ' + t.name + ' for good? This can\'t be undone.')) return;
              api('trash_delete', { id: t.id }).then(function () { toast('Deleted for good'); load(); }).catch(function (e) { toast(e.message, 'error'); });
            } }, 'Delete for good', h('span.sr-only', ': ' + t.name)))));
      });
      p.replaceChildren(h('div.media-tools', h('h2', 'Trash'), empty), intro, list);
    }

    select(startTab, false);
    app.replaceChildren(topbar('Media', 'Large photos are resized automatically. Unused photos go to the trash when you delete or change what used them.', null),
      h('div.content', tablist, panels[0], panels[1]));
    load();
  };

  views.backups = function () {
    var rows = B.backups;
    var table = rows.length ? h('table.table',
      h('thead', h('tr', h('th', { scope: 'col' }, 'Content'), h('th', { scope: 'col' }, 'Saved'), h('th', { scope: 'col' }, h('span.sr-only', 'Actions')))),
      h('tbody', rows.map(function (r) {
        return h('tr', h('td', r.name.replace('pages/', 'Page: ').replace(/^site$/, 'Site settings')), h('td', fmtDate(r.time)),
          h('td', h('button.button.button--small', { type: 'button', onclick: function () {
            if (!confirm('Restore this version of ' + r.name + '? The current version is backed up first.')) return;
            api('restore_backup', { file: r.file }).then(function (res) {
              var n = res.photos_restored || 0;
              toast(trashNote('Restored' + (n ? ' · ' + n + (n === 1 ? ' photo' : ' photos') + ' brought back from the trash' : ''), res));
              setTimeout(function () { location.reload(); }, 1600);
            }).catch(function (e) { toast(e.message, 'error'); });
          } }, 'Restore', h('span.sr-only', ' ' + r.name + ' from ' + fmtDate(r.time)))));
      }))) : h('p.muted', 'No backups yet. A copy is made every time you save.');
    var demos = Object.keys(B.demos || {});
    var demoPanel = demos.length ? h('div.panel', h('h2', 'Start from a demo site'),
      h('p.muted', 'Replaces all pages, settings and plugin content with a ready-made example. Your login is kept and everything replaced is backed up here first.'),
      h('div.plugin-list', demos.map(function (d) {
        var m = B.demos[d];
        return h('div.plugin-card', h('div.plugin-card__body', h('strong', m.name), h('p', m.description)),
          h('button.button.button--small', { type: 'button', onclick: function () {
            if (!confirm('Load the “' + m.name + '” demo? This replaces your current pages and settings (a backup is kept).')) return;
            api('load_demo', { id: d }).then(function () { toast('Demo loaded'); setTimeout(function () { location.href = './'; }, 700); }).catch(function (e) { toast(e.message, 'error'); });
          } }, 'Load demo', h('span.sr-only', ': ' + m.name)));
      }))) : null;
    app.replaceChildren(topbar('Backups', 'Every save keeps the previous version. Restore one if something goes wrong.', null), h('div.content', h('div.panel', table), demoPanel));
  };

  views.account = function () {
    var cur = h('input', { type: 'password', autocomplete: 'current-password', id: 'pw-current' });
    var nw = h('input', { type: 'password', autocomplete: 'new-password', id: 'pw-new', 'aria-describedby': 'pw-new-help' });
    var nw2 = h('input', { type: 'password', autocomplete: 'new-password', id: 'pw-new2' });
    function go(e) {
      e.preventDefault();
      if (nw.value !== nw2.value) return toast('New passwords don\'t match', 'error');
      api('change_password', { current: cur.value, new: nw.value }).then(function () { toast('Password changed'); cur.value = nw.value = nw2.value = ''; }).catch(function (e) { toast(e.message, 'error'); });
    }
    app.replaceChildren(topbar('Account', null, null), h('div.content', h('form.panel.narrow-panel', { onsubmit: go },
      h('h2', 'Change password'),
      h('div.field', h('label.field__label', { for: 'pw-current' }, 'Current password'), cur),
      h('div.field', h('label.field__label', { for: 'pw-new' }, 'New password'), nw, h('p.field__help', { id: 'pw-new-help' }, 'At least 10 characters. A passphrase of a few random words works well, and password managers are fine to use.')),
      h('div.field', h('label.field__label', { for: 'pw-new2' }, 'Repeat new password'), nw2),
      h('button.button.button--primary', { type: 'submit' }, 'Update password'))));
  };

  views.plugins = function () {
    var list = h('div.plugin-list', B.plugins.length ? B.plugins.map(function (p) {
      var input = h('input', { type: 'checkbox', checked: p.enabled, 'aria-label': 'Enable ' + p.name, onchange: function () {
        var on = this.checked;
        api('toggle_plugin', { id: p.id, enabled: on }).then(function () { toast(p.name + (on ? ' enabled' : ' disabled')); setTimeout(function () { location.reload(); }, 600); })
          .catch(function (e) { toast(e.message, 'error'); input.checked = !on; });
      } });
      return h('div.plugin-card' + (p.enabled ? '.is-on' : ''),
        h('div.plugin-card__body', h('strong', p.name), h('span.muted', [p.version ? 'v' + p.version : '', p.author].filter(Boolean).join(' · ')), h('p', p.description)),
        h('label.switch', input, h('span.switch__track'), h('span.switch__text', p.enabled ? 'On' : 'Off')));
    }) : h('p.muted', 'No plugins installed. Add a folder to /plugins.'));
    app.replaceChildren(topbar('Plugins', 'Turn extra features on or off. Content added by a plugin is kept if you switch it off.', null),
      h('div.content', list, h('div.callout', h('p', h('strong', 'Installing a plugin: '), 'upload its folder to /plugins on the server and it will appear here. See README.md for how to write your own.'))));
  };

  views.collection = function () {
    var C = B.collection;
    if (B.item) return collectionEditor(C, B.item);
    var items = B.items.slice();
    var q = '';
    var list = h('div.page-list');
    function draw() {
      list.innerHTML = '';
      var shown = items.filter(function (it) { return !q || (it.title + ' ' + it.subtitle).toLowerCase().indexOf(q) > -1; });
      if (!items.length) list.appendChild(h('div.empty', h('p', 'No ' + C.label.toLowerCase() + ' yet.'), h('p.muted', 'Click “Add ' + C.singular + '” to create the first one.')));
      else if (!shown.length) list.appendChild(h('p.muted', 'Nothing matches “' + q + '”.'));
      shown.forEach(function (it) {
        var editHref = '?view=collection&c=' + encodeURIComponent(C.id) + '&item=' + encodeURIComponent(it.id);
        list.appendChild(h('div.page-row.item-row',
          h('a.item-thumb', { href: editHref, tabindex: '-1', 'aria-hidden': 'true' }, it.thumb ? h('img', { src: it.thumb, alt: '', loading: 'lazy' }) : icon('car')),
          h('a.page-row__main', { href: editHref }, h('strong', it.title), h('span.muted', [it.subtitle, 'edited ' + fmtDate(it.updated)].filter(Boolean).join(' · '))),
          h('div.page-row__tags', it.badge ? h('span.pill.pill--' + (it.badge.tone || 'plain'), it.badge.label) : null),
          h('div.page-row__actions',
            h('a.button.button--small', { href: editHref }, 'Edit', h('span.sr-only', ' ' + it.title)),
            it.url ? h('a.button.button--small.button--ghost', { href: it.url, target: '_blank' }, 'View', h('span.sr-only', ' ' + it.title)) : null,
            h('button.tool.tool--danger', { type: 'button', title: 'Delete ' + it.title, onclick: function () {
              if (!confirm('Delete “' + it.title + '”? A backup is kept. Its photos go to the trash (unless something else uses them) and come back if you restore it from Backups.')) return;
              api('delete_item', { collection: C.id, id: it.id }).then(function (r) { items = items.filter(function (x) { return x !== it; }); draw(); toast(trashNote('Deleted', r)); }).catch(function (e) { toast(e.message, 'error'); });
            } }, '✕'))));
      });
    }
    var search = h('input.search', { type: 'search', placeholder: 'Search ' + C.label.toLowerCase() + '…', oninput: function () { q = this.value.toLowerCase().trim(); draw(); } });
    draw();
    app.replaceChildren(
      topbar(C.label, items.length + ' ' + (items.length === 1 ? C.singular : C.label.toLowerCase()), h('a.button.button--primary', { href: '?view=collection&c=' + encodeURIComponent(C.id) + '&item=new' }, '+ Add ' + C.singular)),
      h('div.content', C.help ? h('div.callout', h('p', C.help)) : null, items.length > 4 ? search : null, list));
  };

  function collectionEditor(C, it) {
    var data = clone(it.data);
    var id = it.id;
    var back = '?view=collection&c=' + encodeURIComponent(C.id);
    var viewLink = h('a.button.button--ghost', { href: it.url || '#', target: '_blank', hidden: it.url ? null : true }, 'View on site ↗');
    var heading = h('h1', id ? 'Edit ' + C.singular : 'New ' + C.singular);
    var checker = C.a11y ? itemA11yPanel(C, data) : null; // re-runs after each save, like the page editor
    var actions = saveButton(function () {
      return api('save_item', { collection: C.id, id: id, item: data }).then(function (r) {
        if (checker) checker.run();
        if (!id) { id = r.id; history.replaceState(null, '', back + '&item=' + encodeURIComponent(id)); }
        heading.textContent = r.summary.title;
        if (r.summary.url) { viewLink.href = r.summary.url; viewLink.hidden = false; }
        return r;
      });
    });
    var bar = topbar('', null, [h('a.button.button--ghost', { href: back }, '← All ' + C.label.toLowerCase()), viewLink].concat(actions));
    bar.querySelector('.topbar__title').replaceChildren(heading);
    if (id) heading.textContent = (B.items.filter(function (x) { return x.id === id; })[0] || {}).title || heading.textContent;
    app.replaceChildren(bar, h('div.content', h('div.panel.item-panel', h('div.field-grid', renderFields(C.fields, data))), checker));
  }

  views.claude = function () {
    var C = B.connector;
    function redraw(next) { if (next) C = B.connector = next; draw(); }

    function draw() {
      var parts = [];

      var sw = h('input', { type: 'checkbox', id: 'claude-on', checked: C.enabled, 'aria-describedby': 'claude-on-help', onchange: function () {
        var on = sw.checked;
        api('connector_toggle', { enabled: on }).then(function (r) { toast(on ? 'Claude can now connect to this site' : 'Claude access turned off'); redraw(r.connector); })
          .catch(function (e) { toast(e.message, 'error'); sw.checked = !on; });
      } });
      parts.push(h('div.panel',
        h('h2', 'Claude access'),
        h('label.switch', sw, h('span.switch__track'), h('span.switch__text', 'Let Claude edit this website')),
        h('p.muted#claude-on-help', 'When this is on, you can ask Claude in a chat to change page text, add or update ' +
          (B.collections && B.collections.length ? B.collections.map(function (c) { return c.label.toLowerCase(); }).join(' and ') : 'listings') +
          ', add photos and change site settings. Claude has to sign in with a Bench login first. It can\'t change logins, plugins, updates, security settings or script code. Turning this off stops all access straight away.')));

      if (C.enabled) {
        var url = h('input', { type: 'text', id: 'claude-url', readonly: true, value: C.mcp_url, onfocus: function () { this.select(); } });
        var copy = h('button.button', { type: 'button', onclick: function () {
          (navigator.clipboard ? navigator.clipboard.writeText(C.mcp_url) : Promise.reject()).then(function () { toast('Address copied'); })
            .catch(function () { url.focus(); url.select(); toast('Press Ctrl+C (or ⌘C) to copy'); });
        } }, 'Copy');
        var check = h('p.muted', { role: 'status' }, 'Checking this server is set up for sign-in…');
        fetch('/.well-known/oauth-protected-resource', { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (j) {
          if (j.resource !== C.mcp_url) throw new Error();
          check.textContent = 'This server is set up correctly for Claude to sign in.';
        }).catch(function () {
          check.className = 'sec-summary is-warn';
          check.textContent = 'Claude won\'t be able to sign in yet: this server isn\'t passing /.well-known/ requests to Bench. Check the site has Bench\'s .htaccess (Apache/LiteSpeed) or the nginx rules in the README, and that Bench is at the root of the domain.';
        });
        parts.push(h('div.panel',
          h('h2', 'Connect Claude'),
          C.https ? null : h('p.sec-summary.is-warn', 'Claude can only connect to sites with an https:// address. Set up SSL for this site first.'),
          h('ol.steps',
            h('li', 'In Claude, open ', h('strong', 'Settings → Connectors'), ' and choose ', h('strong', 'Add custom connector'), '. (In a Team or Enterprise plan, an owner may need to add it under Organization settings.)'),
            h('li', 'Give it a name, such as "' + (B.siteName || 'My website') + '", and paste this address:',
              h('div.copy-row', h('label.sr-only', { for: 'claude-url' }, 'Connector address'), url, copy)),
            h('li', 'Click ', h('strong', 'Connect'), '. Log in with your Bench username and password on the page that opens, and click ', h('strong', 'Allow'), '.'),
            h('li', 'In a chat, turn the connector on from the tools menu and ask, for example: "Change the opening hours to 8am–5pm" or "Mark the blue Transit as sold".')),
          check));
      }

      if (C.clients.length) {
        parts.push(h('div.panel', h('h2', 'Connected'),
          h('ul.connector-list', C.clients.map(function (c) {
            var btn = h('button.button.button--small', { type: 'button', onclick: function () {
              if (!confirm('Disconnect ' + c.name + '? It will need to sign in again to make changes.')) return;
              api('connector_disconnect', { id: c.id }).then(function (r) { toast('Disconnected'); redraw(r.connector); }).catch(function (e) { toast(e.message, 'error'); });
            } }, 'Disconnect', h('span.sr-only', ' ' + c.name + (c.user ? ' (' + c.user + ')' : '')));
            return h('li.connector-client', h('span', h('strong', c.name), c.user ? ' — signed in as ' + c.user : '', h('br'),
              h('span.muted', c.last_used ? 'Last used ' + fmtDate(c.last_used) : 'Connected ' + fmtDate(c.created))), btn);
          })),
          C.clients.length > 1 ? h('p', h('button.button', { type: 'button', onclick: function () {
            if (!confirm('Disconnect every Claude connection to this site?')) return;
            api('connector_disconnect', { id: '*' }).then(function (r) { toast('All disconnected'); redraw(r.connector); }).catch(function (e) { toast(e.message, 'error'); });
          } }, 'Disconnect all')) : null));
      }

      parts.push(h('div.panel', h('h2', 'Changes made by Claude'),
        C.log.length ? h('ul.connector-list', C.log.map(function (l) {
          var undo = l.can_undo ? h('button.button.button--small', { type: 'button', onclick: function () {
            if (!confirm('Undo this change? ' + l.summary + '\n\nIf the same thing was changed again afterwards, those later changes are undone too.')) return;
            undo.disabled = true;
            api('connector_undo', { id: l.id }).then(function (r) { toast(r.message); redraw(r.connector); }).catch(function (e) { toast(e.message, 'error'); undo.disabled = false; });
          } }, 'Undo', h('span.sr-only', ': ' + l.summary)) : null;
          return h('li.connector-change',
            h('span', h('strong', fmtDate(l.time)), ' — ', l.summary, h('br'),
              h('span.muted', 'By ' + l.user + ' via ' + l.app + (l.undone ? ' · undone by ' + String(l.undone).split(' at ')[0] : '')),
              l.link && !l.undone ? [' · ', h('a', { href: l.link }, 'View', h('span.sr-only', ': ' + l.summary))] : null),
            undo);
        })) : h('p.muted', 'Nothing yet. Every change Claude makes is listed here, and can be undone. Older versions are also in Backups.')));

      app.replaceChildren(topbar('Connections', 'Let Claude make changes to this website when you ask in a chat.', null), h('div.content', parts));
    }
    draw();
  };

  views.security = function () {
    var R = B.report;
    var count = function (lvl) { return R.filter(function (r) { return r.level === lvl && !r.ack; }).length; };
    var crit = count('critical'), warn = count('warn');
    var label = { critical: 'Action needed', warn: 'Worth fixing', ok: 'OK', info: 'Info' };
    var summary = crit ? h('div.sec-summary.is-critical', h('strong', crit + ' critical issue' + (crit > 1 ? 's' : '')), ' — see below. Most need your hosting company to act, so the guidance is written so you can forward it to them.')
      : warn ? h('div.sec-summary.is-warn', h('strong', 'No critical issues. '), warn + ' thing' + (warn > 1 ? 's' : '') + ' worth tightening.')
      : h('div.sec-summary.is-ok', h('strong', 'All checks passed. '), 'Re-check after hosting changes or when Bench is updated.');
    var list = h('div.sec-list', R.map(function (r) {
      var lvl = r.ack ? 'info' : r.level;
      return h('div.sec-item.is-' + lvl,
        h('span.sec-item__tag', r.ack ? 'Acknowledged' : label[lvl]),
        h('div.sec-item__body',
          h('strong', r.title), h('p', r.detail),
          r.files ? h('ul.sec-files', r.files.map(function (f) { return h('li', h('code', f)); })) : null,
          r.url ? h('p', h('a', { href: r.url, target: '_blank', rel: 'noopener' }, 'Official advisory ↗')) : null));
    }));
    var ini = 'session.upload_progress.enabled = 0\ndisplay_errors = Off\nexpose_php = Off\nallow_url_include = Off\nsession.use_strict_mode = 1\npost_max_size = 8M\nupload_max_filesize = 6M';
    var host = h('div.panel',
      h('h2', 'For your hosting company'),
      h('p.muted', 'Bench can\'t update PHP itself — only the host can. If anything above is marked "Action needed", send them this:'),
      h('ol.sec-steps',
        h('li', 'Update PHP to a version with the latest security fixes (for PHP2Shell: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or newer, or a distribution build with the fix backported), then restart PHP-FPM / Apache.'),
        h('li', 'Set these PHP options for the site (in php.ini or the PHP-FPM pool — not .user.ini):'),
        h('li.sec-steps__code', h('pre', ini)),
        h('li', 'Confirm the site\'s .htaccess is honoured (AllowOverride All), or add the equivalent nginx rules from Bench\'s README.')),
      h('p.muted', 'PHP version reported here: ' + B.phpVersion + '. The admin checks this on every page and shows a red banner while a known critical fix is missing.'));
    app.replaceChildren(topbar('Security', 'Checks on this server and site. Re-run any time by reloading the page.', h('button.button', { type: 'button', onclick: function () { location.reload(); } }, 'Re-check')),
      h('div.content', summary, list, host));
  };

  // Release notes are Markdown from the changelog: render bullets and **bold** safely (no HTML).
  function notesView(md) {
    var out = h('div.update-notes'), list = null;
    function inline(text) {
      return text.split(/(\*\*[^*]+\*\*|`[^`]+`)/).map(function (part) {
        if (/^\*\*[^*]+\*\*$/.test(part)) return h('strong', part.slice(2, -2));
        if (/^`[^`]+`$/.test(part)) return h('code', part.slice(1, -1));
        return part;
      });
    }
    md.split(/\r?\n/).forEach(function (line) {
      var m = line.match(/^(\s*)[-*] (.*)$/);
      if (m) {
        if (!list) { list = h('ul'); out.appendChild(list); }
        var li = h('li', inline(m[2]));
        if (m[1].length >= 2 && list.lastChild && list.lastChild !== li) {
          var sub = list.lastChild.querySelector('ul') || list.lastChild.appendChild(h('ul'));
          sub.appendChild(li);
        } else list.appendChild(li);
      } else if (line.trim()) {
        list = null;
        out.appendChild(h('p', inline(line.trim())));
      }
    });
    return out;
  }

  views.updates = function () {
    var U = B.updates;
    function redraw(next) { if (next) U = B.updates = next; draw(); }
    function busy(btn, label) { btn.disabled = true; btn.textContent = label; }
    // After an update or undo the files (and version) have changed under this page,
    // so reload to show the new state; the outcome is shown at the top from the log.
    function result(r) {
      if (r.result && (r.result.ok || r.result.rolled_back)) { location.href = '?view=updates&done=' + Date.now(); return; }
      if (r.result) toast(r.result.message, 'error');
      redraw(r.updates);
    }

    function draw() {
      var A = U.available;
      var parts = [];
      var last = U.log[0];
      if (/[?&]done=/.test(location.search) && last) {
        var good = last.event === 'updated' || (last.event === 'rolled-back' && last.by === 'admin' && !/stopped working/.test(last.message));
        parts.push(h('div.sec-summary' + (good ? '.is-ok' : '.is-warn'), { role: 'status' }, h('strong', last.message),
          last.notes && last.notes.length ? h('ul', last.notes.map(function (n) { return h('li', n); })) : null));
      }

      if (!U.enabled) {
        parts.push(h('div.sec-summary.is-warn', h('strong', 'Updates are turned off for this site. '), 'Someone set \'updates\' => false in config.php — usually because the site is deployed from Git, so it\'s updated there instead.'));
      } else if (A && !A.blocked) {
        var install = h('button.button.button--primary', { type: 'button', onclick: function () {
          if (!confirm('Update Bench to ' + A.version + ' now? The site shows a "back in a moment" page for a few seconds while files are replaced. Your pages, settings, uploads and the custom/ folder aren\'t touched, and you can go back afterwards.')) return;
          busy(install, 'Updating… don\'t close this page');
          api('update_install', { version: A.version }).then(result).catch(function (e) { toast(e.message, 'error'); redraw(); });
        } }, 'Update to ' + A.version);
        parts.push(h('div.panel.update-card',
          h('h2', 'Bench ' + A.version + ' is available'),
          h('p.muted', 'This site has ' + U.version + '.' + (A.released ? ' Released ' + fmtDate(A.released) + '.' : '') +
            (A.patch ? ' This is a fix release.' : ' This is a feature release, so it waits for you to install it.') +
            (A.skipped ? ' It was undone or rolled back on this site before, so it won\'t install automatically.' : '')),
          A.notes ? h('details.update-card__notes', { open: true }, h('summary', 'What\'s new in ' + A.version), notesView(A.notes)) : null,
          h('p', install)));
      } else if (A && A.blocked) {
        parts.push(h('div.sec-summary.is-warn', h('strong', 'Bench ' + A.version + ' is available, but can\'t be installed here yet. '), A.blocked));
      } else {
        parts.push(h('div.sec-summary.is-ok', h('strong', 'Bench is up to date. '), 'This site has version ' + U.version + '.' + (U.checked ? ' Last checked ' + fmtDate(U.checked) + '.' : '')));
      }

      if (U.error) parts.push(h('div.sec-summary.is-warn', h('strong', 'The last check didn\'t work: '), U.error));
      if (U.problems.length) parts.push(h('div.panel', h('h2', 'Before updates can install here'), h('ul', U.problems.map(function (p) { return h('li', p); }))));

      // Automatic fix releases
      var auto = h('input', { type: 'checkbox', id: 'auto-updates', checked: U.auto === 'patch', disabled: U.git || !U.enabled ? true : null, 'aria-describedby': 'auto-updates-help', onchange: function () {
        api('update_settings', { auto: auto.checked ? 'patch' : 'off' }).then(function (r) { toast(auto.checked ? 'Fix releases will install automatically' : 'Automatic updates turned off'); redraw(r.updates); }).catch(function (e) { toast(e.message, 'error'); });
      } });
      parts.push(h('div.panel',
        h('h2', 'Automatic updates'),
        h('label.check', auto, h('span', 'Install fix releases automatically (for example 1.5.0 → 1.5.1)')),
        h('p.muted#auto-updates-help', U.git
          ? 'Turned off because this site is deployed with Git — update it through the repository instead.'
          : 'Fix releases only contain bug and security fixes, so they\'re safe to install without you. Bigger releases (1.5 → 1.6) always wait for you to click Update. Bench checks twice a day.')));

      // Undo the last update
      if (U.undo) {
        var undo = h('button.button', { type: 'button', onclick: function () {
          if (!confirm('Go back to Bench ' + U.undo.from + '? The files that changed in the update to ' + U.undo.to + ' are put back. Your content isn\'t affected.')) return;
          busy(undo, 'Going back…');
          api('update_rollback', { id: U.undo.id }).then(result).catch(function (e) { toast(e.message, 'error'); redraw(); });
        } }, 'Go back to ' + U.undo.from);
        parts.push(h('div.panel', h('h2', 'Undo the last update'),
          h('p.muted', 'Updated from ' + U.undo.from + ' to ' + U.undo.to + ' on ' + fmtDate(U.undo.time) + '. If something isn\'t right since then, you can put the previous version back.'),
          h('p', undo)));
      }

      // History
      if (U.log.length) {
        parts.push(h('div.panel', h('h2', 'Update history'),
          h('ul.update-log', U.log.map(function (l) {
            return h('li', h('strong', fmtDate(l.time)), ' — ', l.message, l.by ? ' (' + l.by + ')' : '',
              l.notes && l.notes.length ? h('ul', l.notes.map(function (n) { return h('li', n); })) : null);
          }))));
      }

      var check = h('button.button', { type: 'button', disabled: !U.enabled ? true : null, onclick: function () {
        busy(check, 'Checking…');
        api('update_check', { force: true }).then(function (r) { redraw(r.updates); toast(r.updates.available ? 'Bench ' + r.updates.available.version + ' is available' : (r.updates.error ? 'The check didn\'t work' : 'Bench is up to date')); })
          .catch(function (e) { toast(e.message, 'error'); redraw(); });
      } }, 'Check now');
      app.replaceChildren(topbar('Updates', 'New versions of Bench. Your content, uploads and the custom/ folder are never changed by an update.', check),
        h('div.content', parts));
    }
    draw();
  };

  // Check for Bench updates in the background when one is due (twice a day).
  if (B.updateCheckDue) {
    api('update_check', {}).then(function (r) {
      if (r.auto && r.auto.ok) toast(r.auto.message);
      if (B.view === 'updates') { B.updates = r.updates; views.updates(); }
    }).catch(function () { /* not worth bothering anyone about */ });
  }

  // Check whether short links (/vans) reach Bench on this server; warn if not.
  if (B.urls && B.urls.mode === 'auto' && !B.urls.ok) {
    fetch(B.base + '/bench-url-check?t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : 'no'; })
      .catch(function () { return 'no'; })
      .then(function (t) { if (t.trim() !== 'ok') document.getElementById('url-warning').hidden = false; });
  }

  (views[B.view] || views.pages)();
})();
