<?php
/**
 * Corpo de um plano (texto ou estruturado). Reutilizado pela equipe e pelo portal.
 * @var array $plan @var bool $family  (family = true: linguagem leiga, sem kcal por opcao nem aviso de valor da TACO)
 * "Opção N" so aparece quando a refeicao tem 2+ opcoes.
 */
use App\Nutrition\FamilyPhrase;
use App\Nutrition\MealPlanCalculator;
use App\Nutrition\PlanDays;
use App\Nutrition\PlanText;

$family = $family ?? false;
// Plano por dias (v2): $dayIndexes (lista de indices) e $dayDates (data exibida por indice) restringem/rotulam os dias;
// $markToday (so equipe) marca o dia de hoje; $dayAnchors = false evita id duplicado quando a pagina ja tem o id do dia.
$groups = [];
if ($plan['mode'] !== 'texto') {
    $struct = (array) ($plan['structure'] ?? []);
    if (PlanDays::isMulti($struct)) {
        $all = PlanDays::days($struct);
        $idxs = isset($dayIndexes) ? array_values((array) $dayIndexes) : array_keys($all);
        // $dayDates em lista (mesma ordem de $dayIndexes) permite indices repetidos (ciclo de plano curto).
        $byPosition = isset($dayDates) && is_array($dayDates) && array_is_list($dayDates) && count($dayDates) === count($idxs);
        foreach ($idxs as $pos => $k) {
            if (!isset($all[$k])) {
                continue;
            }
            $date = $byPosition ? (string) $dayDates[$pos] : (isset($dayDates[$k]) ? (string) $dayDates[$k] : (string) $all[$k]['date']);
            $groups[] = ['date' => $date, 'meals' => $all[$k]['meals']];
        }
    } else {
        $groups[] = ['date' => null, 'meals' => (array) ($struct['meals'] ?? [])];
    }
}
$markToday = $markToday ?? false;
$dayAnchors = $dayAnchors ?? true;
$weekdays = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
?>
<div class="plano-corpo">
<?php if ($plan['mode'] === 'texto'): ?>
  <?= PlanText::toHtml((string) ($plan['body_text'] ?? '')) ?>
<?php else: ?>
  <?php foreach ($groups as $group): $multiDay = $group['date'] !== null; ?>
  <?php if ($multiDay): $dt = new DateTimeImmutable($group['date']); ?>
  <section class="plano-dia"<?= $dayAnchors ? ' id="dia-' . e($group['date']) . '"' : '' ?>>
    <h2><?= e($weekdays[(int) $dt->format('w')] . ', ' . $dt->format('d/m')) ?><?= $markToday && $group['date'] === date('Y-m-d') ? ' <span class="selo">hoje</span>' : '' ?></h2>
    <?php if (!$family): ?><p class="ajuda"><?= e(fmt_num(MealPlanCalculator::day(['meals' => $group['meals']])['kcal'], 0)) ?> kcal no dia</p><?php endif; ?>
  <?php endif; ?>
  <?php foreach ($group['meals'] as $meal): ?>
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
  <?php if ($multiDay): ?></section><?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>
<?php if (trim((string) ($plan['guidance_text'] ?? '')) !== ''): ?>
  <section class="plano-orientacoes"><h3>Orientações</h3><?= PlanText::toHtml((string) $plan['guidance_text']) ?></section>
<?php endif; ?>
</div>
