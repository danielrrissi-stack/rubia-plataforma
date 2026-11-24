<?php
/** @var array $patient @var string $first @var array $values @var string[] $errors @var bool $under2 */
$pid = (int) $patient['id'];
$field = static function (string $id, string $label, array $values, string $help): void {
    echo '<div class="campo"><label for="' . e($id) . '">' . e($label) . '</label>'
        . '<input type="text" inputmode="decimal" id="' . e($id) . '" name="' . e($id) . '" value="' . e($values[$id] ?? '') . '">'
        . '<p class="ajuda">' . e($help) . '</p></div>';
};
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>Registrar medida de <?= e($first) ?></h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<details class="portal-dicas">
  <summary>Como medir</summary>
  <ul>
    <li><strong>Peso:</strong> de manhã, de preferência em jejum, com pouca roupa e sem sapatos.</li>
    <li><strong>Altura:</strong> descalço, de costas para a parede, olhando para a frente. Marque a altura da cabeça e meça com fita.</li>
    <li><strong>Cintura:</strong> com fita métrica, na altura do umbigo, sem apertar a pele, ao final de uma respiração normal.</li>
  </ul>
</details>
<form method="post" action="<?= e(url('/portal/p/' . $pid . '/medidas/nova')) ?>" novalidate>
  <?= csrf_field() ?>
  <p class="ajuda">Preencha pelo menos uma medida.</p>
  <?php $field('data_medida', 'Data da medida', $values, 'Dia/mês/ano, por exemplo 25/03/2026.'); ?>
  <?php $field('peso', 'Peso (kg)', $values, 'Use vírgula para os decimais, por exemplo 28,4.'); ?>
  <?php $field('altura', 'Altura (cm)', $values, 'Por exemplo 130,5.'); ?>
  <?php if ($under2): ?>
  <div class="campo">
    <label for="posicao">Como foi medida a altura?</label>
    <select id="posicao" name="posicao">
      <option value="deitado"<?= ($values['posicao'] ?? 'deitado') !== 'em_pe' ? ' selected' : '' ?>>Deitado</option>
      <option value="em_pe"<?= ($values['posicao'] ?? '') === 'em_pe' ? ' selected' : '' ?>>Em pé</option>
    </select>
  </div>
  <?php endif; ?>
  <?php $field('cintura', 'Cintura (cm)', $values, 'Opcional.'); ?>
  <button type="submit" class="btn">Salvar medida</button>
</form>
