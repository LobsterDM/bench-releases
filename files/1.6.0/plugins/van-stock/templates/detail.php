<?php
/**
 * Vehicle page. Available: $item (the vehicle), $collection, $site.
 * A theme can replace this with themes/<theme>/templates/vans-detail.php
 */
$v = $item;
$title = item_title($collection, $v);
$images = $v['images'];
$sold = $v['status'] === 'sold';
$reduced = van_is_reduced($v);
$vat = van_vat($v);
$listUrl = url($collection['route']);
$features = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $v['features']))));

$spec = array_filter([
    'Stock no.'       => $v['stock_no'] ?? '',
    'Make'            => $v['make'],
    'Model'           => $v['model'],
    'Variant'         => $v['variant'],
    'Type'            => $v['body'],
    'Year'            => $v['year'],
    'Mileage'         => $v['mileage'] !== '' ? t('%s miles', number_format((float) $v['mileage'])) : '',
    'Fuel'            => $v['fuel'],
    'Gearbox'         => $v['gearbox'],
    'Engine'          => $v['engine'],
    'Wheelbase'       => $v['wheelbase'],
    'Roof'            => $v['roof'],
    'Seats'           => $v['seats'],
    'Payload'         => $v['payload'] !== '' ? t('%s kg', number_format((float) $v['payload'])) : '',
    'Colour'          => $v['colour'],
    'Previous owners' => $v['owners'],
    'MOT until'       => $v['mot'] ? bench_month_year(strtotime($v['mot'])) : '',
    'Service history' => $v['history'],
    'ULEZ compliant'  => $v['ulez'] ? t('Yes') : '',
], fn($x) => $x !== '' && $x !== null);

// Similar: same type or make, excluding this one
$similar = array_slice(array_values(array_filter(vans_query(['show_sold' => false]), fn($o) =>
    $o['id'] !== $v['id'] && (($v['body'] !== '' && $o['body'] === $v['body']) || strcasecmp($o['make'], $v['make']) === 0))), 0, 3);

