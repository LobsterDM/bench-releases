<?php $hasImg = (bool) $b['image']; ?>
<?= section_open('text', $b, $anchor, $hasImg ? 'has-image image-' . ($b['image_side'] === 'left' ? 'left' : 'right') : '') ?>
  <div class="container <?= $hasImg ? 'split' : 'narrow' ?>">
    <div class="split__text prose">
      <?php if ($b['heading']): ?><h2 class="section-title"><?= e($b['heading']) ?></h2><?php endif; ?>
      <?= rich($b['body']) ?>
      <?php if ($b['button_text']): ?><p><a class="btn btn--accent" href="<?= e(link_to($b['button_link'])) ?>"><?= e($b['button_text']) ?></a></p><?php endif; ?>
    </div>
    <?php if ($hasImg): ?>
      <figure class="split__media"><img src="<?= e(media_url($b['image'])) ?>" alt="<?= e($b['image_alt'] ?? '') ?>" loading="lazy"></figure>
    <?php endif; ?>
  </div>
</section>
