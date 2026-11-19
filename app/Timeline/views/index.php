<?php
/** @var array $patient @var array $period @var array $result @var ?array $weightGoal */
use App\Timeline\TimelinePeriod;

$pid = (int) $patient['id'];
?>
<h1>Linha do tempo — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">&larr; Voltar ao paciente</a></p>

<nav class="abas" aria-label="Período">
<?php foreach (TimelinePeriod::CHOICES as $key => $label): ?>
  <a class="aba" href="<?= e(url('/pacientes/' . $pid . '/linha-do-tempo?periodo=' . $key)) ?>"<?= $period['choice'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>

<h2><?= e($period['label']) ?><?php if ($period['ref'] !== null): ?> (<?= e(fmt_date($period['ref'])) ?>) — <?= e((string) $period['days']) ?> <?= $period['days'] === 1 ? 'dia' : 'dias' ?><?php endif; ?></h2>

<?php if ($weightGoal !== null && $weightGoal['goal'] !== null) { $wg = $weightGoal; include dirname(__DIR__, 2) . '/WeightGoals/views/_card.php'; } ?>

<?php if ($result['events'] === []): ?>
  <p class="ajuda">Nada registrado neste período.</p>
<?php else: ?>
  <?php if ($result['truncated']): ?><p class="ajuda">Mostrando os 200 eventos mais recentes.</p><?php endif; ?>
  <table class="tabela linha-do-tempo">
    <thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">O que aconteceu</th></tr></thead>
    <tbody>
    <?php foreach ($result['events'] as $ev): ?>
      <tr><td><?= e(fmt_date($ev['date'])) ?></td>
        <td><span class="selo"><?= e($ev['label']) ?></span></td>
        <td><?php if ($ev['link'] !== null): ?><a href="<?= e($ev['link']) ?>"><?= e($ev['text']) ?></a><?php else: ?><?= e($ev['text']) ?><?php endif; ?><?php if ($ev['badge'] !== null): ?> <span class="selo selo-familia"><?= e($ev['badge']) ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
