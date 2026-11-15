<?php
/**
 * Car Stock plugin
 *
 * Registers:
 *   - a "cars" collection (Admin → Cars) with detail pages at /cars/<car-id>
 *   - a "Car listings" section for the page editor (filterable stock list or featured cars)
 *   - a "Car stock" tab in Settings
 *
 * Shows how a plugin can add a whole feature without touching core files.
 */

const CARS_FUELS = ['Petrol', 'Diesel', 'Hybrid', 'Plug-in hybrid', 'Electric'];
const CARS_BODIES = ['Hatchback', 'Saloon', 'Estate', 'SUV', 'Coupe', 'Convertible', 'MPV', 'Pick-up', 'Van'];
const CARS_STATUS = ['available', 'reserved', 'sold', 'draft'];

register_collection('cars', [
    'label'       => 'Cars',
    'singular'    => 'car',
    'icon'        => 'car',
    'route'       => 'cars',               // detail pages live at /cars/<id>; make a page with the slug "cars" for the list
    'image_field' => 'images',
    'sort'        => ['updated', 'desc'],
    'title'       => '{year} {make} {model}',
    'subtitle'    => fn($c) => implode(' · ', array_filter([
        $c['variant'],
        $c['mileage'] !== '' ? number_format((float) $c['mileage']) . ' miles' : '',
        $c['price'] !== '' ? '£' . number_format((float) $c['price']) : '',
    ])),
    'is_public'   => fn($c) => $c['status'] !== 'draft',
    'badge'       => fn($c) => [
        'available' => ['label' => 'Available', 'tone' => 'ok'],
        'reserved'  => ['label' => 'Reserved', 'tone' => 'warn'],
        'sold'      => ['label' => 'Sold', 'tone' => 'danger'],
        'draft'     => ['label' => 'Draft (hidden)', 'tone' => 'plain'],
    ][$c['status']] ?? null,
    'detail_template' => __DIR__ . '/templates/detail.php',
    'fields' => [
        f('_h_vehicle', 'Vehicle', 'heading'),
        f('make', 'Make', 'text', ['width' => 'third', 'placeholder' => 'Ford', 'translate' => false]),
        f('model', 'Model', 'text', ['width' => 'third', 'placeholder' => 'Focus', 'translate' => false]),
        f('variant', 'Variant / trim', 'text', ['width' => 'third', 'placeholder' => '1.0 EcoBoost Titanium', 'translate' => false]),
        f('year', 'Year', 'number', ['width' => 'third', 'placeholder' => '2019']),
        f('price', 'Price', 'number', ['width' => 'third', 'prefix' => '£']),
        f('was_price', 'Was (optional)', 'number', ['width' => 'third', 'prefix' => '£', 'help' => 'Shows as a reduced price']),
        f('status', 'Status', 'select', ['width' => 'half', 'options' => CARS_STATUS, 'labels' => ['available' => 'Available', 'reserved' => 'Reserved', 'sold' => 'Sold', 'draft' => 'Draft (hidden from site)'], 'default' => 'available', 'help' => 'Sold cars stay on the site with a SOLD banner. Draft hides the car.']),
        f('finance', 'Finance line (optional)', 'text', ['width' => 'half', 'placeholder' => 'From £189/month']),
        f('featured', 'Feature on the home page', 'checkbox', ['width' => 'half']),
        f('reg', 'Registration (private)', 'text', ['width' => 'half', 'translate' => false, 'help' => 'Sent with enquiries so you know which car — never shown on the site']),

        f('_h_spec', 'Specification', 'heading'),
        f('mileage', 'Mileage', 'number', ['width' => 'third', 'suffix' => 'miles']),
        f('fuel', 'Fuel', 'select', ['width' => 'third', 'options' => CARS_FUELS]),
        f('gearbox', 'Gearbox', 'select', ['width' => 'third', 'options' => ['Manual', 'Automatic']]),
        f('body', 'Body type', 'select', ['width' => 'third', 'options' => CARS_BODIES]),
        f('engine', 'Engine', 'text', ['width' => 'third', 'placeholder' => '1.0L', 'translate' => false]),
        f('colour', 'Colour', 'text', ['width' => 'third']),
        f('doors', 'Doors', 'select', ['width' => 'third', 'options' => ['', '2', '3', '4', '5']]),
        f('owners', 'Previous owners', 'number', ['width' => 'third']),
        f('mot', 'MOT expiry', 'date', ['width' => 'third']),
        f('history', 'Service history', 'select', ['width' => 'half', 'options' => ['', 'Full service history', 'Full dealer history', 'Part service history', 'No history']]),
        f('ulez', 'ULEZ / Clean Air Zone compliant', 'checkbox', ['width' => 'half']),

        f('_h_photos', 'Photos & description', 'heading'),
        f('images', 'Photos', 'gallery'),
        f('description', 'Description', 'richtext'),
        f('features', 'Features & extras', 'textarea', ['help' => 'One per line, e.g. "Heated seats"']),
        f('meta_description', 'Search engine description (optional)', 'textarea'),
    ],
]);

