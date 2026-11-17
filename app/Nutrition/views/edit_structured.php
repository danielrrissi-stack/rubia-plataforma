<?php
/** Placeholder: o editor estruturado e da Task 7. @var array $plan @var array $patient @var array{kind:string,saveUrl:string,backUrl:string} $context */
?>
<h1>Plano alimentar — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url($context['backUrl'])) ?>">Voltar</a></p>
<p class="aviso">Editor estruturado em construção.</p>
