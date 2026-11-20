<?php
/** @var array $snippets @var bool $canEdit @var array $me */
?>
<h1>Trechos de texto</h1>
<p class="ajuda">Textos curtos e nomeados que você insere com um clique ("Inserir trecho") no editor de plano em texto.</p>
<p>
  <?php if ($canEdit): ?><a class="btn" href="<?= e(url('/trechos/novo')) ?>">Novo trecho</a><?php endif; ?>
  <a class="btn btn-quiet" href="<?= e(url('/modelos-plano')) ?>">Modelos de plano</a>
</p>
<?php if ($snippets === []): ?>
  <p class="ajuda">Nenhum trecho cadastrado.</p>
<?php else: ?>
<div class="tabela-rolagem"><table class="tabela">
  <thead><tr><th>Título</th><th>Texto</th><th>Autor</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($snippets as $s): $mine = $canEdit && \App\Nutrition\SnippetRepository::canModify($s, $me); ?>
    <tr>
      <td><?= e($s['title']) ?></td>
      <td><?= e(mb_strimwidth((string) $s['body'], 0, 120, '…', 'UTF-8')) ?></td>
      <td><?= e($s['author']) ?></td>
      <td>
        <?php if ($mine): ?>
          <a href="<?= e(url('/trechos/' . $s['id'] . '/editar')) ?>">Editar</a>
          <form class="form-inline" method="post" action="<?= e(url('/trechos/' . $s['id'] . '/excluir')) ?>"><?= csrf_field() ?>
            <button type="submit" class="btn btn-quiet">Excluir</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
