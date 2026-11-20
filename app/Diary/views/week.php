<?php
/**
 * @var array $patient @var list<array> $current @var list<array> $ended @var string $today @var bool $canEdit
 * @var array $grid @var string $monday @var string $prev @var string $next @var array $byDay @var array $plans @var int $userId @var array<int,bool> $hasEntries
 */
use App\Diary\DiaryType;

$pid = (int) $patient['id'];
$dayLabel = static function (string $ymd): string {
    $d = new \DateTimeImmutable($ymd);
    return \App\Diary\DiaryType::WEEKDAY_LABELS[(int) $d->format('N')] . ' ' . $d->format('d/m');
};
$entryHtml = static function (array $e) use ($pid): string {
    ob_start();
    $type = (string) $e['type'];
    $head = \App\Diary\DiaryType::LABELS[$type] ?? $type; ?>
    <article class="diario-registro">
      <h4><?= e($head) ?><?php if ($type === 'alimentacao' && $e['meal'] !== null): ?> — <?= e(\App\Diary\DiaryType::MEAL_LABELS[$e['meal']] ?? $e['meal']) ?><?= $e['meal_time'] !== null ? ' ' . e(substr((string) $e['meal_time'], 0, 5)) : '' ?><?php endif; ?></h4>
      <?php if ($type === 'alimentacao'): ?>
        <?php if ($e['description'] !== null && $e['description'] !== ''): ?><p><?= e($e['description']) ?></p><?php endif; ?>
        <?php if ($e['photo_path'] !== null && $e['photo_path'] !== ''): ?>
          <p><a href="<?= e(url('/diario/registros/' . (int) $e['id'] . '/foto')) ?>"><img class="diario-foto" src="<?= e(url('/diario/registros/' . (int) $e['id'] . '/foto')) ?>" alt="Foto da refeição" loading="lazy"></a></p>
        <?php endif; ?>
      <?php elseif ($type === 'atividade'): ?>
        <p><?= e((string) $e['activity']) ?><?= $e['minutes'] !== null ? ' — ' . e(\App\Diary\DiaryFormat::minutes((int) $e['minutes'])) : '' ?></p>
      <?php else: ?>
        <p><?= e(\App\Diary\DiaryFormat::cell($type, [$e], false) ?? '') ?></p>
      <?php endif; ?>
      <p class="ajuda">Anotado por <?= e($e['recorder_name']) ?> (<?= e(['responsavel' => 'responsável', 'paciente' => 'paciente', 'medico' => 'médica', 'nutricionista' => 'nutricionista'][$e['recorded_by_role']] ?? $e['recorded_by_role']) ?>)</p>
      <?php foreach ($e['comments'] as $c): ?>
        <div class="diario-comentario">
          <p><strong><?= e($c['author_name']) ?></strong> <span class="ajuda"><?= e(fmt_date((string) $c['created_at'])) ?></span><br><?= e($c['body']) ?></p>
          <?php if ((int) $c['created_by'] === (int) $e['viewer_id'] && $e['may_comment']): ?>
            <details><summary>Editar comentário</summary>
              <form method="post" action="<?= e(url('/diario/comentarios/' . (int) $c['id'] . '/editar')) ?>"><?= csrf_field() ?>
                <textarea name="body" rows="2" maxlength="500" required aria-label="Comentário"><?= e($c['body']) ?></textarea>
                <button type="submit" class="btn btn-quiet">Salvar</button>
              </form>
            </details>
            <form method="post" action="<?= e(url('/diario/comentarios/' . (int) $c['id'] . '/excluir')) ?>" class="form-inline" data-confirm="Excluir este comentário?"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($e['may_comment']): ?>
        <form method="post" action="<?= e(url('/diario/registros/' . (int) $e['id'] . '/comentar')) ?>"><?= csrf_field() ?>
          <label>Comentar <textarea name="body" rows="2" maxlength="500" required></textarea></label>
          <button type="submit" class="btn btn-quiet">Comentar</button>
        </form>
      <?php endif; ?>
    </article>
    <?php
    return (string) ob_get_clean();
};
$name = $patient['social_name'] ?: $patient['full_name'];
$row = static function (array $p) use ($today, $canEdit, $pid, $hasEntries): string {
    ob_start(); ?>
    <li>
      <strong><?= e(DiaryType::LABELS[$p['type']] ?? $p['type']) ?></strong>
      — <?= e(DiaryType::weekdaysLabel((int) $p['weekdays'])) ?>,
      <?= (string) $p['start_date'] > $today ? 'a partir de ' : 'desde ' ?><?= e(fmt_date($p['start_date'])) ?><?= $p['end_date'] !== null ? ' até ' . e(fmt_date($p['end_date'])) : '' ?>
      <?php if ((int) $p['secret'] === 1): ?><span class="selo selo-alerta">sigiloso</span><?php endif; ?>
      <?php if ($p['guidance'] !== null && $p['guidance'] !== ''): ?><br><span class="ajuda"><?= e($p['guidance']) ?></span><?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn btn-quiet" href="<?= e(url('/diario/pedidos/' . (int) $p['id'] . '/editar')) ?>">Editar</a>
        <?php if ((string) $p['start_date'] <= $today && ($p['end_date'] === null || (string) $p['end_date'] > $today)): ?>
          <form method="post" action="<?= e(url('/diario/pedidos/' . (int) $p['id'] . '/encerrar')) ?>" class="form-inline" data-confirm="Encerrar este pedido? Os registros já feitos continuam."><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Encerrar</button></form>
        <?php endif; ?>
        <?php if (empty($hasEntries[(int) $p['id']])): ?>
        <form method="post" action="<?= e(url('/diario/pedidos/' . (int) $p['id'] . '/excluir')) ?>" class="form-inline" data-confirm="Excluir este pedido?"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Excluir</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </li>
    <?php
    return (string) ob_get_clean();
};
?>
<h1>Diário — <?= e($name) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar à ficha</a>
<?php if ($canEdit): ?> · <a class="btn" href="<?= e(url('/pacientes/' . $pid . '/diario/pedidos/novo')) ?>">Novo pedido</a><?php endif; ?></p>

