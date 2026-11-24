<?php
/** @var array $patient @var ?string $type @var array<string,string> $values @var list<string> $errors */
use App\Certificates\CertificateType;

$pid = (int) $patient['id'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<p><a href="<?= e(url('/pacientes/' . $pid . '/atestados')) ?>">← Atestados de <?= e($patient['social_name'] ?: $patient['full_name']) ?></a></p>
<h1>Novo atestado</h1>
<nav class="atestado-tipos" aria-label="Tipo de atestado">
  <?php foreach (CertificateType::LABELS as $key => $label): ?>
    <a class="btn<?= $type === $key ? '' : ' btn-quiet' ?>"<?= $type === $key ? ' aria-current="page"' : '' ?> href="<?= e(url('/pacientes/' . $pid . '/atestados/novo?tipo=' . $key)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($type === null): ?>
  <p class="ajuda">Escolha o tipo de documento.</p>
<?php else: ?>
  <?php if ($errors !== []): ?>
    <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/pacientes/' . $pid . '/atestados')) ?>" class="cartao">
    <h2><?= e(CertificateType::TITLES[$type]) ?></h2>
    <?= csrf_field() ?>
    <input type="hidden" name="tipo" value="<?= e($type) ?>">
    <?php if ($type === 'acompanhante'): ?>
      <div class="campo"><label for="acompanhante_nome">Nome do acompanhante</label>
        <input type="text" id="acompanhante_nome" name="acompanhante_nome" value="<?= e($v('acompanhante_nome')) ?>" maxlength="150" required></div>
      <div class="campo"><label for="acompanhante_cpf">CPF do acompanhante (opcional)</label>
        <input type="text" id="acompanhante_cpf" name="acompanhante_cpf" value="<?= e($v('acompanhante_cpf')) ?>" maxlength="14" inputmode="numeric"></div>
    <?php endif; ?>
    <div class="campo"><label for="data"><?= $type === 'aptidao' ? 'Data do exame' : 'Data do atendimento' ?></label>
      <input type="date" id="data" name="data" value="<?= e($v('data')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
    <?php if (in_array($type, CertificateType::WITH_PERIOD, true)): ?>
      <div class="campo"><label for="inicio">Das</label><input type="time" id="inicio" name="inicio" value="<?= e($v('inicio')) ?>" required></div>
      <div class="campo"><label for="fim">Às</label><input type="time" id="fim" name="fim" value="<?= e($v('fim')) ?>" required></div>
    <?php endif; ?>
    <?php if ($type === 'afastamento'): ?>
      <div class="campo"><label for="dias">Dias de afastamento</label>
        <input type="number" id="dias" name="dias" value="<?= e($v('dias')) ?>" min="1" max="90" required></div>
      <div class="campo"><label for="a_partir">A partir de</label>
        <input type="date" id="a_partir" name="a_partir" value="<?= e($v('a_partir')) ?>">
        <p class="ajuda">Em branco: a partir da data do atendimento.</p></div>
    <?php endif; ?>
    <?php if (in_array($type, CertificateType::WITH_CID, true)): ?>
      <div class="campo"><label for="cid">CID-10 (opcional)</label>
        <input type="text" id="cid" name="cid" value="<?= e($v('cid')) ?>" maxlength="8" placeholder="ex.: E66.9"></div>
      <div class="campo"><label><input type="checkbox" name="cid_autorizado" value="1"<?= $v('cid_autorizado') !== '' ? ' checked' : '' ?>>
        O paciente ou responsável autorizou incluir o CID</label>
        <p class="ajuda">Sem esta autorização o CID não pode sair no documento.</p></div>
    <?php endif; ?>
    <div class="campo"><label for="observacao">Observação (opcional)</label>
      <textarea id="observacao" name="observacao" rows="3" maxlength="500"><?= e($v('observacao')) ?></textarea></div>
    <button type="submit" class="btn">Emitir</button>
  </form>
<?php endif; ?>
