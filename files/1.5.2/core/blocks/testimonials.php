<?php ?>
<?= section_open('testimonials', $b, $anchor) ?>
  <div class="container">
    <?= section_head($b) ?>
    <div class="grid grid--3">
      <?php foreach ($b['items'] as $it): $r = max(1, min(5, (int) ($it['rating'] ?? 5))); ?>
        <figure class="card review">
          <div class="review__stars" role="img" aria-label="Rated <?= $r ?> out of 5 stars"><?= str_repeat(icon('star', 'icon star'), $r) ?></div>
          <blockquote class="review__quote"><?= nl2br(e($it['quote'] ?? '')) ?></blockquote>
          <figcaption class="review__by"><strong><?= e($it['name'] ?? '') ?></strong><?php if (!empty($it['location'])): ?> <span><?= e($it['location']) ?></span><?php endif; ?></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
