<?php
/** @var array $patients @var string $q @var string $today @var bool $canCreate */
?>
<h1>Pacientes</h1>
<form method="get" action="<?= e(url('/pacientes')) ?>" class="busca" role="search">
  <label for="q" class="visually-hidden">Buscar por nome ou CPF</label>
  <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome ou CPF">
  <button type="submit" class="btn">Buscar</button>
  <?php if ($canCreate): ?><a class="btn" href="<?= e(url('/pacientes/novo')) ?>">Novo paciente</a><?php endif; ?>
</form>
<?php if ($patients === []): ?>
  <p>Nenhum paciente encontrado.</p>
<?php else: ?>
<table class="tabela">
  <thead><tr><th>Nome</th><th>Idade</th><th>Sexo</th></tr></thead>
  <tbody>
  <?php foreach ($patients as $p): ?>
    <tr>
      <td><a href="<?= e(url('/pacientes/' . $p['id'])) ?>"><?= e($p['full_name']) ?></a></td>
      <td><?= e(\App\Growth\Age::label($p['birth_date'], $today)) ?></td>
      <td><?= $p['sex'] === 'F' ? 'Feminino' : 'Masculino' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
