<?php
/**
 * "Van listings" section. Filters use plain GET parameters so results are
 * linkable and work without JavaScript.
 */
$filters = [];
if (!empty($b['show_filters'])) {
    foreach (['make', 'body', 'fuel', 'gearbox', 'max_price', 'sort'] as $k) {
        $filters[$k] = isset($_GET[$k]) && is_string($_GET[$k]) ? mb_substr(trim($_GET[$k]), 0, 40) : '';
    }
}
$vans = vans_query([
    'featured_only' => !empty($b['featured_only']),
    'show_sold'     => !empty($b['show_sold']),
    'limit'         => (int) $b['limit'],
    'filters'       => $filters,
]);
$all = items_all('vans', true);
$makes = array_values(array_unique(array_filter(array_column($all, 'make'))));
sort($makes);
$id = $anchor ?: 'stock';
$site = $site ?? site();
$active = array_filter($filters, fn($v, $k) => $v !== '' && $k !== 'sort', ARRAY_FILTER_USE_BOTH);
?>
<?= section_open('van_listings', $b, $id) ?>
  <div class="container">
    <?= section_head($b) ?>

    <?php if (!empty($b['show_filters'])): ?>
      <details class="car-filters-wrap" open data-collapse-mobile="<?= $active ? '0' : '1' ?>">
      <summary>Filter &amp; sort<?= $active ? ' (' . count($active) . ')' : '' ?></summary>
      <form class="car-filters" method="get" action="#<?= e($id) ?>">
        <?php if (!pretty_urls()): ?><input type="hidden" name="p" value="<?= e($page['slug'] ?? '') ?>"><?php endif; ?>
        <label><span>Make</span>
          <select name="make"><option value="">Any make</option>
            <?php foreach ($makes as $m): ?><option<?= strcasecmp($filters['make'], $m) === 0 ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
          </select></label>
        <label><span>Type</span>
          <select name="body"><option value="">Any type</option>
            <?php foreach (VANS_BODIES as $v): ?><option<?= $filters['body'] === $v ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
          </select></label>
        <label><span>Fuel</span>
          <select name="fuel"><option value="">Any</option>
            <?php foreach (VANS_FUELS as $v): ?><option<?= $filters['fuel'] === $v ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
          </select></label>
        <label><span>Gearbox</span>
          <select name="gearbox"><option value="">Any</option>
            <?php foreach (['Manual', 'Automatic'] as $v): ?><option<?= $filters['gearbox'] === $v ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
          </select></label>
        <label><span>Max price</span>
          <select name="max_price"><option value="">No max</option>
            <?php foreach ([3000, 5000, 7500, 10000, 12500, 15000, 20000, 25000] as $v): ?><option value="<?= $v ?>"<?= (string) $filters['max_price'] === (string) $v ? ' selected' : '' ?>>£<?= number_format($v) ?></option><?php endforeach; ?>
          </select></label>
        <label><span>Sort by</span>
          <select name="sort">
            <?php foreach (['newest' => 'Newest in', 'price-asc' => 'Price: low to high', 'price-desc' => 'Price: high to low', 'miles' => 'Lowest mileage', 'year' => 'Newest first (by year)'] as $v => $l): ?>
              <option value="<?= e($v) ?>"<?= $filters['sort'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select></label>
        <div class="car-filters__actions">
          <button class="btn btn--accent" type="submit">Search</button>
          <?php if ($active): ?><a class="car-filters__clear" href="?<?= pretty_urls() ? '' : 'p=' . e(rawurlencode($page['slug'] ?? '')) ?>#<?= e($id) ?>">Clear filters</a><?php endif; ?>
        </div>
      </form>
      </details>
      <p class="car-count"><?= count($vans) ?> <?= count($vans) === 1 ? 'vehicle' : 'vehicles' ?> <?= $active ? (count($vans) === 1 ? 'matches' : 'match') . ' your search' : 'in stock' ?></p>
    <?php endif; ?>

    <?php if ($vans): ?>
      <div class="car-grid">
        <?php foreach ($vans as $v) echo van_card($v, item_heading_tag($b)); ?>
      </div>
    <?php else: ?>
      <div class="notice car-empty">
        <p><strong><?= $active ? 'No vehicles match those filters.' : 'New stock arriving soon.' ?></strong></p>
        <p><?= $active ? 'Try widening your search, or ' : '' ?>Call us on <a href="<?= e(tel_link($site['phone'])) ?>"><?= e($site['phone']) ?></a> — if we haven't got what you want, we'll find it for you.</p>
      </div>
    <?php endif; ?>

    <?php if ($vans && !empty($site['vans_vat_note'])): ?><p class="van-vatnote"><?= e($site['vans_vat_note']) ?></p><?php endif; ?>

    <?php if ($b['button_text']): ?>
      <p class="car-more"><a class="btn btn--accent btn--lg" href="<?= e(link_to($b['button_link'])) ?>"><?= e($b['button_text']) ?></a></p>
    <?php endif; ?>
  </div>
</section>
