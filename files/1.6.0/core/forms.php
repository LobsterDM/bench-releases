<?php
/**
 * Contact forms.
 * The site never sends mail itself: forms post straight to a third-party form
 * service (Web3Forms, Formspree, Basin or any compatible endpoint), or an embedded
 * form builder is shown instead (Tally, Jotform, Fillout, Google Forms…).
 *
 * Plugins can reuse this for their own enquiry forms:
 *   echo bench_contact_form(['subject' => 'Enquiry: 2019 Ford Focus', 'hidden' => ['car' => '…']]);
 */

/**
 * Options:
 *   subject       email subject (defaults to the Settings value)
 *   hidden        extra fields sent with the message, e.g. ['vehicle' => 'Ford Focus, AB19 CDE']
 *   message       default text for the message box
 *   services      false to hide the "what do you need" dropdown
 *   fields        extra visible fields: [['name' => 'part_exchange', 'label' => 'Part exchange reg', 'type' => 'text']]
 *   button        button text
 */
function bench_contact_form(array $o = []): string
{
    $site = site();
    $o += ['subject' => $site['form_subject'], 'hidden' => [], 'message' => '', 'services' => true, 'fields' => [], 'button' => t('Send message')];
    $provider = $site['form_provider'] ?: 'none';
    $action = '';
    $hidden = [];
    $honeypot = '';
    $ready = false;

    switch ($provider) {
        case 'web3forms':
            $action = 'https://api.web3forms.com/submit';
            $hidden = ['access_key' => $site['form_access_key'], 'subject' => $o['subject'], 'from_name' => $site['business_name'] . ' website'];
            if (bench_localising()) {
                $hidden['language'] = bench_languages()[bench_lang()]['english_name']; // so the owner knows to reply in Welsh
            }
            $honeypot = '<input type="checkbox" name="botcheck" tabindex="-1" autocomplete="off">';
            $ready = $site['form_access_key'] !== '';
            break;
        case 'formspree':
        case 'basin':
        case 'custom':
            $action = $site['form_endpoint'];
            $hidden = ['_subject' => $o['subject']];
            if (bench_localising()) {
                $hidden['language'] = bench_languages()[bench_lang()]['english_name'];
            }
            $honeypot = '<input type="text" name="_gotcha" tabindex="-1" autocomplete="off">';
            $ready = $provider === 'formspree' ? (bool) preg_match('#^https://formspree\.io/f/#', $action) : (bool) preg_match('#^https://#', $action);
            break;
    }

    if ($provider === 'embed') {
        return '<div class="embed form-embed">' . $site['form_embed'] /* admin-supplied embed code */ . '</div>';
    }

    if (!$ready) {
        $msg = '<div class="notice"><p><strong>' . e(t('Contact form not set up yet.')) . '</strong></p>';
        if (is_admin()) {
            $msg .= '<p>Choose a form service in <a href="' . e(base_path()) . '/admin/?view=settings">Settings → Contact form</a>.</p>';
        } elseif ($site['phone']) {
            $tel = '<a href="' . e(tel_link($site['phone'])) . '">' . e($site['phone']) . '</a>';
            $msg .= '<p>' . ($site['email']
                ? sprintf(e(t('Please call us on %1$s or email %2$s.')), $tel, '<a href="mailto:' . e($site['email']) . '">' . e($site['email']) . '</a>')
                : sprintf(e(t('Please call us on %s.')), $tel)) . '</p>';
        }
        return $msg . '</div>';
    }

    $hidden += $o['hidden'];
    $services = $o['services'] ? array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $site['form_services'])))) : [];

    ob_start();
    if (is_admin() && stripos((string) $site['form_access_key'] . $action, 'YOUR-') !== false): ?>
      <p class="notice">Demo mode: replace the placeholder key in <a href="<?= e(base_path()) ?>/admin/?view=settings">Settings → Contact form</a> before going live. (Only you can see this.)</p>
    <?php endif; ?>
    <?php
    static $formNo = 0;
    $id = 'f' . (++$formNo);
    // One field: label tied to the input, optional hint, and an error slot announced with the field (1.3.1, 3.3.1, 3.3.2)
    // $missing: the error shown when a required field is left empty, e.g. "Enter your name"
    $field = function (string $name, string $label, string $control, string $hint = '', bool $required = false, string $missing = '') use ($id) {
        $fid = "$id-$name";
        $desc = trim(($hint !== '' ? "$fid-hint " : '') . "$fid-err");
        $control = str_replace('{attrs}', 'id="' . $fid . '" name="' . e($name) . '" aria-describedby="' . $desc . '"' . ($required ? ' required aria-required="true"' : '') . ($missing !== '' ? ' data-missing="' . e($missing) . '"' : ''), $control);
        return '<div class="field"><label for="' . $fid . '">' . e($label) . ($required ? ' <span aria-hidden="true">*</span><span class="sr-only"> (' . e(t('required')) . ')</span>' : ' <span class="field__optional">(' . e(t('optional')) . ')</span>') . '</label>'
            . ($hint !== '' ? '<span class="field__hint" id="' . $fid . '-hint">' . e($hint) . '</span>' : '')
            . $control . '<span class="field__error" id="' . $fid . '-err"></span></div>';
    };
    ?>
    <form class="form js-ajax-form" action="<?= e($action) ?>" method="POST" novalidate data-success="<?= e($site['form_success']) ?>" aria-describedby="<?= $id ?>-help">
      <p class="form__help" id="<?= $id ?>-help"><?= sprintf(e(t('Fields marked %s are required.')), '<span aria-hidden="true">*</span>') ?><?php if ($site['phone']): ?> <?= sprintf(e(t('Prefer to talk? Call %s.')), '<a href="' . e(tel_link($site['phone'])) . '">' . e($site['phone']) . '</a>') ?><?php endif; ?></p>
      <div class="form__errors" id="<?= $id ?>-errors" tabindex="-1"></div>
      <?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <div class="hp-wrap" aria-hidden="true"><label><?= e(t('Leave this box empty')) ?> <?= $honeypot ?></label></div>
      <div class="form__row">
        <?= $field('name', t('Your name'), '<input type="text" {attrs} autocomplete="name">', '', true, t('Enter your name')) ?>
        <?= $field('phone', t('Phone'), '<input type="tel" {attrs} autocomplete="tel" inputmode="tel">') ?>
      </div>
      <div class="form__row">
        <?= $field('email', t('Email'), '<input type="email" {attrs} autocomplete="email" spellcheck="false">', t('So we can reply, e.g. name@example.com'), true, t('Enter your email address')) ?>
        <?= $field('postcode', t('Postcode'), '<input type="text" {attrs} autocomplete="postal-code">') ?>
      </div>
      <?php if ($services):
          $opts = '<option value="">' . e(t('Please choose…')) . '</option>';
          foreach ($services as $sv) { $opts .= '<option>' . e($sv) . '</option>'; }
          echo $field('service', t('What do you need help with?'), '<select {attrs}>' . $opts . '</select>');
      endif; ?>
      <?php foreach ($o['fields'] as $fld): ?>
        <?= $field($fld['name'], $fld['label'], '<input type="' . e($fld['type'] ?? 'text') . '" {attrs}>', $fld['hint'] ?? '') ?>
      <?php endforeach; ?>
      <?= $field('message', t('Message'), '<textarea {attrs} rows="5">' . e($o['message']) . '</textarea>', '', true, t('Enter your message')) ?>
      <button class="btn btn--accent btn--lg" type="submit"><?= e($o['button']) ?></button>
      <p class="form__status" role="status" aria-live="polite"></p>
    </form>
    <?php
    return ob_get_clean();
}

/** Phone / email / address / hours list used beside forms. */
function bench_contact_details(bool $map = false): string
{
    $site = site();
    ob_start(); ?>
    <ul class="details">
      <?php if ($site['phone']): ?><li><?= icon('phone') ?><a href="<?= e(tel_link($site['phone'])) ?>"><?= e($site['phone']) ?></a></li><?php endif; ?>
      <?php if ($site['email']): ?><li><?= icon('mail') ?><a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a></li><?php endif; ?>
      <?php if ($site['address']): ?><li><?= icon('pin') ?><span><?= nl2br(e($site['address'])) ?></span></li><?php endif; ?>
      <?php if ($site['hours']): ?><li><?= icon('clock') ?><span><?= e($site['hours']) ?></span></li><?php endif; ?>
    </ul>
    <?php if ($map && $site['address']): ?>
      <iframe class="map" title="<?= e(t('Map showing where %s is', $site['business_name'])) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
        src="https://maps.google.com/maps?q=<?= rawurlencode(str_replace("\n", ', ', $site['address'])) ?>&amp;output=embed"></iframe>
    <?php endif;
    return ob_get_clean();
}
