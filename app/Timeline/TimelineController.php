<?php
declare(strict_types=1);

namespace App\Timeline;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\WeightGoals\WeightGoalController;

/** Linha do tempo do paciente (so medica e nutricionista; cada evento segue a permissao da origem). */
final class TimelineController
{
    public const STAFF = ['medico', 'nutricionista'];

    public static function canOpen(array $user, int $patientId): bool
    {
        return in_array($user['role'] ?? '', self::STAFF, true)
            && Container::gate()->check($user, 'view', Policy::CADASTRO_BASICO, $patientId);
    }

    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        if (!in_array($user['role'] ?? '', self::STAFF, true)) {
            throw new HttpException(403, 'Acesso negado');
        }
        Container::gate()->authorize($user, 'view', Policy::CADASTRO_BASICO, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null) {
            throw new HttpException(404);
        }
        $today = date('Y-m-d');
        $only = $user['role'] === 'nutricionista' ? (int) $user['id'] : null;
        $period = TimelinePeriod::resolve($id, (string) $r->input('periodo', 'visita'), $today, $only);
        Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, ['section' => 'linha_do_tempo', 'periodo' => $period['choice']], $r);
        return Response::html(View::render('Timeline/views/index.php', [
            'patient' => $patient, 'period' => $period,
            'result' => TimelineBuilder::events($user, $id, $period['from'], $today),
            'weightGoal' => WeightGoalController::card($user, $id, $today),
            'canEvolucao' => \App\Evolution\EvolutionAccess::canView($user, $id),
            'title' => 'Linha do tempo', 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'));
    }
}
