<?php
/**
 * @var array $patient @var string $first @var int $userId @var list<array> $items @var string $day @var string $today
 * @var list<string> $links @var list<string> $upcoming @var bool $future @var bool $measured
 */
use App\Diary\DiaryFormat;
use App\Diary\DiaryType;

$pid = (int) $patient['id'];
$base = '/portal/p/' . $pid . '/diario';
$short = static function (string $ymd): string {
    $d = new \DateTimeImmutable($ymd);
    return DiaryType::WEEKDAY_LABELS[(int) $d->format('N')] . ' ' . $d->format('d/m');
};
$linkLabel = static function (string $ymd) use ($today, $short): string {
    if ($ymd === $today) {
        return 'hoje';
    }
    if ($ymd === date('Y-m-d', strtotime($today . ' +1 day'))) {
        return 'amanhã';
    }
    return $ymd === date('Y-m-d', strtotime($today . ' -1 day')) ? 'ontem' : $short($ymd);
};
$upcoming = $upcoming ?? [];
$future = $future ?? false;
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>O que a Dra. Rubia pediu para anotar</h1>
<nav aria-label="Escolher o dia">
  <ul class="portal-dias">
    <?php foreach ($links as $d): ?>
      <li><?php if ($d === $day): ?><strong aria-current="date"><?= e($linkLabel($d)) ?></strong><?php else: ?><a href="<?= e(url($base . '?dia=' . $d)) ?>"><?= e($linkLabel($d)) ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php if ($upcoming !== []): ?>
<nav aria-label="Próximos dias">
  <p class="ajuda">Próximos dias</p>
  <ul class="portal-dias">
    <?php foreach ($upcoming as $d): ?>
      <li><?php if ($d === $day): ?><strong aria-current="date"><?= e($linkLabel($d)) ?></strong><?php else: ?><a href="<?= e(url($base . '?dia=' . $d)) ?>"><?= e($linkLabel($d)) ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php endif; ?>
<h2><?= e($short($day)) ?></h2>
<?php if ($items === []): ?>
  <p class="aviso">Nada para anotar neste dia.</p>
<?php elseif ($future): ?>
  <p class="aviso">Dá para anotar a partir do dia <?= e((new \DateTimeImmutable($day))->format('d/m')) ?>.</p>
<?php endif; ?>
<?php foreach ($items as $item):
    $req = $item['request'];
    $type = (string) $req['type'];
    $entries = $item['entries'];
    $once = in_array($type, DiaryType::ONE_PER_DAY, true); ?>
  <section class="portal-medicamento">
    <h3><?= e(DiaryType::LABELS[$type] ?? $type) ?></h3>
    <?php if (($req['guidance'] ?? '') !== ''): ?><p class="ajuda"><?= e($req['guidance']) ?></p><?php endif; ?>
    <?php if ($future): ?>
    <?php elseif ($type === 'medidas'): ?>
      <?php if ($measured): ?>
        <p>Peso anotado ✔</p>
      <?php else: ?>
        <p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/medidas/nova')) ?>"><?= $day === $today ? 'Pesar hoje' : 'Anotar peso' ?></a></p>
      <?php endif; ?>
    <?php else: ?>
      <?php foreach ($entries as $e): ?>
        <article class="diario-registro">
          <?php if ($type === 'alimentacao'): ?>
            <p><strong><?= e(DiaryType::MEAL_LABELS[$e['meal']] ?? (string) $e['meal']) ?></strong><?= $e['meal_time'] !== null ? ' ' . e(substr((string) $e['meal_time'], 0, 5)) : '' ?></p>
            <?php if (($e['description'] ?? '') !== ''): ?><p><?= e($e['description']) ?></p><?php endif; ?>
            <?php if (($e['photo_path'] ?? '') !== ''): ?>
              <p><img class="diario-foto" src="<?= e(url($base . '/fotos/' . (int) $e['id'])) ?>" alt="Foto da refeição" loading="lazy"></p>
            <?php endif; ?>
          <?php elseif ($type === 'atividade'): ?>
            <p><?= e((string) $e['activity']) ?><?= $e['minutes'] !== null ? ' — ' . e(DiaryFormat::minutes((int) $e['minutes'])) : '' ?></p>
          <?php else: ?>
            <p><?= e(DiaryFormat::cell($type, [$e], false) ?? '') ?></p>
          <?php endif; ?>
          <p class="ajuda">Anotado por <?= e($e['recorder_name']) ?></p>
          <?php foreach ($e['comments'] as $c): ?>
            <div class="diario-comentario">
              <p>Comentário de <?= e($c['author_name']) ?> em <?= e(fmt_date((string) $c['created_at'])) ?>: <?= e($c['body']) ?></p>
            </div>
          <?php endforeach; ?>
          <?php if ($e['may_change'] && !$once): ?>
            <p><a class="btn btn-quiet" href="<?= e(url($base . '/registros/' . (int) $e['id'] . '/editar')) ?>">Corrigir</a></p>
            <form method="post" action="<?= e(url($base . '/registros/' . (int) $e['id'] . '/remover')) ?>" data-confirm="Remover este registro?">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-quiet">Remover</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php
      $anotar = url($base . '/' . (int) $req['id'] . '/anotar?dia=' . $day);
      if ($once): ?>
        <p><a class="btn" href="<?= e($anotar) ?>"><?= $entries === [] ? 'Anotar' : 'Corrigir' ?></a></p>
        <?php if ($entries !== [] && $entries[0]['may_change']): ?>
          <form method="post" action="<?= e(url($base . '/registros/' . (int) $entries[0]['id'] . '/remover')) ?>" data-confirm="Remover este registro?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-quiet">Remover</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p><a class="btn" href="<?= e($anotar) ?>"><?= $type === 'alimentacao' ? 'Anotar refeição' : 'Anotar atividade' ?></a></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
