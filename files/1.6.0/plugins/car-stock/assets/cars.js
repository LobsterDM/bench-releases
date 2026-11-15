/* Car Stock plugin — photo gallery on car pages */
(function () {
  'use strict';
  document.querySelectorAll('[data-car-gallery]').forEach(function (g) {
    var main = g.querySelector('[data-car-main]');
    var thumbs = Array.prototype.slice.call(g.querySelectorAll('.car-gallery__thumbs button'));
    var boxLinks = Array.prototype.slice.call(g.querySelectorAll('[data-lightbox]'));
    var current = 0;
    function show(i) {
      if (!thumbs.length) return;
      current = (i + thumbs.length) % thumbs.length;
      var src = thumbs[current].getAttribute('data-src');
      var im = main.querySelector('img');
      im.src = src; main.href = src;
      var base = im.getAttribute('data-title');
      im.alt = base && window.benchT ? base + ', ' + window.benchT('photo %1$d of %2$d', current + 1, thumbs.length) : im.alt.replace(/photo \d+ of/, 'photo ' + (current + 1) + ' of');
      thumbs.forEach(function (t, j) { t.classList.toggle('is-active', j === current); t.setAttribute('aria-pressed', j === current ? 'true' : 'false'); });
      thumbs[current].scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    }
    thumbs.forEach(function (t, i) { t.addEventListener('click', function () { show(i); }); });
    if (main) main.addEventListener('click', function (e) {
      e.preventDefault();
      if (boxLinks[current]) boxLinks[current].click();
    });
    // Swipe on the main photo (phones)
    var x0 = null;
    g.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    g.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
    });
  });
  // Collapse the filter panel on phones unless a filter is active
  document.querySelectorAll('[data-collapse-mobile="1"]').forEach(function (d) {
    if (window.matchMedia('(max-width: 620px)').matches) d.open = false;
  });
  // Filters submit only when the Search button is pressed: changing a dropdown
  // must not reload the page by itself (WCAG 3.2.2 / 3.2.5).
})();
