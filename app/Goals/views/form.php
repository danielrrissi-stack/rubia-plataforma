<?php
/**
 * @var array $patient @var ?array $goal @var array $values @var string[] $errors @var list<array> $templates
 * @var string[] $categories @var bool $lockCategory @var bool $canSecret
 */
use App\Goals\GoalPanel;

$pid = (int) $patient['id'];
$isNew = $goal === null;
$action = $isNew ? '/pacientes/' . $pid . '/metas/nova' : '/metas/' . (int) $goal['id'] . '/editar';
$name = $patient['social_name'] ?: $patient['full_name'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<h1><?= $isNew ? 'Nova meta' : 'Editar meta' ?> — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/metas')) ?>">Voltar às metas</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($isNew && $templates !== []): ?>
  <section class="cartao" aria-labelledby="h-modelos">
    <h2 id="h-modelos">Escolher da biblioteca</h2>
    <ul>
    <?php foreach ($templates as $t): ?>
      <li><a href="<?= e(url('/pacientes/' . $pid . '/metas/nova?modelo=' . (int) $t['id'])) ?>"><?= e($t['emoji']) ?> <?= e($t['family_text']) ?></a>
        <span class="ajuda">(<?= e(GoalPanel::CATEGORY_LABELS[$t['category']] ?? $t['category']) ?>)</span></li>
    <?php endforeach; ?>
    </ul>
    <p class="ajuda">Ou escreva uma meta própria abaixo.</p>
  </section>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <?php if ($v('template_id') !== ''): ?><input type="hidden" name="template_id" value="<?= e($v('template_id')) ?>"><?php endif; ?>
  <div class="campo"><label for="family_text">Texto para a família</label>
    <input type="text" id="family_text" name="family_text" value="<?= e($v('family_text')) ?>" maxlength="160" required data-ditado></div>
  <div class="campo"><label for="child_text">Texto para a criança (opcional)</label>
    <input type="text" id="child_text" name="child_text" value="<?= e($v('child_text')) ?>" maxlength="160" data-ditado>
    <p class="ajuda">Se ficar vazio, a criança vê o texto da família.</p></div>
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
  <div class="campo"><label for="times_per_week">Frequência</label>
    <select id="times_per_week" name="times_per_week">
      <?php for ($n = 1; $n <= 7; $n++): ?>
        <option value="<?= $n ?>"<?= $v('times_per_week') === (string) $n ? ' selected' : '' ?>><?= $n === 7 ? 'Todo dia' : $n . ' vez' . ($n === 1 ? '' : 'es') . ' por semana' ?></option>
      <?php endfor; ?>
    </select></div>
  <div class="campo"><label for="start_date">Início</label>
    <input type="date" id="start_date" name="start_date" value="<?= e($v('start_date')) ?>" required></div>
  <div class="campo"><label for="end_date">Término (opcional)</label>
    <input type="date" id="end_date" name="end_date" value="<?= e($v('end_date')) ?>"></div>
  <?php if ($canSecret): ?>
    <p class="campo"><label><input type="checkbox" name="secret" value="1"<?= $v('secret') === '1' ? ' checked' : '' ?>> Meta sigilosa (os responsáveis não veem; só o paciente e a equipe)</label></p>
  <?php endif; ?>
  <button type="submit" class="btn">Salvar</button>
</form>
