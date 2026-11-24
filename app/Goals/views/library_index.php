<?php
/**
 * @var list<array> $templates
 */
use App\Goals\GoalPanel;

?>
<h1>Biblioteca de metas</h1>
<p><a class="btn" href="<?= e(url('/metas-modelo/novo')) ?>">Nova meta pronta</a></p>
<p class="ajuda">Metas prontas usadas ao combinar metas com o paciente. Desative em vez de apagar: as metas já criadas não mudam.</p>
<?php if ($templates === []): ?><p>Nenhuma meta pronta.</p><?php else: ?>
<div class="tabela-rolagem">
<table>
  <thead><tr><th scope="col">Meta</th><th scope="col">Categoria</th><th scope="col">Frequência</th><th scope="col">Situação</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $t): ?>
    <tr>
      <td><?= e($t['emoji']) ?> <?= e($t['family_text']) ?></td>
      <td><?= e(GoalPanel::CATEGORY_LABELS[$t['category']] ?? $t['category']) ?></td>
      <td><?= (int) $t['times_per_week'] === 7 ? 'Todo dia' : e($t['times_per_week']) . 'x por semana' ?></td>
      <td><?= (int) $t['active'] === 1 ? 'Ativa' : '<span class="selo">desativada</span>' ?></td>
      <td><a class="btn btn-quiet" href="<?= e(url('/metas-modelo/' . (int) $t['id'] . '/editar')) ?>">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
