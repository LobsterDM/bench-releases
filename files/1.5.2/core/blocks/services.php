<?php ?>
<?= section_open('services', $b, $anchor) ?>
  <div class="container">
    <?= section_head($b) ?>
    <div class="grid grid--<?= e((int) $b['columns'] ?: 3) ?>">
      <?php $hx = item_heading_tag($b); foreach ($b['items'] as $it): $hasLink = !empty($it['link']); ?>
        <<?= $hasLink ? 'a href="' . e(link_to($it['link'])) . '"' : 'article' ?> class="card service">
          <?php if (!empty($it['icon'])): ?><span class="service__icon"><?= icon($it['icon']) ?></span><?php endif; ?>
          <<?= $hx ?> class="service__title"><?= e($it['title'] ?? '') ?></<?= $hx ?>>
          <?php if (!empty($it['text'])): ?><p class="service__text"><?= nl2br(e($it['text'])) ?></p><?php endif; ?>
          <?php if ($hasLink): ?><span class="service__more">Find out more<span class="sr-only"> about <?= e($it['title'] ?? '') ?></span> <?= icon('arrow') ?></span><?php endif; ?>
        </<?= $hasLink ? 'a' : 'article' ?>>
      <?php endforeach; ?>
    </div>
  </div>
</section>
