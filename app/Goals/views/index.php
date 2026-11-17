<?php
/**
 * @var array $patient @var string $today @var array $activePanel @var array $endedPanel @var ?int $stars
 */
use App\Goals\GoalPanel;

$pid = (int) $patient['id'];
$name = $patient['social_name'] ?: $patient['full_name'];
$grid = static function (array $panel, bool $canEnd): void {
    ?>
  <div class="tabela-rolagem">
  <table class="grade-metas">
    <thead><tr>
      <th scope="col">Meta</th>
      <?php foreach ($panel['weeks'] as $i => $ws): ?>
        <th scope="col"><?= e(substr(fmt_date($ws), 0, 5)) ?><?= $i === count($panel['weeks']) - 1 ? ' (atual)' : '' ?></th>
      <?php endforeach; ?>
      <th scope="col">Ações</th>
    </tr></thead>
    <tbody>
    <?php foreach ($panel['rows'] as $row): $g = $row['goal']; ?>
      <tr>
        <th scope="row">
          <?= e($g['emoji']) ?> <?= e($g['family_text']) ?>
          <?php if ((int) $g['secret'] === 1): ?><span class="selo selo-alerta">sigilosa</span><?php endif; ?>
          <br><span class="ajuda"><?= e(GoalPanel::CATEGORY_LABELS[$g['category']] ?? $g['category']) ?> · <?= (int) $g['times_per_week'] === 7 ? 'todo dia' : e($g['times_per_week']) . 'x por semana' ?> · desde <?= e(fmt_date($g['start_date'])) ?><?= $g['end_date'] !== null ? ' até ' . e(fmt_date($g['end_date'])) : '' ?></span>
        </th>
        <?php foreach ($row['cells'] as $c): ?>
          <td<?= $c['complete'] ? ' class="meta-completa"' : '' ?>><?= $c['applicable'] ? e($c['done']) . '/' . e($c['target']) . ($c['complete'] ? ' ✓' : '') : '—' ?></td>
        <?php endforeach; ?>
        <td>
          <a class="btn btn-quiet" href="<?= e(url('/metas/' . (int) $g['id'] . '/editar')) ?>">Editar</a>
          <?php if ($canEnd): ?>
          <form method="post" action="<?= e(url('/metas/' . (int) $g['id'] . '/encerrar')) ?>" class="form-inline"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Encerrar</button></form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/metas/' . (int) $g['id'] . '/excluir')) ?>" class="form-inline" data-confirm="Excluir esta meta? As estrelas dela deixam de contar."><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php
};
?>
<h1>Metas — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a>
   <a class="btn" href="<?= e(url('/pacientes/' . $pid . '/metas/nova')) ?>">Nova meta</a></p>
<?php if ($stars !== null): ?><p>Estrelas desde o início: <strong><?= e($stars) ?></strong></p><?php endif; ?>
<p class="ajuda">Cada célula mostra os dias feitos/combinados na semana (segunda a domingo); ✓ = semana cumprida. As marcações são feitas só pela família.</p>

<h2>Metas ativas</h2>
<?php if ($activePanel['rows'] === []): ?><p>Nenhuma meta ativa.</p><?php else: $grid($activePanel, true); endif; ?>

<?php if ($endedPanel['rows'] !== []): ?>
<h2>Encerradas</h2>
<?php $grid($endedPanel, false); ?>
<?php endif; ?>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
