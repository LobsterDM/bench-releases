<?php if (empty($b['items'])) return; ?>
<?= section_open('stats', $b, $anchor) ?>
  <div class="container">
    <dl class="stats">
      <?php foreach ($b['items'] as $it): ?>
        <div class="stat"><dt class="stat__label"><?= e($it['label'] ?? '') ?></dt><dd class="stat__num"><?= e($it['number'] ?? '') ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </div>
</section>
