<?php ?>
<?= section_open('features', $b, $anchor) ?>
  <div class="container">
    <?= section_head($b) ?>
    <ol class="features">
      <?php $hx = item_heading_tag($b); foreach ($b['items'] as $i => $it): ?>
        <li class="feature">
          <span class="feature__num" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <div>
            <<?= $hx ?> class="feature__title"><?= e($it['title'] ?? '') ?></<?= $hx ?>>
            <?php if (!empty($it['text'])): ?><p><?= nl2br(e($it['text'])) ?></p><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
