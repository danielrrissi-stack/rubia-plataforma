<?php
/**
 * Corpo de um plano (texto ou estruturado). Reutilizado pela equipe e pelo portal.
 * @var array $plan @var bool $family  (family = true: linguagem leiga, sem kcal por opcao nem aviso de valor da TACO)
 * "Opção N" so aparece quando a refeicao tem 2+ opcoes.
 */
use App\Nutrition\FamilyPhrase;
use App\Nutrition\MealPlanCalculator;
use App\Nutrition\PlanText;

$family = $family ?? false;
?>
<div class="plano-corpo">
<?php if ($plan['mode'] === 'texto'): ?>
  <?= PlanText::toHtml((string) ($plan['body_text'] ?? '')) ?>
<?php else: ?>
  <?php foreach ((array) ($plan['structure']['meals'] ?? []) as $meal): ?>
    <section class="plano-refeicao">
      <h3><?= e($meal['name']) ?><?= ($meal['time'] ?? '') !== '' ? ' — ' . e($meal['time']) : '' ?></h3>
      <?php if (($meal['note'] ?? '') !== ''): ?><p class="ajuda"><?= e($meal['note']) ?></p><?php endif; ?>
      <?php $opts = (array) ($meal['options'] ?? []); $many = count($opts) > 1; ?>
      <?php foreach ($opts as $oi => $opt): ?>
        <?php $kcal = $family ? '' : ' <span class="ajuda">(' . e(fmt_num(MealPlanCalculator::option($opt)['kcal'], 0)) . ' kcal)</span>'; ?>
        <?php if ($many): ?>
          <h4>Opção <?= e($oi + 1) ?><?= $kcal ?></h4>
        <?php elseif ($kcal !== ''): ?>
          <p class="ajuda"><?= trim($kcal) ?></p>
        <?php endif; ?>
        <ul>
          <?php foreach ((array) ($opt['items'] ?? []) as $item): ?>
            <li><?= e(FamilyPhrase::item($item)) ?><?php if (!$family && MealPlanCalculator::item($item)['missing']): ?> <span class="ajuda">(valor não disponível na TACO)</span><?php endif; ?><?php foreach ((array) ($item['subs'] ?? []) as $sub): ?> ou <?= e(FamilyPhrase::item($sub)) ?><?php if (!$family && MealPlanCalculator::item($sub)['missing']): ?> <span class="ajuda">(valor não disponível na TACO)</span><?php endif; ?><?php endforeach; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
<?php if (trim((string) ($plan['guidance_text'] ?? '')) !== ''): ?>
  <section class="plano-orientacoes"><h3>Orientações</h3><?= PlanText::toHtml((string) $plan['guidance_text']) ?></section>
<?php endif; ?>
</div>
