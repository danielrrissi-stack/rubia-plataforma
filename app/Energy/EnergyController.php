<?php
declare(strict_types=1);

namespace App\Energy;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\Age;
use App\Support\Num;

final class EnergyController
{
    public const FACTORS = [
        '1.2' => 'Sedentário (1,2)',
        '1.375' => 'Levemente ativo (1,375)',
        '1.55' => 'Moderadamente ativo (1,55)',
        '1.725' => 'Muito ativo (1,725)',
        '1.9' => 'Extremamente ativo (1,9)',
    ];
    private const STAFF = ['medico', 'nutricionista'];

    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::ENERGIA, $id, $r);
        $patient = $this->patient($id);
        if (in_array($user['role'], self::STAFF, true)) {
            Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, ['section' => 'energia'], $r);
        }
        $latest = Container::measurements()->latest($id);
        $peso = $latest !== null && $latest['weight_kg'] !== null ? fmt_num((float) $latest['weight_kg'], 1) : '';
        $altura = $latest !== null && $latest['height_cm'] !== null ? fmt_num((float) $latest['height_cm'], 1) : '';
        // "Recalcular" (GET) permite sobrescrever os valores da ultima medida
        $values = [
            'peso' => isset($r->query['peso']) ? (string) $r->query['peso'] : $peso,
            'altura' => isset($r->query['altura']) ? (string) $r->query['altura'] : $altura,
            'formula' => '', 'fator' => '1.2', 'fator_outro' => '', 'notas' => '',
        ];
        return $this->render($r, $patient, $values, [], $latest);
    }

    public function store(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'edit', Policy::ENERGIA, $id, $r);
        $patient = $this->patient($id);
        $post = $r->post;
        $values = [
            'peso' => (string) ($post['peso'] ?? ''), 'altura' => (string) ($post['altura'] ?? ''),
            'formula' => (string) ($post['formula'] ?? ''), 'fator' => (string) ($post['fator'] ?? ''),
            'fator_outro' => (string) ($post['fator_outro'] ?? ''), 'notas' => trim((string) ($post['notas'] ?? '')),
        ];
        $errors = [];
        $kg = $this->num($values['peso']);
        $cm = $this->num($values['altura']);
        if ($kg === null || $kg < 2 || $kg > 400) {
            $errors[] = 'Informe um peso válido (2 a 400 kg).';
        }
        if ($cm === null || $cm < 30 || $cm > 250) {
            $errors[] = 'Informe uma altura válida (30 a 250 cm).';
        }
        $factor = $this->num($values['fator'] === 'outro' ? $values['fator_outro'] : $values['fator']);
        if ($factor === null || $factor < 1.0 || $factor > 2.5) {
            $errors[] = 'O fator de atividade deve estar entre 1,0 e 2,5.';
        }
        $ageYears = round(Age::days((string) $patient['birth_date'], date('Y-m-d')) / 365.25, 2);
        $tmb = null;
        if ($errors === []) {
            $all = EnergyCalculator::all((string) $patient['sex'], $kg, $cm, $ageYears);
            if (!isset(EnergyCalculator::FORMULAS[$values['formula']]) || $all[$values['formula']] === null) {
                $errors[] = 'Escolha uma fórmula disponível para a idade do paciente.';
            } else {
                $tmb = $all[$values['formula']];
            }
        }
        if ($errors !== []) {
            return $this->render($r, $patient, $values, $errors, Container::measurements()->latest($id));
        }
        Container::energyGoals()->save([
            'patient_id' => $id,
            'formula' => $values['formula'],
            'activity_factor' => number_format($factor, 3, '.', ''),
            'weight_kg' => number_format($kg, 3, '.', ''),
            'height_cm' => number_format($cm, 1, '.', ''),
            'age_years' => number_format($ageYears, 2, '.', ''),
            'tmb_kcal' => $tmb,
            'get_kcal' => (int) round($tmb * $factor),
            'notes' => $values['notas'],
        ], (int) $user['id'], $r);
        Session::flash('ok', 'Meta energética salva.');
        return Response::redirect('/pacientes/' . $id . '/energia');
    }

    private function num(string $raw): ?float
    {
        try {
            return Num::parse($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function render(Request $r, array $patient, array $values, array $errors, ?array $latest): Response
    {
        $id = (int) $patient['id'];
        $ageYears = round(Age::days((string) $patient['birth_date'], date('Y-m-d')) / 365.25, 2);
        $kg = $this->num($values['peso']);
        $cm = $this->num($values['altura']);
        $results = $kg !== null && $cm !== null && $kg > 0 && $cm > 0
            ? EnergyCalculator::all((string) $patient['sex'], $kg, $cm, $ageYears)
            : null;
        $user = $r->attributes['user'];
        return Response::html(View::render('Energy/views/index.php', [
            'patient' => $patient, 'values' => $values, 'errors' => $errors, 'latest' => $latest,
            'results' => $results, 'ageYears' => $ageYears, 'factors' => self::FACTORS,
            'current' => Container::energyGoals()->current($id),
            'history' => Container::energyGoals()->history($id),
            'canEdit' => Container::gate()->check($user, 'edit', Policy::ENERGIA, $id),
            'title' => 'Energia', 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'));
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }
}
