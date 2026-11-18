<?php
/**
 * @var array $patient @var string $today @var bool $canCadastro @var bool $canEdit @var bool $canCrescimento
 * @var bool $canProntuario @var array $evolutions @var bool $canPlano @var ?array $currentPlan @var bool $canExames @var ?array $labSummary @var bool $canEnergia @var bool $canAcessoProf @var bool $canChildAccount @var ?array $treatmentCard @var ?array $goalsCard @var bool $adult @var ?array $professional @var array $guardians @var ?array $growth
 */
$pid = (int) $patient['id'];
?>
<h1><?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p>
  <?php if ($patient['social_name']): ?>Nome civil: <?= e($patient['full_name']) ?> · <?php endif; ?>
  <?= e(\App\Growth\Age::label($patient['birth_date'], $today)) ?> (nascimento <?= e(fmt_date($patient['birth_date'])) ?>) ·
  <?= $patient['sex'] === 'F' ? 'Feminino' : 'Masculino' ?>
</p>
<?php if ($canEdit || $canAcessoProf || $canChildAccount): ?>
  <p><?php if ($canEdit): ?><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/editar')) ?>">Editar cadastro</a>
     <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/responsaveis')) ?>">Responsáveis</a><?php endif; ?>
     <?php if ($canAcessoProf): ?><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/acesso-profissionais')) ?>">Acesso de profissionais</a><?php endif; ?>
     <?php if ($canChildAccount): ?><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/acesso-crianca')) ?>">Conta da criança</a><?php endif; ?></p>
<?php endif; ?>

<?php if ($canCadastro): ?>
<section class="cartao" aria-labelledby="h-cadastro">
  <h2 id="h-cadastro">Cadastro</h2>
  <dl>
    <dt>Telefone</dt><dd><?= e($patient['phone']) ?></dd>
    <dt>E-mail</dt><dd><?= e($patient['email']) ?></dd>
    <dt>CPF</dt><dd><?= e($patient['cpf']) ?></dd>
    <dt>CNS</dt><dd><?= e($patient['cns']) ?></dd>
    <dt>Endereço</dt><dd><?= nl2br(e($patient['address'])) ?></dd>
    <dt>Profissional responsável</dt><dd><?= e($professional['name'] ?? '') ?></dd>
    <dt>Observações administrativas</dt><dd><?= nl2br(e($patient['admin_notes'])) ?></dd>
  </dl>
  <h3>Responsáveis</h3>
  <?php if ($guardians === []): ?><p>Nenhum responsável vinculado.</p><?php else: ?>
    <ul><?php foreach ($guardians as $g): ?>
      <li><?= e($g['user_name']) ?> (<?= e($g['relationship']) ?>) — <?= e($g['user_email']) ?></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($canEdit && $patient['user_id'] === null && $adult): ?>
    <h3>Acesso do paciente</h3>
    <form method="post" action="<?= e(url('/pacientes/' . $pid . '/acesso')) ?>">
      <?= csrf_field() ?>
      <div class="campo">
        <label for="acesso_email">E-mail para o acesso do paciente</label>
        <input type="email" id="acesso_email" name="email" required maxlength="190">
      </div>
      <button type="submit" class="btn">Dar acesso ao paciente</button>
    </form>
  <?php elseif ($patient['user_id'] !== null): ?>
    <p>O paciente possui acesso próprio.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* Area de cartoes: tarefas futuras (Crescimento, Prontuario, Energia) adicionam seus cartoes aqui, cada um sob Gate::check. */ ?>
