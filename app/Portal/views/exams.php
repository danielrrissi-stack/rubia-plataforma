<?php
/** @var array $patient @var string $first @var list<array{collected_on:string,items:list<array{name:string,value:string,unit:string,phrase:?string}>}> $collections */
$pid = (int) $patient['id'];
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>Exames de <?= e($first) ?></h1>
<?php if ($collections === []): ?>
  <p class="aviso">Ainda não há exames liberados pela Dra. Rubia.</p>
<?php else: ?>
  <p class="ajuda">Em caso de dúvida sobre algum resultado, converse com a Dra. Rubia na próxima consulta.</p>
  <?php foreach ($collections as $c): ?>
    <section class="portal-exames">
      <h2>Exames de <?= e(fmt_date($c['collected_on'])) ?></h2>
      <ul>
        <?php foreach ($c['items'] as $it): ?>
          <li><?= e($it['name']) ?>: <?= e(trim($it['value'] . ' ' . $it['unit'])) ?><?= $it['phrase'] !== null ? ' — ' . e($it['phrase']) : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
