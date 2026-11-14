<?php ?>
<?= section_open('cta', $b, $anchor) ?>
  <div class="container cta">
    <div class="cta__text">
      <?php if ($b['heading']): ?><h2 class="cta__title"><?= e($b['heading']) ?></h2><?php endif; ?>
      <?php if ($b['text']): ?><p><?= nl2br(e($b['text'])) ?></p><?php endif; ?>
    </div>
    <div class="cta__actions">
      <?php if ($b['button_text']): ?><a class="btn btn--light btn--lg" href="<?= e(link_to($b['button_link'])) ?>"><?= e($b['button_text']) ?></a><?php endif; ?>
      <?php if (!empty($b['show_phone']) && $site['phone']): ?><a class="cta__phone" href="<?= e(tel_link($site['phone'])) ?>"><?= icon('phone') ?> <?= e($site['phone']) ?></a><?php endif; ?>
    </div>
  </div>
</section>
