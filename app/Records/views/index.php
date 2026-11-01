<?php
/** @var array $patient @var array $history @var array $evolutions @var bool $canEdit */
$pid = (int) $patient['id'];
$labels = \App\Records\RecordController::KINDS;
$meta = static function (array $row): string {
    $by = $row['author_name'] . ($row['author_register'] ? ' (' . $row['author_register'] . ')' : '');
    return $by . ' · ' . substr(fmt_date((string) $row['created_at']), 0, 10) . ' ' . substr((string) $row['created_at'], 11, 5) . ' · versão ' . (int) $row['version'];
};
$actions = static function (array $row, bool $canEdit) use ($pid): void {
    $id = (int) $row['id'];
    echo '<p><a class="btn btn-quiet" href="' . e(url('/prontuario/' . $id . '/versoes')) . '">Ver versões</a>';
    if ($canEdit) {
        echo ' <a class="btn btn-quiet" href="' . e(url('/prontuario/' . $id . '/revisar')) . '">Corrigir</a>';
    }
    echo '</p>';
    if ($canEdit) {
        echo '<form method="post" action="' . e(url('/prontuario/' . $id . '/remover')) . '">' . csrf_field()
            . '<label>Motivo da remoção <input name="motivo" maxlength="255" required></label> '
            . '<button type="submit" class="btn btn-quiet">Remover</button></form>';
    }
};
?>
<h1>Prontuário — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a></p>

<h2>Histórico</h2>
<?php foreach ($history as $kind => $rows): ?>
<section class="cartao">
  <h3><?= e($labels[$kind]) ?></h3>
  <?php if ($rows === []): ?><p class="ajuda">Nada registrado.</p><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <p class="ajuda"><?= e($meta($row)) ?></p>
    <?php include __DIR__ . '/_content.php'; ?>
    <?php $actions($row, $canEdit); ?>
  <?php endforeach; ?>
  <?php if ($canEdit && $rows === []): ?>
    <p><a class="btn" href="<?= e(url('/pacientes/' . $pid . '/prontuario/' . $kind)) ?>">Registrar</a></p>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<h2>Evoluções</h2>
<?php if ($canEdit): ?><p><a class="btn" href="<?= e(url('/pacientes/' . $pid . '/prontuario/evolucao')) ?>">Nova evolução</a></p><?php endif; ?>
<?php if ($evolutions === []): ?><p>Nenhuma evolução registrada.</p><?php endif; ?>
<?php foreach ($evolutions as $row): ?>
<section class="cartao">
  <h3>Evolução de <?= e(fmt_date($row['encounter_date'])) ?></h3>
  <p class="ajuda"><?= e($meta($row)) ?></p>
  <?php include __DIR__ . '/_content.php'; ?>
  <?php $actions($row, $canEdit); ?>
</section>
<?php endforeach; ?>
