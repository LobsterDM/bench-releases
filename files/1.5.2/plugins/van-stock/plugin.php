<?php
/**
 * Van Stock plugin
 *
 * Registers:
 *   - a "vans" collection (Admin → Vehicles) with pages at /vans/<vehicle-id>
 *   - a "Van listings" section for the page editor (filterable stock list or featured strip)
 *   - a "Van stock" tab in Settings
 *
 * Built from the Car Stock plugin and reuses its front-end class names (.car-*),
 * so both look the same and either stylesheet works. Functions are prefixed van_
 * so the two plugins can be switched on together.
 */

const VANS_FUELS = ['Diesel', 'Petrol', 'Hybrid', 'Electric'];
const VANS_BODIES = ['Small van', 'Medium van', 'Large van', 'Crew van', 'Dropside', 'Tipper', 'Luton / box van', 'Recovery truck', 'Pick-up', '4x4', 'Minibus', 'Car', 'Other'];
const VANS_STATUS = ['available', 'reserved', 'sold', 'draft'];
const VANS_VAT = ['plus' => '+ VAT', 'inc' => 'inc. VAT', 'none' => 'No VAT'];

register_collection('vans', [
    'label'       => 'Vehicles',
    'singular'    => 'vehicle',
    'icon'        => 'van',
    'route'       => 'vans',               // pages at /vans/<id>; make a page with the slug "vans" for the list
    'image_field' => 'images',
    'sort'        => ['updated', 'desc'],
    'title'       => fn($v) => trim(implode(' ', array_filter([$v['year'], $v['make'], $v['model']]))) ?: 'Untitled vehicle',
    'subtitle'    => fn($v) => implode(' · ', array_filter([
        $v['variant'],
        $v['mileage'] !== '' ? number_format((float) $v['mileage']) . ' miles' : '',
        $v['price'] !== '' ? van_price($v) : '',
    ])),
    'is_public'   => fn($v) => $v['status'] !== 'draft',
    'badge'       => fn($v) => [
        'available' => ['label' => 'Available', 'tone' => 'ok'],
        'reserved'  => ['label' => 'Reserved', 'tone' => 'warn'],
        'sold'      => ['label' => 'Sold', 'tone' => 'danger'],
        'draft'     => ['label' => 'Draft (hidden)', 'tone' => 'plain'],
    ][$v['status']] ?? null,
    'detail_template' => __DIR__ . '/templates/detail.php',
    'fields' => [
        f('_h_vehicle', 'Vehicle', 'heading'),
        f('make', 'Make', 'text', ['width' => 'third', 'placeholder' => 'Ford']),
        f('model', 'Model', 'text', ['width' => 'third', 'placeholder' => 'Transit Custom']),
        f('variant', 'Variant / trim', 'text', ['width' => 'third', 'placeholder' => '2.0 TDCi 130 L1H1 Limited']),
        f('year', 'Year', 'number', ['width' => 'third', 'placeholder' => '2019']),
        f('price', 'Price', 'number', ['width' => 'third', 'prefix' => '£', 'help' => 'Leave blank for "Call for price"']),
        f('vat', 'VAT', 'select', ['width' => 'third', 'options' => array_keys(VANS_VAT), 'labels' => ['plus' => 'Plus VAT', 'inc' => 'Price includes VAT', 'none' => 'No VAT to pay'], 'default' => 'plus']),
        f('was_price', 'Was (optional)', 'number', ['width' => 'third', 'prefix' => '£', 'help' => 'Shows as a reduced price']),
        f('status', 'Status', 'select', ['width' => 'third', 'options' => VANS_STATUS, 'labels' => ['available' => 'Available', 'reserved' => 'Reserved', 'sold' => 'Sold', 'draft' => 'Draft (hidden from site)'], 'default' => 'available', 'help' => 'Sold vehicles stay on the site with a SOLD banner. Draft hides it.']),
        f('finance', 'Finance line (optional)', 'text', ['width' => 'third', 'placeholder' => 'Finance available']),
        f('stock_no', 'Stock number', 'text', ['width' => 'third', 'placeholder' => 'WW33', 'help' => 'Shown on the vehicle page and sent with enquiries']),
        f('reg', 'Registration (private)', 'text', ['width' => 'third', 'help' => 'Sent with enquiries so you know which vehicle — never shown on the site']),
        f('featured', 'Feature on the home page', 'checkbox', ['width' => 'third']),

        f('_h_spec', 'Specification', 'heading'),
        f('body', 'Type', 'select', ['width' => 'third', 'options' => array_merge([''], VANS_BODIES)]),
        f('mileage', 'Mileage', 'number', ['width' => 'third', 'suffix' => 'miles']),
        f('fuel', 'Fuel', 'select', ['width' => 'third', 'options' => array_merge([''], VANS_FUELS), 'default' => 'Diesel']),
        f('gearbox', 'Gearbox', 'select', ['width' => 'third', 'options' => ['', 'Manual', 'Automatic'], 'default' => 'Manual']),
        f('engine', 'Engine', 'text', ['width' => 'third', 'placeholder' => '2.0 TDCi 130bhp']),
        f('colour', 'Colour', 'text', ['width' => 'third']),
        f('wheelbase', 'Wheelbase', 'select', ['width' => 'third', 'options' => ['', 'SWB', 'MWB', 'LWB', 'XLWB']]),
        f('roof', 'Roof', 'select', ['width' => 'third', 'options' => ['', 'Low roof', 'Medium roof', 'High roof']]),
        f('seats', 'Seats', 'number', ['width' => 'third']),
        f('payload', 'Payload', 'number', ['width' => 'third', 'suffix' => 'kg']),
        f('owners', 'Previous owners', 'number', ['width' => 'third']),
        f('mot', 'MOT expiry', 'date', ['width' => 'third', 'help' => 'Leave blank if sold with a new 12-month MOT']),
        f('history', 'Service history', 'select', ['width' => 'half', 'options' => ['', 'Full service history', 'Full dealer history', 'Part service history', 'No history']]),
        f('ulez', 'ULEZ / Clean Air Zone compliant', 'checkbox', ['width' => 'half']),

        f('_h_photos', 'Photos & description', 'heading'),
        f('images', 'Photos', 'gallery'),
        f('description', 'Description', 'richtext'),
        f('features', 'Features & extras', 'textarea', ['help' => 'One per line, e.g. "Ply-lined load area"']),
        f('meta_description', 'Search engine description (optional)', 'textarea'),
    ],
]);

