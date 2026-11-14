<?php
/**
 * Contact form block — the form itself lives in core/forms.php so plugins can reuse it.
 */
$detailsCol = !empty($b['show_details']) && ($site['phone'] || $site['email'] || $site['address']);
?>
<?= section_open('contact', $b, $anchor) ?>
  <div class="container <?= $detailsCol ? 'contact' : 'narrow' ?>">
    <div class="contact__main">
      <?= section_head($b) ?>
      <?= bench_contact_form() ?>
    </div>
    <?php if ($detailsCol): ?>
      <div class="contact__details card">
        <<?= $hx = item_heading_tag($b) ?>>Get in touch</<?= $hx ?>>
        <?= bench_contact_details(!empty($b['show_map'])) ?>
      </div>
    <?php endif; ?>
  </div>
</section>
