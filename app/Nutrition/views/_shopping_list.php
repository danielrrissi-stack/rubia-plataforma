<?php
/** Lista de compras do portal. @var list<array{name:string,text:string}> $list */
?>
<section class="plano-compras">
  <h2>Lista de compras</h2>
  <p class="ajuda">Para os 7 dias acima.</p>
  <ul class="lista-compras">
<?php foreach ($list as $row): ?>
    <li><?= e($row['name'] . ' — ' . $row['text']) ?></li>
<?php endforeach; ?>
  </ul>
</section>
