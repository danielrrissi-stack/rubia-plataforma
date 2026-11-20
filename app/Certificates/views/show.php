<?php
/** @var array $patient @var array $cert @var list<string> $errors @var ?array $voider */
$pid = (int) $patient['id'];
$base = '/pacientes/' . $pid . '/atestados/' . $cert['id'];
$voided = $cert['voided_at'] !== null;
?>
<p><a href="<?= e(url('/pacientes/' . $pid . '/atestados')) ?>">← Atestados de <?= e($patient['social_name'] ?: $patient['full_name']) ?></a></p>
<h1><?= e(\App\Certificates\CertificateType::TITLES[$cert['type']]) ?></h1>
<p>Emitido em <?= e(fmt_date($cert['issued_on'])) ?></p>
<?php if ($voided): ?>
  <p class="aviso aviso-erro" role="status"><strong>Anulado</strong> em <?= e(fmt_date(substr((string) $cert['voided_at'], 0, 10))) ?><?= $voider !== null ? ' por ' . e($voider['name']) : '' ?>. Motivo: <?= e($cert['void_reason']) ?></p>
<?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<p><a class="btn" href="<?= e(url($base . '/pdf')) ?>">Baixar PDF</a></p>
<section class="cartao atestado-previa" aria-label="Prévia do documento">
  <?= $cert['body_html'] /* gravado ja escapado por CertificateText */ ?>
</section>
<?php if (!$voided): ?>
  <form method="post" action="<?= e(url($base . '/anular')) ?>" class="cartao">
    <h2>Anular este atestado</h2>
    <?= csrf_field() ?>
    <p class="ajuda">Use se foi emitido por engano. Ele continua na lista, marcado como anulado.</p>
    <div class="campo"><label for="motivo">Motivo</label>
      <input type="text" id="motivo" name="motivo" maxlength="200" required></div>
    <button type="submit" class="btn btn-quiet">Anular</button>
  </form>
<?php endif; ?>
