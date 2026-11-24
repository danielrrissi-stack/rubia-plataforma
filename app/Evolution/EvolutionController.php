<?php
declare(strict_types=1);

namespace App\Evolution;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Painel de evolucao: curva de peso x doses dos remedios, aviso de estabilizacao e metabolismo. So a medica. */
final class EvolutionController
{
    public function index(Request $r): Response
    {
        [$user, $patient] = $this->open($r, 'view');
        $period = EvolutionData::period((string) $r->input('periodo', EvolutionData::DEFAULT_PERIOD));
        Container::audit()->record((int) $user['id'], 'view', 'patients', (int) $patient['id'], (int) $patient['id'], null, ['section' => 'evolucao', 'periodo' => $period], $r);
        return $this->render($r, $user, $patient, $period, [], null);
    }

    public function data(Request $r): Response
    {
        [$user, $patient] = $this->open($r, 'view');
        $period = EvolutionData::period((string) $r->input('periodo', EvolutionData::DEFAULT_PERIOD));
        $d = EvolutionData::build($user, $patient, date('Y-m-d'), $period);
        unset($d['settings']);
        $d['plateau'] = ['status' => $d['plateau']['status'], 'text' => $d['plateau']['text']];
        return Response::json($d);
    }

    public function saveSettings(Request $r): Response
    {
        [$user, $patient] = $this->open($r, 'edit');
        $pid = (int) $patient['id'];
        $raw = [
            'modo' => (string) ($r->post['modo'] ?? ''), 'kg' => trim((string) ($r->post['kg'] ?? '')),
            'pct' => trim((string) ($r->post['pct'] ?? '')), 'z' => trim((string) ($r->post['z'] ?? '')),
        ];
        try {
            Container::evolutionSettings()->save($pid, $raw, (int) $user['id'], $r);
        } catch (InvalidEvolutionSettingsException $e) {
            return $this->render($r, $user, $patient, EvolutionData::DEFAULT_PERIOD, $e->errors, $raw);
        }
        Session::flash('ok', 'Critério salvo.');
        return Response::redirect('/pacientes/' . $pid . '/evolucao');
    }

    /** @return array{0:array,1:array} */
    private function open(Request $r, string $action): array
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        EvolutionAccess::authorize($user, $action, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null) {
            throw new HttpException(404);
        }
        return [$user, $patient];
    }

    private function render(Request $r, array $user, array $patient, string $period, array $errors, ?array $values): Response
    {
        $pid = (int) $patient['id'];
        $d = EvolutionData::build($user, $patient, date('Y-m-d'), $period);
        $s = $d['settings'];
        $values ??= [
            'modo' => $s['adult_mode'], 'kg' => fmt_num($s['kg_per_week'], 2),
            'pct' => fmt_num($s['pct_per_4w'], 2), 'z' => fmt_num($s['z_per_4w'], 3),
        ];
        return Response::html(View::render('Evolution/views/index.php', [
            'patient' => $patient, 'period' => $period, 'd' => $d, 'errors' => $errors, 'values' => $values,
            'canEdit' => EvolutionAccess::canEdit($user, $pid),
            'title' => 'Evolução', 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'));
    }
}
