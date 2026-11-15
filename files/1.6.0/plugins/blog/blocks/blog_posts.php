<?php
/**
 * "Blog posts" section. As the main blog list it reads ?category=, ?tag=, ?author= and
 * ?pg= from the address, so category, tag and author links and page numbers work
 * without JavaScript and can be shared.
 */
$site = $site ?? site();
$id = $anchor ?: 'posts';
$perPage = max(1, min(60, (int) $b['limit'] ?: 9));
$here = $page['slug'] ?? blog_route();
$archive = !empty($b['archive']);

// Filters from the address (main list only); unknown names show an empty result, not every post
$filter = ['category' => '', 'tag' => '', 'author' => ''];
if ($archive) {
    foreach ($filter as $k => $_) {
        $v = isset($_GET[$k]) && is_string($_GET[$k]) ? strtolower(trim($_GET[$k])) : '';
        $filter[$k] = preg_match('/^[a-z0-9\-]{1,80}$/', $v) ? $v : '';
    }
}
$fixedCat = $b['category'] !== '' ? slugify($b['category']) : '';
$catSlug = $fixedCat ?: $filter['category'];
$active = array_filter(['category' => $fixedCat ? '' : $filter['category'], 'tag' => $filter['tag'], 'author' => $filter['author']]);

$posts = blog_posts([
    'category'     => $catSlug,
    'tag'          => $filter['tag'],
    'author'       => $filter['author'],
    'sticky_first' => !$active,
]);
$total = count($posts);
$pages = max(1, (int) ceil($total / $perPage));
$pg = $archive && isset($_GET['pg']) && is_string($_GET['pg']) && ctype_digit($_GET['pg']) ? max(1, (int) $_GET['pg']) : 1;
$shown = array_slice($posts, ($pg - 1) * $perPage, $perPage);

// What the visitor is looking at, as a heading
$filterTitle = '';
if ($active) {
    $kind = array_key_first($active);
    $name = blog_term_name($kind, $active[$kind]) ?: $active[$kind];
    $filterTitle = ['category' => t('Posts in %s', blog_category_label($name)), 'tag' => t('Posts tagged “%s”', $name), 'author' => t('Posts by %s', $name)][$kind];
}
if ($archive && ($filter['tag'] || $filter['author'] || $pg > 1)) {
    $GLOBALS['blog_head']['noindex'] = true; // archive and later pages: follow links, but don't list them in search results
}
$hx = ($b['heading'] || $filterTitle) ? 'h3' : 'h2';
$listUrl = fn(array $q) => blog_list_url($q + $active, $here) . '#' . $id;
$cats = blog_categories();
?>
<?= section_open('blog_posts', $b, $id, 'blog-list--' . ($b['layout'] === 'list' ? 'list' : 'grid')) ?>
  <div class="container">
    <?= section_head($b) ?>

    <?php if (!empty($b['show_categories']) && !$fixedCat && count($cats) > 1): ?>
      <nav class="blog-cats" aria-label="<?= e(t('Blog categories')) ?>">
        <ul>
          <li><a href="<?= e(blog_list_url([], $archive ? $here : blog_route())) ?>#<?= e($id) ?>"<?= !$catSlug && $archive ? ' aria-current="page"' : '' ?>><?= e(t('All posts')) ?></a></li>
          <?php foreach ($cats as $cat): ?>
            <li><a href="<?= e(blog_list_url(['category' => slugify($cat)], $archive ? $here : blog_route())) ?>#<?= e($id) ?>"<?= $archive && $catSlug === slugify($cat) && !$filter['tag'] && !$filter['author'] ? ' aria-current="page"' : '' ?>><?= e(blog_category_label($cat)) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </nav>
    <?php endif; ?>

    <?php if ($filterTitle): ?>
      <div class="blog-filter">
        <h2 class="blog-filter__title"><?= e($filterTitle) ?></h2>
        <p><?= e($total === 1 ? t('1 post') : t('%d posts', $total)) ?> · <a href="<?= e(blog_list_url([], $here)) ?>#<?= e($id) ?>"><?= e(t('Show all posts')) ?></a></p>
      </div>
    <?php endif; ?>

    <?php if ($shown): ?>
      <div class="blog-grid">
        <?php foreach ($shown as $p) echo blog_card($p, $hx, !empty($b['show_excerpt'])); ?>
      </div>
    <?php else: ?>
      <div class="notice blog-empty"><p><strong><?= e($active ? t('No posts here yet.') : t('No posts yet. Check back soon.')) ?></strong></p>
        <?php if ($active): ?><p><a href="<?= e(blog_list_url([], $here)) ?>#<?= e($id) ?>"><?= e(t('See all posts')) ?></a></p><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($archive && $pages > 1): ?>
      <nav class="blog-pager" aria-label="<?= e(t('Blog pages')) ?>">
        <ul>
          <?php if ($pg > 1): ?><li><a href="<?= e($listUrl(['pg' => $pg - 1])) ?>" rel="prev">← <?= e(t('Newer posts')) ?></a></li><?php endif; ?>
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li><?php if ($i === $pg): ?><a href="<?= e($listUrl(['pg' => $i])) ?>" aria-current="page"><span class="sr-only"><?= e(t('Page')) ?> </span><?= $i ?></a><?php else: ?><a href="<?= e($listUrl(['pg' => $i])) ?>"><span class="sr-only"><?= e(t('Page')) ?> </span><?= $i ?></a><?php endif; ?></li>
          <?php endfor; ?>
          <?php if ($pg < $pages): ?><li><a href="<?= e($listUrl(['pg' => $pg + 1])) ?>" rel="next"><?= e(t('Older posts')) ?> →</a></li><?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>

    <?php if ($b['button_text'] && $shown): ?>
      <p class="blog-more"><a class="btn btn--accent btn--lg" href="<?= e(link_to($b['button_link'])) ?>"><?= e($b['button_text']) ?></a></p>
    <?php endif; ?>
  </div>
</section>
