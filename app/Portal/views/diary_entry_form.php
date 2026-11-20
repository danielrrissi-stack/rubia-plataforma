<?php
/** @var array $patient @var array $request @var ?array $entry @var string $day @var array $values @var string[] $errors */
use App\Diary\DiaryType;

$pid = (int) $patient['id'];
$type = (string) $request['type'];
$back = '/portal/p/' . $pid . '/diario?dia=' . $day;
$action = $entry === null
    ? '/portal/p/' . $pid . '/diario/' . (int) $request['id'] . '/anotar?dia=' . $day
    : '/portal/p/' . $pid . '/diario/registros/' . (int) $entry['id'] . '/editar';
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<p class="portal-links"><a href="<?= e(url($back)) ?>">&larr; Voltar</a></p>
<h1><?= e(DiaryType::LABELS[$type] ?? $type) ?> — <?= e(fmt_date($day)) ?></h1>
<?php if (($request['guidance'] ?? '') !== ''): ?><p class="ajuda"><?= e($request['guidance']) ?></p><?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>"<?= $type === 'alimentacao' ? ' enctype="multipart/form-data"' : '' ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="dia" value="<?= e($day) ?>">
  <?php if ($type === 'alimentacao'): ?>
    <div class="campo"><label for="meal">Refeição</label>
      <select id="meal" name="meal" required>
        <option value="">—</option>
        <?php foreach (DiaryType::MEAL_LABELS as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $v('meal') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="campo"><label for="meal_time">Horário (opcional)</label>
      <input type="time" id="meal_time" name="meal_time" value="<?= e($v('meal_time')) ?>"></div>
    <div class="campo"><label for="description">O que comeu</label>
      <textarea id="description" name="description" rows="3" maxlength="1000" required><?= e($v('description')) ?></textarea></div>
    <div class="campo"><label for="foto">Foto (opcional)</label>
      <input type="file" id="foto" name="foto" accept="image/*">
      <p class="ajuda">Fotografe só o prato.</p></div>
    <?php if ($entry !== null && ($entry['photo_path'] ?? '') !== ''): ?>
      <div class="campo"><label><input type="checkbox" name="remover_foto" value="1"> Remover foto</label></div>
    <?php endif; ?>
  <?php elseif ($type === 'agua'): ?>
    <div class="campo"><label for="glasses">Quantos copos de água</label>
      <input type="number" id="glasses" name="glasses" min="0" max="30" inputmode="numeric" value="<?= e($v('glasses')) ?>" required></div>
  <?php elseif ($type === 'sono'): ?>
    <div class="campo"><label for="slept_at">Dormiu às</label>
      <input type="time" id="slept_at" name="slept_at" value="<?= e($v('slept_at')) ?>" required></div>
    <div class="campo"><label for="woke_at">Acordou às</label>
      <input type="time" id="woke_at" name="woke_at" value="<?= e($v('woke_at')) ?>" required></div>
    <fieldset class="campo"><legend>Como dormiu?</legend>
      <?php foreach ([1 => 'mal', 2 => 'mais ou menos', 3 => 'bem'] as $q => $label): ?>
        <label><input type="radio" name="sleep_quality" value="<?= $q ?>"<?= $v('sleep_quality') === (string) $q ? ' checked' : '' ?>>
          <?= e(\App\Diary\DiaryFormat::QUALITY_EMOJI[$q]) ?> <?= e($label) ?></label>
      <?php endforeach; ?>
    </fieldset>
  <?php elseif ($type === 'atividade'): ?>
    <div class="campo"><label for="activity">O que fez</label>
      <input type="text" id="activity" name="activity" maxlength="120" value="<?= e($v('activity')) ?>" required data-ditado></div>
    <div class="campo"><label for="minutes">Quantos minutos (opcional)</label>
      <input type="number" id="minutes" name="minutes" min="1" max="600" inputmode="numeric" value="<?= e($v('minutes')) ?>"></div>
  <?php elseif ($type === 'tela'): ?>
    <div class="campo"><label for="minutes">Minutos de tela</label>
      <input type="number" id="minutes" name="minutes" min="0" max="1440" inputmode="numeric" value="<?= e($v('minutes')) ?>" required></div>
  <?php endif; ?>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url($back)) ?>">Cancelar</a>
</form>
