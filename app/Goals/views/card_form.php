<?php
/**
 * @var ?array $card @var array $values @var string[] $errors
 */
use App\Goals\EduCardRepository;

$isNew = $card === null;
$action = $isNew ? '/cartoes/novo' : '/cartoes/' . (int) $card['id'] . '/editar';
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$bands = is_array($values['bands'] ?? null) ? $values['bands'] : [];
?>
<h1><?= $isNew ? 'Novo cartão' : 'Revisar cartão' ?></h1>
<p><a href="<?= e(url('/cartoes')) ?>">Voltar aos cartões</a></p>
<?php if (!$isNew): ?>
  <p class="ajuda">Situação: <strong><?= $card['status'] === 'publicado' ? 'publicado' : 'rascunho' ?></strong>.
    Salvar não muda a situação; publique ou despublique na lista.</p>
<?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="title">Título (até 60 caracteres)</label>
    <input type="text" id="title" name="title" value="<?= e($v('title')) ?>" maxlength="60" required data-ditado></div>
  <div class="campo"><label for="emoji">Emoji</label>
    <input type="text" id="emoji" name="emoji" value="<?= e($v('emoji')) ?>" maxlength="16" size="4" required></div>
  <div class="campo"><label for="body">Texto (até 600 caracteres)</label>
    <textarea id="body" name="body" rows="7" maxlength="600" required><?= e($v('body')) ?></textarea></div>
  <fieldset class="campo"><legend>Faixas etárias</legend>
    <?php foreach (array_keys(EduCardRepository::BANDS) as $b): ?>
      <label><input type="checkbox" name="bands[]" value="<?= e($b) ?>"<?= in_array($b, $bands, true) ? ' checked' : '' ?>> <?= e($b) ?> anos</label>
    <?php endforeach; ?>
  </fieldset>
  <div class="campo"><label for="sort_order">Ordem</label>
    <input type="text" inputmode="numeric" id="sort_order" name="sort_order" value="<?= e($v('sort_order')) ?>" maxlength="4"></div>
  <button type="submit" class="btn">Salvar</button>
</form>
