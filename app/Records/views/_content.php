<?php
/** Conteudo somente-leitura de um registro. @var array $row linha de clinical_entries */
$p = json_decode((string) $row['payload'], true) ?: [];
$show = static function (string $label, mixed $val): void {
    if ($val === null || $val === '' || $val === []) {
        return;
    }
    echo '<dt>' . e($label) . '</dt><dd>' . nl2br(e((string) $val)) . '</dd>';
};
?>
<dl>
<?php if ($row['kind'] === 'historico_familiar'): ?>
  <?php if (($p['condicoes'] ?? []) === []): ?><dt>Condições</dt><dd>Nenhuma informada</dd><?php endif; ?>
  <?php foreach ($p['condicoes'] ?? [] as $c): ?>
    <dt><?= e(ucfirst($c['condicao'])) ?></dt><dd><?= e($c['parentesco'] ?? 'parentesco não informado') ?></dd>
  <?php endforeach; ?>
  <?php $show('Observações', $p['observacoes'] ?? ''); ?>
<?php elseif ($row['kind'] === 'gestacional'): ?>
  <?php
  $show('Peso ao nascer', isset($p['peso_nascer_g']) ? $p['peso_nascer_g'] . ' g' : null);
  $show('Comprimento ao nascer', isset($p['comprimento_nascer_cm']) ? fmt_num((float) $p['comprimento_nascer_cm'], 1) . ' cm' : null);
  $show('Idade gestacional', isset($p['idade_gestacional_semanas']) ? $p['idade_gestacional_semanas'] . ' semanas' : null);
  $show('Tipo de parto', $p['tipo_parto'] ?? null);
  $show('Aleitamento materno', isset($p['aleitamento_materno_meses']) ? $p['aleitamento_materno_meses'] . ' meses' : null);
  $show('Diabetes gestacional', !empty($p['diabetes_gestacional']) ? 'Sim' : 'Não');
  $show('Intercorrências', $p['intercorrencias'] ?? '');
  ?>
<?php elseif ($row['kind'] === 'evolucao'): ?>
  <?php
  $show('Data do atendimento', fmt_date($row['encounter_date']));
  $show('CID-10', $row['cid10']);
  foreach (\App\Records\RecordController::EVOLUTION_FIELDS as $k => $label) {
      $show($label, $p[$k] ?? '');
  }
  ?>
<?php else: ?>
  <?php $show('Texto', $p['texto'] ?? ''); ?>
<?php endif; ?>
</dl>
