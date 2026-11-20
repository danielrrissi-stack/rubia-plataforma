<?php
/**
 * @var array $patient @var ?array $request @var array $values @var string[] $errors @var string[] $types
 * @var bool $lockType @var bool $canSecret
 */
use App\Diary\DiaryType;

$pid = (int) $patient['id'];
$isNew = $request === null;
$action = $isNew ? '/pacientes/' . $pid . '/diario/pedidos/novo' : '/diario/pedidos/' . (int) $request['id'] . '/editar';
$name = $patient['social_name'] ?: $patient['full_name'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$mask = (int) ($values['weekdays'] ?? 0);
?>
<h1><?= $isNew ? 'Novo pedido de registro' : 'Editar pedido de registro' ?> — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/diario')) ?>">Voltar ao diário</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="type">O que registrar</label>
    <?php if ($lockType): ?>
      <input type="hidden" name="type" value="<?= e($v('type')) ?>">
      <select id="type" name="type" disabled>
        <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $v('type') === $t ? ' selected' : '' ?>><?= e(DiaryType::LABELS[$t]) ?></option><?php endforeach; ?>
      </select>
      <p class="ajuda">O tipo não muda depois que há registros.</p>
    <?php else: ?>
      <select id="type" name="type" required>
        <option value="">Escolha…</option>
        <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $v('type') === $t ? ' selected' : '' ?>><?= e(DiaryType::LABELS[$t]) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?></div>
  <fieldset class="campo"><legend>Dias da semana</legend>
    <?php foreach (DiaryType::WEEKDAY_LABELS as $n => $label): ?>
      <label class="inline"><input type="checkbox" name="weekdays[]" value="<?= $n ?>"<?= ($mask & (1 << ($n - 1))) !== 0 ? ' checked' : '' ?>> <?= e($label) ?></label>
    <?php endforeach; ?>
    <button type="button" class="btn btn-quiet" id="todo-dia" hidden>Todo dia</button>
  </fieldset>
  <div class="campo"><label for="start_date">Início</label>
    <input type="date" id="start_date" name="start_date" value="<?= e($v('start_date')) ?>" required></div>
  <div class="campo"><label for="end_date">Término (opcional)</label>
    <input type="date" id="end_date" name="end_date" value="<?= e($v('end_date')) ?>"></div>
  <div class="campo"><label for="guidance">Orientação (opcional)</label>
    <input type="text" id="guidance" name="guidance" value="<?= e($v('guidance')) ?>" maxlength="255" data-ditado>
    <p class="ajuda">Aparece para a família junto do pedido.</p></div>
  <?php if ($canSecret): ?>
    <input type="hidden" name="secret_shown" value="1">
    <div class="campo"><label><input type="checkbox" name="secret" value="1"<?= (int) ($values['secret'] ?? 0) === 1 ? ' checked' : '' ?>> Sigiloso</label>
      <p class="ajuda">Os responsáveis e a nutricionista não veem este pedido nem os registros dele; só o paciente e a médica.</p></div>
  <?php endif; ?>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/diario')) ?>">Cancelar</a>
</form>
<script src="<?= e(url('/assets/js/diary-weekdays.js')) ?>" defer></script>
