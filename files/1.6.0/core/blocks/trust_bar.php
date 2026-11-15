<?php if (empty($b['items'])) return; ?>
<?= section_open('trust_bar', $b, $anchor) ?>
  <div class="container">
    <ul class="trust">
      <?php foreach ($b['items'] as $it): ?>
        <li class="trust__item"><?= icon($it['icon'] ?? '', 'icon trust__icon') ?><span><?= e($it['text'] ?? '') ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
