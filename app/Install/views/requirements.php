<?php
/** @var list<array{item:string,ok:bool,required:bool,detail:string}> $requirements */
/** @var string $storageWarning */
?>
<section aria-labelledby="req-titulo">
  <h2 id="req-titulo">Requisitos do servidor</h2>
  <?php if ($storageWarning !== ''): ?><p class="aviso aviso-erro" role="alert"><?= e($storageWarning) ?></p><?php endif; ?>
  <table>
    <thead><tr><th scope="col">Item</th><th scope="col">Situação</th><th scope="col">Detalhe</th></tr></thead>
    <tbody>
    <?php foreach ($requirements as $r): ?>
      <tr>
        <td><?= e($r['item']) ?><?= $r['required'] ? '' : ' (informativo)' ?></td>
        <td><?= $r['ok'] ? 'OK' : ($r['required'] ? 'Falhou' : 'Atenção') ?></td>
        <td><?= e($r['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
