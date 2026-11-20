<?php
/** @var array $user @var array $patient @var string $first @var bool $many @var bool $hasExams @var bool $hasTreatment */
$pid = (int) $patient['id'];
?>
<h1>Olá! Bem-vindo ao acompanhamento</h1>
<p class="portal-lead">Acompanhamento de <strong><?= e($first) ?></strong></p>
<ul class="portal-lista">
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/metas')) ?>">
    <span class="portal-cartao-titulo"><?= e($user['role'] === 'paciente' ? 'Minhas metas' : 'Metas de ' . $first) ?></span>
    <span class="portal-cartao-texto">Marque o que foi feito e veja as estrelas.</span>
  </a></li>
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/crescimento')) ?>">
    <span class="portal-cartao-titulo">Crescimento</span>
    <span class="portal-cartao-texto">Veja como <?= e($first) ?> está se desenvolvendo.</span>
  </a></li>
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/alimentacao')) ?>">
    <span class="portal-cartao-titulo">Alimentação</span>
    <span class="portal-cartao-texto">Veja o plano alimentar de <?= e($first) ?>.</span>
  </a></li>
  <?php if ($hasExams): ?><li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/exames')) ?>">
    <span class="portal-cartao-titulo">Exames</span>
    <span class="portal-cartao-texto">Veja os exames de <?= e($first) ?> liberados pela Dra. Rubia.</span>
  </a></li><?php endif; ?>
  <?php if ($hasTreatment): ?><li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/tratamento')) ?>">
    <span class="portal-cartao-titulo">Tratamento</span>
    <span class="portal-cartao-texto">Veja os medicamentos<?= !empty($treatmentLogs) ? ' e registre as doses' : '' ?>.</span>
  </a></li><?php endif; ?>
  <?php if (!empty($hasDiary)): ?><li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/diario')) ?>">
    <span class="portal-cartao-titulo">Diário</span>
    <span class="portal-cartao-texto">Anote o que a equipe pediu.</span>
  </a></li><?php endif; ?>
</ul>
<?php if (!empty($agendaCard)): ?>
<section class="portal-cartao" id="portal-consultas"><h2>Consultas</h2>
  <?php if ($agendaCard['upcoming'] === [] && $agendaCard['past'] === []): ?>
    <p>Nenhuma consulta marcada.</p>
  <?php endif; ?>
  <?php foreach (['upcoming' => 'Próximas', 'past' => 'Últimas'] as $k => $title): ?>
    <?php if ($agendaCard[$k] !== []): ?><h3><?= e($title) ?></h3>
    <ul><?php foreach ($agendaCard[$k] as $a): ?><li><?= e(fmt_date($a['date']) . ' às ' . $a['time'] . ' — ' . $a['professional'] . ' — ' . $a['status']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/medidas/nova')) ?>">Registrar medida</a></p>
<p class="portal-links">
  <?php if ($many): ?><a href="<?= e(url('/portal')) ?>">Trocar de pessoa</a> · <?php endif; ?>
  <a href="<?= e(url('/portal/conta')) ?>">Minha conta</a>
</p>
