<?php
/** @var array $users @var array $roles @var int $me */
$fmtDt = static function (?string $dt): string {
    if ($dt === null || $dt === '') {
        return 'Nunca';
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt);
    return $d ? $d->format('d/m/Y H:i') : '';
};
?>
<h1>Equipe</h1>
<p><a class="btn" href="<?= e(url('/equipe/novo')) ?>">Novo usuário</a></p>
<table class="tabela">
  <thead>
    <tr>
      <th>Nome</th><th>E-mail</th><th>Papel</th><th>Registro</th><th>Admin</th>
      <th>Status</th><th>2FA ativo</th><th>Último acesso</th><th>Ações</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['name']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e($roles[$u['role']] ?? $u['role']) ?></td>
      <td><?= e($u['professional_register']) ?></td>
      <td><?= (int) $u['is_admin'] === 1 ? 'Sim' : 'Não' ?></td>
      <td><?= (int) $u['active'] === 1 ? 'Ativo' : 'Inativo' ?></td>
      <td><?= (int) $u['totp_enabled'] === 1 ? 'Sim' : 'Não' ?></td>
      <td><?= e($fmtDt($u['last_login_at'])) ?></td>
      <td>
        <a href="<?= e(url('/equipe/' . $u['id'] . '/editar')) ?>">Editar</a>
        <?php if ((int) $u['active'] === 1 && $u['password_hash'] === null): ?>
          <form method="post" action="<?= e(url('/equipe/' . $u['id'] . '/convite')) ?>" class="form-inline">
            <?= csrf_field() ?><button type="submit" class="btn btn-quiet">Reenviar convite</button>
          </form>
        <?php endif; ?>
        <?php if ((int) $u['totp_enabled'] === 1 && (int) $u['id'] !== $me): ?>
          <form method="post" action="<?= e(url('/equipe/' . $u['id'] . '/redefinir-2fa')) ?>" class="form-inline">
            <?= csrf_field() ?><button type="submit" class="btn btn-quiet" title="Use quando o usuário perder o celular com o aplicativo autenticador">Redefinir 2FA</button>
          </form>
        <?php endif; ?>
        <?php if ((int) $u['active'] === 1 && (int) $u['id'] !== $me): ?>
          <form method="post" action="<?= e(url('/equipe/' . $u['id'] . '/desativar')) ?>" class="form-inline">
            <?= csrf_field() ?><button type="submit" class="btn btn-quiet">Desativar</button>
          </form>
        <?php elseif ((int) $u['active'] !== 1): ?>
          <form method="post" action="<?= e(url('/equipe/' . $u['id'] . '/reativar')) ?>" class="form-inline">
            <?= csrf_field() ?><button type="submit" class="btn btn-quiet">Reativar</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
