<?php
/** @var array $patient @var string $first @var array $values @var string[] $errors @var list<array> $prescriptions */
$pid = (int) $patient['id'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid . '/tratamento')) ?>">&larr; Voltar</a></p>
<h1>Contar um efeito ou intercorrência</h1>
<p class="aviso">Este relato não é visto na hora. Em caso de urgência, procure atendimento ou um pronto-socorro.</p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('/portal/p/' . $pid . '/tratamento/relato')) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="data">Data</label>
    <input type="date" id="data" name="data" value="<?= e($v('data')) ?>" required></div>
  <div class="campo"><label for="medicamento">Medicamento (opcional)</label>
    <select id="medicamento" name="medicamento">
      <option value="">—</option>
      <?php foreach ($prescriptions as $p): ?>
        <option value="<?= e($p['id']) ?>"<?= $v('medicamento') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['drug_name']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="campo"><label for="descricao">O que aconteceu</label>
    <textarea id="descricao" name="descricao" rows="4" maxlength="2000" required><?= e($v('descricao')) ?></textarea></div>
  <button type="submit" class="btn">Enviar relato</button>
  <a class="btn btn-quiet" href="<?= e(url('/portal/p/' . $pid . '/tratamento')) ?>">Cancelar</a>
</form>
