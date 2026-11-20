<?php
/**
 * Editor do modo estruturado (refeicoes > opcoes > itens com substitutos; busca na TACO; totais ao vivo).
 * Reutilizavel pelo editor de modelos (Task 9) via $context: sem paciente e sem meta de energia.
 * @var array $plan @var ?array $patient @var array $values @var string[] $errors @var ?int $metaKcal
 * @var array{kind:string,saveUrl:string,backUrl:string,energyUrl?:string,publishUrl?:string} $context
 */
use App\Nutrition\MealPlanController;

$isPlan = $context['kind'] === 'plan';
$patient = $patient ?? null;
$metaKcal = $metaKcal ?? null;
$data = MealPlanController::editorData(is_array($plan['structure'] ?? null) ? $plan['structure'] : null);
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<h1><?= $isPlan ? 'Plano alimentar' : 'Modelo' ?><?php if ($patient !== null): ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?><?php endif; ?></h1>
<p><a href="<?= e(url($context['backUrl'])) ?>">Voltar</a><?php if ($isPlan && ($plan['source_diet_code'] ?? null) !== null): ?>
  · <span class="ajuda">Origem: dieta pronta <a href="<?= e(url('/dietas-prontas/' . $plan['source_diet_code'])) ?>"><?= e($plan['source_diet_code']) ?></a></span><?php endif; ?></p>

<div id="pe-erros" class="aviso aviso-erro" role="alert"<?= $errors === [] ? ' hidden' : '' ?>>
  <p>Não foi possível salvar. Corrija:</p>
  <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
</div>
<p id="pe-conflito" class="aviso aviso-erro" role="alert" hidden>Este rascunho foi alterado em outra janela depois que você o abriu. O que está nesta tela foi salvo por cima; confira antes de publicar.</p>
<?php if ($isPlan && $metaKcal === null && isset($context['energyUrl'])): ?>
  <p class="aviso">Paciente sem meta de energia. <a href="<?= e(url($context['energyUrl'])) ?>">Calcular a meta na seção Energia</a> para comparar com o total do plano.</p>
<?php endif; ?>
<noscript><p class="aviso aviso-erro">Este editor precisa de JavaScript ativado no navegador.</p></noscript>

<div id="plano-editor" class="pe"
     data-plano="<?= e($json) ?>"
     data-meta-kcal="<?= e($metaKcal ?? '') ?>"
     data-busca-url="<?= e(url('/alimentos/busca')) ?>"
     data-salvar-url="<?= e(url($context['saveUrl'])) ?>"
     data-revisao="<?= e($values['revisao'] ?? ($plan['revision'] ?? 1)) ?>"
     data-csrf="<?= e(\App\Core\Csrf::token()) ?>">
  <p><label>Título <input id="pe-titulo" value="<?= e($values['title'] ?? ($plan['title'] ?? '')) ?>" maxlength="150" required data-ditado></label></p>

  <section class="pe-totais" id="pe-totais" aria-labelledby="pe-totais-titulo">
    <h2 id="pe-totais-titulo">Total do dia <span class="ajuda">(Opção 1 de cada refeição)</span></h2>
    <p class="pe-totais-linha" aria-live="polite">
      <strong><span data-total="kcal">0</span> kcal</strong> ·
      Proteína <span data-total="protein">0</span> g (<span data-pct="protein">0</span>%) ·
      Carboidrato <span data-total="carb">0</span> g (<span data-pct="carb">0</span>%) ·
      Gordura <span data-total="fat">0</span> g (<span data-pct="fat">0</span>%) ·
      Fibra <span data-total="fiber">0</span> g
    </p>
    <?php if ($metaKcal !== null): ?>
      <p class="pe-meta">Meta: <?= e(fmt_num((float) $metaKcal, 0)) ?> kcal · <span id="pe-diferenca">diferença 0 kcal</span></p>
      <div class="pe-barra" aria-hidden="true"><div class="pe-barra-cheia" id="pe-barra"></div></div>
    <?php elseif ($isPlan): ?>
      <p class="ajuda">Sem meta de energia para comparar.</p>
    <?php endif; ?>
  </section>

  <div id="pe-refeicoes"></div>
  <p><button type="button" class="btn btn-quiet" id="pe-add-refeicao">Adicionar refeição</button></p>

  <?php $snippetTargets = ['pe-orientacoes' => 'Orientações']; require __DIR__ . '/_snippet_picker.php'; ?>
  <p><label>Orientações<br><textarea id="pe-orientacoes" rows="6"><?= e($values['orientacoes'] ?? ($plan['guidance_text'] ?? '')) ?></textarea></label></p>
  <p class="pe-acoes">
    <button type="button" class="btn btn-quiet" id="pe-salvar">Salvar rascunho</button>
    <?php if ($isPlan && isset($context['publishUrl'])): ?><button type="button" class="btn" id="pe-publicar">Salvar e publicar</button><?php endif; ?>
    <span id="pe-status" class="ajuda" role="status"></span>
  </p>
  <?php /* Avisos do salvamento automatico ficam aqui, abaixo dos botoes: nao empurram a tela enquanto se digita. */ ?>
  <div id="pe-aviso-auto" class="aviso" role="status" hidden>
    <p></p>
    <ul></ul>
  </div>
</div>
<?php if ($isPlan && isset($context['publishUrl'])): ?>
  <form id="pe-form-publicar" method="post" action="<?= e(url($context['publishUrl'])) ?>" hidden><?= csrf_field() ?></form>
<?php endif; ?>
<script src="<?= e(url('/assets/js/snippet-picker.js')) ?>" defer></script>
<script src="<?= e(url('/assets/js/meal-plan-editor.js')) ?>" defer></script>
