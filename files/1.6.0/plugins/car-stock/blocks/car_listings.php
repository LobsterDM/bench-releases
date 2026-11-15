<?php
/**
 * "Car listings" section. Filters use plain GET parameters so results are
 * linkable and work without JavaScript.
 */
$filters = [];
if (!empty($b['show_filters'])) {
    foreach (['make', 'body', 'fuel', 'gearbox', 'max_price', 'sort'] as $k) {
        $filters[$k] = isset($_GET[$k]) && is_string($_GET[$k]) ? mb_substr(trim($_GET[$k]), 0, 40) : '';
    }
}
$cars = cars_query([
    'featured_only' => !empty($b['featured_only']),
    'show_sold'     => !empty($b['show_sold']),
    'limit'         => (int) $b['limit'],
    'filters'       => $filters,
]);
$all = items_all('cars', true);
$makes = array_values(array_unique(array_filter(array_column($all, 'make'))));
sort($makes);
$id = $anchor ?: 'stock';
$active = array_filter($filters, fn($v, $k) => $v !== '' && $k !== 'sort', ARRAY_FILTER_USE_BOTH);
?>
<?= section_open('car_listings', $b, $id) ?>
  <div class="container">
    <?= section_head($b) ?>

    <?php if (!empty($b['show_filters'])): ?>
      <details class="car-filters-wrap" open data-collapse-mobile="<?= $active ? '0' : '1' ?>">
      <summary><?= e(t('Filter & sort')) ?><?= $active ? ' (' . count($active) . ')' : '' ?></summary>
      <form class="car-filters" method="get" action="#<?= e($id) ?>">
        <?php if (!pretty_urls()): ?><input type="hidden" name="p" value="<?= e(url_param($page['slug'] ?? 'home')) ?>"><?php endif; ?>
        <label><span><?= e(t('Make')) ?></span>
          <select name="make"><option value=""><?= e(t('Any make')) ?></option>
            <?php foreach ($makes as $m): ?><option<?= strcasecmp($filters['make'], $m) === 0 ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
          </select></label>
        <label><span><?= e(t('Body type')) ?></span>
          <select name="body"><option value=""><?= e(t('Any')) ?></option>
            <?php foreach (CARS_BODIES as $v): ?><option<?= $filters['body'] === $v ? ' selected' : '' ?> value="<?= e($v) ?>"><?= e(t($v)) ?></option><?php endforeach; ?>
          </select></label>
        <label><span><?= e(t('Fuel')) ?></span>
          <select name="fuel"><option value=""><?= e(t('Any')) ?></option>
            <?php foreach (CARS_FUELS as $v): ?><option<?= $filters['fuel'] === $v ? ' selected' : '' ?> value="<?= e($v) ?>"><?= e(t($v)) ?></option><?php endforeach; ?>
          </select></label>
        <label><span><?= e(t('Gearbox')) ?></span>
          <select name="gearbox"><option value=""><?= e(t('Any')) ?></option>
            <?php foreach (['Manual', 'Automatic'] as $v): ?><option<?= $filters['gearbox'] === $v ? ' selected' : '' ?> value="<?= e($v) ?>"><?= e(t($v)) ?></option><?php endforeach; ?>
          </select></label>
        <label><span><?= e(t('Max price')) ?></span>
          <select name="max_price"><option value=""><?= e(t('No max')) ?></option>
            <?php foreach ([5000, 7500, 10000, 12500, 15000, 20000, 25000, 30000] as $v): ?><option value="<?= $v ?>"<?= (string) $filters['max_price'] === (string) $v ? ' selected' : '' ?>>£<?= number_format($v) ?></option><?php endforeach; ?>
          </select></label>
        <label><span><?= e(t('Sort by')) ?></span>
          <select name="sort">
            <?php foreach (['newest' => 'Newest in', 'price-asc' => 'Price: low to high', 'price-desc' => 'Price: high to low', 'miles' => 'Lowest mileage', 'year' => 'Newest car'] as $v => $l): ?>
              <option value="<?= e($v) ?>"<?= $filters['sort'] === $v ? ' selected' : '' ?>><?= e(t($l)) ?></option>
            <?php endforeach; ?>
          </select></label>
        <div class="car-filters__actions">
          <button class="btn btn--accent" type="submit"><?= e(t('Search')) ?></button>
          <?php if ($active): ?><a class="car-filters__clear" href="?<?= pretty_urls() ? '' : 'p=' . e(url_param($page['slug'] ?? 'home')) ?>#<?= e($id) ?>"><?= e(t('Clear filters')) ?></a><?php endif; ?>
        </div>
      </form>
      </details>
      <?php $n = count($cars); ?>
      <p class="car-count"><?= e($active ? ($n === 1 ? t('1 car matches your search') : t('%d cars match your search', $n)) : ($n === 1 ? t('1 car in stock') : t('%d cars in stock', $n))) ?></p>
    <?php endif; ?>

    <?php if ($cars): ?>
      <div class="car-grid">
        <?php foreach ($cars as $car) echo car_card($car, item_heading_tag($b)); ?>
      </div>
    <?php else: ?>
      <div class="notice car-empty">
        <p><strong><?= e($active ? t('No cars match those filters.') : t('New stock arriving soon.')) ?></strong></p>
        <?php $tel = '<a href="' . e(tel_link($site['phone'])) . '">' . e($site['phone']) . '</a>'; ?>
        <p><?= sprintf(e($active ? t('Try widening your search, or call us on %s — we can source cars to order.') : t('Call us on %s — we can source cars to order.')), $tel) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($b['button_text']): ?>
      <p class="car-more"><a class="btn btn--accent btn--lg" href="<?= e(link_to($b['button_link'])) ?>"><?= e($b['button_text']) ?></a></p>
    <?php endif; ?>
  </div>
</section>
