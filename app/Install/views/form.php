<?php
/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var string $global */
/** @var list<array> $requirements */
/** @var string $storageWarning */
$blocked = !\App\Install\Requirements::passes($requirements);
$field = static function (string $name, string $label, string $type = 'text', string $help = '') use ($values, $errors): void {
    $val = $type === 'password' ? '' : ($values[$name] ?? '');
    $err = $errors[$name] ?? '';
    echo '<div class="campo"><label for="', e($name), '">', e($label), '</label>';
    echo '<input type="', e($type), '" id="', e($name), '" name="', e($name), '" value="', e($val), '" required',
        $type === 'password' ? ' autocomplete="new-password"' : '',
        $err !== '' ? ' aria-invalid="true" aria-describedby="' . e($name) . '-erro"' : '', '>';
    if ($help !== '') {
        echo '<p class="ajuda">', e($help), '</p>';
    }
    if ($err !== '') {
        echo '<p class="aviso aviso-erro" id="', e($name), '-erro" role="alert">', e($err), '</p>';
    }
    echo '</div>';
};
?>
<section class="instalador" style="max-width:40rem;margin:1.5rem auto">
  <h1>Instalação do sistema</h1>
  <?= \App\Core\View::render('Install/views/requirements.php', compact('requirements', 'storageWarning'), null) ?>

  <?php if ($global !== ''): ?><p class="aviso aviso-erro" role="alert"><?= e($global) ?></p><?php endif; ?>
  <?php if ($blocked): ?>
    <p class="aviso aviso-erro" role="alert">Há requisitos obrigatórios com falha. Corrija-os e recarregue esta página para instalar.</p>
  <?php else: ?>
  <form method="post" action="<?= e(url('/instalar')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <h2>Banco de dados</h2>
    <?php $field('db_host', 'Servidor do banco (host)'); ?>
    <?php $field('db_name', 'Nome do banco'); ?>
    <?php $field('db_user', 'Usuário do banco'); ?>
    <div class="campo">
      <label for="db_pass">Senha do banco</label>
      <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
    </div>

    <h2>Site e e-mail</h2>
    <?php $field('base_url', 'Endereço do site', 'text', 'Exemplo: https://rubia.med.br'); ?>
    <?php $field('mail_from', 'E-mail remetente dos avisos', 'email'); ?>

    <h2>Médica administradora</h2>
    <?php $field('admin_name', 'Nome completo'); ?>
    <?php $field('admin_email', 'E-mail (usado para entrar)', 'email'); ?>
    <?php $field('admin_crm', 'CRM', 'text', 'Exemplo: CRM-SP 123456'); ?>
    <?php $field('admin_password', 'Senha', 'password', 'Mínimo de 10 caracteres.'); ?>
    <?php $field('admin_password2', 'Confirme a senha', 'password'); ?>

    <h2>Senha de backup</h2>
    <p class="aviso aviso-erro" role="note"><strong>Guarde esta senha fora do sistema. Sem ela não é possível restaurar backups.</strong></p>
    <?php $field('backup_pass', 'Senha de backup', 'password', 'Mínimo de 12 caracteres.'); ?>
    <?php $field('backup_pass2', 'Confirme a senha de backup', 'password'); ?>

    <button type="submit" class="btn">Instalar</button>
  </form>
  <?php endif; ?>
</section>
