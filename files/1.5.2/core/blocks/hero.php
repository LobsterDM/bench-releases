<?php
$bg = $b['image'] ? ' style="--hero-image:url(\'' . e(media_url($b['image'])) . '\')"' : '';
$cls = 'hero--' . ($b['size'] === 'small' ? 'small' : 'large') . ($b['image'] ? ' has-image' : '');
?>
<?= str_replace('<section ', '<section' . $bg . ' ', section_open('hero', $b, $anchor, $cls)) ?>
  <div class="container hero__inner">
    <?php if ($b['eyebrow']): ?><p class="hero__eyebrow"><?= e($b['eyebrow']) ?></p><?php endif; ?>
    <?php if ($b['heading']): ?><<?= $index === 0 ? 'h1' : 'h2' ?> class="hero__title"><?= e($b['heading']) ?></<?= $index === 0 ? 'h1' : 'h2' ?>><?php endif; ?>
    <?php if ($b['subheading']): ?><p class="hero__sub"><?= nl2br(e($b['subheading'])) ?></p><?php endif; ?>
    <div class="hero__actions">
      <?php if ($b['button_text']): ?><a class="btn btn--accent btn--lg" href="<?= e(link_to($b['button_link'])) ?>"><?= e($b['button_text']) ?></a><?php endif; ?>
      <?php if ($b['button2_text']): ?>
        <a class="btn btn--ghost btn--lg" href="<?= e(link_to($b['button2_link'])) ?>"><?= e($b['button2_text']) ?></a>
      <?php elseif ($site['phone'] && $b['size'] !== 'small'): ?>
        <a class="btn btn--ghost btn--lg" href="<?= e(tel_link($site['phone'])) ?>"><?= icon('phone') ?> <?= e($site['phone']) ?></a>
      <?php endif; ?>
    </div>
    <?php if (!empty($b['badges'])): ?>
      <ul class="hero__badges">
        <?php foreach ($b['badges'] as $badge): if (empty($badge['text'])) continue; ?>
          <li><?= icon('check') ?> <?= e($badge['text']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
