<?php
/**
 * Executive theme — page layout.
 * Available: $site, $page, $nav, $content (rendered sections), $title, $description
 */
$current = $page['slug'] ?? '';
$year = date('Y');
?>
<!doctype html>
<html lang="en-GB">
<head>
<?= bench_head($title, $description) ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Manrope:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(theme_url('assets/style.css')) ?>">
<?= custom_css() ?></head>
<body class="page-<?= e($current) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<?php if ($site['announcement'] || $site['hours']): ?>
<div class="announce" role="region" aria-label="Notice">
  <div class="container announce__inner">
    <span><?= e($site['announcement'] ?: $site['hours']) ?></span>
    <?php if ($site['hours'] && $site['announcement']): ?><span class="announce__hours"><?= icon('clock') ?> <?= e($site['hours']) ?></span><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<header class="site-header" data-sticky-header>
  <div class="container site-header__inner">
    <a class="logo" href="<?= e(url()) ?>">
      <?php if ($site['logo']): ?>
        <img src="<?= e(media_url($site['logo'])) ?>" alt="<?= e($site['business_name']) ?> – home page">
      <?php else: ?>
        <span class="logo__mark"><?= icon($site['logo_icon'] ?: 'car') ?></span>
        <span class="logo__text"><strong><?= e($site['business_name']) ?><span class="sr-only"> – home page</span></strong><?php if ($site['tagline']): ?><small><?= e($site['tagline']) ?></small><?php endif; ?></span>
      <?php endif; ?>
    </a>

    <button class="nav-toggle" type="button" aria-controls="site-nav" aria-expanded="false" data-nav-toggle><span></span><span class="sr-only">Menu</span></button>

    <nav class="site-nav" id="site-nav" aria-label="Main">
      <ul>
        <?php foreach ($nav as $item): ?>
          <li><a href="<?= e($item['url']) ?>"<?= $item['slug'] === $current ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <?php if ($site['phone']): ?>
      <a class="header-call" href="<?= e(tel_link($site['phone'])) ?>"><?= icon('phone') ?><span><small>Call to book</small><?= e($site['phone']) ?></span></a>
    <?php endif; ?>
  </div>
</header>

<main id="main" tabindex="-1">
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-about">
      <p class="footer-logo"><?= e($site['business_name']) ?></p>
      <?php if ($site['tagline']): ?><p class="footer-tagline"><?= e($site['tagline']) ?></p><?php endif; ?>
      <?php if ($site['footer_text']): ?><p><?= nl2br(e($site['footer_text'])) ?></p><?php endif; ?>
      <?php if ($site['facebook'] || $site['instagram']): ?>
        <p class="social">
          <?php if ($site['facebook']): ?><a href="<?= e($site['facebook']) ?>" rel="noopener">Facebook<span class="sr-only"> (<?= e($site['business_name']) ?>)</span></a><?php endif; ?>
          <?php if ($site['instagram']): ?><a href="<?= e($site['instagram']) ?>" rel="noopener">Instagram<span class="sr-only"> (<?= e($site['business_name']) ?>)</span></a><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
    <nav aria-labelledby="footer-pages">
      <h2 class="footer-title" id="footer-pages">Pages</h2>
      <ul class="footer-links">
        <?php foreach ($nav as $item): ?><li><a href="<?= e($item['url']) ?>"<?= $item['slug'] === $current ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li><?php endforeach; ?>
      </ul>
    </nav>
    <div>
      <h2 class="footer-title">Contact</h2>
      <ul class="footer-contact">
        <?php if ($site['phone']): ?><li><?= icon('phone') ?><a href="<?= e(tel_link($site['phone'])) ?>"><?= e($site['phone']) ?></a></li><?php endif; ?>
        <?php if ($site['email']): ?><li><?= icon('mail') ?><a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a></li><?php endif; ?>
        <?php if ($site['address']): ?><li><?= icon('pin') ?><span><?= nl2br(e($site['address'])) ?></span></li><?php endif; ?>
        <?php if ($site['hours']): ?><li><?= icon('clock') ?><span><?= e($site['hours']) ?></span></li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="container footer-bottom">
    <span>&copy; <?= $year ?> <?= e($site['business_name']) ?></span>
    <a href="<?= e(base_path()) ?>/admin/">Admin<span class="sr-only"> (site editors)</span></a>
  </div>
  <?php if ($site['phone']): ?>
    <a class="mobile-call" href="<?= e(tel_link($site['phone'])) ?>"><?= icon('phone') ?> Call <?= e($site['phone']) ?></a>
  <?php endif; ?>
</footer>

<?= bench_footer($page) ?>
</body>
</html>
