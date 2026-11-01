<?php
/** @var array $pending @var string $today */
?>
<h1>Decisões de maioridade</h1>
<p class="ajuda">Pacientes que completaram 18 anos e ainda têm responsáveis com acesso. Escolha o que acontece com esse acesso.</p>
<?php if ($pending === []): ?>
  <p>Nenhuma decisão pendente.</p>
<?php endif; ?>
<?php foreach ($pending as $p): $pid = (int) $p['id']; ?>
<section class="cartao" aria-labelledby="h-p<?= $pid ?>">
  <h2 id="h-p<?= $pid ?>"><a href="<?= e(url('/pacientes/' . $pid)) ?>"><?= e($p['social_name'] ?: $p['full_name']) ?></a></h2>
  <p><?= e(\App\Growth\Age::label($p['birth_date'], $today)) ?> (nascimento <?= e(fmt_date($p['birth_date'])) ?>)</p>
  <form method="post" action="<?= e(url('/pacientes/' . $pid . '/maioridade')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="decisao<?= $pid ?>">Decisão</label>
      <select id="decisao<?= $pid ?>" name="decisao" required>
        <option value="">Selecione</option>
        <option value="encerrar">Encerrar acesso dos responsáveis</option>
        <option value="transferir">Transferir o acesso para o próprio paciente</option>
        <option value="manter">Manter o acesso dos responsáveis</option>
      </select>
    </div>
    <?php if ($p['user_id'] === null): ?>
    <div class="campo">
      <label for="email<?= $pid ?>">E-mail do paciente (obrigatório ao transferir)</label>
      <input type="email" id="email<?= $pid ?>" name="email" maxlength="190">
    </div>
    <?php endif; ?>
    <div class="campo">
      <label for="obs<?= $pid ?>">Observações</label>
      <textarea id="obs<?= $pid ?>" name="observacoes" rows="2"></textarea>
    </div>
    <button type="submit" class="btn">Registrar decisão</button>
  </form>
</section>
<?php endforeach; ?>