<section class="cartao" aria-labelledby="h-semana">
  <h2 id="h-semana">Semana de <?= e(date('d/m', strtotime($monday))) ?> a <?= e(date('d/m', strtotime($grid['days'][6]))) ?></h2>
  <p>
    <a href="<?= e(url('/pacientes/' . $pid . '/diario?semana=' . $prev)) ?>">Semana anterior</a> ·
    <a href="<?= e(url('/pacientes/' . $pid . '/diario?semana=' . $next)) ?>">Próxima semana</a>
  </p>
  <?php if ($grid['rows'] === []): ?>
    <p class="ajuda">Nenhum pedido de registro nesta semana.</p>
  <?php else: ?>
    <div class="tabela-rolagem diario-grade">
      <table>
        <thead><tr><th scope="col">Pedido</th><?php foreach ($grid['days'] as $d): ?><th scope="col"><?= e($dayLabel($d)) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($grid['rows'] as $gr): ?>
          <tr>
            <th scope="row"><?= e(\App\Diary\DiaryType::LABELS[$gr['request']['type']] ?? $gr['request']['type']) ?><?php if ((int) $gr['request']['secret'] === 1): ?> <span class="selo selo-alerta">sigiloso</span><?php endif; ?></th>
            <?php foreach ($grid['days'] as $d): $cell = $gr['cells'][$d]; ?>
              <td><?= $cell['label'] !== null ? e($cell['label']) : ($cell['asked'] ? '—' : '') ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php foreach ($grid['days'] as $d): if (!isset($byDay[$d])) { continue; } ?>
  <?php $hasFood = array_filter($byDay[$d], fn (array $e): bool => $e['type'] === 'alimentacao') !== []; ?>
  <section class="cartao" id="dia-<?= e($d) ?>" aria-labelledby="h-<?= e($d) ?>">
    <h3 id="h-<?= e($d) ?>"><?= e($dayLabel($d)) ?></h3>
    <div class="<?= $hasFood ? 'diario-duplo' : '' ?>">
      <div>
        <?php if ($hasFood): ?><h4>Anotado pela família</h4><?php endif; ?>
        <?php foreach ($byDay[$d] as $e) { echo $entryHtml($e + ['viewer_id' => $userId]); } ?>
      </div>
      <?php if ($hasFood): ?>
        <div>
          <h4>Plano alimentar</h4>
          <?php $plan = $plans[$d] ?? null; ?>
          <?php if ($plan === null): ?>
            <p class="ajuda">Sem plano alimentar publicado neste dia.</p>
          <?php else: ?>
            <p class="ajuda"><?= e($plan['title']) ?></p>
            <?php $family = false; require dirname(__DIR__, 2) . '/Nutrition/views/_plan_body.php'; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>

<section class="cartao" aria-labelledby="h-pedidos">
  <h2 id="h-pedidos">Pedidos de registro</h2>
  <?php if ($current === []): ?>
    <p class="ajuda">Nenhum pedido de registro.</p>
  <?php else: ?>
    <ul><?php foreach ($current as $p) { echo $row($p); } ?></ul>
  <?php endif; ?>
</section>
<?php if ($ended !== []): ?>
  <details class="cartao">
    <summary>Pedidos encerrados (<?= count($ended) ?>)</summary>
    <ul><?php foreach ($ended as $p) { echo $row($p); } ?></ul>
  </details>
<?php endif; ?>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
