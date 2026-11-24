<?php
/**
 * @var ?array $food @var array $values @var string[] $errors @var string[] $measureErrors
 * @var array $measurePosted @var array $measures
 */
$own = $food === null || $food['source'] === 'proprio';
$fmtG = static fn(mixed $g): string => str_replace('.', ',', (string) (float) $g);
$fields = [
    'kcal' => 'Calorias (kcal) *', 'protein_g' => 'Proteína (g)', 'carb_g' => 'Carboidrato (g)',
    'fat_g' => 'Gordura (g)', 'fiber_g' => 'Fibra (g)',
];
?>
<h1><?= $food === null ? 'Novo alimento' : e($food['name']) ?></h1>
<p><a href="<?= e(url('/alimentos')) ?>">Voltar à lista</a></p>

<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="cartao">
  <h2><?= $own ? 'Dados do alimento' : 'Valores (tabela TACO, somente leitura)' ?></h2>
  <?php if ($own): ?>
  <p class="ajuda">Informe os valores <strong>por 100 g</strong> do alimento pronto. Para uma receita da casa, escolha o grupo "Receita" e calcule os valores por 100 g da receita já preparada.</p>
  <form method="post" action="<?= e(url($food === null ? '/alimentos/novo' : '/alimentos/' . $food['id'] . '/editar')) ?>">
    <?= csrf_field() ?>
    <p class="campo"><label for="name">Nome *</label>
      <input id="name" name="name" value="<?= e($values['name']) ?>" maxlength="200" required></p>
    <p class="campo"><label for="group_name">Grupo</label>
      <input id="group_name" name="group_name" value="<?= e($values['group_name']) ?>" maxlength="100" list="grupos-sugeridos">
      <datalist id="grupos-sugeridos"><option value="Receita"></option></datalist></p>
    <?php foreach ($fields as $k => $label): ?>
      <p class="campo"><label for="<?= e($k) ?>"><?= e($label) ?></label>
        <input id="<?= e($k) ?>" name="<?= e($k) ?>" value="<?= e($values[$k]) ?>" inputmode="decimal"<?= $k === 'kcal' ? ' required' : '' ?>></p>
    <?php endforeach; ?>
    <p><button type="submit" class="btn">Salvar</button></p>
  </form>
  <?php else: ?>
  <p class="ajuda">Alimentos da TACO não podem ser alterados. Valores por 100 g.</p>
  <ul>
    <?php foreach ($fields as $k => $label): ?>
      <li><?= e(rtrim($label, ' *')) ?>: <?= $values[$k] === '' ? '—' : e($values[$k]) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($food !== null && $food['source'] === 'proprio'): ?>
  <form method="post" action="<?= e(url('/alimentos/' . $food['id'] . '/ativo')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="ativo" value="<?= (int) $food['active'] === 1 ? '0' : '1' ?>">
    <button type="submit" class="btn btn-quiet"><?= (int) $food['active'] === 1 ? 'Desativar (some das buscas)' : 'Reativar' ?></button>
  </form>
  <?php endif; ?>
</section>

<?php if ($food !== null): ?>
<section class="cartao">
  <h2>Medidas caseiras</h2>
  <?php if ($measureErrors !== []): ?>
    <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($measureErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($measures === []): ?><p class="ajuda">Nenhuma medida cadastrada.</p><?php else: ?>
  <table>
    <thead><tr><th>Medida</th><th>Plural</th><th>Gramas</th><th>Origem</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($measures as $m): ?>
      <?php if ($m['source'] === 'proprio'): ?>
      <tr>
        <td colspan="4">
          <form method="post" action="<?= e(url('/alimentos/medidas/' . $m['id'] . '/editar')) ?>">
            <?= csrf_field() ?>
            <label>Medida <input name="name" value="<?= e($m['name']) ?>" maxlength="100" required></label>
            <label>Plural <input name="name_plural" value="<?= e($m['name_plural'] ?? '') ?>" maxlength="100"></label>
            <label>Gramas <input name="grams" value="<?= e($fmtG($m['grams'])) ?>" inputmode="decimal" required></label>
            Meu<?= (int) $m['active'] === 1 ? '' : ' (inativa)' ?>
            <button type="submit" class="btn btn-quiet">Salvar</button>
          </form>
        </td>
        <td>
          <form method="post" action="<?= e(url('/alimentos/medidas/' . $m['id'] . '/editar')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="ativo" value="<?= (int) $m['active'] === 1 ? '0' : '1' ?>">
            <button type="submit" class="btn btn-quiet"><?= (int) $m['active'] === 1 ? 'Desativar' : 'Reativar' ?></button>
          </form>
        </td>
      </tr>
      <?php else: ?>
      <tr>
        <td><?= e($m['name']) ?></td><td><?= e($m['name_plural'] ?? '—') ?></td><td><?= e($fmtG($m['grams'])) ?></td><td>POF</td><td></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3>Adicionar medida</h3>
  <form method="post" action="<?= e(url('/alimentos/' . $food['id'] . '/medidas')) ?>">
    <?= csrf_field() ?>
    <p class="campo"><label for="m-name">Medida * (ex.: fatia, colher de sopa)</label>
      <input id="m-name" name="name" value="<?= e($measurePosted['name']) ?>" maxlength="100" required></p>
    <p class="campo"><label for="m-plural">Plural (ex.: fatias)</label>
      <input id="m-plural" name="name_plural" value="<?= e($measurePosted['name_plural']) ?>" maxlength="100"></p>
    <p class="campo"><label for="m-grams">Peso em gramas * (0,1 a 2000)</label>
      <input id="m-grams" name="grams" value="<?= e($measurePosted['grams']) ?>" inputmode="decimal" required></p>
    <p><button type="submit" class="btn">Adicionar medida</button></p>
  </form>
</section>
<?php endif; ?>
