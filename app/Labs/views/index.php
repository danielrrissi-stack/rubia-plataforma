<?php
/** @var array $patient @var array $evolution @var list<array> $collections @var bool $all */
use App\Labs\LabClassifier;

$pid = (int) $patient['id'];
$cols = $evolution['collections'];
?>
<h1>Exames — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar à ficha</a></p>
<p><a class="btn" href="<?= e(url('/pacientes/' . $pid . '/exames/nova')) ?>">Nova coleta</a></p>

<section class="cartao" aria-labelledby="h-evolucao">
  <h2 id="h-evolucao">Evolução</h2>
  <?php if ($evolution['rows'] === []): ?>
    <p class="ajuda">Nenhum resultado registrado.</p>
  <?php else: ?>
    <p class="ajuda">▲ alterado (alto) · ▼ alterado (baixo) · ◆ limítrofe. Coletas mais recentes à esquerda.</p>
    <div class="tabela-rolagem">
      <table class="lab-evolucao">
        <thead><tr>
          <th scope="col">Exame</th>
          <?php foreach ($cols as $c): ?>
            <th scope="col"><a href="<?= e(url('/exames/' . $c['id'])) ?>"><?= e(fmt_date($c['collected_on'])) ?></a><?php if ($c['released']): ?> <span class="selo selo-familia">liberada</span><?php endif; ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($evolution['rows'] as $row): ?>
            <tr>
              <th scope="row"><?= e($row['test']['name']) ?> <span class="ajuda"><?= e($row['test']['unit']) ?></span></th>
              <?php foreach ($cols as $c): $cell = $row['cells'][$c['id']] ?? null; ?>
                <?php if ($cell === null): ?><td class="lab-vazio">—</td>
                <?php else: $cls = $cell['class']; ?>
                  <td class="lab-celula<?= $cls !== null && $cls !== 'normal' ? ' lab-' . e($cls) : '' ?>"<?= $cell['no_range'] ? ' title="sem faixa para esta idade"' : '' ?>><span class="lab-valor"><?= e($cell['value']) ?></span><?php if ($cls !== null && LabClassifier::MARK[$cls] !== ''): ?> <span class="lab-marca" aria-hidden="true"><?= e(LabClassifier::MARK[$cls]) ?></span><?php endif; ?><?php if ($cls !== null): ?><span class="sr-only"><?= e(LabClassifier::LABEL[$cls]) ?></span><?php elseif ($cell['no_range']): ?><span class="sr-only">sem faixa para esta idade</span><?php endif; ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if ($evolution['homa'] !== null): ?>
            <tr>
              <th scope="row">HOMA-IR (calculado)</th>
              <?php foreach ($cols as $c): $h = $evolution['homa']['cells'][$c['id']] ?? null; ?>
                <td class="lab-celula"><?= $h === null ? '—' : '<span class="lab-valor">' . e($h['value']) . '</span>' ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$all && $evolution['total'] > count($cols)): ?>
      <p><a href="<?= e(url('/pacientes/' . $pid . '/exames?todas=1')) ?>">Ver todas as <?= e($evolution['total']) ?> coletas</a></p>
    <?php elseif ($all && $evolution['total'] > 6): ?>
      <p><a href="<?= e(url('/pacientes/' . $pid . '/exames')) ?>">Mostrar só as 6 mais recentes</a></p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="cartao" aria-labelledby="h-coletas">
  <h2 id="h-coletas">Coletas</h2>
  <?php if ($collections === []): ?>
    <p class="ajuda">Nenhuma coleta registrada.</p>
  <?php else: ?>
    <ul class="lab-coletas">
      <?php foreach ($collections as $c): $n = count($c['results']); $a = (int) $c['attachment_count']; ?>
        <li><a href="<?= e(url('/exames/' . $c['id'])) ?>"><?= e(fmt_date($c['collected_on'])) ?></a>
          <?php if ($c['lab_name'] !== null): ?> · <?= e($c['lab_name']) ?><?php endif; ?>
          · <?= e($n) ?> resultado<?= $n === 1 ? '' : 's' ?>
          <?php if ($a > 0): ?> · <?= e($a) ?> laudo<?= $a === 1 ? '' : 's' ?><?php endif; ?>
          · <?php if ($c['released_at'] !== null): ?><span class="selo selo-familia">liberada para a família</span><?php else: ?><span class="selo">não liberada</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
