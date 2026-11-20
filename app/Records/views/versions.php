<?php
/** @var array $patient @var array $entry @var array $versions */
$pid = (int) $patient['id'];
$labels = \App\Records\RecordController::KINDS;
?>
<h1>Versões — <?= e($labels[$entry['kind']]) ?></h1>
<p><?= e($patient['social_name'] ?: $patient['full_name']) ?> · <a href="<?= e(url('/pacientes/' . $pid . '/prontuario')) ?>">Voltar ao prontuário</a></p>
<?php foreach ($versions as $row): ?>
<section class="cartao">
  <h2>Versão <?= (int) $row['version'] ?></h2>
  <p class="ajuda">
    <?= e($row['author_name']) ?><?= $row['author_register'] ? ' (' . e($row['author_register']) . ')' : '' ?> ·
    <?= e(fmt_date((string) $row['created_at'])) ?> <?= e(substr((string) $row['created_at'], 11, 5)) ?>
    <?php if ($row['superseded_at'] !== null): ?> · substituída em <?= e(fmt_date((string) $row['superseded_at'])) ?> <?= e(substr((string) $row['superseded_at'], 11, 5)) ?><?php endif; ?>
    <?php if ($row['deleted_at'] !== null): ?> · removida em <?= e(fmt_date((string) $row['deleted_at'])) ?> — motivo: <?= e($row['deleted_reason']) ?><?php endif; ?>
  </p>
  <?php include __DIR__ . '/_content.php'; ?>
</section>
<?php endforeach; ?>
