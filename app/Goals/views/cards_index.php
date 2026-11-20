<?php
/**
 * @var list<array> $cards
 */
use App\Goals\EduCardRepository;

?>
<h1>Cartões educativos</h1>
<p><a class="btn" href="<?= e(url('/cartoes/novo')) ?>">Novo cartão</a></p>
<p class="ajuda">Textos curtos da área "Para você ler" do portal, por faixa etária (anos completos hoje). Só os
  cartões publicados aparecem para as famílias e as crianças; os rascunhos esperam a sua revisão.</p>
<?php if ($cards === []): ?><p>Nenhum cartão.</p><?php else: ?>
<div class="tabela-rolagem">
<table>
  <thead><tr><th scope="col">Cartão</th><th scope="col">Faixas</th><th scope="col">Ordem</th><th scope="col">Situação</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($cards as $c): ?>
    <?php $published = $c['status'] === 'publicado'; ?>
    <tr>
      <td><?= e($c['emoji']) ?> <?= e($c['title']) ?></td>
      <td><?= e(implode(', ', array_map(static fn (string $b): string => $b . ' anos', array_filter(explode(',', (string) $c['bands']), static fn (string $b): bool => isset(EduCardRepository::BANDS[$b]))))) ?></td>
      <td><?= e($c['sort_order']) ?></td>
      <td><?= $published ? '<span class="selo">Publicado</span>' . ($c['approved_at'] !== null ? ' em ' . e(fmt_date(substr((string) $c['approved_at'], 0, 10))) : '') : '<span class="selo selo-familia">Rascunho</span>' ?></td>
      <td>
        <a class="btn btn-quiet" href="<?= e(url('/cartoes/' . (int) $c['id'] . '/editar')) ?>">Revisar</a>
        <form method="post" class="form-inline" action="<?= e(url('/cartoes/' . (int) $c['id'] . ($published ? '/despublicar' : '/publicar'))) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-quiet"><?= $published ? 'Despublicar' : 'Publicar' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
