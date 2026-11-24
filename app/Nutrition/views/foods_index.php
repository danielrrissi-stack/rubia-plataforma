<?php
/** @var array $foods @var string $q @var bool $onlyOwn */
?>
<h1>Alimentos</h1>
<p class="ajuda">Tabela TACO e os alimentos e receitas da casa ("Minha tabela"). Todos os valores são por 100 g.</p>
<p><a class="btn" href="<?= e(url('/alimentos/novo')) ?>">Novo alimento ou receita</a></p>

<form method="get" action="<?= e(url('/alimentos')) ?>" class="cartao">
  <p class="campo"><label for="q">Buscar</label>
    <input id="q" type="search" name="q" value="<?= e($q) ?>" maxlength="100"></p>
  <p class="campo"><label><input type="checkbox" name="meus" value="1"<?= $onlyOwn ? ' checked' : '' ?>> Só os meus</label></p>
  <p><button type="submit" class="btn btn-quiet">Buscar</button></p>
</form>

<?php if ($foods === []): ?>
  <p class="ajuda">Nenhum alimento encontrado.</p>
<?php else: ?>
<table>
  <thead><tr><th>Alimento</th><th>Origem</th><th>kcal</th><th>Situação</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($foods as $f): ?>
    <tr>
      <td><?= e($f['name']) ?><?= $f['group_name'] !== null ? ' <span class="ajuda">(' . e($f['group_name']) . ')</span>' : '' ?></td>
      <td><?= $f['source'] === 'proprio' ? 'Meu' : 'TACO' ?></td>
      <td><?= $f['kcal'] === null ? '—' : e(str_replace('.', ',', (string) (float) $f['kcal'])) ?></td>
      <td><?= (int) $f['active'] === 1 ? 'Ativo' : 'Inativo' ?></td>
      <td><a href="<?= e(url('/alimentos/' . $f['id'] . '/editar')) ?>"><?= $f['source'] === 'proprio' ? 'Editar' : 'Ver / medidas' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="ajuda">Mostrando até 100 resultados; use a busca para refinar.</p>
<?php endif; ?>
