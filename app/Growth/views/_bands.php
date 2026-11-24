<?php
/** Painel de graficos por faixa. @var int $pid @var array<string,string> $bandAges @var string $bandCurrent */
?>
<section class="cartao" aria-labelledby="h-faixas">
  <h2 id="h-faixas">Altura, peso e IMC por faixa</h2>
  <div id="growth-bands" data-url="<?= e(url('/pacientes/' . $pid . '/faixas.json')) ?>" data-faixa="<?= e($bandCurrent) ?>">
    <?php if (count($bandAges) > 1): ?>
    <div class="abas" role="group" aria-label="Faixa de idade">
      <?php foreach ($bandAges as $key => $label): ?>
        <button type="button" class="aba" data-faixa-idade="<?= e($key) ?>" aria-pressed="<?= $key === $bandCurrent ? 'true' : 'false' ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="ajuda" data-role="msg" role="status"></p>
    <?php foreach (['hfa' => 'Altura (cm)', 'wfa' => 'Peso (kg)', 'bfa' => 'IMC (kg/m²)'] as $key => $label): ?>
    <div class="faixa-grafico" data-chart="<?= e($key) ?>" hidden>
      <h3><?= e($label) ?></h3>
      <div class="grafico"><canvas role="img" aria-label="<?= e($label) ?> para a idade, com as faixas de classificação"></canvas></div>
    </div>
    <?php endforeach; ?>
    <p class="ajuda">Faixas da OMS para a idade e o sexo. A OMS não tem curva de peso para a idade depois dos 10 anos.</p>
  </div>
</section>
