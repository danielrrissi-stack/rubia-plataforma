<?php
/** @var array $patient @var array $versions @var array<int,string> $names */
$dash = '—';
$n = static fn(mixed $v, int $d = 1): string => $v === null ? '—' : fmt_num((float) $v, $d);
?>
<h1>Versões da medida — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $patient['id'] . '/crescimento')) ?>">&larr; Voltar ao crescimento</a></p>
<div class="tabela-rolagem">
<table>
  <thead><tr>
    <th>Versão</th><th>Data da medida</th><th>Peso (kg)</th><th>Altura (cm)</th><th>Cintura</th><th>Quadril</th><th>PC</th><th>PA</th>
    <th>Origem</th><th>Registrada por</th><th>Registrada em</th><th>Situação</th>
  </tr></thead>
  <tbody>
  <?php foreach ($versions as $i => $v): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= e(fmt_date($v['measured_on'])) ?></td>
      <td><?= e($n($v['weight_kg'], 1)) ?></td>
      <td><?= e($n($v['height_cm'], 1)) ?></td>
      <td><?= e($n($v['waist_cm'])) ?></td>
      <td><?= e($n($v['hip_cm'])) ?></td>
      <td><?= e($n($v['head_cm'])) ?></td>
      <td><?= $v['systolic'] !== null ? e($v['systolic'] . '/' . $v['diastolic']) : e($dash) ?></td>
      <td><?= $v['source'] === 'familia' ? 'família' : 'equipe' ?></td>
      <td><?= e($names[(int) $v['created_by']] ?? '') ?></td>
      <td><?= e(date('d/m/Y H:i', strtotime((string) $v['created_at']))) ?></td>
      <td><?php if ($v['deleted_at'] !== null): ?>Removida — <?= e((string) $v['deleted_reason']) ?>
          <?php elseif ($v['superseded_at'] !== null): ?>Substituída
          <?php else: ?>Vigente<?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
