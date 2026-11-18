<?php
/**
 * Seletor de profissional (so quando ha mais de uma agenda).
 * Variaveis: $agendas (list<array{id,name,role}>), $prof (?int), $selectAction (caminho), $selectHidden (array nome => valor).
 */
if (count($agendas) > 1): ?>
<form method="get" action="<?= e(url($selectAction)) ?>" class="agenda-filtro">
  <label for="agenda-prof">Profissional</label>
  <select id="agenda-prof" name="prof">
    <?php foreach ($agendas as $ag): ?>
      <option value="<?= (int) $ag['id'] ?>"<?= (int) $ag['id'] === $prof ? ' selected' : '' ?>><?= e($ag['name']) ?> (<?= e($ag['role'] === 'nutricionista' ? 'nutricionista' : 'médica') ?>)</option>
    <?php endforeach; ?>
  </select>
  <?php foreach ($selectHidden as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach; ?>
  <button type="submit" class="btn btn-quiet">Ver</button>
</form>
<?php endif;