register_block('car_listings', [
    'label'       => 'Car listings',
    'description' => 'Your stock from Admin → Cars, with optional search filters',
    'fields' => [
        f('heading', 'Heading'),
        f('intro', 'Intro', 'textarea'),
        f('show_filters', 'Show search filters', 'checkbox', ['default' => true]),
        f('featured_only', 'Only cars marked "feature on home page"', 'checkbox', ['default' => false]),
        f('limit', 'Maximum cars to show (0 = all)', 'text', ['default' => '0', 'translate' => false]),
        f('show_sold', 'Include sold cars (shown last)', 'checkbox', ['default' => true]),
        f('button_text', 'Button under the list', 'text', ['help' => 'e.g. "View all stock"']),
        f('button_link', 'Button link', 'link', ['default' => 'cars']),
    ],
]);

register_settings('Car stock', [
    f('cars_intro_note', 'Note on every car page', 'textarea', ['default' => 'Every car is HPI checked, comes with a fresh service and a 3-month warranty. Part exchange welcome.']),
    f('cars_finance_note', 'Finance disclaimer', 'textarea', ['help' => 'Shown on car pages when a finance line is set', 'default' => 'Finance subject to status. Representative example available on request. We are a credit broker, not a lender.']),
    f('cars_part_exchange', 'Ask for part-exchange details on enquiry forms', 'checkbox', ['default' => true]),
]);

add_hook('head', function () {
    return '<link rel="stylesheet" href="' . e(plugin_asset(__DIR__, 'assets/cars.css')) . '">' . "\n";
});
add_hook('footer', function () {
    return '<script src="' . e(plugin_asset(__DIR__, 'assets/cars.js')) . '" defer></script>';
});

/* ---------- Helpers used by the templates ---------- */

function car_price(array $car): string
{
    return $car['price'] !== '' ? '£' . number_format((float) $car['price']) : t('POA');
}

function car_spec_chips(array $car): array
{
    return array_filter([
        'calendar' => $car['year'],
        'gauge'    => $car['mileage'] !== '' ? t('%s mi', number_format((float) $car['mileage'])) : '',
        'fuel'     => $car['fuel'] !== '' ? t($car['fuel']) : '',
        'gear'     => $car['gearbox'] !== '' ? t($car['gearbox']) : '',
    ]);
}

