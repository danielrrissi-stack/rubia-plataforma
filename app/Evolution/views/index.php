<?php
/** @var array $patient @var string $period @var array $d resultado de EvolutionData::build @var array $errors @var array $values @var bool $canEdit */
use App\Evolution\EvolutionData;

$pid = (int) $patient['id'];
$child = $d['child'];
$pl = $d['plateau'];
$rate = static function (?float $v) use ($child): string {
    if ($v === null) {
        return 'poucos dados';
    }
    return $child ? 'queda de ' . fmt_num($v, 3) . ' no escore-z em 4 semanas' : fmt_num($v, 2) . ' kg/semana';
};
?>
<h1>Evolução — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">&larr; Voltar ao paciente</a>
  · <a href="<?= e(url('/pacientes/' . $pid . '/tratamento')) ?>">Tratamento</a>
  · <a href="<?= e(url('/pacientes/' . $pid . '/meta-peso')) ?>">Meta de peso</a></p>
<p class="ajuda">Curva de <?= $child ? 'escore-z do IMC' : 'peso' ?> cruzada com as doses dos remédios. Só a médica vê este painel.</p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="cartao" aria-labelledby="h-aviso">
  <h2 id="h-aviso">Efeito da dose atual</h2>
  <p><span class="selo<?= $pl['status'] === 'estabilizou' ? ' selo-alerta' : '' ?>"><?= e($pl['text']) ?></span></p>
  <?php if ($pl['from'] !== null && $pl['to'] !== null && $pl['status'] !== 'sem_dados'): ?>
    <p class="ajuda">Avaliado de <?= e(fmt_date($pl['from'])) ?> a <?= e(fmt_date($pl['to'])) ?>.</p>
  <?php endif; ?>
</section>

<nav class="periodos" aria-label="Período">
  <?php foreach (EvolutionData::PERIODS as $k => $label): ?>
    <a class="btn<?= $k === $period ? '' : ' btn-quiet' ?>"<?= $k === $period ? ' aria-current="page"' : '' ?> href="<?= e(url('/pacientes/' . $pid . '/evolucao?periodo=' . $k)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="cartao" aria-labelledby="h-curva">
  <h2 id="h-curva"><?= $child ? 'Escore-z do IMC' : 'Peso' ?> e doses</h2>
  <div id="evolution-chart" data-url="<?= e(url('/pacientes/' . $pid . '/evolucao.json?periodo=' . $period)) ?>">
    <div class="evolucao-graf"><canvas data-role="curva" role="img" aria-label="Curva de <?= $child ? 'escore-z do IMC' : 'peso' ?>"></canvas></div>
    <div class="evolucao-faixas"><canvas data-role="faixas" role="img" aria-label="Doses dos remédios ao longo do tempo"></canvas></div>
    <p class="ajuda" data-role="msg" role="status"></p>
  </div>
  <p class="ajuda">Bolinha cheia: pesagem no consultório. Bolinha vazia: pesagem em casa.<?= $d['path'] !== null ? ' Tracejado: caminho da meta de peso.' : '' ?></p>
</section>

<section class="cartao" aria-labelledby="h-ritmo">
  <h2 id="h-ritmo">Ritmo por dose</h2>
  <?php if ($d['meds'] === []): ?><p class="ajuda">Nenhum remédio no período.</p><?php else: ?>
  <div class="tabela-rolagem"><table class="tabela">
    <thead><tr><th scope="col">Remédio</th><th scope="col">Dose</th><th scope="col">De</th><th scope="col">Até</th><th scope="col">Ritmo</th></tr></thead>
    <tbody>
    <?php foreach ($d['meds'] as $m): foreach ($m['segments'] as $s): ?>
      <tr><td><?= e($m['name']) ?></td><td><?= e($s['dose']) ?></td><td><?= e(fmt_date($s['from'])) ?></td>
        <td><?= $s['to'] === null ? 'atual' : e(fmt_date($s['to'])) ?></td><td><?= e($rate($s['rate'])) ?></td></tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<details class="cartao" id="evolucao-metabolismo">
  <summary>Metabolismo (cintura, pressão e exames)</summary>
  <?php if ($d['metabolism'] === []): ?><p class="ajuda">Sem cintura, pressão ou exames no período.</p><?php endif; ?>
  <div id="evolution-metabolism"></div>
</details>

<?php if ($canEdit): ?>
<section class="cartao" aria-labelledby="h-criterio">
  <h2 id="h-criterio">Critério de "estabilizou"</h2>
  <p class="ajuda">Padrão: 0,2 kg/semana · 1% em 4 semanas · 0,05 de escore-z em 4 semanas. Vale para as últimas 4 semanas da dose atual.</p>
  <form method="post" action="<?= e(url('/pacientes/' . $pid . '/evolucao/criterio')) ?>">
    <?= csrf_field() ?>
    <fieldset class="campo">
      <legend>Adulto: medir por</legend>
      <label><input type="radio" name="modo" value="kg"<?= $values['modo'] === 'kg' ? ' checked' : '' ?>> kg por semana</label>
      <label><input type="radio" name="modo" value="pct"<?= $values['modo'] === 'pct' ? ' checked' : '' ?>> % do peso em 4 semanas</label>
    </fieldset>
    <div class="campo"><label for="crit-kg">Perda mínima (kg por semana)</label>
      <input type="text" inputmode="decimal" id="crit-kg" name="kg" value="<?= e($values['kg']) ?>" maxlength="6"></div>
    <div class="campo"><label for="crit-pct">Perda mínima (% do peso em 4 semanas)</label>
      <input type="text" inputmode="decimal" id="crit-pct" name="pct" value="<?= e($values['pct']) ?>" maxlength="6"></div>
    <div class="campo"><label for="crit-z">Criança: queda mínima do escore-z em 4 semanas</label>
      <input type="text" inputmode="decimal" id="crit-z" name="z" value="<?= e($values['z']) ?>" maxlength="6"></div>
    <button type="submit" class="btn">Salvar critério</button>
  </form>
</section>
<?php endif; ?>

<script src="<?= e(asset('/assets/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/evolution-chart.js')) ?>"></script>
