<?php
/** @var array $patient @var string $first @var bool $adult @var ?array $last */
$pid = (int) $patient['id'];
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>Crescimento de <?= e($first) ?></h1>

<?php if ($last === null): ?>
  <p class="aviso">Ainda não há medidas registradas. Registre a primeira para ver o gráfico.</p>
<?php else: ?>
<section class="portal-resumo" aria-label="Última medida">
  <p class="ajuda">Última medida, em <?= e($last['date']) ?></p>
  <p class="portal-valores">
    <?php if ($last['weight'] !== null): ?><span><strong><?= e($last['weight']) ?></strong> de peso</span><?php endif; ?>
    <?php if ($last['height'] !== null): ?><span><strong><?= e($last['height']) ?></strong> de altura</span><?php endif; ?>
  </p>
  <?php if ($last['note'] !== null): ?>
  <p class="portal-nota portal-nota-<?= e($last['tone']) ?>"><?= e($last['note']) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<div id="growth-chart" data-simple="1" data-url="<?= e(url('/pacientes/' . $pid . '/curvas.json')) ?>" data-adult="<?= $adult ? '1' : '0' ?>">
  <?php if ($adult): ?>
  <div class="abas" role="group" aria-label="O que mostrar">
    <button type="button" class="aba" data-indicador="peso" aria-pressed="true">Peso</button>
    <button type="button" class="aba" data-indicador="imc" aria-pressed="false">IMC</button>
  </div>
  <?php else: ?>
  <p class="ajuda">A faixa colorida mostra a faixa esperada para a idade. Os pontos são as medidas de <?= e($first) ?>.</p>
  <?php endif; ?>
  <p class="ajuda" data-role="msg" role="status"></p>
  <div class="grafico"><canvas aria-label="Gráfico de crescimento" role="img"></canvas></div>
</div>

<p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/medidas/nova')) ?>">Registrar medida</a></p>
<script src="<?= e(url('/assets/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/growth-chart.js')) ?>"></script>
