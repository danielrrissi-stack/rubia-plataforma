<?php
/**
 * Marcar consulta: buscar paciente (GET ?q=), escolher (?paciente=), tipo e observacao (POST).
 * Variaveis: $prof, $professional, $start ('Y-m-d H:i'), $q, $results, $patient (?array), $refusal (?string),
 * $kind, $note, $errors.
 */
use App\Agenda\AgendaStatus;

$startDt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $start);
$when = $startDt !== false
    ? AgendaStatus::WEEKDAY_LONG[(int) $startDt->format('N')] . ', ' . $startDt->format('d/m/Y') . ' às ' . $startDt->format('H:i')
    : $start;
$base = ['prof' => $prof, 'inicio' => $start];
$bookUrl = static fn (array $q): string => url('/agenda/marcar') . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
?>
<h1>Marcar consulta</h1>
<p><strong><?= e($professional['name'] ?? '') ?></strong> — <?= e($when) ?></p>

<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($patient === null): ?>
  <form method="get" action="<?= e(url('/agenda/marcar')) ?>" class="agenda-busca">
    <input type="hidden" name="prof" value="<?= (int) $prof ?>">
    <input type="hidden" name="inicio" value="<?= e($start) ?>">
    <label for="agenda-q">Buscar paciente pelo nome</label>
    <input id="agenda-q" type="search" name="q" value="<?= e($q) ?>" maxlength="100" required>
    <button type="submit" class="btn">Buscar</button>
  </form>
  <?php if ($q !== ''): ?>
    <?php if ($results === []): ?>
      <p>Nenhum paciente encontrado.</p>
    <?php else: ?>
      <ul class="agenda-resultados">
        <?php foreach ($results as $p): ?>
          <li><?= e($p['social_name'] ?: $p['full_name']) ?><?= $p['social_name'] ? ' <span class="ajuda">(' . e($p['full_name']) . ')</span>' : '' ?>
            <span class="ajuda">nasc. <?= e(fmt_date((string) $p['birth_date'])) ?></span>
            <a class="btn btn-quiet" href="<?= e($bookUrl($base + ['paciente' => (int) $p['id']])) ?>">Escolher</a></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($results) >= 20): ?><p class="ajuda">Mostrando os 20 primeiros. Refine a busca.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
<?php else: ?>
  <p>Paciente: <strong><?= e($patient['social_name'] ?: $patient['full_name']) ?></strong>
    <span class="ajuda">nasc. <?= e(fmt_date((string) $patient['birth_date'])) ?></span>
    <a href="<?= e($bookUrl($base)) ?>">trocar</a></p>
  <?php if ($refusal !== null): ?>
    <p class="aviso aviso-erro" role="alert"><?= e($refusal) ?></p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/agenda/marcar')) ?>" class="agenda-form">
      <?= csrf_field() ?>
      <input type="hidden" name="prof" value="<?= (int) $prof ?>">
      <input type="hidden" name="inicio" value="<?= e($start) ?>">
      <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
      <div class="campo">
        <label for="agenda-kind">Tipo</label>
        <select id="agenda-kind" name="kind">
          <?php foreach (AgendaStatus::KIND_LABELS as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= $k === $kind ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="agenda-note">Observação (opcional)</label>
        <input id="agenda-note" type="text" name="note" value="<?= e($note) ?>" maxlength="255">
        <p class="ajuda">Só a equipe vê. Não escreva dados clínicos.</p>
      </div>
      <button type="submit" class="btn">Marcar consulta</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
<p><a href="<?= e(url('/agenda') . '?' . http_build_query(['prof' => $prof, 'data' => substr($start, 0, 10)], '', '&', PHP_QUERY_RFC3986)) ?>">Voltar à agenda</a></p>
