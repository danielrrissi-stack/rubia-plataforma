<?php
/** @var array $patient @var list<array> $items */
use App\Medications\Via;

$pid = (int) $patient['id'];
$name = $patient['social_name'] ?: $patient['full_name'];
?>
<h1>Receita — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/tratamento')) ?>">Voltar ao tratamento</a></p>
<?php if ($items === []): ?>
  <p class="vazio">Nenhuma prescrição ativa ou futura para a receita.</p>
<?php else: ?>
<form method="get" action="<?= e(url('/pacientes/' . $pid . '/receita/imprimir')) ?>">
  <fieldset class="cartao">
    <legend>Medicamentos na receita</legend>
    <?php foreach ($items as $p): ?>
      <p><label><input type="checkbox" name="p[]" value="<?= (int) $p['id'] ?>" checked>
        <?= e($p['drug_name']) ?> — <?= e($p['current']['dose']) ?> · <?= e(Via::LABELS[$p['route']] ?? $p['route']) ?>
        <?php if ($p['status'] === 'futura'): ?><span class="selo">futura</span><?php endif; ?></label></p>
    <?php endforeach; ?>
  </fieldset>
  <button type="submit" class="btn">Gerar receita</button>
</form>
<?php endif; ?>
