<?php
/** @var array $patient @var string $first @var int $userId @var list<array> $items @var list<array> $events @var string[] $errors @var array $values @var string $nowDate @var string $nowTime */
use App\Medications\Via;

$pid = (int) $patient['id'];
$base = '/portal/p/' . $pid . '/tratamento';
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>Tratamento de <?= e($first) ?></h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($items === []): ?>
  <p class="aviso">Nenhum medicamento em uso no momento.</p>
<?php endif; ?>
<?php foreach ($items as $p):
    $id = (int) $p['id'];
    $cur = $p['current'] ?? null;
    if ($p['status'] === 'encerrada'): ?>
  <section class="portal-medicamento">
    <p><?= e($p['drug_name']) ?> — encerrado em <?= e(fmt_date((string) $p['end_date'])) ?></p>
  </section>
<?php continue; endif; ?>
  <section class="portal-medicamento">
    <h2><?= e($p['drug_name']) ?></h2>
    <p>Via: <?= e(Via::LABELS[$p['route']] ?? $p['route']) ?></p>
    <?php if ($cur !== null): ?>
      <p>Dose: <?= e($cur['dose']) ?></p>
      <p>Como usar: <?= e($cur['frequency']) ?></p>
      <?php if (($cur['instructions'] ?? '') !== ''): ?><p><?= e($cur['instructions']) ?></p><?php endif; ?>
    <?php endif; ?>
    <p><?= $p['status'] === 'futura' ? 'Começa em' : 'Desde' ?> <?= e(fmt_date((string) $p['start_date'])) ?></p>
    <?php if (!empty($p['next_review_date'])): ?><p>Próxima avaliação com a Dra. Rubia: <?= e(fmt_date((string) $p['next_review_date'])) ?></p><?php endif; ?>
    <?php if ($p['status'] === 'ativa' && (int) $p['family_logs'] === 1):
        $mine = (int) ($values['rx'] ?? 0) === $id; ?>
      <form method="post" action="<?= e(url($base . '/' . $id . '/dose')) ?>">
        <?= csrf_field() ?>
        <h3>Registrar dose</h3>
        <div class="campo"><label for="data-<?= $id ?>">Data</label>
          <input type="date" id="data-<?= $id ?>" name="data" value="<?= e($mine ? (string) $values['data'] : $nowDate) ?>" required></div>
        <div class="campo"><label for="hora-<?= $id ?>">Hora</label>
          <input type="time" id="hora-<?= $id ?>" name="hora" value="<?= e($mine ? (string) $values['hora'] : $nowTime) ?>" required></div>
        <div class="campo"><label for="obs-<?= $id ?>">Observação (opcional)</label>
          <input type="text" id="obs-<?= $id ?>" name="observacao" maxlength="200" value="<?= e($mine ? (string) $values['observacao'] : '') ?>" data-ditado></div>
        <button type="submit" class="btn">Registrar dose</button>
      </form>
    <?php endif; ?>
    <?php if ($p['logs'] !== []): ?>
      <h3>Doses dos últimos 7 dias</h3>
      <ul>
        <?php foreach ($p['logs'] as $l): ?>
          <li><?= e(fmt_date(substr((string) $l['given_at'], 0, 10))) ?> às <?= e(substr((string) $l['given_at'], 11, 5)) ?>
            — <?= e($l['dose']) ?> (<?= e($l['recorder_name']) ?>)<?= ($l['note'] ?? '') !== '' ? ' — ' . e($l['note']) : '' ?>
            <?php if ((int) $l['recorded_by'] === $userId): ?>
              <form method="post" action="<?= e(url($base . '/doses/' . (int) $l['id'] . '/remover')) ?>" data-confirm="Remover esta dose registrada?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-quiet">Remover</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<section class="portal-medicamento">
  <h2>Efeitos e intercorrências</h2>
  <?php if ($events === []): ?>
    <p class="ajuda">Nenhum relato até agora.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($events as $ev): ?>
        <li><?= e(fmt_date((string) $ev['occurred_on'])) ?><?= !empty($ev['drug_name']) ? ' — ' . e($ev['drug_name']) : '' ?>: <?= e($ev['description']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p><a class="btn" href="<?= e(url($base . '/relato')) ?>">Contar um efeito ou intercorrência</a></p>
</section>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
