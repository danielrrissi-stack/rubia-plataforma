<?php
/** @var array $patient @var ?array $measurement @var array $values @var string[] $errors */
$pid = (int) $patient['id'];
$action = $measurement === null ? '/pacientes/' . $pid . '/medidas/nova' : '/medidas/' . $measurement['id'] . '/corrigir';
$field = static function (string $id, string $label, array $values, string $help = '', bool $required = false): void {
    echo '<div class="campo"><label for="' . e($id) . '">' . e($label) . '</label>'
        . '<input type="text" inputmode="decimal" id="' . e($id) . '" name="' . e($id) . '" value="' . e($values[$id]) . '"'
        . ($required ? ' required' : '') . '>'
        . ($help !== '' ? '<p class="ajuda">' . e($help) . '</p>' : '') . '</div>';
};
?>
<h1><?= $measurement === null ? 'Nova medida' : 'Corrigir medida' ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<?php if ($measurement !== null): ?>
  <p class="ajuda">A correção cria uma nova versão; a anterior permanece no histórico.</p>
<?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <?php $field('data_medida', 'Data da medida', $values, 'Formato dd/mm/aaaa.', true); ?>
  <?php $field('peso', 'Peso (kg)', $values, 'Use vírgula para decimais, por exemplo 32,5.'); ?>
  <?php $field('altura', 'Altura / comprimento (cm)', $values); ?>
  <div class="campo">
    <label for="posicao">Posição da medida de altura</label>
    <select id="posicao" name="posicao">
      <option value="">Não informada</option>
      <option value="em_pe"<?= $values['posicao'] === 'em_pe' ? ' selected' : '' ?>>Em pé</option>
      <option value="deitado"<?= $values['posicao'] === 'deitado' ? ' selected' : '' ?>>Deitado</option>
    </select>
  </div>
  <?php $field('cintura', 'Circunferência da cintura (cm)', $values); ?>
  <?php $field('quadril', 'Circunferência do quadril (cm)', $values); ?>
  <?php $field('pc', 'Perímetro cefálico (cm)', $values, 'Para crianças pequenas.'); ?>
  <?php $field('sistolica', 'Pressão sistólica (mmHg)', $values); ?>
  <?php $field('diastolica', 'Pressão diastólica (mmHg)', $values); ?>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/crescimento')) ?>">Cancelar</a>
</form>
