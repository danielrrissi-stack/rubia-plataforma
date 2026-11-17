<?php
/**
 * Editor do modo texto. Reutilizavel pelo editor de modelos (Task 9) via $context.
 * @var array $plan @var ?array $patient @var array $values @var string[] $errors
 * @var array{kind:string,saveUrl:string,backUrl:string} $context
 */
$isPlan = $context['kind'] === 'plan';
$patient = $patient ?? null;
?>
<h1><?= $isPlan ? 'Plano alimentar' : 'Modelo' ?><?php if ($patient !== null): ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?><?php endif; ?></h1>
<p><a href="<?= e(url($context['backUrl'])) ?>">Voltar</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" action="<?= e(url($context['saveUrl'])) ?>" id="plano-form" data-previa-url="<?= e(url('/planos/previa')) ?>" data-csrf="<?= e(\App\Core\Csrf::token()) ?>" data-snippets-url="<?= e(url('/trechos.json')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="revisao" value="<?= e($values['revisao']) ?>">
  <p><label>Título <input name="titulo" value="<?= e($values['title']) ?>" maxlength="150" required></label></p>
  <p class="barra-editor">
    <button type="button" class="btn btn-quiet" data-insert="titulo">Título de refeição</button>
    <button type="button" class="btn btn-quiet" data-insert="item">Item</button>
    <button type="button" class="btn btn-quiet" data-insert="negrito">Negrito</button>
    <button type="button" class="btn btn-quiet" data-insert="trecho" aria-expanded="false" aria-controls="trechos-box">Inserir trecho</button>
  </p>
  <p id="trechos-box" class="barra-editor" hidden>
    <label for="trechos-select">Trecho</label>
    <select id="trechos-select"></select>
    <button type="button" class="btn btn-quiet" id="trechos-inserir">Inserir no cursor</button>
    <span id="trechos-msg" class="ajuda" role="status"></span>
    <a href="<?= e(url('/trechos')) ?>">Gerenciar trechos</a>
  </p>
  <div class="editor-duplo">
    <div>
      <label for="corpo">Plano (cole o texto aqui)</label>
      <textarea id="corpo" name="corpo" rows="22" data-previa-fonte><?= e($values['corpo']) ?></textarea>
    </div>
    <div>
      <h2>Pré-visualização</h2>
      <div id="previa" class="previa-plano" aria-live="polite"></div>
    </div>
  </div>
  <p><label>Orientações<br><textarea name="orientacoes" rows="6"><?= e($values['orientacoes']) ?></textarea></label></p>
  <p>
    <button type="submit" class="btn btn-quiet">Salvar rascunho</button>
    <?php if ($isPlan): ?><button type="submit" class="btn" name="acao" value="publicar">Salvar e publicar</button><?php endif; ?>
  </p>
</form>
<script src="<?= e(url('/assets/js/plan-text-editor.js')) ?>" defer></script>