/** Stock list with filters applied from the query string. */
function cars_query(array $opts): array
{
    $cars = items_all('cars', true);
    $q = $opts['filters'] ?? [];
    $cars = array_filter($cars, function ($c) use ($opts, $q) {
        if (!empty($opts['featured_only']) && empty($c['featured'])) return false;
        if (empty($opts['show_sold']) && $c['status'] === 'sold') return false;
        if (!empty($q['make']) && strcasecmp($c['make'], $q['make']) !== 0) return false;
        if (!empty($q['body']) && $c['body'] !== $q['body']) return false;
        if (!empty($q['fuel']) && $c['fuel'] !== $q['fuel']) return false;
        if (!empty($q['gearbox']) && $c['gearbox'] !== $q['gearbox']) return false;
        if (!empty($q['max_price']) && (float) $c['price'] > (float) $q['max_price']) return false;
        return true;
    });
    $sort = $q['sort'] ?? 'newest';
    usort($cars, function ($a, $b) use ($sort) {
        // Sold cars always go to the end
        $s = ($a['status'] === 'sold') <=> ($b['status'] === 'sold');
        if ($s !== 0) return $s;
        switch ($sort) {
            case 'price-asc':  return (float) $a['price'] <=> (float) $b['price'];
            case 'price-desc': return (float) $b['price'] <=> (float) $a['price'];
            case 'miles':      return (float) $a['mileage'] <=> (float) $b['mileage'];
            case 'year':       return (int) $b['year'] <=> (int) $a['year'];
            default:           return strcmp($b['created'] ?? '', $a['created'] ?? '');
        }
    });
    $limit = (int) ($opts['limit'] ?? 0);
    return $limit > 0 ? array_slice($cars, 0, $limit) : array_values($cars);
}

/** $hx: 'h2' when the list has no section heading, so heading levels stay in order (1.3.1). */
function car_card(array $car, string $hx = 'h3'): string
{
    $c = collection('cars');
    $img = item_image($c, $car);
    $url = item_url($c, $car);
    ob_start(); ?>
    <article class="car-card is-<?= e($car['status']) ?>">
      <a class="car-card__media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($img): ?><img src="<?= e(media_url($img)) ?>" alt="" loading="lazy"><?php else: ?><span class="car-card__noimg"><?= icon('car') ?></span><?php endif; ?>
        <?php if ($car['status'] === 'sold'): ?><span class="car-ribbon car-ribbon--sold"><?= e(t('Sold')) ?></span>
        <?php elseif ($car['status'] === 'reserved'): ?><span class="car-ribbon car-ribbon--reserved"><?= e(t('Reserved')) ?></span>
        <?php elseif ($car['was_price'] !== '' && (float) $car['was_price'] > (float) $car['price']): ?><span class="car-ribbon car-ribbon--reduced"><?= e(t('Reduced')) ?></span><?php endif; ?>
        <?php $n = count($car['images']); if ($n > 1): ?><span class="car-card__count"><?= e(t('%d photos', $n)) ?></span><?php endif; ?>
      </a>
      <div class="car-card__body">
        <<?= $hx ?> class="car-card__title"><a href="<?= e($url) ?>"><?= e(item_title($c, $car)) ?></a></<?= $hx ?>>
        <?php if ($car['variant']): ?><p class="car-card__variant"><?= e($car['variant']) ?></p><?php endif; ?>
        <ul class="car-chips">
          <?php foreach (car_spec_chips($car) as $ic => $txt): ?><li><?= icon($ic) ?><?= e($txt) ?></li><?php endforeach; ?>
        </ul>
        <div class="car-card__foot">
          <div class="car-price">
            <?php if ($car['was_price'] !== '' && (float) $car['was_price'] > (float) $car['price']): ?><s>£<?= e(number_format((float) $car['was_price'])) ?></s><?php endif; ?>
            <strong><?= e(car_price($car)) ?></strong>
            <?php if ($car['finance'] && $car['status'] !== 'sold'): ?><span><?= e($car['finance']) ?></span><?php endif; ?>
          </div>
          <a class="btn btn--accent car-card__btn" href="<?= e($url) ?>"><?= e(t('View car')) ?><span class="sr-only">: <?= e(item_title($c, $car)) ?></span></a>
        </div>
      </div>
    </article>
    <?php
    return ob_get_clean();
}
