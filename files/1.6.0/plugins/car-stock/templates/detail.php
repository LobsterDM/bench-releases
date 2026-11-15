<?php
/**
 * Car detail page. Available: $item (the car), $collection, $site.
 * A theme can replace this with themes/<theme>/templates/cars-detail.php
 */
$car = $item;
$title = item_title($collection, $car);
$images = $car['images'];
$sold = $car['status'] === 'sold';
$reduced = $car['was_price'] !== '' && (float) $car['was_price'] > (float) $car['price'];
$listUrl = url($collection['route']);
$features = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $car['features']))));

$spec = array_filter([
    'Make'            => $car['make'],
    'Model'           => $car['model'],
    'Variant'         => $car['variant'],
    'Year'            => $car['year'],
    'Mileage'         => $car['mileage'] !== '' ? t('%s miles', number_format((float) $car['mileage'])) : '',
    'Fuel'            => $car['fuel'],
    'Gearbox'         => $car['gearbox'],
    'Engine'          => $car['engine'],
    'Body type'       => $car['body'],
    'Colour'          => $car['colour'],
    'Doors'           => $car['doors'],
    'Previous owners' => $car['owners'],
    'MOT until'       => $car['mot'] ? bench_month_year(strtotime($car['mot'])) : '',
    'Service history' => $car['history'],
    'ULEZ compliant'  => $car['ulez'] ? t('Yes') : '',
], fn($v) => $v !== '' && $v !== null);

// Similar cars: same body type or make, excluding this one
$similar = array_slice(array_values(array_filter(cars_query(['show_sold' => false]), fn($c) =>
    $c['id'] !== $car['id'] && ($c['body'] === $car['body'] || strcasecmp($c['make'], $car['make']) === 0))), 0, 3);

