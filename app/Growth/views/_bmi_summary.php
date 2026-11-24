<?php
/** Resumo do IMC. @var ?array $bmiSummary saida de BmiSummary::make() */
?>
<section class="cartao" id="resumo-imc" aria-labelledby="h-imc">
  <h2 id="h-imc">Resumo do IMC</h2>
  <?php if ($bmiSummary === null): ?>
    <p class="ajuda">Registre uma medida com peso e altura para ver o resumo.</p>
  <?php else: $s = $bmiSummary; ?>
    <div class="imc-quadro tom-<?= e($s['tone']) ?>">
      <p class="imc-frase"><strong>IMC <?= e(fmt_num($s['bmi'], 1)) ?>: <?= e($s['status']) ?>.</strong>
        O peso adequado é entre <strong><?= e(fmt_num($s['min'], 1)) ?></strong> e <strong><?= e(fmt_num($s['max'], 1)) ?> kg</strong>.</p>
      <dl class="imc-numeros">
        <div><dt>Mínimo</dt><dd><?= e(fmt_num($s['min'], 1)) ?> kg</dd></div>
        <div class="imc-atual"><dt>Peso medido</dt><dd><?= e(fmt_num($s['weight'], 1)) ?> kg</dd></div>
        <div><dt>Máximo</dt><dd><?= e(fmt_num($s['max'], 1)) ?> kg</dd></div>
      </dl>
    </div>
    <p class="ajuda">Medida de <?= e(fmt_date($s['date'])) ?>: <?= e($s['age']) ?>, altura <?= e(fmt_num($s['height'], 1)) ?> cm.
      <?= $s['adult'] ? 'Faixa adequada do adulto: IMC de 18,5 a 24,9.' : 'Faixa de Eutrofia da OMS para a idade e o sexo (escore Z do IMC de −2 a +1).' ?></p>
    <?php if ($s['abran'] !== null): ?><p class="ajuda">Comparação — tabela da calculadora infantil da ABRAN, faixa "Normal" (IMC <?= e(fmt_num($s['abran']['bmiMin'], 1)) ?> a <?= e(fmt_num($s['abran']['bmiMax'], 1)) ?>):
      <?= e(fmt_num($s['abran']['min'], 1)) ?> a <?= e(fmt_num($s['abran']['max'], 1)) ?> kg. A categoria e a cor acima seguem a OMS.</p><?php endif; ?>
    <?php if ($s['implausible']): ?><p><span class="selo selo-alerta">verificar medida</span></p><?php endif; ?>
  <?php endif; ?>
</section>