<div class="cartoes" id="cartoes-paciente">
  <?php if ($canCrescimento): ?><section class="cartao" id="cartao-crescimento"><h2>Crescimento</h2>
    <?php if ($growth === null): ?><p class="ajuda">Nenhuma medida registrada.</p><?php else: ?>
      <p>Última medida: <?= e(fmt_date($growth['date'])) ?><?php if ($growth['bmi'] !== null): ?> · IMC <?= e(fmt_num($growth['bmi'], 1)) ?><?= $growth['status'] ? ' — ' . e($growth['status']) : '' ?><?php endif; ?></p>
    <?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/crescimento')) ?>">Ver crescimento</a></p></section><?php endif; ?>
  <?php if ($canProntuario): ?><section class="cartao" id="cartao-prontuario"><h2>Prontuário</h2>
    <?php if ($evolutions === []): ?><p class="ajuda">Nenhuma evolução registrada.</p><?php else: $last = $evolutions[0]; $dx = (string) (json_decode((string) $last['payload'], true)['diagnostico'] ?? ''); ?>
      <p><?= count($evolutions) ?> evolução(ões) · última em <?= e(fmt_date($last['encounter_date'])) ?><?= $dx !== '' ? ' — ' . e(mb_strimwidth($dx, 0, 120, '…')) : '' ?></p>
    <?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/prontuario')) ?>">Abrir prontuário</a></p></section><?php endif; ?>
  <?php if ($canEnergia): ?><section class="cartao" id="cartao-energia"><h2>Energia</h2>
    <?php if ($energy === null): ?><p class="ajuda">Nenhuma meta energética definida.</p><?php else: ?>
      <p><strong><?= e($energy['get_kcal']) ?> kcal/dia</strong> — <?= e(\App\Energy\EnergyCalculator::FORMULAS[$energy['formula']]) ?> · <?= e(fmt_date(substr((string) $energy['created_at'], 0, 10))) ?></p>
    <?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/energia')) ?>">Ver energia</a></p></section><?php endif; ?>
  <?php if ($canPlano): ?><section class="cartao" id="cartao-plano"><h2>Plano alimentar</h2>
    <?php if ($currentPlan === null): ?><p class="ajuda">Nenhum plano vigente.</p><?php else: ?>
      <p><strong><?= e($currentPlan['title']) ?></strong> · publicado em <?= e(fmt_date(substr((string) $currentPlan['published_at'], 0, 10))) ?></p>
    <?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/plano')) ?>">Ver plano alimentar</a></p></section><?php endif; ?>
  <?php if ($canExames): ?><section class="cartao" id="cartao-exames"><h2>Exames</h2>
    <?php if ($labSummary === null): ?><p class="ajuda">Nenhuma coleta registrada.</p><?php else: $n = $labSummary['altered']; ?>
      <p>Última coleta: <?= e(fmt_date($labSummary['date'])) ?> · <?= $n === 0 ? 'nenhum valor alterado' : e($n) . ($n === 1 ? ' valor alterado' : ' valores alterados') ?></p>
      <?php if ($labSummary['no_range'] > 0): ?><p class="ajuda"><?= e($labSummary['no_range']) ?> <?= $labSummary['no_range'] === 1 ? 'valor' : 'valores' ?> sem faixa para esta idade (não classificado<?= $labSummary['no_range'] === 1 ? '' : 's' ?>).</p><?php endif; ?>
    <?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/exames')) ?>">Ver exames</a></p></section><?php endif; ?>
  <?php if ($treatmentCard !== null): ?><section class="cartao" id="cartao-tratamento"><h2>Tratamento</h2>
    <?php if ($treatmentCard['items'] === []): ?><p class="ajuda">Nenhuma prescrição ativa.</p><?php else: ?>
      <ul><?php foreach ($treatmentCard['items'] as $it): ?><li><?= e($it['drug_name']) ?> — <?= e($it['current']['dose'] ?? '—') ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($treatmentCard['review'] !== null): ?><p>Próxima avaliação: <?= e(fmt_date($treatmentCard['review'])) ?></p><?php endif; ?>
    <?php if ($treatmentCard['pending'] > 0): ?><p><strong><?= e($treatmentCard['pending']) ?></strong> <?= $treatmentCard['pending'] === 1 ? 'relato' : 'relatos' ?> da família para ver</p><?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/tratamento')) ?>">Ver tratamento</a></p></section><?php endif; ?>
  <?php if ($goalsCard !== null): $gp = $goalsCard['panel']; $nw = count($gp['weeks']); ?><section class="cartao" id="cartao-metas"><h2>Metas</h2>
    <?php if ($gp['rows'] === []): ?><p class="ajuda">Nenhuma meta ativa.</p><?php else: ?>
      <table class="grade-metas">
        <thead><tr><th scope="col">Meta</th><?php foreach ($gp['weeks'] as $i => $ws): ?><th scope="col"><?= e(substr(fmt_date($ws), 0, 5)) ?><?= $i === $nw - 1 ? ' (atual)' : '' ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($gp['rows'] as $row): ?>
          <tr><th scope="row"><?= e($row['goal']['emoji']) ?> <?= e($row['goal']['family_text']) ?><?php if ((int) $row['goal']['secret'] === 1): ?> <span class="selo selo-alerta">sigilosa</span><?php endif; ?></th>
          <?php foreach ($row['cells'] as $c): ?><td<?= $c['complete'] ? ' class="meta-completa"' : '' ?>><?= $c['applicable'] ? e($c['done']) . '/' . e($c['target']) . ($c['complete'] ? ' ✓' : '') : '—' ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($goalsCard['stars'] !== null): ?><p>Estrelas: <strong><?= e($goalsCard['stars']) ?></strong><?= $goalsCard['lastCheck'] !== null ? ' · último registro da família em ' . e(fmt_date($goalsCard['lastCheck'])) : '' ?></p><?php endif; ?>
    <p><a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/metas')) ?>">Ver metas</a></p></section><?php endif; ?>
</div>
