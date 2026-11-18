<?php
/** @var array $patient @var string $today @var list<array> $current @var list<array> $ended @var list<array> $events */
use App\Medications\Via;

$pid = (int) $patient['id'];
$name = $patient['social_name'] ?: $patient['full_name'];
$row = static function (array $p): void {
    $c = $p['current'];
    ?>
    <tr>
      <th scope="row"><a href="<?= e(url('/prescricoes/' . (int) $p['id'])) ?>"><?= e($p['drug_name']) ?></a>
        <?php if ((int) $p['secret'] === 1): ?><span class="selo selo-alerta">sigilosa</span><?php endif; ?>
        <?php if ($p['status'] === 'futura'): ?><span class="selo">futura</span><?php endif; ?></th>
      <td><?= e(Via::LABELS[$p['route']] ?? $p['route']) ?></td>
      <td><?= $c === null ? '—' : e($c['dose']) . ' · ' . e($c['frequency']) ?></td>
      <td>desde <?= e(fmt_date($p['start_date'])) ?><?= $p['end_date'] !== null ? ' até ' . e(fmt_date($p['end_date'])) : '' ?></td>
      <td><?= $p['next_review_date'] !== null ? e(fmt_date($p['next_review_date'])) : '—' ?></td>
    </tr>
    <?php
};
$head = '<thead><tr><th scope="col">Medicamento</th><th scope="col">Via</th><th scope="col">Dose atual</th><th scope="col">Período</th><th scope="col">Próxima avaliação</th></tr></thead>';
?>
<h1>Tratamento — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar à ficha</a></p>
<p><a class="btn" href="<?= e(url('/pacientes/' . $pid . '/tratamento/nova')) ?>">Nova prescrição</a>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/efeitos/novo')) ?>">Registrar efeito</a>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/receita')) ?>">Receita</a></p>

<section class="cartao" aria-labelledby="h-ativas">
  <h2 id="h-ativas">Ativas e futuras</h2>
  <?php if ($current === []): ?>
    <p class="ajuda">Nenhuma prescrição ativa.</p>
  <?php else: ?>
    <div class="tabela-rolagem"><table><?= $head ?><tbody><?php foreach ($current as $p) { $row($p); } ?></tbody></table></div>
  <?php endif; ?>
</section>

<?php if ($ended !== []): ?>
<details class="cartao">
  <summary>Encerradas (<?= e(count($ended)) ?>)</summary>
  <div class="tabela-rolagem"><table><?= $head ?><tbody><?php foreach ($ended as $p) { $row($p); } ?></tbody></table></div>
</details>
<?php endif; ?>

<section class="cartao" aria-labelledby="h-efeitos">
  <h2 id="h-efeitos">Relatos de efeitos</h2>
  <?php if ($events === []): ?>
    <p class="ajuda">Nenhum relato de efeito registrado.</p>
  <?php else: ?>
    <ul>
    <?php foreach ($events as $ev): ?>
      <li><strong><?= e(fmt_date($ev['occurred_on'])) ?></strong>
        <?= $ev['drug_name'] !== null ? '· ' . e($ev['drug_name']) : '' ?>
        <?php if ($ev['source'] === 'familia' && $ev['reviewed_by'] === null): ?><span class="selo selo-alerta">para ver</span><?php endif; ?>
        <?php if ($ev['severity'] !== null): ?><span class="selo"><?= e($ev['severity']) ?></span><?php endif; ?>
        <br><?= nl2br(e($ev['description'])) ?>
        <br><span class="ajuda">Relatado por <?= e($ev['reporter_name']) ?><?= $ev['source'] === 'familia' ? ' (família)' : ' (equipe)' ?></span>
        <?php if ($ev['conduct'] !== null): ?><br>Conduta: <?= nl2br(e($ev['conduct'])) ?><?php endif; ?>
        <?php if ($ev['source'] === 'familia' && $ev['reviewed_by'] === null): ?>
          <form method="post" action="<?= e(url('/efeitos/' . (int) $ev['id'] . '/ciente')) ?>">
            <?= csrf_field() ?>
            <div class="campo"><label for="sev-<?= e($ev['id']) ?>">Gravidade (opcional)</label>
              <select id="sev-<?= e($ev['id']) ?>" name="severity"><option value="">—</option><?php foreach (['leve', 'moderada', 'grave'] as $sv): ?><option value="<?= e($sv) ?>"><?= e($sv) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="con-<?= e($ev['id']) ?>">Conduta (opcional)</label>
              <textarea id="con-<?= e($ev['id']) ?>" name="conduct" rows="2" maxlength="2000"></textarea></div>
            <button type="submit" class="btn btn-quiet">Ciente</button>
          </form>
        <?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