register_block('van_listings', [
    'label'       => 'Van listings',
    'description' => 'Your vehicles from Admin → Vehicles, with optional search filters',
    'fields' => [
        f('heading', 'Heading'),
        f('intro', 'Intro', 'textarea'),
        f('show_filters', 'Show search filters', 'checkbox', ['default' => true]),
        f('featured_only', 'Only vehicles marked "feature on home page"', 'checkbox', ['default' => false]),
        f('limit', 'Maximum vehicles to show (0 = all)', 'text', ['default' => '0']),
        f('show_sold', 'Include sold vehicles (shown last)', 'checkbox', ['default' => true]),
        f('button_text', 'Button under the list', 'text', ['help' => 'e.g. "View all vehicles"']),
        f('button_link', 'Button link', 'link', ['default' => 'vans']),
    ],
]);

register_settings('Van stock', [
    f('vans_intro_note', 'Note on every vehicle page', 'textarea', ['default' => 'Every vehicle is sold with a full service, 12 months MOT and a warranty.']),
    f('vans_vat_note', 'VAT note', 'textarea', ['default' => 'Unless specified, all vehicles carry a 20% VAT charge.', 'help' => 'Shown under stock lists and on vehicle pages']),
    f('vans_finance_note', 'Finance note', 'textarea', ['default' => 'Finance can be arranged, subject to status.']),
    f('vans_part_exchange', 'Ask for part-exchange details on enquiry forms', 'checkbox', ['default' => true]),
]);

add_hook('head', function () {
    return '<link rel="stylesheet" href="' . e(plugin_asset(__DIR__, 'assets/vans.css')) . '">' . "\n";
});
add_hook('footer', function () {
    return '<script src="' . e(plugin_asset(__DIR__, 'assets/vans.js')) . '" defer></script>';
});

/* ---------- Helpers used by the templates ---------- */

/** "£8,995" — or "Call for price" when no price is set. */
function van_price(array $v): string
{
    return $v['price'] !== '' ? '£' . number_format((float) $v['price']) : 'Call for price';
}

/** "+ VAT", "inc. VAT", "No VAT" — empty when there's no price. */
function van_vat(array $v): string
{
    return $v['price'] !== '' ? (VANS_VAT[$v['vat'] ?? 'plus'] ?? '') : '';
}

