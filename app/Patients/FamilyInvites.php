<?php
declare(strict_types=1);

namespace App\Patients;

use App\Auth\AuthController;
use App\Core\Config;
use App\Core\Container;

/** Convite por e-mail para contas de familia (responsavel/paciente). */
final class FamilyInvites
{
    public static function send(array $user): void
    {
        $token = Container::tokens()->issue((int) $user['id'], 'invite', AuthController::INVITE_TTL_MIN);
        $link = rtrim((string) Config::get('base_url', ''), '/') . '/senha/definir/' . $token;
        $html = '<p>Olá, ' . e($user['name']) . '.</p>'
            . '<p>A Dra. Rubia liberou o seu acesso ao acompanhamento.</p>'
            . '<p>Para definir sua senha e entrar, use o link abaixo:</p>'
            . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
            . '<p>O link é válido por 72 horas. Se você não esperava este e-mail, ignore-o.</p>';
        Container::mailer()->queue((string) $user['email'], 'Acesso ao acompanhamento — Dra. Rubia', $html);
    }
}
