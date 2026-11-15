<?php ?>
<?= section_open('areas', $b, $anchor) ?>
  <div class="container areas">
    <div class="areas__text">
      <?php if ($b['heading']): ?><h2 class="section-title"><?= e($b['heading']) ?></h2><?php endif; ?>
      <?php if ($b['text']): ?><p><?= nl2br(e($b['text'])) ?></p><?php endif; ?>
    </div>
    <ul class="areas__list">
      <?php foreach ($b['items'] as $it): ?><li><?= icon('pin') ?> <?= e($it['name'] ?? '') ?></li><?php endforeach; ?>
    </ul>
  </div>
</section>