function van_is_reduced(array $v): bool
{
    return $v['was_price'] !== '' && (float) $v['was_price'] > (float) $v['price'];
}

function van_spec_chips(array $v): array
{
    return array_filter([
        'calendar' => $v['year'],
        'gauge'    => $v['mileage'] !== '' ? number_format((float) $v['mileage']) . ' mi' : '',
        'fuel'     => $v['fuel'],
        'gear'     => $v['gearbox'],
    ]);
}

/** Stock list with filters applied from the query string. */
function vans_query(array $opts): array
{
    $vans = items_all('vans', true);
    $q = $opts['filters'] ?? [];
    $vans = array_filter($vans, function ($v) use ($opts, $q) {
        if (!empty($opts['featured_only']) && empty($v['featured'])) return false;
        if (empty($opts['show_sold']) && $v['status'] === 'sold') return false;
        if (!empty($q['make']) && strcasecmp($v['make'], $q['make']) !== 0) return false;
        if (!empty($q['body']) && $v['body'] !== $q['body']) return false;
        if (!empty($q['fuel']) && $v['fuel'] !== $q['fuel']) return false;
        if (!empty($q['gearbox']) && $v['gearbox'] !== $q['gearbox']) return false;
        if (!empty($q['max_price']) && ($v['price'] === '' || (float) $v['price'] > (float) $q['max_price'])) return false;
        return true;
    });
    $sort = $q['sort'] ?? 'newest';
    usort($vans, function ($a, $b) use ($sort) {
        $s = ($a['status'] === 'sold') <=> ($b['status'] === 'sold'); // sold always last
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
    return $limit > 0 ? array_slice($vans, 0, $limit) : array_values($vans);
}

/** $hx: 'h2' when the list has no section heading, so heading levels stay in order (1.3.1). */
function van_card(array $v, string $hx = 'h3'): string
{
    $c = collection('vans');
    $img = item_image($c, $v);
    $url = item_url($c, $v);
    ob_start(); ?>
    <article class="car-card is-<?= e($v['status']) ?>">
      <a class="car-card__media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($img): ?><img src="<?= e(media_url($img)) ?>" alt="" loading="lazy"><?php else: ?><span class="car-card__noimg"><?= icon('van') ?></span><?php endif; ?>
        <?php if ($v['status'] === 'sold'): ?><span class="car-ribbon car-ribbon--sold">Sold</span>
        <?php elseif ($v['status'] === 'reserved'): ?><span class="car-ribbon car-ribbon--reserved">Reserved</span>
        <?php elseif (van_is_reduced($v)): ?><span class="car-ribbon car-ribbon--reduced">Reduced</span><?php endif; ?>
        <?php $n = count($v['images']); if ($n > 1): ?><span class="car-card__count"><?= $n ?> photos</span><?php endif; ?>
      </a>
      <div class="car-card__body">
        <<?= $hx ?> class="car-card__title"><a href="<?= e($url) ?>"><?= e(item_title($c, $v)) ?></a></<?= $hx ?>>
        <?php if ($v['variant'] || $v['body']): ?><p class="car-card__variant"><?= e($v['variant'] ?: $v['body']) ?></p><?php endif; ?>
        <ul class="car-chips">
          <?php foreach (van_spec_chips($v) as $ic => $txt): ?><li><?= icon($ic) ?><?= e($txt) ?></li><?php endforeach; ?>
        </ul>
        <div class="car-card__foot">
          <div class="car-price">
            <?php if (van_is_reduced($v)): ?><s>£<?= e(number_format((float) $v['was_price'])) ?></s><?php endif; ?>
            <strong<?= $v['price'] === '' ? ' class="van-poa"' : '' ?>><?= e(van_price($v)) ?><?php if ($vat = van_vat($v)): ?> <small class="van-vat"><?= e($vat) ?></small><?php endif; ?></strong>
            <?php if ($v['finance'] && $v['status'] !== 'sold'): ?><span><?= e($v['finance']) ?></span><?php endif; ?>
          </div>
          <a class="btn btn--accent car-card__btn" href="<?= e($url) ?>">View<span class="sr-only">: <?= e(item_title($c, $v)) ?></span></a>
        </div>
      </div>
    </article>
    <?php
    return ob_get_clean();
}
