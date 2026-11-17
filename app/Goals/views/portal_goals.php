<?php
/**
 * @var int $pid @var string $greeting @var bool $isChild @var int $stars @var list<string> $badges
 * @var list<array> $rows @var string $week @var string $weekLabel @var ?string $prevWeek @var ?string $nextWeek
 * @var list<array> $cards @var bool $many
 */
$base = '/portal/p/' . $pid . '/metas';
?>
<h1><?= e($greeting) ?></h1>
<p class="portal-lead metas-estrelas">Você já tem <strong data-stars><?= e($stars) ?></strong> <span aria-hidden="true">⭐</span> <?= $stars === 1 ? 'estrela' : 'estrelas' ?></p>

<nav class="metas-semana" aria-label="Semanas">
  <?php if ($prevWeek !== null): ?><a class="btn btn-quiet" href="<?= e(url($base . '?semana=' . $prevWeek)) ?>">&larr; Semana anterior</a><?php endif; ?>
  <strong>Semana de <?= e($weekLabel) ?></strong>
  <?php if ($nextWeek !== null): ?><a class="btn btn-quiet" href="<?= e(url($base . '?semana=' . $nextWeek)) ?>">Semana seguinte &rarr;</a><?php endif; ?>
</nav>

<?php if ($rows === []): ?>
  <p class="aviso">Ainda não há metas para esta semana.</p>
<?php else: ?>
<ul class="portal-lista metas-lista">
  <?php foreach ($rows as $row): ?>
  <li class="metas-meta">
    <p class="metas-texto"><span aria-hidden="true"><?= e($row['emoji']) ?></span> <?= e($row['text']) ?>
      <?php if ($row['count'] !== null): ?><span class="selo" data-goal-count="<?= e($row['id']) ?>"><?= e($row['count']) ?></span><?php endif; ?></p>
    <div class="metas-dias">
      <?php foreach ($row['days'] as $d): ?>
        <?php $aria = 'Marcar ' . $row['text'] . ' em ' . $d['label']; ?>
        <?php if ($d['enabled']): ?>
        <form method="post" class="metas-form" data-goal-check data-goal="<?= e($row['id']) ?>"
              action="<?= e(url($base . '/' . $row['id'] . ($d['checked'] ? '/desmarcar' : '/marcar'))) ?>"
              data-base="<?= e(url($base . '/' . $row['id'])) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="dia" value="<?= e($d['ymd']) ?>">
          <input type="hidden" name="semana" value="<?= e($week) ?>">
          <button type="submit" class="metas-dia<?= $d['checked'] ? ' metas-dia-feito' : '' ?>" aria-pressed="<?= $d['checked'] ? 'true' : 'false' ?>" aria-label="<?= e($aria) ?>">
            <span class="metas-dia-nome"><?= e($d['short']) ?></span><span class="metas-dia-marca" aria-hidden="true"><?= $d['checked'] ? '✓' : '' ?></span>
          </button>
        </form>
        <?php else: ?>
        <span class="metas-form">
          <button type="button" class="metas-dia<?= $d['checked'] ? ' metas-dia-feito' : '' ?>" disabled aria-pressed="<?= $d['checked'] ? 'true' : 'false' ?>" aria-label="<?= e($aria) ?>">
            <span class="metas-dia-nome"><?= e($d['short']) ?></span><span class="metas-dia-marca" aria-hidden="true"><?= $d['checked'] ? '✓' : '' ?></span>
          </button>
        </span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<p class="aviso aviso-erro metas-erro" role="alert" hidden data-goal-error></p>

<?php if ($badges !== []): ?>
<section class="portal-resumo">
  <h2>Conquistas</h2>
  <p><?php foreach ($badges as $b): ?><span class="selo metas-selo">🏅 <?= e($b) ?></span> <?php endforeach; ?></p>
</section>
<?php endif; ?>

<?php if ($cards !== []): ?>
<section class="portal-resumo" id="para-ler">
  <h2>Para você ler</h2>
  <?php foreach ($cards as $c): ?><p><?= e($c['emoji'] ?? '') ?> <?= e($c['title'] ?? '') ?></p><?php endforeach; ?>
</section>
<?php endif; ?>

<p class="portal-links">
  <?php if ($many): ?><a href="<?= e(url('/portal')) ?>">Trocar de pessoa</a> · <?php endif; ?>
  <?php if (!$isChild): ?><a href="<?= e(url('/portal/p/' . $pid)) ?>">Início</a> · <?php endif; ?>
  <a href="<?= e(url('/portal/conta')) ?>">Minha conta</a>
</p>
<script src="<?= e(url('/assets/js/goal-check.js')) ?>"></script>
