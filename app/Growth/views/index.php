<?php
/**
 * @var array $patient @var string $today @var array $childRows @var array $adultRows @var array $velocity
 * @var array<string,string> $tabs @var bool $adultPatient @var bool $canWrite
 */
use App\Growth\GrowthFormat;

$pid = (int) $patient['id'];
$dash = '—';
$num = static fn(mixed $v, int $d = 1): string => $v === null ? $dash : fmt_num((float) $v, $d);
$indCell = static function (?array $ind) use ($dash): string {
    $f = GrowthFormat::indicator($ind);
    if ($f === null) {
        return e($dash);
    }
    return 'Z ' . e($f['z']) . ' · P ' . e($f['p']) . '<br><span class="ajuda">' . e($f['status']) . '</span>';
};
$badges = static function (array $row): string {
    $m = $row['m'];
    $out = $m['source'] === 'familia' ? '<span class="selo selo-familia">família</span> ' : '<span class="selo">equipe</span> ';
    return $out;
};
$actions = static function (array $row) use ($canWrite, $pid): string {
    $id = (int) $row['m']['id'];
    $h = '<a href="' . e(url('/medidas/' . $id . '/versoes')) . '">Versões</a>';
    if ($canWrite) {
        $h = '<a href="' . e(url('/medidas/' . $id . '/corrigir')) . '">Corrigir</a> · ' . $h
            . '<details><summary>Remover</summary><form method="post" action="' . e(url('/medidas/' . $id . '/remover')) . '">'
            . csrf_field()
            . '<label for="motivo-' . $id . '">Motivo da remoção</label>'
            . '<input type="text" id="motivo-' . $id . '" name="motivo" required maxlength="255">'
            . '<button type="submit" class="btn btn-quiet">Confirmar remoção</button></form></details>';
    }
    return $h;
};
$flags = static function (array $row): string {
    $out = '';
    if ($row['m']['retroactive']) {
        $out .= ' <span class="selo">retroativo</span>';
    }
    if ($row['implausible']) {
        $out .= ' <span class="selo selo-alerta">verificar medida</span>';
    }
    return $out;
};
?>
<h1>Crescimento — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">&larr; Voltar ao paciente</a>
  <?php if ($canWrite): ?> · <a class="btn" href="<?= e(url('/pacientes/' . $pid . '/medidas/nova')) ?>">Registrar medida</a><?php endif; ?></p>

<section class="cartao" aria-labelledby="h-curvas">
  <h2 id="h-curvas">Curvas</h2>
  <div id="growth-chart" data-url="<?= e(url('/pacientes/' . $pid . '/curvas.json')) ?>" data-adult="<?= $adultPatient ? '1' : '0' ?>">
    <div class="abas" role="group" aria-label="Indicador">
      <?php if ($adultPatient): ?>
        <button type="button" class="aba" data-indicador="peso" aria-pressed="true">Peso</button>
        <button type="button" class="aba" data-indicador="imc" aria-pressed="false">IMC</button>
      <?php else: $first = true; foreach ($tabs as $key => $label): ?>
        <button type="button" class="aba" data-indicador="<?= e($key) ?>" aria-pressed="<?= $first ? 'true' : 'false' ?>"><?= e($label) ?></button>
      <?php $first = false; endforeach; endif; ?>
    </div>
    <?php if (!$adultPatient): ?>
    <div class="abas" role="group" aria-label="Escala">
      <button type="button" class="aba" data-tipo="z" aria-pressed="true">Escore Z</button>
      <button type="button" class="aba" data-tipo="percentil" aria-pressed="false">Percentil</button>
    </div>
    <?php endif; ?>
    <p class="ajuda" data-role="msg" role="status"></p>
    <div class="grafico"><canvas aria-label="Gráfico de crescimento" role="img"></canvas></div>
  </div>
</section>

