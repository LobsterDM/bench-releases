<?php ?>
<?= section_open('faq', $b, $anchor) ?>
  <div class="container narrow">
    <?= section_head($b) ?>
    <div class="faq">
      <?php foreach ($b['items'] as $it): ?>
        <details class="faq__item">
          <summary><?= e($it['question'] ?? '') ?></summary>
          <div class="faq__answer prose"><?= rich($it['answer'] ?? '') ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php
// FAQ structured data for search engines
$faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
foreach ($b['items'] as $it) {
    $faqLd['mainEntity'][] = ['@type' => 'Question', 'name' => $it['question'] ?? '', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags(rich($it['answer'] ?? ''))]];
}
?>
<script type="application/ld+json"><?= json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
