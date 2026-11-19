<?php
/** @var array $wg resultado de WeightGoalController::card() @var bool $wgLink mostrar o botao "Ver meta" */
use App\WeightGoals\WeightGoalProgress;

$wgLink = $wgLink ?? true;
$g = $wg['goal'];
$fmtV = static function (float $v, string $kind): string {
    return $kind === 'peso' ? fmt_num($v, 1) . ' kg' : ($v > 0 ? '+' : '') . fmt_num($v, 2);
};
?>
<section class="cartao" id="cartao-meta-peso"><h2>Meta de peso</h2>
<?php if ($g === null): ?>
  <p class="ajuda">Nenhuma meta de peso.</p>
  <?php if ($wgLink): ?><p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $wg['patientId'] . '/meta-peso')) ?>"><?= $wg['canEdit'] ? 'Definir meta' : 'Ver meta' ?></a></p><?php endif; ?>
<?php else: $pr = $wg['progress']; $kind = (string) $g['kind']; $last = $pr['last']; $st = $pr['status']; ?>
  <p><strong>Alvo: <?= $kind === 'peso' ? '' : 'escore-z do IMC ' ?><?= e($fmtV((float) $g['target_value'], $kind)) ?></strong> até <?= e(fmt_date((string) $g['target_date'])) ?>
    <?php if ($wg['targetBmi'] !== null): ?> · IMC alvo <?= e(fmt_num($wg['targetBmi'], 1)) ?><?php endif; ?></p>
  <?php if ($wg['equivalentKg'] !== null): ?><p class="ajuda">≈ <?= e(fmt_num($wg['equivalentKg'], 1)) ?> kg com a altura atual</p><?php endif; ?>
  <p>Partida: <?= e($fmtV((float) $g['start_value'], $kind)) ?> em <?= e(fmt_date((string) $g['start_on'])) ?>
    <?php if ($last !== null && $last['on'] > $g['start_on']): ?> · Atual: <?= e($fmtV((float) $last['value'], $kind)) ?> em <?= e(fmt_date($last['on'])) ?>
      <?php if ($last['bmi'] !== null): ?> (IMC <?= e(fmt_num($last['bmi'], 1)) ?><?= $kind === 'peso' && $last['z'] !== null ? ', escore-z ' . e(($last['z'] > 0 ? '+' : '') . fmt_num($last['z'], 2)) : '' ?>)<?php endif; ?>
    <?php endif; ?></p>
  <p><span class="selo<?= in_array($st, ['atras', 'prazo_vencido'], true) ? ' selo-alerta' : '' ?>"><?= e(WeightGoalProgress::LABELS[$st]) ?></span>
    <?php if ($pr['expected'] !== null && !in_array($st, ['aguardando', 'atingida'], true)): ?> esperado em <?= e(fmt_date($last['on'])) ?> no caminho: <?= e($fmtV($pr['expected'], $kind)) ?><?php endif; ?></p>
  <?php $tt = WeightGoalProgress::trendText($pr); if ($tt !== null): ?><p class="ajuda"><?= e($tt) ?></p><?php endif; ?>
  <?php if ($wgLink): ?><p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $wg['patientId'] . '/meta-peso')) ?>">Ver meta</a></p><?php endif; ?>
<?php endif; ?>
</section>
