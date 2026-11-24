<?php
/** @var array $patient @var array $dietCard @var bool $canEdit @var ?array $draft */
use App\Diets\DietAdapter;
use App\Diets\DietController;

$pid = (int) $patient['id'];
$rs = $dietCard['restrictions'];
?>
<section class="cartao" id="restricoes"><h2>Restrições e alergias alimentares</h2>
<?php if ($dietCard['allergyText'] !== null): ?>
  <p class="ajuda"><strong>Alergias no prontuário:</strong>
    <?= $dietCard['allergyText'] === '' ? 'nada registrado.' : nl2br(e($dietCard['allergyText'])) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<form method="post" action="<?= e(url('/pacientes/' . $pid . '/restricoes')) ?>">
  <?= csrf_field() ?>
  <fieldset class="campo"><legend>Marque o que o paciente não pode ou não deve comer</legend>
  <?php foreach (DietAdapter::RESTRICTIONS as $k => $label): ?>
    <label class="inline"><input type="checkbox" name="restricoes[]" value="<?= e($k) ?>" <?= in_array($k, $rs['items'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
  <?php endforeach; ?>
  </fieldset>
  <p><label>Outros alimentos a evitar (separe por vírgula)
    <input type="text" name="outros" maxlength="500" value="<?= e($rs['other_text']) ?>" data-ditado></label></p>
  <p class="ajuda">Lactose: leite, iogurte e queijo continuam na sugestão, na versão sem lactose. As demais restrições trocam o alimento
    por outro de mesmas calorias. "Outros alimentos" só geram aviso, para você trocar no editor.</p>
  <p><button type="submit" class="btn btn-quiet">Salvar restrições</button>
  <?php if ($rs['updated_at'] !== null): ?><span class="ajuda">Atualizado em <?= e(fmt_date(substr((string) $rs['updated_at'], 0, 10))) ?> por <?= e((string) $rs['updated_by_name']) ?>.</span><?php endif; ?></p>
</form>
<?php else: ?>
  <p><?= $rs['items'] === [] && $rs['other_text'] === '' ? 'Nenhuma restrição registrada.' : e(implode(', ', array_map(static fn(string $k): string => DietAdapter::RESTRICTIONS[$k] ?? $k, $rs['items'])) . ($rs['other_text'] !== '' ? ' · Outros: ' . $rs['other_text'] : '')) ?></p>
<?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="cartao"><h2>Sugestão de cardápio</h2>
<?php if ($dietCard['band'] === null): ?>
  <p class="ajuda">As dietas prontas são para pacientes a partir de 3 anos.</p>
<?php else: ?>
<form method="post" action="<?= e(url('/pacientes/' . $pid . '/plano/sugestao')) ?>">
  <?= csrf_field() ?>
  <p><label>Versão
    <select name="versao">
      <?php foreach (DietController::BAND_LABEL as $k => $label): ?><option value="<?= e($k) ?>" <?= $dietCard['band'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
  <label>Calorias
    <select name="kcal">
      <?php foreach ($dietCard['levels']['adulto'] as $k): ?><option value="<?= e($k) ?>" <?= $dietCard['kcal'] === $k ? 'selected' : '' ?>><?= e(number_format($k, 0, ',', '.')) ?> kcal</option><?php endforeach; ?>
    </select></label>
  <label>Duração
    <select name="dias">
      <?php foreach ([7, 14] as $n): ?><option value="<?= $n ?>" <?= $dietCard['days'] === $n ? 'selected' : '' ?>><?= $n ?> dias</option><?php endforeach; ?>
    </select></label>
  <label>Início
    <input type="date" name="inicio" value="<?= e($dietCard['start']) ?>" min="<?= e(date('Y-m-d', strtotime('-7 days'))) ?>" max="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>" required></label></p>
  <p class="ajuda">
    <?= $dietCard['goalKcal'] === null ? 'Paciente sem meta de energia: confira as calorias.' : 'Meta de energia vigente: ' . e(number_format($dietCard['goalKcal'], 0, ',', '.')) . ' kcal (arredondada para a centena).' ?>
    Versão infantil: 1.000 a 2.000 kcal; adolescente/adulto: 1.000 a 3.000 kcal.
    A sugestão vira um rascunho já adaptado às restrições acima; a família só vê depois de publicado, e nunca vê as calorias.
  </p>
  <?php if ($draft !== null): ?>
  <p><label class="inline"><input type="checkbox" name="descartar_rascunho" value="1"> Descartar o rascunho atual ("<?= e($draft['title']) ?>")</label></p>
  <?php endif; ?>
  <p><button type="submit" class="btn">Gerar sugestão</button>
    <a class="btn btn-quiet" href="<?= e(url('/dietas-prontas')) ?>">Ver dietas prontas</a></p>
</form>
<?php endif; ?>
</section>
<?php endif; ?>