$ld = array_filter([
    '@context' => 'https://schema.org', '@type' => 'Car', 'name' => $title,
    'brand' => $car['make'] ? ['@type' => 'Brand', 'name' => $car['make']] : null,
    'model' => $car['model'], 'vehicleModelDate' => $car['year'], 'fuelType' => $car['fuel'],
    'vehicleTransmission' => $car['gearbox'], 'color' => $car['colour'], 'bodyType' => $car['body'],
    'mileageFromOdometer' => $car['mileage'] !== '' ? ['@type' => 'QuantitativeValue', 'value' => (float) $car['mileage'], 'unitCode' => 'SMI'] : null,
    'image' => array_map(fn($i) => media_url($i), $images) ?: null,
    'offers' => $car['price'] !== '' ? ['@type' => 'Offer', 'price' => (float) $car['price'], 'priceCurrency' => 'GBP',
        'availability' => $sold ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock', 'itemCondition' => 'https://schema.org/UsedCondition'] : null,
]);
?>
<section class="block car-detail">
  <div class="container">
    <nav class="car-crumbs" aria-label="<?= e(t('Breadcrumb')) ?>"><a href="<?= e($listUrl) ?>">← <?= e(t('All stock')) ?></a></nav>

    <div class="car-detail__top">
      <div class="car-gallery" data-car-gallery>
        <div class="car-gallery__main">
          <?php if ($images): ?>
            <a href="<?= e(media_url($images[0])) ?>" data-car-main><img src="<?= e(media_url($images[0])) ?>" alt="<?= e($title) ?><?= count($images) > 1 ? ', ' . e(t('photo %1$d of %2$d', 1, count($images))) : '' ?>" data-title="<?= e($title) ?>"><span class="sr-only"> – <?= e(t('view larger')) ?></span></a>
          <?php else: ?>
            <span class="car-card__noimg"><?= icon('car') ?></span>
          <?php endif; ?>
          <?php if ($sold): ?><span class="car-ribbon car-ribbon--sold"><?= e(t('Sold')) ?></span><?php elseif ($car['status'] === 'reserved'): ?><span class="car-ribbon car-ribbon--reserved"><?= e(t('Reserved')) ?></span><?php endif; ?>
        </div>
        <?php // hidden links so the shared lightbox can page through every photo
        foreach ($images as $img): ?><a href="<?= e(media_url($img)) ?>" data-lightbox data-caption="<?= e($title) ?>" hidden><img src="" alt="<?= e($title) ?>"></a><?php endforeach; ?>
        <?php if (count($images) > 1): ?>
          <div class="car-gallery__thumbs">
            <?php foreach ($images as $i => $img): ?>
              <button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-src="<?= e(media_url($img)) ?>" aria-label="<?= e(t('Show photo %1$d of %2$d', $i + 1, count($images))) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><img src="<?= e(media_url($img)) ?>" alt="" loading="lazy"></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="car-summary card">
        <h1 class="car-summary__title"><?= e($title) ?></h1>
        <?php if ($car['variant']): ?><p class="car-summary__variant"><?= e($car['variant']) ?></p><?php endif; ?>
        <div class="car-price car-price--lg">
          <?php if ($reduced && !$sold): ?><s><?= e(t('Was %s', '£' . number_format((float) $car['was_price']))) ?></s><?php endif; ?>
          <strong><?= e($sold ? t('Sold') : car_price($car)) ?></strong>
          <?php if ($car['finance'] && !$sold): ?><span><?= e($car['finance']) ?></span><?php endif; ?>
        </div>
        <ul class="car-keyspec">
          <?php foreach (car_spec_chips($car) as $ic => $txt): ?><li><?= icon($ic) ?><span><?= e($txt) ?></span></li><?php endforeach; ?>
          <?php if ($car['body']): ?><li><?= icon('car') ?><span><?= e(t($car['body'])) ?></span></li><?php endif; ?>
          <?php if ($car['owners'] !== ''): ?><li><?= icon('users') ?><span><?= e((int) $car['owners'] === 1 ? t('1 owner') : t('%d owners', (int) $car['owners'])) ?></span></li><?php endif; ?>
        </ul>
        <div class="car-summary__actions">
          <?php if (!$sold): ?><a class="btn btn--accent btn--lg" href="#enquire"><?= e(t('Enquire about this car')) ?></a><?php endif; ?>
          <?php if ($site['phone']): ?><a class="btn btn--outline btn--lg" href="<?= e(tel_link($site['phone'])) ?>"><?= icon('phone') ?> <?= e($site['phone']) ?></a><?php endif; ?>
        </div>
        <?php if ($site['cars_intro_note']): ?><p class="car-summary__note"><?= icon('shield') ?> <?= e($site['cars_intro_note']) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="car-detail__body">
      <div class="car-detail__main">
        <?php if (trim($car['description']) !== ''): ?>
          <h2 class="car-h2"><?= e(t('About this car')) ?></h2>
          <div class="prose"><?= rich($car['description']) ?></div>
        <?php endif; ?>

        <?php if ($features): ?>
          <h2 class="car-h2"><?= e(t('Features')) ?></h2>
          <ul class="car-features"><?php foreach ($features as $ft): ?><li><?= icon('check') ?><?= e($ft) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <h2 class="car-h2"><?= e(t('Specification')) ?></h2>
        <dl class="car-spec"><?php foreach ($spec as $k => $v): ?><div><dt><?= e(t($k)) ?></dt><dd><?= e(t((string) $v)) ?></dd></div><?php endforeach; ?></dl>
        <?php if ($car['finance'] && $site['cars_finance_note']): ?><p class="car-small"><?= e($site['cars_finance_note']) ?></p><?php endif; ?>
      </div>

      <div class="car-detail__side" id="enquire">
        <?php if (!$sold): ?>
          <div class="car-enquire">
            <h2 class="car-h2"><?= e(t('Enquire about this car')) ?></h2>
            <?= bench_contact_form([
                'subject'  => 'Car enquiry: ' . $title . ($car['reg'] ? ' (' . $car['reg'] . ')' : ''),
                'hidden'   => ['vehicle' => $title . ($car['variant'] ? ' ' . $car['variant'] : '') . ' — ' . car_price($car) . ($car['reg'] ? ' — reg ' . $car['reg'] : '')],
                'message'  => t('Hi, I’m interested in the %s. Is it still available?', $title),
                'services' => false,
                'fields'   => !empty($site['cars_part_exchange']) ? [['name' => 'part_exchange', 'label' => t('Part exchange? Your reg & mileage (optional)')]] : [],
                'button'   => t('Send enquiry'),
            ]) ?>
          </div>
        <?php else: ?>
          <div class="notice"><p><strong><?= e(t('This car has been sold.')) ?></strong></p><p><?= sprintf(e(t('Looking for something similar? %s or call us — we can source cars to order.')), '<a href="' . e($listUrl) . '">' . e(t('Browse our current stock')) . '</a>') ?></p></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($similar): ?>
      <div class="car-similar">
        <h2 class="car-h2"><?= e(t('You might also like')) ?></h2>
        <div class="car-grid"><?php foreach ($similar as $s) echo car_card($s); ?></div>
      </div>
    <?php endif; ?>
  </div>
</section>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
