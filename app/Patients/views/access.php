<?php
/** @var array $patient @var array $grants @var array $available */
$pid = (int) $patient['id'];
?>
<h1>Acesso de profissionais — <?= e($patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a></p>

<h2>Nutricionistas com acesso</h2>
<?php if ($grants === []): ?>
  <p>Nenhum nutricionista liberado para este paciente.</p>
<?php else: ?>
<table class="tabela">
  <thead><tr><th>Nome</th><th>E-mail</th><th>Liberado em</th><th>Ações</th></tr></thead>
  <tbody>
  <?php foreach ($grants as $g): ?>
    <tr>
      <td><?= e($g['professional_name']) ?></td>
      <td><?= e($g['professional_email']) ?></td>
      <td><?= e(fmt_date($g['granted_at'])) ?></td>
      <td>
        <form method="post" action="<?= e(url('/acesso-profissionais/' . $g['id'] . '/revogar')) ?>" class="form-inline">
          <?= csrf_field() ?><button type="submit" class="btn btn-quiet">Revogar</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Liberar nutricionista</h2>
<?php if ($available === []): ?>
  <p>Não há outros nutricionistas ativos para liberar.</p>
<?php else: ?>
<form method="post" action="<?= e(url('/pacientes/' . $pid . '/acesso-profissionais')) ?>">
  <?= csrf_field() ?>
  <div class="campo">
    <label for="profissional">Nutricionista</label>
    <select id="profissional" name="profissional" required>
      <option value="">Selecione</option>
      <?php foreach ($available as $n): ?>
        <option value="<?= (int) $n['id'] ?>"><?= e($n['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn">Liberar acesso</button>
</form>
<?php endif; ?>
