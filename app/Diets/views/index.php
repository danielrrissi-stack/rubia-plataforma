<?php
/** @var list<array> $rows @var int $total @var string $band @var int $week @var ?int $kcal */
use App\Diets\DietBuilder;
use App\Diets\DietController;
?>
<h1>Dietas prontas</h1>
<p><a href="<?= e(url('/modelos-plano')) ?>">Modelos de plano</a></p>
<p class="ajuda"><?= e(number_format($total, 0, ',', '.')) ?> dietas: 52 semanas, com frutas e legumes da estação, de 100 em 100 kcal
  (infantil 1.000 a 2.000; adolescente/adulto 1.000 a 3.000). Cada dieta mostra os 7 dias da semana N. Para usar em um paciente,
  abra o plano alimentar dele e clique em "Gerar sugestão", para 7 ou 14 dias.</p>
<form method="get" action="<?= e(url('/dietas-prontas')) ?>">
  <p><label>Versão
    <select name="versao">
      <?php foreach (DietController::BAND_LABEL as $k => $label): ?><option value="<?= e($k) ?>" <?= $band === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
  <label>Semana
    <select name="semana">
      <?php for ($w = 1; $w <= 52; $w++): ?><option value="<?= $w ?>" <?= $week === $w ? 'selected' : '' ?>>Semana <?= $w ?> (<?= e(DietBuilder::SEASON_LABEL[DietBuilder::season($w)]) ?>)</option><?php endfor; ?>
    </select></label>
  <label>Calorias
    <select name="kcal">
      <option value="">Todas</option>
      <?php foreach (DietBuilder::levels('adulto') as $k): ?><option value="<?= $k ?>" <?= $kcal === $k ? 'selected' : '' ?>><?= e(number_format($k, 0, ',', '.')) ?> kcal</option><?php endforeach; ?>
    </select></label>
  <button type="submit" class="btn btn-quiet">Filtrar</button></p>
</form>
<?php if ($rows === []): ?><p>Nenhuma dieta com esses filtros.</p><?php else: ?>
<div class="tabela-rolagem"><table class="tabela">
  <thead><tr><th>Nome</th><th>Estação</th><th>kcal</th><th>Proteína</th><th>Carboidrato</th><th>Gordura</th><th>Fibra</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $d): ?>
    <tr>
      <td><a href="<?= e(url('/dietas-prontas/' . $d['code'])) ?>"><?= e($d['code']) ?></a></td>
      <td><?= e(DietBuilder::SEASON_LABEL[$d['season']]) ?></td>
      <td><?= e(fmt_num((float) $d['kcal'], 0)) ?></td>
      <td><?= e(fmt_num((float) $d['protein_g'], 0)) ?> g</td>
      <td><?= e(fmt_num((float) $d['carb_g'], 0)) ?> g</td>
      <td><?= e(fmt_num((float) $d['fat_g'], 0)) ?> g</td>
      <td><?= e(fmt_num((float) $d['fiber_g'], 0)) ?> g</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
