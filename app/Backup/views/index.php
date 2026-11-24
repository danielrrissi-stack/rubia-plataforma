<?php
/** @var array $files @var ?string $lastBackupAt */
$last = '';
if ($lastBackupAt) {
    $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastBackupAt);
    $last = $d ? $d->format('d/m/Y H:i') : '';
}
?>
<h1>Backups</h1>
<p>Último backup: <strong><?= $last !== '' ? e($last) : 'Nunca' ?></strong>. Um backup criptografado é gerado automaticamente a cada 24 horas e mantido por 30 dias.</p>
<form method="post" action="<?= e(url('/admin/backups/gerar')) ?>">
  <?= csrf_field() ?>
  <button type="submit" class="btn">Gerar backup agora</button>
</form>
<p><a href="<?= e(url('/admin/backups/chave-privada')) ?>">Baixar a chave privada de backup</a>
  (protegida pela senha de backup; guarde-a fora do servidor).</p>
<table class="tabela">
  <thead><tr><th>Data</th><th>Arquivo</th><th>Tamanho</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($files as $f): ?>
    <tr>
      <td><?= e(date('d/m/Y H:i', $f['mtime'])) ?></td>
      <td><?= e($f['name']) ?></td>
      <td><?= e($f['mb']) ?> MB</td>
      <td><a href="<?= e(url('/admin/backups/' . $f['name'])) ?>">Baixar</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($files === []): ?>
    <tr><td colspan="4">Nenhum backup encontrado.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