<?php if ($childRows !== []): ?>
<section class="cartao" aria-labelledby="h-crianca">
  <h2 id="h-crianca">Medidas (até 19 anos)</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr>
      <th>Data</th><th>Idade</th><th>Peso (kg)</th><th>Altura (cm)</th><th>IMC (kg/m²)</th><th>IMC/idade</th>
      <th>Peso/idade</th><th>Altura/idade</th><th>Peso/altura</th><th>PC (cm)</th><th>Cintura (cm)</th><th>PA</th><th>Origem</th><th>Ações</th>
    </tr></thead>
    <tbody>
    <?php foreach ($childRows as $row): $m = $row['m']; ?>
      <tr>
        <td><?= e(fmt_date($m['measured_on'])) ?><?= $flags($row) ?></td>
        <td><?= e($row['age']) ?></td>
        <td><?= e($num($m['weight_kg'], 1)) ?></td>
        <td><?= e($num($m['height_cm'], 1)) ?><?= $m['position'] ? ' <span class="ajuda">(' . ($m['position'] === 'deitado' ? 'deitado' : 'em pé') . ')</span>' : '' ?></td>
        <td><?= e($num($row['bmi'], 1)) ?></td>
        <td><?= $indCell($row['ind']['bfa']) ?></td>
        <td><?= $indCell($row['ind']['wfa']) ?></td>
        <td><?= $indCell($row['ind']['hfa']) ?></td>
        <td><?= $indCell($row['ind']['wflh']) ?></td>
        <td><?= $m['head_cm'] !== null && \App\Growth\Age::days((string) $patient['birth_date'], (string) $m['measured_on']) < 731 ? e(fmt_num((float) $m['head_cm'], 1)) : e($dash) ?></td>
        <td><?= e($num($m['waist_cm'], 1)) ?></td>
        <td><?= $m['systolic'] !== null ? e($m['systolic'] . '/' . $m['diastolic']) . '<br><span class="ajuda">' . e((string) $row['bp']) . '</span>' : e($dash) ?></td>
        <td><?= $badges($row) ?></td>
        <td><?= $actions($row) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($adultRows !== []): ?>
<section class="cartao" aria-labelledby="h-adulto">
  <h2 id="h-adulto">Medidas (adulto)</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr>
      <th>Data</th><th>Peso (kg)</th><th>Altura (cm)</th><th>IMC</th><th>Cintura</th><th>Quadril (cm)</th><th>RCQ</th><th>PA</th><th>Origem</th><th>Ações</th>
    </tr></thead>
    <tbody>
    <?php foreach ($adultRows as $row): $m = $row['m']; ?>
      <tr>
        <td><?= e(fmt_date($m['measured_on'])) ?><?= $flags($row) ?></td>
        <td><?= e($num($m['weight_kg'], 1)) ?></td>
        <td><?= e($num($m['height_cm'], 1)) ?></td>
        <td><?= $row['bmi'] !== null ? e(fmt_num($row['bmi'], 1)) . '<br><span class="ajuda">' . e($row['bmiClass']) . '</span>' : e($dash) ?></td>
        <td><?= $m['waist_cm'] !== null ? e(fmt_num((float) $m['waist_cm'], 1)) . ' cm<br><span class="ajuda">' . e((string) $row['waistRisk']) . '</span>' : e($dash) ?></td>
        <td><?= e($num($m['hip_cm'], 1)) ?></td>
        <td><?= $row['whr'] !== null ? e(fmt_num($row['whr'], 2)) . '<br><span class="ajuda">' . e((string) $row['whrRisk']) . '</span>' : e($dash) ?></td>
        <td><?= $m['systolic'] !== null ? e($m['systolic'] . '/' . $m['diastolic']) . '<br><span class="ajuda">' . e((string) $row['bp']) . '</span>' : e($dash) ?></td>
        <td><?= $badges($row) ?></td>
        <td><?= $actions($row) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($childRows === [] && $adultRows === []): ?><p>Nenhuma medida registrada.</p><?php endif; ?>

<section class="cartao" aria-labelledby="h-vel">
  <h2 id="h-vel">Velocidade de crescimento</h2>
  <?php if ($velocity === []): ?>
    <p><?= e($dash) ?> <span class="ajuda">São necessárias duas alturas com ao menos 90 dias de intervalo.</span></p>
  <?php else: ?>
    <ul>
    <?php foreach ($velocity as $v): ?>
      <li><?= e(fmt_date($v['from'])) ?> a <?= e(fmt_date($v['to'])) ?>: <strong><?= e(fmt_num($v['cm_per_year'], 1)) ?> cm/ano</strong></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<script src="<?= e(url('/assets/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/growth-chart.js')) ?>"></script>