$priceLine = van_price($v) . ($vat ? ' ' . $vat : '');
$ld = array_filter([
    '@context' => 'https://schema.org', '@type' => 'Vehicle', 'name' => $title,
    'brand' => $v['make'] ? ['@type' => 'Brand', 'name' => $v['make']] : null,
    'model' => $v['model'], 'vehicleModelDate' => $v['year'], 'fuelType' => $v['fuel'],
    'vehicleTransmission' => $v['gearbox'], 'color' => $v['colour'], 'bodyType' => $v['body'],
    'vehicleSeatingCapacity' => $v['seats'] !== '' ? (int) $v['seats'] : null,
    'mileageFromOdometer' => $v['mileage'] !== '' ? ['@type' => 'QuantitativeValue', 'value' => (float) $v['mileage'], 'unitCode' => 'SMI'] : null,
    'image' => array_map(fn($i) => media_url($i), $images) ?: null,
    'offers' => $v['price'] !== '' ? array_filter(['@type' => 'Offer',
        'price' => (float) $v['price'], 'priceCurrency' => 'GBP',
        'priceSpecification' => ['@type' => 'PriceSpecification', 'price' => (float) $v['price'], 'priceCurrency' => 'GBP',
            'valueAddedTaxIncluded' => ($v['vat'] ?? 'plus') !== 'plus'],
        'availability' => $sold ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
        'itemCondition' => 'https://schema.org/UsedCondition']) : null,
]);
?>
<section class="block car-detail van-detail">
  <div class="container">
    <nav class="car-crumbs" aria-label="<?= e(t('Breadcrumb')) ?>"><a href="<?= e($listUrl) ?>">← <?= e(t('All vehicles')) ?></a></nav>

    <div class="car-detail__top">
      <div class="car-gallery" data-car-gallery>
        <div class="car-gallery__main">
          <?php if ($images): ?>
            <a href="<?= e(media_url($images[0])) ?>" data-car-main><img src="<?= e(media_url($images[0])) ?>" alt="<?= e($title) ?><?= count($images) > 1 ? ', ' . e(t('photo %1$d of %2$d', 1, count($images))) : '' ?>" data-title="<?= e($title) ?>"><span class="sr-only"> – <?= e(t('view larger')) ?></span></a>
          <?php else: ?>
            <span class="car-card__noimg"><?= icon('van') ?></span>
          <?php endif; ?>
          <?php if ($sold): ?><span class="car-ribbon car-ribbon--sold"><?= e(t('Sold')) ?></span><?php elseif ($v['status'] === 'reserved'): ?><span class="car-ribbon car-ribbon--reserved"><?= e(t('Reserved')) ?></span><?php endif; ?>
        </div>
        <?php foreach ($images as $img): ?><a href="<?= e(media_url($img)) ?>" data-lightbox data-caption="<?= e($title) ?>" hidden><img src="" alt="<?= e($title) ?>"></a><?php endforeach; ?>
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
        <?php if ($v['variant']): ?><p class="car-summary__variant"><?= e($v['variant']) ?></p><?php endif; ?>
        <div class="car-price car-price--lg">
          <?php if ($reduced && !$sold): ?><s><?= e(t('Was %s', '£' . number_format((float) $v['was_price']))) ?></s><?php endif; ?>
          <strong<?= !$sold && $v['price'] === '' ? ' class="van-poa"' : '' ?>><?= e($sold ? t('Sold') : van_price($v)) ?><?php if ($vat && !$sold): ?> <small class="van-vat"><?= e($vat) ?></small><?php endif; ?></strong>
          <?php if ($v['finance'] && !$sold): ?><span><?= e($v['finance']) ?></span><?php endif; ?>
        </div>
        <ul class="car-keyspec">
          <?php foreach (van_spec_chips($v) as $ic => $txt): ?><li><?= icon($ic) ?><span><?= e($txt) ?></span></li><?php endforeach; ?>
          <?php if ($v['body']): ?><li><?= icon('van') ?><span><?= e(t($v['body'])) ?></span></li><?php endif; ?>
          <?php if ($v['seats'] !== ''): ?><li><?= icon('users') ?><span><?= e(t('%d seats', (int) $v['seats'])) ?></span></li><?php endif; ?>
        </ul>
        <div class="car-summary__actions">
          <?php if (!$sold): ?><a class="btn btn--accent btn--lg" href="#enquire"><?= e(t('Enquire about this vehicle')) ?></a><?php endif; ?>
          <?php if ($site['phone']): ?><a class="btn btn--outline btn--lg" href="<?= e(tel_link($site['phone'])) ?>"><?= icon('phone') ?> <?= e($site['phone']) ?></a><?php endif; ?>
        </div>
        <ul class="van-detail-notes">
          <?php if ($site['vans_intro_note']): ?><li><?= icon('shield') ?><span><?= e($site['vans_intro_note']) ?></span></li><?php endif; ?>
          <?php if ($site['vans_finance_note'] && !$sold): ?><li><?= icon('pound') ?><span><?= e($site['vans_finance_note']) ?></span></li><?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="car-detail__body">
      <div class="car-detail__main">
        <?php if (trim($v['description']) !== ''): ?>
          <h2 class="car-h2"><?= e(t('About this vehicle')) ?></h2>
          <div class="prose"><?= rich($v['description']) ?></div>
        <?php endif; ?>

        <?php if ($features): ?>
          <h2 class="car-h2"><?= e(t('Features')) ?></h2>
          <ul class="car-features"><?php foreach ($features as $ft): ?><li><?= icon('check') ?><?= e($ft) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <h2 class="car-h2"><?= e(t('Specification')) ?></h2>
        <dl class="car-spec"><?php foreach ($spec as $k => $val): ?><div><dt><?= e(t($k)) ?></dt><dd><?= e(t((string) $val)) ?></dd></div><?php endforeach; ?></dl>
        <?php if ($site['vans_vat_note']): ?><p class="car-small"><?= e($site['vans_vat_note']) ?></p><?php endif; ?>
      </div>

      <div class="car-detail__side" id="enquire">
        <?php if (!$sold): ?>
          <div class="car-enquire">
            <h2 class="car-h2"><?= e(t('Enquire about this vehicle')) ?></h2>
            <?= bench_contact_form([
                'subject'  => 'Vehicle enquiry: ' . $title . (!empty($v['stock_no']) ? ' — stock ' . $v['stock_no'] : '') . ($v['reg'] ? ' (' . $v['reg'] . ')' : ''),
                'hidden'   => ['vehicle' => $title . ($v['variant'] ? ' ' . $v['variant'] : '') . (!empty($v['stock_no']) ? ' — stock ' . $v['stock_no'] : '') . ' — ' . $priceLine . ($v['reg'] ? ' — reg ' . $v['reg'] : '')],
                'message'  => t('Hi, I’m interested in the %s. Is it still available?', $title),
                'services' => false,
                'fields'   => !empty($site['vans_part_exchange']) ? [['name' => 'part_exchange', 'label' => t('Part exchange? Your reg & mileage (optional)')]] : [],
                'button'   => t('Send enquiry'),
            ]) ?>
          </div>
        <?php else: ?>
          <div class="notice"><p><strong><?= e(t('This vehicle has been sold.')) ?></strong></p><p><?= sprintf(e(t('Looking for something similar? %s or give us a call — if we haven’t got what you want, we’ll find it for you.')), '<a href="' . e($listUrl) . '">' . e(t('Browse our current stock')) . '</a>') ?></p></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($similar): ?>
      <div class="car-similar">
        <h2 class="car-h2"><?= e(t('You might also like')) ?></h2>
        <div class="car-grid"><?php foreach ($similar as $s) echo van_card($s); ?></div>
      </div>
    <?php endif; ?>
  </div>
</section>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
