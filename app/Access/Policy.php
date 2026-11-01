<?php
declare(strict_types=1);

namespace App\Access;

/**
 * Matriz papel x secao x acao. Default-deny: papel, secao ou acao desconhecidos
 * e usuario inativo retornam false.
 *
 * ATENCAO: para paciente/responsavel, edit ANTROPOMETRIA retorna true, mas a
 * restricao "apenas criar medidas com source='familia'" e aplicada pelos
 * controllers (medidas), nao por esta classe.
 */
final class Policy
{
    public const CADASTRO_BASICO = 'cadastro_basico';
    public const CADASTRO = 'cadastro';
    public const AGENDA = 'agenda';
    public const ANTROPOMETRIA = 'antropometria';
    public const ENERGIA = 'energia';
    public const NUTRICAO = 'nutricao';
    public const HISTORICO = 'historico';
    public const CLINICO = 'clinico';
    public const EXAMES = 'exames';
    public const MEDICAMENTOS = 'medicamentos';
    public const METAS = 'metas';
    public const AUDITORIA = 'auditoria';
    public const USUARIOS = 'usuarios';

    private const CLINICAL_ALL = [
        self::CADASTRO_BASICO, self::CADASTRO, self::AGENDA, self::ANTROPOMETRIA, self::ENERGIA,
        self::NUTRICAO, self::HISTORICO, self::CLINICO, self::EXAMES, self::MEDICAMENTOS, self::METAS,
    ];

    /** @var array<string,array{view:string[],edit:string[]}> */
    private const MATRIX = [
        'medico' => ['view' => self::CLINICAL_ALL, 'edit' => self::CLINICAL_ALL],
        'nutricionista' => [
            'view' => [self::CADASTRO_BASICO, self::ANTROPOMETRIA, self::ENERGIA, self::NUTRICAO],
            'edit' => [self::ANTROPOMETRIA, self::ENERGIA, self::NUTRICAO],
        ],
        'secretaria' => [
            'view' => [self::CADASTRO_BASICO, self::CADASTRO, self::AGENDA],
            'edit' => [self::CADASTRO, self::AGENDA],
        ],
        'paciente' => [
            'view' => [self::CADASTRO_BASICO, self::ANTROPOMETRIA],
            'edit' => [self::ANTROPOMETRIA],
        ],
        'responsavel' => [
            'view' => [self::CADASTRO_BASICO, self::ANTROPOMETRIA],
            'edit' => [self::ANTROPOMETRIA],
        ],
        'crianca' => ['view' => [], 'edit' => []],
    ];

    private const ADMIN_EXTRA = [
        'view' => [self::AUDITORIA, self::USUARIOS],
        'edit' => [self::USUARIOS],
    ];

    public function allows(array $user, string $action, string $section): bool
    {
        if ((int) ($user['active'] ?? 0) !== 1) {
            return false;
        }
        $role = (string) ($user['role'] ?? '');
        if (!isset(self::MATRIX[$role]) || !isset(self::ADMIN_EXTRA[$action])) {
            return false;
        }
        if (in_array($section, self::MATRIX[$role][$action], true)) {
            return true;
        }
        return (int) ($user['is_admin'] ?? 0) === 1
            && in_array($section, self::ADMIN_EXTRA[$action], true);
    }
}
