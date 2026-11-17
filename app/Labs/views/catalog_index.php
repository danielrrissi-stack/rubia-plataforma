<?php
/** @var list<array> $tests @var array<string,string> $groups */
?>
<h1>Catálogo de exames</h1>
<p class="ajuda">Exames disponíveis na coleta e suas faixas de referência. Exames não se apagam: desative para tirá-los da coleta.</p>
<p><a class="btn" href="<?= e(url('/catalogo-exames/novo')) ?>">Novo exame</a></p>
<table>
  <thead><tr><th>Exame</th><th>Unidade</th><th>Grupo</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($tests as $t): ?>
    <tr>
      <td><?= e($t['name']) ?><?php if ((int) $t['reference_confirmed'] === 0): ?> <span class="selo selo-alerta">a confirmar</span><?php endif; ?></td>
      <td><?= e($t['unit']) ?></td>
      <td><?= e($groups[$t['group_name']] ?? $t['group_name']) ?></td>
      <td><?= (int) $t['active'] === 1 ? 'Ativo' : 'Inativo' ?></td>
      <td><a href="<?= e(url('/catalogo-exames/' . $t['id'] . '/editar')) ?>">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
