<?php
/**
 * @var string $userName @var string $role @var string $today @var array $recent @var array $pending @var bool $canCreate @var list<array> $unreviewed @var list<array> $reviewsDue
 */
$heading = $role === 'nutricionista' ? 'Meus pacientes' : 'Pacientes recentes';
?>
<h1>Bem-vindo, <?= e($userName) ?></h1>

<?php if ($pending !== []): ?>
<section class="cartao" aria-labelledby="h-maioridade">
  <h2 id="h-maioridade">Decisões de maioridade pendentes</h2>
  <p class="ajuda">Pacientes com 18 anos completos que ainda têm responsáveis vinculados.</p>
  <ul>
    <?php foreach ($pending as $p): ?>
      <li>
        <a href="<?= e(url('/pacientes/' . $p['id'])) ?>"><?= e($p['social_name'] ?: $p['full_name']) ?></a>
        (<?= e(\App\Growth\Age::label($p['birth_date'], $today)) ?>) —
        <a href="<?= e(url('/maioridade')) ?>">Decidir</a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($unreviewed !== []): ?>
<section class="cartao" aria-labelledby="h-relatos">
  <h2 id="h-relatos">Relatos da família para ver</h2>
  <ul>
    <?php foreach ($unreviewed as $ev): ?>
      <li>
        <?= e(fmt_date($ev['occurred_on'])) ?> —
        <a href="<?= e(url('/pacientes/' . (int) $ev['patient_id'] . '/tratamento')) ?>"><?= e($ev['patient_name']) ?></a>:
        <?= e(mb_strimwidth((string) $ev['description'], 0, 80, '…')) ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($reviewsDue !== []): ?>
<section class="cartao" aria-labelledby="h-reavaliacoes">
  <h2 id="h-reavaliacoes">Reavaliações</h2>
  <ul>
    <?php foreach ($reviewsDue as $rv): ?>
      <li>
        <a href="<?= e(url('/pacientes/' . (int) $rv['patient_id'] . '/tratamento')) ?>"><?= e($rv['patient_name']) ?></a> —
        <?= e($rv['drug_name']) ?>, <?= e(fmt_date($rv['next_review_date'])) ?>
        <?php if ($rv['next_review_date'] < $today): ?><span class="selo selo-alerta">vencida</span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="cartao" aria-labelledby="h-recentes">
  <h2 id="h-recentes"><?= e($heading) ?></h2>
  <?php if ($recent === []): ?>
    <p><?= $role === 'nutricionista' ? 'Nenhum paciente liberado para você ainda.' : 'Nenhum paciente cadastrado.' ?></p>
  <?php else: ?>
    <ul>
      <?php foreach ($recent as $p): ?>
        <li>
          <a href="<?= e(url('/pacientes/' . $p['id'])) ?>"><?= e($p['social_name'] ?: $p['full_name']) ?></a>
          — <?= e(\App\Growth\Age::label($p['birth_date'], $today)) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<p>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes')) ?>">Todos os pacientes</a>
  <?php if ($canCreate): ?><a class="btn" href="<?= e(url('/pacientes/novo')) ?>">Novo paciente</a><?php endif; ?>
  <?php if ($role === 'medico'): ?><a class="btn btn-quiet" href="<?= e(url('/maioridade')) ?>">Decisões de maioridade</a><?php endif; ?>
</p>
