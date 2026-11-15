<?php if (trim($b['code']) === '' && !$b['heading']) return; ?>
<?= section_open('html', $b, $anchor) ?>
  <div class="container">
    <?= section_head($b) ?>
    <div class="embed"><?= $b['code'] /* raw by design: admin-supplied embed code */ ?></div>
  </div>
</section>
