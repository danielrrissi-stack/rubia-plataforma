<?php
/** @var array $patient @var list<array> $current @var list<array> $ended @var string $today @var bool $canEdit */
use App\Diary\DiaryType;

$pid = (int) $patient['id'];
$name = $patient['social_name'] ?: $patient['full_name'];
$row = static function (array $p) use ($today, $canEdit, $pid): string {
    ob_start(); ?>
    <li>
      <strong><?= e(DiaryType::LABELS[$p['type']] ?? $p['type']) ?></strong>
      — <?= e(DiaryType::weekdaysLabel((int) $p['weekdays'])) ?>,
      <?= (string) $p['start_date'] > $today ? 'a partir de ' : 'desde ' ?><?= e(fmt_date($p['start_date'])) ?><?= $p['end_date'] !== null ? ' até ' . e(fmt_date($p['end_date'])) : '' ?>
      <?php if ((int) $p['secret'] === 1): ?><span class="selo selo-alerta">sigiloso</span><?php endif; ?>
      <?php if ($p['guidance'] !== null && $p['guidance'] !== ''): ?><br><span class="ajuda"><?= e($p['guidance']) ?></span><?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn btn-quiet" href="<?= e(url('/diario/pedidos/' . (int) $p['id'] . '/editar')) ?>">Editar</a>
        <?php if ((string) $p['start_date'] <= $today && ($p['end_date'] === null || (string) $p['end_date'] > $today)): ?>
          <form method="post" action="<?= e(url('/diario/pedidos/' . (int) $p['id'] . '/encerrar')) ?>" class="form-inline" data-confirm="Encerrar este pedido? Os registros já feitos continuam."><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Encerrar</button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/diario/pedidos/' . (int) $p['id'] . '/excluir')) ?>" class="form-inline" data-confirm="Excluir este pedido? Só é possível se ainda não há registros."><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir</button></form>
      <?php endif; ?>
    </li>
    <?php
    return (string) ob_get_clean();
};
?>
<h1>Diário — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar à ficha</a>
<?php if ($canEdit): ?> · <a class="btn" href="<?= e(url('/pacientes/' . $pid . '/diario/pedidos/novo')) ?>">Novo pedido</a><?php endif; ?></p>

<section class="cartao" aria-labelledby="h-pedidos">
  <h2 id="h-pedidos">Pedidos de registro</h2>
  <?php if ($current === []): ?>
    <p class="ajuda">Nenhum pedido de registro.</p>
  <?php else: ?>
    <ul><?php foreach ($current as $p) { echo $row($p); } ?></ul>
  <?php endif; ?>
</section>
<?php if ($ended !== []): ?>
  <details class="cartao">
    <summary>Pedidos encerrados (<?= count($ended) ?>)</summary>
    <ul><?php foreach ($ended as $p) { echo $row($p); } ?></ul>
  </details>
<?php endif; ?>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
