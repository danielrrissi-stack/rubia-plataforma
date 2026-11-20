<?php
/** @var array $templates @var bool $canEdit @var array $me */
$modes = ['texto' => 'Texto', 'estruturado' => 'Estruturado'];
?>
<h1>Modelos de plano alimentar</h1>
<p class="ajuda">Modelos da equipe: ao criar um plano, é possível começar de um deles. Para criar um a partir de um plano existente, use "Salvar como modelo" na página do plano.</p>
<p>
  <?php if ($canEdit): ?>
    <a class="btn" href="<?= e(url('/modelos-plano/novo?modo=texto')) ?>">Novo modelo em texto</a>
    <a class="btn btn-quiet" href="<?= e(url('/modelos-plano/novo?modo=estruturado')) ?>">Novo modelo estruturado</a>
  <?php endif; ?>
  <a class="btn btn-quiet" href="<?= e(url('/trechos')) ?>">Trechos de texto</a>
  <a class="btn btn-quiet" href="<?= e(url('/dietas-prontas')) ?>">Dietas prontas</a>
</p>
<?php if ($templates === []): ?>
  <p class="ajuda">Nenhum modelo cadastrado.</p>
<?php else: ?>
<div class="tabela-rolagem"><table class="tabela">
  <thead><tr><th>Modelo</th><th>Modo</th><th>Autor</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $t): $mine = $canEdit && \App\Nutrition\TemplateRepository::canModify($t, $me); ?>
    <tr>
      <td><?= e($t['title']) ?></td>
      <td><?= e($modes[$t['mode']] ?? $t['mode']) ?></td>
      <td><?= e($t['author']) ?></td>
      <td>
        <?php if ($mine): ?>
          <a href="<?= e(url('/modelos-plano/' . $t['id'] . '/editar')) ?>">Editar</a>
          <form class="form-inline" method="post" action="<?= e(url('/modelos-plano/' . $t['id'] . '/excluir')) ?>"><?= csrf_field() ?>
            <button type="submit" class="btn btn-quiet">Excluir</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
