<?php
/** @var array $agendaCard @var int $pid */
$rows = static function (array $list) use ($pid): void { ?>
  <ul><?php foreach ($list as $a): ?><li>
    <?php $text = fmt_date($a['date']) . ' ' . $a['time'] . ' — ' . $a['professional'] . ' — ' . $a['kind'] . ' — ' . $a['status']; ?>
    <?php if ($a['link']): ?><a href="<?= e(url('/agenda/consultas/' . $a['id'])) ?>"><?= e($text) ?></a><?php else: ?><?= e($text) ?><?php endif; ?>
  </li><?php endforeach; ?></ul>
<?php };
?>
<section class="cartao" id="cartao-consultas"><h2>Consultas</h2>
  <?php if ($agendaCard['upcoming'] === [] && $agendaCard['past'] === []): ?>
    <p class="ajuda">Nenhuma consulta marcada.</p>
  <?php endif; ?>
  <?php if ($agendaCard['upcoming'] !== []): ?><h3>Próximas</h3><?php $rows($agendaCard['upcoming']); ?><?php endif; ?>
  <?php if ($agendaCard['past'] !== []): ?><h3>Últimas</h3><?php $rows($agendaCard['past']); ?><?php endif; ?>
  <?php if ($agendaCard['canBook']): ?><p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/agendar') . '?prof=' . (int) $agendaCard['bookProf']) ?>">Agendar</a></p><?php endif; ?>
</section>
