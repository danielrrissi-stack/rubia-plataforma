<?php
/** @var array $patient @var list<array> $items */
use App\Certificates\CertificateType;

$pid = (int) $patient['id'];
?>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">← <?= e($patient['social_name'] ?: $patient['full_name']) ?></a></p>
<h1>Atestados e declarações</h1>
<p><a class="btn" href="<?= e(url('/pacientes/' . $pid . '/atestados/novo')) ?>">Novo atestado</a></p>
<?php if ($items === []): ?>
  <p class="ajuda">Nenhum atestado emitido.</p>
<?php else: ?>
  <table class="tabela">
    <thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">Situação</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr>
        <td><?= e(fmt_date($c['issued_on'])) ?></td>
        <td><?= e(CertificateType::TITLES[$c['type']]) ?></td>
        <td><?= $c['voided_at'] !== null ? '<span class="selo selo-alerta">anulado</span>' : 'válido' ?></td>
        <td><a href="<?= e(url('/pacientes/' . $pid . '/atestados/' . $c['id'])) ?>">Abrir</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
