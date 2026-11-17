<?php
/** @var array $collection @var array $patient @var list<array> $rows @var ?string $homa @var list<array> $attachments */
use App\Labs\LabClassifier;

$pid = (int) $patient['id'];
$cid = (int) $collection['id'];
$released = $collection['released_at'] !== null;
?>
<h1>Coleta de <?= e(fmt_date($collection['collected_on'])) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/exames')) ?>">Voltar aos exames</a> ·
  <?= e($patient['social_name'] ?: $patient['full_name']) ?> ·
  <?php if ($released): ?><span class="selo selo-familia">liberada para a família em <?= e(fmt_date(substr((string) $collection['released_at'], 0, 10))) ?></span><?php else: ?><span class="selo">não liberada para a família</span><?php endif; ?></p>
<?php if ($collection['lab_name'] !== null): ?><p>Laboratório: <?= e($collection['lab_name']) ?></p><?php endif; ?>
<?php if ($collection['notes'] !== null): ?><p>Observação: <?= nl2br(e($collection['notes'])) ?></p><?php endif; ?>

<section class="cartao" aria-labelledby="h-resultados">
  <h2 id="h-resultados">Resultados</h2>
  <table class="lab-resultados">
    <thead><tr><th scope="col">Exame</th><th scope="col">Valor</th><th scope="col">Classificação</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): $cell = $row['cell']; $cls = $cell['class']; ?>
        <tr>
          <th scope="row"><?= e($row['test']['name']) ?></th>
          <td class="lab-celula<?= $cls !== null && $cls !== 'normal' ? ' lab-' . e($cls) : '' ?>"><span class="lab-valor"><?= e($cell['value']) ?></span> <?= e($row['test']['unit']) ?><?php if ($cls !== null && LabClassifier::MARK[$cls] !== ''): ?> <span class="lab-marca" aria-hidden="true"><?= e(LabClassifier::MARK[$cls]) ?></span><?php endif; ?></td>
          <td><?php if ($cls !== null): ?><?= e(LabClassifier::LABEL[$cls]) ?><?php elseif ($cell['no_range']): ?><span class="ajuda">sem faixa para esta idade</span><?php else: ?><span class="ajuda">resultado em texto (sem classificação)</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($homa !== null): ?>
        <tr><th scope="row">HOMA-IR (calculado)</th><td><span class="lab-valor"><?= e($homa) ?></span></td><td><span class="ajuda">glicemia × insulina ÷ 405</span></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<section class="cartao" aria-labelledby="h-laudos">
  <h2 id="h-laudos">Laudos</h2>
  <?php if ($attachments === []): ?>
    <p class="ajuda">Nenhum laudo anexado.</p>
  <?php else: ?>
    <ul class="lab-laudos">
      <?php foreach ($attachments as $a): ?>
        <li><a href="<?= e(url('/exames/anexos/' . $a['id'])) ?>"><?= e($a['original_name']) ?></a>
          <span class="ajuda">(<?= e(fmt_num((int) $a['size_bytes'] / 1024 / 1024, 1)) ?> MB)</span>
          <form class="form-inline" method="post" action="<?= e(url('/exames/anexos/' . $a['id'] . '/excluir')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir laudo</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/exames/' . $cid . '/anexos')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="campo">
      <label for="laudos">Anexar laudos</label>
      <input type="file" id="laudos" name="laudos[]" multiple accept="application/pdf,image/jpeg,image/png" required>
      <p class="ajuda">PDF, JPG ou PNG, até 10 MB cada.</p>
    </div>
    <button type="submit" class="btn btn-quiet">Enviar laudos</button>
  </form>
</section>

<section class="cartao" aria-labelledby="h-acoes">
  <h2 id="h-acoes">Ações</h2>
  <div class="acoes-plano">
    <a class="btn" href="<?= e(url('/exames/' . $cid . '/editar')) ?>">Editar coleta</a>
    <?php if ($released): ?>
      <form class="form-inline" method="post" action="<?= e(url('/exames/' . $cid . '/recolher')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Recolher (a família deixa de ver)</button></form>
    <?php else: ?>
      <form class="form-inline" method="post" action="<?= e(url('/exames/' . $cid . '/liberar')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Liberar para a família</button></form>
    <?php endif; ?>
    <form class="form-inline" method="post" action="<?= e(url('/exames/' . $cid . '/excluir')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir coleta</button></form>
  </div>
</section>
