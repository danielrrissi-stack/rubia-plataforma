<?php
/**
 * @var ?array $template @var array $values @var string[] $errors @var string[] $categories @var bool $lockCategory
 */
use App\Goals\GoalPanel;

$isNew = $template === null;
$action = $isNew ? '/metas-modelo/novo' : '/metas-modelo/' . (int) $template['id'] . '/editar';
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<h1><?= $isNew ? 'Nova meta pronta' : 'Editar meta pronta' ?></h1>
<p><a href="<?= e(url('/metas-modelo')) ?>">Voltar à biblioteca</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="family_text">Texto para a família</label>
    <input type="text" id="family_text" name="family_text" value="<?= e($v('family_text')) ?>" maxlength="160" required data-ditado></div>
  <div class="campo"><label for="child_text">Texto para a criança (opcional)</label>
    <input type="text" id="child_text" name="child_text" value="<?= e($v('child_text')) ?>" maxlength="160" data-ditado></div>
  <div class="campo"><label for="emoji">Emoji</label>
    <input type="text" id="emoji" name="emoji" value="<?= e($v('emoji')) ?>" maxlength="16" size="4" required></div>
  <div class="campo"><label for="category">Categoria</label>
    <?php if ($lockCategory): ?>
      <input type="text" id="category" value="<?= e(GoalPanel::CATEGORY_LABELS[$v('category')] ?? $v('category')) ?>" disabled>
      <p class="ajuda">Só a médica altera a categoria.</p>
    <?php else: ?>
      <select id="category" name="category">
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c) ?>"<?= $v('category') === $c ? ' selected' : '' ?>><?= e(GoalPanel::CATEGORY_LABELS[$c]) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?></div>
  <div class="campo"><label for="times_per_week">Frequência sugerida</label>
    <select id="times_per_week" name="times_per_week">
      <?php for ($n = 1; $n <= 7; $n++): ?>
        <option value="<?= $n ?>"<?= $v('times_per_week') === (string) $n ? ' selected' : '' ?>><?= $n === 7 ? 'Todo dia' : $n . ' vez' . ($n === 1 ? '' : 'es') . ' por semana' ?></option>
      <?php endfor; ?>
    </select></div>
  <div class="campo"><label for="sort_order">Ordem</label>
    <input type="text" inputmode="numeric" id="sort_order" name="sort_order" value="<?= e($v('sort_order')) ?>" maxlength="4"></div>
  <p class="campo"><label><input type="checkbox" name="active" value="1"<?= $v('active') === '1' ? ' checked' : '' ?>> Ativa (aparece ao criar metas)</label></p>
  <button type="submit" class="btn">Salvar</button>
</form>
