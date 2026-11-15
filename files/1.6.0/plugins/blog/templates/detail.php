<?php
/**
 * Blog post page. Available: $item (the post), $collection, $site.
 * A theme can replace this with themes/<theme>/templates/posts-detail.php
 */
$p = $item;
$live = blog_is_live($p);
$showAuthor = !empty($site['blog_show_author']) && $p['author'] !== '';

// Previous / next among live posts (by date)
$all = blog_posts();
$prev = $next = null;
foreach ($all as $i => $o) {
    if ($o['id'] === $p['id']) {
        $next = $all[$i - 1] ?? null; // newer
        $prev = $all[$i + 1] ?? null; // older
        break;
    }
}

// For the page <head> (og:image, published time), and the browser tab / search result text
$GLOBALS['blog_head'] = ['image' => $p['image'], 'published' => $live ? date('c', blog_time($p)) : ''];
if (!$live) {
    $GLOBALS['blog_head']['noindex'] = true;
}

$ld = array_filter([
    '@context'      => 'https://schema.org',
    '@type'         => 'BlogPosting',
    'headline'      => $p['title'],
    'description'   => blog_excerpt($p, 40),
    'image'         => $p['image'] ? blog_abs_url(media_url($p['image'])) : null,
    'datePublished' => date('c', blog_time($p)),
    'dateModified'  => !empty($p['updated']) ? date('c', strtotime($p['updated'])) : null,
    'author'        => $showAuthor ? ['@type' => 'Person', 'name' => $p['author']] : ['@type' => 'Organization', 'name' => $site['business_name']],
    'publisher'     => ['@type' => 'Organization', 'name' => $site['business_name']],
    'mainEntityOfPage' => blog_abs_url(item_url($collection, $p)),
    'keywords'      => $p['tags'] ? implode(', ', $p['tags']) : null,
    'articleSection'=> $p['categories'][0] ?? null,
]);
?>
<article class="block blog-post">
  <div class="container blog-post__wrap">
    <nav class="blog-crumbs" aria-label="<?= e(t('Breadcrumb')) ?>">
      <ol>
        <li><a href="<?= e(url(blog_route())) ?>"><?= e(t('Blog')) ?></a></li>
        <?php if ($p['categories']): ?><li><a href="<?= e(blog_category_url($p['categories'][0])) ?>"><?= e(blog_category_label($p['categories'][0])) ?></a></li><?php endif; ?>
        <li><a href="<?= e(item_url($collection, $p)) ?>" aria-current="page"><?= e($p['title']) ?></a></li>
      </ol>
    </nav>

    <?php if (!$live): ?>
      <div class="notice blog-notice" role="note">
        <p><strong><?= $p['status'] !== 'published' ? 'Draft' : 'Scheduled for ' . e(blog_date($p, true)) ?></strong> — only you can see this because you're logged in.</p>
      </div>
    <?php endif; ?>

    <header class="blog-post__head">
      <?php if ($p['categories']): ?>
        <p class="blog-post__cats"><span class="sr-only"><?= e(t('Categories:')) ?> </span><?php foreach ($p['categories'] as $i => $cat): ?><?= $i ? ', ' : '' ?><a href="<?= e(blog_category_url($cat)) ?>"><?= e(blog_category_label($cat)) ?></a><?php endforeach; ?></p>
      <?php endif; ?>
      <h1 class="blog-post__title"><?= e($p['title']) ?></h1>
      <p class="blog-meta blog-post__meta">
        <?= blog_time_tag($p) ?>
        <?php if ($showAuthor): ?><span aria-hidden="true"> · </span><span class="sr-only">, </span><?= e(t('by')) ?> <a href="<?= e(blog_author_url($p['author'])) ?>"><?= e($p['author']) ?></a><?php endif; ?>
      </p>
    </header>

    <?php if ($p['image']): ?>
      <figure class="blog-post__image"><img src="<?= e(media_url($p['image'])) ?>" alt="<?= e($p['image_alt']) ?>"></figure>
    <?php endif; ?>

    <div class="prose blog-post__body"><?= rich($p['body']) ?></div>

    <?php if ($p['tags']): ?>
      <div class="blog-tags">
        <h2 class="blog-tags__title"><?= e(t('Tags')) ?></h2>
        <ul><?php foreach ($p['tags'] as $t): ?><li><a href="<?= e(blog_tag_url($t)) ?>"><?= e($t) ?></a></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($prev || $next): ?>
      <nav class="blog-adjacent" aria-label="<?= e(t('More posts')) ?>">
        <ul>
          <?php if ($prev): ?><li class="blog-adjacent__prev"><a href="<?= e(item_url($collection, $prev)) ?>" rel="prev"><span class="blog-adjacent__label">← <?= e(t('Older post')) ?></span> <span class="blog-adjacent__title"><?= e($prev['title']) ?></span></a></li><?php endif; ?>
          <?php if ($next): ?><li class="blog-adjacent__next"><a href="<?= e(item_url($collection, $next)) ?>" rel="next"><span class="blog-adjacent__label"><?= e(t('Newer post')) ?> →</span> <span class="blog-adjacent__title"><?= e($next['title']) ?></span></a></li><?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>

    <p class="blog-back"><a href="<?= e(url(blog_route())) ?>">← <?= e(t('All posts')) ?></a></p>
  </div>
</article>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
