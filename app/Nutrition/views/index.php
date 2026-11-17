<?php
/** @var array $patient @var ?array $current @var ?array $draft @var array $history @var array $templates @var bool $canEdit @var ?array $askDraft @var array $posted */
$pid = (int) $patient['id'];
$status = ['rascunho' => 'Rascunho', 'publicado' => 'Vigente', 'substituido' => 'Substituído', 'encerrado' => 'Encerrado'];
$modes = ['texto' => 'Texto', 'estruturado' => 'Estruturado'];
?>
<h1>Plano alimentar — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a></p>

<?php if ($askDraft !== null): ?>
<section class="cartao" role="alert">
  <h2>Já existe um rascunho</h2>
  <p>Este paciente já tem o rascunho "<?= e($askDraft['title']) ?>" em andamento. O que deseja fazer?</p>
  <p><a class="btn" href="<?= e(url('/planos/' . $askDraft['id'] . '/editar')) ?>">Continuar o rascunho</a></p>
  <form method="post" action="<?= e(url('/pacientes/' . $pid . '/plano/novo')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="modo" value="<?= e($posted['modo']) ?>">
    <input type="hidden" name="origem" value="<?= e($posted['origem']) ?>">
    <input type="hidden" name="modelo_id" value="<?= e($posted['modelo_id']) ?>">
    <input type="hidden" name="descartar_rascunho" value="1">
    <button type="submit" class="btn btn-quiet">Descartar e começar outro</button>
  </form>
</section>
<?php endif; ?>

<section class="cartao"><h2>Plano vigente</h2>
<?php if ($current === null): ?><p class="ajuda">Nenhum plano vigente.</p><?php else: ?>
  <p><strong><?= e($current['title']) ?></strong> · publicado em <?= e(fmt_date(substr((string) $current['published_at'], 0, 10))) ?></p>
  <p>
    <a class="btn btn-quiet" href="<?= e(url('/planos/' . $current['id'])) ?>">Abrir</a>
    <a class="btn btn-quiet" href="<?= e(url('/planos/' . $current['id'] . '/imprimir')) ?>">Imprimir</a>
  </p>
<?php endif; ?>
</section>

<?php if ($draft !== null): ?>
<section class="cartao"><h2>Rascunho</h2>
  <p><strong><?= e($draft['title']) ?></strong> · <?= e($modes[$draft['mode']]) ?></p>
  <p><a class="btn" href="<?= e(url('/planos/' . $draft['id'] . '/editar')) ?>">Continuar o rascunho</a>
    <a class="btn btn-quiet" href="<?= e(url('/planos/' . $draft['id'])) ?>">Ver</a></p>
</section>
<?php endif; ?>

<?php if ($canEdit): ?>
<section class="cartao"><h2>Novo plano</h2>
<form method="post" action="<?= e(url('/pacientes/' . $pid . '/plano/novo')) ?>">
  <?= csrf_field() ?>
  <p><label>Modo
    <select name="modo">
      <?php foreach ($modes as $k => $label): ?><option value="<?= e($k) ?>" <?= $posted['modo'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label></p>
  <p><label>Começar de
    <select name="origem">
      <option value="branco" <?= $posted['origem'] === 'branco' ? 'selected' : '' ?>>Em branco</option>
      <?php if ($current !== null): ?><option value="vigente" <?= $posted['origem'] === 'vigente' ? 'selected' : '' ?>>Cópia do plano vigente</option><?php endif; ?>
      <?php if ($templates !== []): ?><option value="modelo" <?= $posted['origem'] === 'modelo' ? 'selected' : '' ?>>Modelo</option><?php endif; ?>
    </select></label>
    <?php if ($templates !== []): ?>
    <label>Modelo
      <select name="modelo_id">
        <?php foreach ($templates as $t): ?><option value="<?= e($t['id']) ?>" <?= (int) $posted['modelo_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['title']) ?> (<?= e($modes[$t['mode']]) ?>)</option><?php endforeach; ?>
      </select></label>
    <?php endif; ?></p>
  <p class="ajuda">Cópia do plano vigente e modelo usam o modo do original.</p>
  <p><button type="submit" class="btn">Criar rascunho</button></p>
</form>
</section>
<?php endif; ?>

<h2>Histórico</h2>
<?php if ($history === []): ?><p>Nenhum plano registrado.</p><?php else: ?>
<div class="tabela-rolagem"><table class="tabela">
  <thead><tr><th>Plano</th><th>Situação</th><th>Modo</th><th>Autor</th><th>Publicação</th><th>Encerramento</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($history as $h): ?>
    <tr>
      <td><?= e($h['title']) ?></td>
      <td><?= e($status[$h['status']]) ?></td>
      <td><?= e($modes[$h['mode']]) ?></td>
      <td><?= e($h['author']) ?></td>
      <td><?= $h['published_at'] !== null ? e(fmt_date(substr((string) $h['published_at'], 0, 10))) . ' · ' . e($h['publisher']) : '' ?></td>
      <td><?= $h['ended_at'] !== null ? e(fmt_date(substr((string) $h['ended_at'], 0, 10))) . ' · ' . e($h['ended_reason']) : '' ?></td>
      <td><a href="<?= e(url('/planos/' . $h['id'])) ?>">Abrir</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
