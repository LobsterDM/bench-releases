<?php ?>
<?= section_open('gallery', $b, $anchor) ?>
  <div class="container">
    <?= section_head($b) ?>
    <div class="gallery">
      <?php $n = 0; $total = count(array_filter($b['items'], fn($i) => !empty($i['image'])));
      foreach ($b['items'] as $it): if (empty($it['image'])) continue; $n++;
        $alt = trim($it['alt'] ?? '') ?: trim($it['caption'] ?? '') ?: t('Photo %1$d of %2$d', $n, $total); ?>
        <figure class="gallery__item">
          <a href="<?= e(media_url($it['image'])) ?>" data-lightbox data-caption="<?= e($it['caption'] ?? '') ?>"><img src="<?= e(media_url($it['image'])) ?>" alt="<?= e($alt) ?>" loading="lazy"><span class="sr-only"> – <?= e(t('view larger')) ?></span></a>
          <?php if (!empty($it['caption'])): ?><figcaption><?= e($it['caption']) ?></figcaption><?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
