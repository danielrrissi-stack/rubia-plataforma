<?php
/**
 * @var ?array $test @var array $values @var list<array> $rows @var string[] $errors @var array<string,string> $groups
 */
$isNew = $test === null;
$action = $isNew ? '/catalogo-exames/novo' : '/catalogo-exames/' . $test['id'] . '/editar';
?>
<h1><?= $isNew ? 'Novo exame' : e($test['name']) ?></h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if (!$isNew && (int) $test['reference_confirmed'] === 0): ?>
  <form method="post" action="<?= e(url('/catalogo-exames/' . $test['id'] . '/confirmar')) ?>" class="cartao">
    <?= csrf_field() ?>
    <p><span class="selo selo-alerta">a confirmar</span> As faixas deste exame vieram pré-carregadas e ainda não foram conferidas.
      Salvar as faixas também as confirma.</p>
    <button type="submit" class="btn btn-quiet">Confirmar faixas</button>
  </form>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="name">Nome</label>
    <input type="text" id="name" name="name" value="<?= e($values['name']) ?>" maxlength="100" required></div>
  <div class="campo"><label for="unit">Unidade</label>
    <input type="text" id="unit" name="unit" value="<?= e($values['unit']) ?>" maxlength="20" required></div>
  <div class="campo"><label for="group_name">Grupo</label>
    <select id="group_name" name="group_name">
      <?php foreach ($groups as $g => $label): ?>
        <option value="<?= e($g) ?>"<?= $values['group_name'] === $g ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="campo"><label for="sort_order">Ordem</label>
    <input type="text" inputmode="numeric" id="sort_order" name="sort_order" value="<?= e($values['sort_order']) ?>" maxlength="6"></div>
  <div class="campo"><label for="decimals">Casas decimais</label>
    <input type="text" inputmode="numeric" id="decimals" name="decimals" value="<?= e($values['decimals']) ?>" maxlength="1"></div>
  <p class="campo"><label><input type="checkbox" name="active" value="1"<?= (int) $values['active'] === 1 ? ' checked' : '' ?>> Ativo (aparece na coleta)</label></p>

  <h2>Faixas de referência</h2>
  <p class="ajuda">O valor igual ao limite já conta como limítrofe/alterado.</p>
  <p class="ajuda">Idade em anos e meses (deixe a idade final em branco para "sem limite"). Preencha só os cortes que se aplicam.</p>
  <table id="faixas">
    <thead><tr><th>Sexo</th><th>Idade de (anos / meses)</th><th>Idade até (anos / meses)</th><th>Limítrofe alto</th><th>Alterado alto</th><th>Limítrofe baixo</th><th>Alterado baixo</th><th>Fonte</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $row): ?>
      <tr data-faixa>
        <td><select name="faixa[<?= e($i) ?>][sex]" aria-label="Sexo">
          <option value="">Ambos</option>
          <option value="M"<?= $row['sex'] === 'M' ? ' selected' : '' ?>>M</option>
          <option value="F"<?= $row['sex'] === 'F' ? ' selected' : '' ?>>F</option>
        </select></td>
        <td><input type="text" inputmode="numeric" size="3" name="faixa[<?= e($i) ?>][min_a]" value="<?= e($row['min_a']) ?>" aria-label="Idade inicial, anos">
            <input type="text" inputmode="numeric" size="2" name="faixa[<?= e($i) ?>][min_m]" value="<?= e($row['min_m']) ?>" aria-label="Idade inicial, meses"></td>
        <td><input type="text" inputmode="numeric" size="3" name="faixa[<?= e($i) ?>][max_a]" value="<?= e($row['max_a']) ?>" aria-label="Idade final, anos">
            <input type="text" inputmode="numeric" size="2" name="faixa[<?= e($i) ?>][max_m]" value="<?= e($row['max_m']) ?>" aria-label="Idade final, meses"></td>
        <?php foreach (['bh' => 'Limítrofe alto', 'ah' => 'Alterado alto', 'bl' => 'Limítrofe baixo', 'al' => 'Alterado baixo'] as $k => $label): ?>
          <td><input type="text" inputmode="decimal" size="6" name="faixa[<?= e($i) ?>][<?= e($k) ?>]" value="<?= e($row[$k]) ?>" aria-label="<?= e($label) ?>"></td>
        <?php endforeach; ?>
        <td><input type="text" size="14" maxlength="120" name="faixa[<?= e($i) ?>][source]" value="<?= e($row['source']) ?>" aria-label="Fonte"></td>
        <td><button type="button" class="btn btn-quiet" data-remover-faixa hidden>Remover</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><button type="button" class="btn btn-quiet" id="add-faixa" hidden>+ faixa</button></p>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url('/catalogo-exames')) ?>">Cancelar</a>
</form>
<script src="<?= e(url('/assets/js/lab-ranges.js')) ?>" defer></script>
