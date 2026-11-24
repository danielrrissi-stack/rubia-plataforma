<?php
/** @var array $diet @var list<array> $days */
use App\Diets\DietBuilder;
use App\Diets\DietController;

$family = false;
?>
<h1><?= e($diet['code']) ?></h1>
<p><a href="<?= e(url('/dietas-prontas?versao=' . $diet['band'] . '&semana=' . $diet['week'])) ?>">Voltar às dietas prontas</a></p>
<section class="cartao"><h2>Resumo</h2>
  <p><?= e(DietController::BAND_LABEL[$diet['band']]) ?> · semana <?= e($diet['week']) ?> (<?= e(DietBuilder::SEASON_LABEL[$diet['season']]) ?>)
    · título para a família: "<?= e($diet['title']) ?>"</p>
  <p class="ajuda">Valores calculados com a tabela de alimentos atual. A sugestão é um ponto de partida: ajuste às necessidades do paciente antes de publicar.</p>
</section>
<?php foreach ($days as $i => $d):
    $day = $d['day'];
    $percents = $d['percents'];
    $plan = ['mode' => 'estruturado', 'structure' => $d['structure'], 'guidance_text' => $i === 6 ? $diet['guidance_text'] : ''];
?>
<section class="cartao plano-leitura"><h2><?= e($d['label']) ?></h2>
  <p><strong><?= e(fmt_num($day['kcal'], 0)) ?> kcal</strong> (alvo <?= e(number_format((int) $diet['kcal_target'], 0, ',', '.')) ?>)
    · proteína <?= e(fmt_num($day['protein'], 0)) ?> g (<?= e(fmt_num($percents['protein'], 0)) ?>%)
    · carboidrato <?= e(fmt_num($day['carb'], 0)) ?> g (<?= e(fmt_num($percents['carb'], 0)) ?>%)
    · gordura <?= e(fmt_num($day['fat'], 0)) ?> g (<?= e(fmt_num($percents['fat'], 0)) ?>%)
    · fibra <?= e(fmt_num($day['fiber'], 0)) ?> g</p>
  <?php require APP_ROOT . '/app/Nutrition/views/_plan_body.php'; ?></section>
<?php endforeach; ?>
