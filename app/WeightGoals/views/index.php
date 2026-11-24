<?php
/** @var array $patient @var string[] $errors @var array $values @var array $card @var list<string> $allowed @var array $starts @var ?string $zLimit @var list<array> $history */
use App\WeightGoals\WeightGoalKind;
use App\WeightGoals\WeightGoalProgress;

$pid = (int) $patient['id'];
$g = $card['goal'];
$fmtV = static function (float $v, string $kind): string {
    return $kind === 'peso' ? fmt_num($v, 1) . ' kg' : ($v > 0 ? '+' : '') . fmt_num($v, 2);
};
$anyStart = array_filter($starts) !== [];
$reasons = ['substituida' => 'substituída', 'encerrada' => 'encerrada'];
?>
<h1>Meta de peso — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">&larr; Voltar ao paciente</a>
  · <a href="<?= e(url('/pacientes/' . $pid . '/crescimento')) ?>">Medições</a></p>
<p class="ajuda">Só a equipe vê esta meta. Ela não aparece para a família nem para a criança.</p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php $wg = $card; $wgLink = false; include __DIR__ . '/_card.php'; ?>

<?php if ($g !== null): $kind = (string) $g['kind']; $pr = $card['progress']; ?>
<section class="cartao" aria-labelledby="h-grafico">
  <h2 id="h-grafico">Evolução</h2>
  <div id="weight-goal-chart" data-url="<?= e(url('/pacientes/' . $pid . '/meta-peso.json')) ?>">
    <div class="grafico"><canvas role="img" aria-label="Gráfico da meta de peso: <?= e(WeightGoalProgress::LABELS[$pr['status']]) ?>"></canvas></div>
    <p class="ajuda" data-role="msg" role="status"></p>
  </div>
  <p class="ajuda">Pontos: medições. Tracejado: caminho combinado até o alvo. A linha de tendência aparece a partir da 5ª medição.</p>
  <table class="tabela">
    <thead><tr><th scope="col">Data</th><th scope="col"><?= $kind === 'peso' ? 'Peso' : 'Escore-z do IMC' ?></th><th scope="col"><?= $kind === 'peso' ? 'IMC' : 'Peso' ?></th><th scope="col">Diferença do caminho</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($card['points']) as $p): $exp = WeightGoalProgress::expected($g, $p['on']); $d = $p['value'] - $exp; ?>
      <tr><td><?= e(fmt_date($p['on'])) ?><?php if ($p['source'] === 'familia'): ?> <span class="selo selo-familia">família</span><?php endif; ?></td>
        <td><?= e($fmtV($p['value'], $kind)) ?></td>
        <td><?= $kind === 'peso' ? e($p['bmi'] === null ? '—' : fmt_num($p['bmi'], 1)) : e(fmt_num($p['weight'], 1) . ' kg') ?></td>
        <td><?= e(($d > 0 ? '+' : '') . ($kind === 'peso' ? fmt_num($d, 1) . ' kg' : fmt_num($d, 2))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($card['canEdit']): ?>
  <form method="post" action="<?= e(url('/pacientes/' . $pid . '/meta-peso/encerrar')) ?>" data-confirm="Encerrar a meta de peso? Ela continua no histórico.">
    <?= csrf_field() ?><input type="hidden" name="meta" value="<?= e((string) $g['id']) ?>">
    <button type="submit" class="btn btn-quiet">Encerrar meta</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($card['canEdit']): ?>
<section class="cartao" aria-labelledby="h-definir">
  <h2 id="h-definir"><?= $g === null ? 'Definir meta' : 'Trocar meta' ?></h2>
  <?php if (!$anyStart): ?>
    <p class="ajuda">Registre uma medição (peso<?= in_array(WeightGoalKind::IMC_Z, $allowed, true) ? ' e altura' : '' ?>) antes de definir a meta.</p>
  <?php else: ?>
  <?php if ($g !== null): ?><p class="ajuda">A meta atual será encerrada como "substituída" e continua no histórico.</p><?php endif; ?>
  <form method="post" action="<?= e(url('/pacientes/' . $pid . '/meta-peso')) ?>">
    <?= csrf_field() ?>
    <?php if (count($allowed) === 2): ?>
      <fieldset><legend>Tipo de meta</legend>
      <?php foreach ($allowed as $k): ?>
        <label><input type="radio" name="tipo" value="<?= e($k) ?>" <?= ($values['tipo'] === $k || ($values['tipo'] === '' && $k === WeightGoalKind::IMC_Z)) ? 'checked' : '' ?>> <?= e(WeightGoalKind::LABELS[$k]) ?></label>
      <?php endforeach; ?>
      </fieldset>
    <?php endif; ?>
    <?php foreach ($starts as $k => $s): if ($s === null) { continue; } ?>
      <p class="ajuda">Partida <?= count($allowed) === 2 ? '(' . e(WeightGoalKind::LABELS[$k]) . ')' : '' ?>: <?= e($fmtV($s['value'], $k)) ?> em <?= e(fmt_date($s['on'])) ?> (última medição).</p>
    <?php endforeach; ?>
    <p><label>Meta (<?= count($allowed) === 2 ? 'kg ou escore-z, conforme o tipo' : e(WeightGoalKind::LABELS[$allowed[0]]) ?>)
      <input name="alvo" value="<?= e($values['alvo']) ?>" inputmode="decimal" required></label></p>
    <p><label>Prazo <input type="date" name="prazo" value="<?= e($values['prazo']) ?>" required></label>
      <?php if ($zLimit !== null): ?><span class="ajuda">Na meta em escore-z, o prazo vai até <?= e(fmt_date($zLimit)) ?>.</span><?php endif; ?></p>
    <p><label>Observações <textarea name="notas" maxlength="500" rows="2"><?= e($values['notas']) ?></textarea></label></p>
    <button type="submit" class="btn">Salvar meta</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($history !== []): ?>
<section class="cartao" aria-labelledby="h-historico"><h2 id="h-historico">Metas anteriores</h2>
  <ul>
  <?php foreach ($history as $h): $hg = $h['goal']; $hk = (string) $hg['kind']; ?>
    <li><?= e(fmt_date(substr((string) $hg['created_at'], 0, 10))) ?> a <?= e(fmt_date(substr((string) $hg['ended_at'], 0, 10))) ?>:
      alvo <?= e($fmtV((float) $hg['target_value'], $hk)) ?> até <?= e(fmt_date((string) $hg['target_date'])) ?>
      — <?= e($reasons[$hg['end_reason']] ?? '') ?> (situação final: <?= e(WeightGoalProgress::LABELS[$h['progress']['status']]) ?>)</li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>"></script>
<?php if ($g !== null): ?>
<script src="<?= e(url('/assets/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/weight-goal-chart.js')) ?>"></script>
<?php endif; ?>
