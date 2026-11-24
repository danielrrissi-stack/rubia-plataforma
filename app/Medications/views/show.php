<?php
/**
 * @var array $patient @var array $p @var string $status @var string $today
 * @var list<array> $doses @var list<array> $logs @var list<array> $events
 */
use App\Medications\Via;

$pid = (int) $patient['id'];
$rid = (int) $p['id'];
$fmtTime = static fn (string $dt): string => fmt_date(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5);
?>
<h1><?= e($p['drug_name']) ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/tratamento')) ?>">Voltar ao tratamento</a>
  · <span class="selo"><?= e($status) ?></span>
  <?php if ((int) $p['secret'] === 1): ?><span class="selo selo-alerta">sigilosa</span><?php endif; ?></p>

<section class="cartao" aria-labelledby="h-dados">
  <h2 id="h-dados">Dados</h2>
  <p>Via: <?= e(Via::LABELS[$p['route']] ?? $p['route']) ?></p>
  <p>Início: <?= e(fmt_date($p['start_date'])) ?><?= $p['end_date'] !== null ? ' · término: ' . e(fmt_date($p['end_date'])) : '' ?></p>
  <?php if ($p['end_reason'] !== null): ?><p>Motivo do encerramento: <?= e($p['end_reason']) ?></p><?php endif; ?>
  <p>Próxima avaliação: <?= $p['next_review_date'] !== null ? e(fmt_date($p['next_review_date'])) : '—' ?></p>
  <p>Registro de doses pela família: <?= (int) $p['family_logs'] === 1 ? 'ligado' : 'desligado' ?></p>
  <?php if ($p['notes'] !== null): ?><p>Observações internas: <?= nl2br(e($p['notes'])) ?></p><?php endif; ?>
  <p>
    <a class="btn btn-quiet" href="<?= e(url('/prescricoes/' . $rid . '/editar')) ?>">Editar</a>
    <?php if ($status !== 'encerrada'): ?><a class="btn btn-quiet" href="<?= e(url('/prescricoes/' . $rid . '/dose')) ?>">Alterar dose</a><?php endif; ?>
  </p>
</section>

<section class="cartao" aria-labelledby="h-historico">
  <h2 id="h-historico">Histórico de doses</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th scope="col">A partir de</th><th scope="col">Dose</th><th scope="col">Frequência</th><th scope="col">Orientação</th><th scope="col">Motivo</th><th scope="col">Profissional</th></tr></thead>
    <tbody>
    <?php foreach ($doses as $d): ?>
      <tr>
        <td><?= e(fmt_date($d['effective_date'])) ?></td>
        <td><?= e($d['dose']) ?></td>
        <td><?= e($d['frequency']) ?></td>
        <td><?= $d['instructions'] !== null ? nl2br(e($d['instructions'])) : '—' ?></td>
        <td><?= $d['reason'] !== null ? e($d['reason']) : '—' ?></td>
        <td><?= e($d['author_name']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="cartao" aria-labelledby="h-registros">
  <h2 id="h-registros">Doses registradas pela família (últimos 90 dias)</h2>
  <?php if ($logs === []): ?>
    <p class="ajuda">Nenhum registro.</p>
  <?php else: ?>
    <ul>
    <?php foreach ($logs as $l): ?>
      <li><?= e($fmtTime((string) $l['given_at'])) ?> · <?= e($l['dose']) ?> · <?= e($l['recorder_name']) ?><?= $l['note'] !== null ? ' · ' . e($l['note']) : '' ?></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="cartao" aria-labelledby="h-efeitos">
  <h2 id="h-efeitos">Efeitos relatados</h2>
  <?php if ($events === []): ?>
    <p class="ajuda">Nenhum efeito relatado para este medicamento.</p>
  <?php else: ?>
    <ul>
    <?php foreach ($events as $ev): ?>
      <li><strong><?= e(fmt_date($ev['occurred_on'])) ?></strong><?= $ev['severity'] !== null ? ' <span class="selo">' . e($ev['severity']) . '</span>' : '' ?>
        <br><?= nl2br(e($ev['description'])) ?>
        <?php if ($ev['conduct'] !== null): ?><br>Conduta: <?= nl2br(e($ev['conduct'])) ?><?php endif; ?>
        <br><span class="ajuda">Relatado por <?= e($ev['reporter_name']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($status === 'ativa'): ?>
<section class="cartao" aria-labelledby="h-encerrar">
  <h2 id="h-encerrar">Encerrar prescrição</h2>
  <form method="post" action="<?= e(url('/prescricoes/' . $rid . '/encerrar')) ?>">
    <?= csrf_field() ?>
    <div class="campo"><label for="end_date">Data de término</label>
      <input type="date" id="end_date" name="end_date" value="<?= e($today) ?>" min="<?= e($p['start_date']) ?>" max="<?= e($today) ?>" required></div>
    <div class="campo"><label for="reason">Motivo (opcional)</label>
      <input type="text" id="reason" name="reason" maxlength="200" data-ditado></div>
    <button type="submit" class="btn btn-quiet">Encerrar</button>
  </form>
</section>
<?php endif; ?>

<form class="form-inline" method="post" action="<?= e(url('/prescricoes/' . $rid . '/excluir')) ?>" data-confirm="Excluir esta prescrição? Ela deixa de aparecer para todos."><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir prescrição</button></form>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
