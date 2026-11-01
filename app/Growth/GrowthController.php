<?php
declare(strict_types=1);

namespace App\Growth;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Support\Dates;
use App\Support\Num;

/** Painel clinico de medidas: registro, versoes, indicadores, velocidade e curvas OMS. */
final class GrowthController
{
    private const STAFF_WRITE = ['medico', 'nutricionista'];
    private const AUDIT_VIEW = ['medico', 'nutricionista'];
    public const ADULT_MONTHS = 229;
    private const INDICATORS = ['bfa', 'wfa', 'hfa', 'wflh', 'hcfa'];
    private const TAB_LABELS = [
        'bfa' => 'IMC/idade', 'wfa' => 'Peso/idade', 'hfa' => 'Altura/idade', 'wflh' => 'Peso/altura', 'hcfa' => 'PC/idade',
    ];
    private const Z_LINES = [-3, -2, -1, 0, 1, 2, 3];
    private const PCT_LINES = [['P3', -1.881], ['P15', -1.036], ['P50', 0.0], ['P85', 1.036], ['P97', 1.881]];
    private const NOT_APPLICABLE = 'Não se aplica para esta idade';

    // ---- paginas ----

    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::ANTROPOMETRIA, $id, $r);
        if (in_array($user['role'], ['paciente', 'responsavel'], true)) {
            return Response::redirect('/portal/p/' . $id . '/crescimento');
        }
        $patient = $this->patient($id);
        if (in_array($user['role'], self::AUDIT_VIEW, true)) {
            Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, ['section' => 'crescimento'], $r);
        }
        $today = date('Y-m-d');
        $birth = (string) $patient['birth_date'];
        $measures = Container::measurements()->current($id);

        $childRows = [];
        $adultRows = [];
        foreach ($measures as $m) {
            $row = $this->describe($patient, $m);
            if ($row['adult']) {
                $adultRows[] = $row;
            } else {
                $childRows[] = $row;
            }
        }
        $velocity = Velocity::heightVelocity($measures);
        $months = Age::months($birth, $today);

        return $this->page('Growth/views/index.php', 'Crescimento', $r, [
            'patient' => $patient,
            'today' => $today,
            'childRows' => $childRows,
            'adultRows' => $adultRows,
            'velocity' => $velocity,
            'tabs' => $this->tabsFor($birth, $today),
            'adultPatient' => $months >= self::ADULT_MONTHS,
            'canWrite' => $this->canWrite($user, $id),
            'isStaff' => in_array($user['role'], self::AUDIT_VIEW, true),
        ]);
    }

    public function create(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $this->authorizeWrite($r, $id);
        $patient = $this->patient($id);
        return $this->form($r, $patient, null, $this->blank(), []);
    }

    public function store(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorizeWrite($r, $id);
        $patient = $this->patient($id);
        [$data, $errors] = $this->parse($r, (string) $patient['birth_date']);
        if ($errors !== []) {
            return $this->form($r, $patient, null, $this->posted($r), $errors);
        }
        Container::measurements()->create($id, $data, 'equipe', (int) $actor['id'], $r);
        Session::flash('ok', 'Medida registrada.');
        return Response::redirect('/pacientes/' . $id . '/crescimento');
    }

    public function edit(Request $r): Response
    {
        $m = $this->measurement((int) $r->params['id']);
        $this->authorizeWrite($r, (int) $m['patient_id']);
        $patient = $this->patient((int) $m['patient_id']);
        return $this->form($r, $patient, $m, $this->fromRow($m), []);
    }

    public function update(Request $r): Response
    {
        $m = $this->measurement((int) $r->params['id']);
        $pid = (int) $m['patient_id'];
        $actor = $this->authorizeWrite($r, $pid);
        $patient = $this->patient($pid);
        [$data, $errors] = $this->parse($r, (string) $patient['birth_date']);
        if ($errors === []) {
            try {
                Container::measurements()->correct((int) $m['id'], $data, (int) $actor['id'], $r);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            return $this->form($r, $patient, $m, $this->posted($r), $errors);
        }
        Session::flash('ok', 'Medida corrigida. A versão anterior foi mantida no histórico.');
        return Response::redirect('/pacientes/' . $pid . '/crescimento');
    }

    public function remove(Request $r): Response
    {
        $m = $this->measurement((int) $r->params['id']);
        $pid = (int) $m['patient_id'];
        $actor = $this->authorizeWrite($r, $pid);
        try {
            Container::measurements()->remove((int) $m['id'], (string) $r->input('motivo', ''), (int) $actor['id'], $r);
            Session::flash('ok', 'Medida removida. O registro foi mantido no histórico.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/pacientes/' . $pid . '/crescimento');
    }

    public function versions(Request $r): Response
    {
        $m = $this->measurement((int) $r->params['id']);
        $pid = (int) $m['patient_id'];
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'view', Policy::ANTROPOMETRIA, $pid, $r);
        if (!in_array($user['role'], self::AUDIT_VIEW, true)) {
            throw new HttpException(403);
        }
        Container::audit()->record((int) $user['id'], 'view', 'measurements', (int) $m['id'], $pid, null, ['section' => 'versoes'], $r);
        $names = [];
        $versions = Container::measurements()->versions((int) $m['id']);
        foreach ($versions as $v) {
            $names[(int) $v['created_by']] ??= (string) (Container::users()->find((int) $v['created_by'])['name'] ?? '');
        }
        return $this->page('Growth/views/history.php', 'Versões da medida', $r, [
            'patient' => $this->patient($pid), 'versions' => $versions, 'names' => $names,
        ]);
    }

    // ---- curvas.json ----

    /**
     * GET /pacientes/{id}/curvas.json?indicador=bfa|wfa|hfa|wflh|hcfa&tipo=z|percentil
     *
     * Crianca/adolescente: {"reference":{"lines":[{"label","points":[[x,y],...]}]},
     *   "patient":[[x,y,"dd/mm/aaaa"(,true se implausivel)],...],"x_unit":"meses"|"cm"}.
     *   x em meses (idade) ou cm (peso/altura); y na unidade da medida; faixa = idade do paciente +-12 meses.
     * Fora da faixa etaria do indicador: {"reference":null,"message":"Não se aplica para esta idade"}.
     * Adulto (>= 229 meses): {"reference":null,"series":{"peso":[["Y-m-d",kg],...],"imc":[["Y-m-d",imc],...]}}.
     */
    public function curves(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::ANTROPOMETRIA, $id, $r);
        $patient = $this->patient($id);
        $indicator = (string) $r->input('indicador', 'bfa');
        if (!in_array($indicator, self::INDICATORS, true)) {
            throw new HttpException(400);
        }
        $percentile = (string) $r->input('tipo', 'z') === 'percentil';
        $birth = (string) $patient['birth_date'];
        $today = date('Y-m-d');
        $months = Age::months($birth, $today);
        $measures = Container::measurements()->current($id);

        if ($months >= self::ADULT_MONTHS) {
            return Response::json(['reference' => null, 'series' => $this->adultSeries($measures)]);
        }
        if (!isset($this->tabsFor($birth, $today)[$indicator])) {
            return Response::json(['reference' => null, 'message' => self::NOT_APPLICABLE]);
        }
        $sex = (string) $patient['sex'];
        $levels = $percentile
            ? array_map(static fn(array $p): array => [$p[0], $p[1]], self::PCT_LINES)
            : array_map(static fn(int $z): array => [($z > 0 ? '+' : '') . $z, (float) $z], self::Z_LINES);

        $scored = [];
        foreach ($measures as $m) {
            $scored[] = [$m, Container::growth()->indicators(
                $sex, $birth, (string) $m['measured_on'],
                $m['weight_kg'] === null ? null : (float) $m['weight_kg'],
                $m['height_cm'] === null ? null : (float) $m['height_cm'],
                $m['position'], $m['head_cm'] === null ? null : (float) $m['head_cm'],
            )];
        }

        if ($indicator === 'wflh') {
            [$grid, $patientPts, $table] = $this->wflhData($sex, $birth, $today, $scored);
            $unit = 'cm';
        } else {
            [$grid, $patientPts, $table] = $this->ageData($indicator, $sex, $birth, $today, $months, $scored);
            $unit = 'meses';
        }

        $lines = [];
        foreach ($levels as [$label, $z]) {
            $points = [];
            foreach ($grid as $g) {
                $lms = Container::whoRef()->lms($table($g), $sex, $g['x'], $g['source']);
                if ($lms !== null) {
                    $points[] = [$g['display'], round(Lms::valueAt($z, $lms['l'], $lms['m'], $lms['s']), 3)];
                }
            }
            $lines[] = ['label' => $label, 'points' => $points];
        }
        return Response::json([
            'reference' => ['lines' => $lines],
            'patient' => $patientPts,
            'x_unit' => $unit,
        ]);
    }

    /** @return array{0:array,1:array,2:callable} grade de referencia, pontos do paciente, resolvedor de tabela */
    private function ageData(string $indicator, string $sex, string $birth, string $today, float $months, array $scored): array
    {
        $lo = (int) max(0, floor($months - 12));
        $hi = (int) min(self::ADULT_MONTHS - 1, ceil($months + 12));
        $grid = [];
        for ($m = $lo; $m <= $hi; $m++) {
            $grid[] = $this->gridPoint($m * Age::DAYS_PER_MONTH, (float) $m);
        }
        $grid[] = $this->gridPoint((float) Age::days($birth, $today), $months);
        usort($grid, static fn(array $a, array $b): int => $a['display'] <=> $b['display']);
        $unique = [];
        foreach ($grid as $g) {
            $unique[(string) $g['display']] ??= $g;
        }
        $grid = array_values($unique);

        $xMin = max(0.0, $months - 12);
        $xMax = $months + 12;
        $points = [];
        foreach ($scored as [$m, $ind]) {
            if ($ind[$indicator] === null) {
                continue;
            }
            $x = Age::months($birth, (string) $m['measured_on']);
            if ($x < $xMin || $x > $xMax) {
                continue;
            }
            $points[] = $this->patientPoint(round($x, 2), (float) $ind[$indicator]['value'], $m, $ind[$indicator]['implausible']);
        }
        return [$grid, $points, static fn(array $g): string => $indicator];
    }

    private function gridPoint(float $days, float $months): array
    {
        $d = (int) round($days);
        if ($d <= GrowthCalculator::WHO2006_MAX_DAYS) {
            return ['source' => 'who2006', 'x' => (float) $d, 'display' => round($d / Age::DAYS_PER_MONTH, 2)];
        }
        return ['source' => 'who2007', 'x' => $months, 'display' => round($months, 2)];
    }

    private function wflhData(string $sex, string $birth, string $today, array $scored): array
    {
        $days = Age::days($birth, $today);
        $table = $days <= GrowthCalculator::LENGTH_MAX_DAYS ? 'wfl' : 'wfh';
        $range = Container::whoRef()->range($table, $sex, 'who2006');
        $points = [];
        $heights = [];
        foreach ($scored as [$m, $ind]) {
            if ($ind['wflh'] === null) {
                continue;
            }
            // Apenas medidas da tabela desenhada (wfl: <= 730 dias; wfh: > 730), com a altura corrigida pela posicao.
            $mDays = Age::days($birth, (string) $m['measured_on']);
            if (($mDays <= GrowthCalculator::LENGTH_MAX_DAYS ? 'wfl' : 'wfh') !== $table) {
                continue;
            }
            $h = Container::growth()->correctHeight((float) $m['height_cm'], $mDays, $m['position']);
            $heights[] = $h;
            $points[] = $this->patientPoint($h, (float) $m['weight_kg'], $m, $ind['wflh']['implausible']);
        }
        $grid = [];
        if ($range !== null) {
            if ($heights !== []) {
                $lo = min($heights) - 5;
                $hi = max($heights) + 5;
            } else {
                $mid = ($range['min'] + $range['max']) / 2;
                $lo = $mid - 10;
                $hi = $mid + 10;
            }
            $lo = max($range['min'], $lo);
            $hi = min($range['max'], $hi);
            for ($x = (int) ceil($lo); $x <= (int) floor($hi); $x++) {
                $grid[] = ['source' => 'who2006', 'x' => (float) $x, 'display' => (float) $x];
            }
        }
        return [$grid, $points, static fn(array $g): string => $table];
    }

    private function patientPoint(float $x, float $y, array $m, bool $implausible): array
    {
        $p = [$x, round($y, 2), fmt_date((string) $m['measured_on'])];
        if ($implausible) {
            $p[] = true;
        }
        return $p;
    }

    private function adultSeries(array $measures): array
    {
        $peso = [];
        $imc = [];
        foreach ($measures as $m) {
            $on = substr((string) $m['measured_on'], 0, 10);
            if ($m['weight_kg'] !== null) {
                $peso[] = [$on, (float) $m['weight_kg']];
            }
            if ($m['weight_kg'] !== null && $m['height_cm'] !== null) {
                $imc[] = [$on, AdultAnthropometry::bmi((float) $m['weight_kg'], (float) $m['height_cm'])];
            }
        }
        return ['peso' => $peso, 'imc' => $imc];
    }

    // ---- resumo para o cartao do paciente ----

    /** @return ?array{date:string,bmi:?float,status:?string} */
    public static function summary(array $patient, string $today): ?array
    {
        $m = Container::measurements()->latest((int) $patient['id']);
        if ($m === null) {
            return null;
        }
        $bmi = null;
        $status = null;
        if ($m['weight_kg'] !== null && $m['height_cm'] !== null) {
            $d = (new self())->describe($patient, $m);
            $bmi = $d['bmi'];
            $status = $d['adult'] ? $d['bmiClass'] : ($d['ind']['bfa']['status'] ?? null);
        }
        return ['date' => (string) $m['measured_on'], 'bmi' => $bmi, 'status' => $status];
    }

    // ---- helpers ----

    /** Linha de exibicao de uma medida vigente. */
    private function describe(array $patient, array $m): array
    {
        $birth = (string) $patient['birth_date'];
        $sex = (string) $patient['sex'];
        $on = (string) $m['measured_on'];
        $w = $m['weight_kg'] === null ? null : (float) $m['weight_kg'];
        $h = $m['height_cm'] === null ? null : (float) $m['height_cm'];
        $adult = Age::months($birth, $on) >= self::ADULT_MONTHS;
        $ind = ['bfa' => null, 'wfa' => null, 'hfa' => null, 'wflh' => null, 'hcfa' => null];
        $bmi = null;
        $bp = null;
        $row = ['m' => $m, 'adult' => $adult, 'age' => Age::label($birth, $on)];

        if ($adult) {
            if ($w !== null && $h !== null) {
                $bmi = AdultAnthropometry::bmi($w, $h);
                $row['bmiClass'] = AdultAnthropometry::bmiClass($bmi);
            }
            $waist = $m['waist_cm'] === null ? null : (float) $m['waist_cm'];
            $hip = $m['hip_cm'] === null ? null : (float) $m['hip_cm'];
            $row['waistRisk'] = $waist === null ? null : AdultAnthropometry::waistRisk($sex, $waist);
            $row['whr'] = ($waist !== null && $hip !== null) ? AdultAnthropometry::whr($waist, $hip) : null;
            $row['whrRisk'] = $row['whr'] === null ? null : AdultAnthropometry::whrRisk($sex, $row['whr']);
            if ($m['systolic'] !== null && $m['diastolic'] !== null) {
                $bp = BloodPressure::classifyAdult((int) $m['systolic'], (int) $m['diastolic']);
            }
        } else {
            $ind = Container::growth()->indicators($sex, $birth, $on, $w, $h, $m['position'], $m['head_cm'] === null ? null : (float) $m['head_cm']);
            $bmi = $ind['bfa']['value'] ?? null;
            if ($m['systolic'] !== null && $m['diastolic'] !== null) {
                $bp = BloodPressure::classifyChild(
                    $sex, Age::years($birth, $on), $ind['hfa']['percentile'] ?? null,
                    (int) $m['systolic'], (int) $m['diastolic'], Container::bpRef(),
                );
            }
        }
        $row['ind'] = $ind;
        $row['bmi'] = $bmi === null ? null : round((float) $bmi, 1);
        $row['bp'] = $bp;
        $row['implausible'] = false;
        foreach ($ind as $i) {
            if ($i !== null && $i['implausible']) {
                $row['implausible'] = true;
            }
        }
        return $row;
    }

    /** @return array<string,string> indicadores aplicaveis a idade atual do paciente (chave => rotulo) */
    private function tabsFor(string $birth, string $today): array
    {
        $days = Age::days($birth, $today);
        $months = Age::months($birth, $today);
        if ($months >= self::ADULT_MONTHS) {
            return [];
        }
        $tabs = ['bfa' => self::TAB_LABELS['bfa']];
        if ($days <= GrowthCalculator::WHO2006_MAX_DAYS || $months < GrowthCalculator::WFA2007_MAX_MONTHS) {
            $tabs['wfa'] = self::TAB_LABELS['wfa'];
        }
        $tabs['hfa'] = self::TAB_LABELS['hfa'];
        if ($days <= GrowthCalculator::WHO2006_MAX_DAYS) {
            $tabs['wflh'] = self::TAB_LABELS['wflh'];
            $tabs['hcfa'] = self::TAB_LABELS['hcfa'];
        }
        return $tabs;
    }

    private function canWrite(array $user, int $patientId): bool
    {
        return in_array($user['role'], self::STAFF_WRITE, true)
            && Container::gate()->check($user, 'edit', Policy::ANTROPOMETRIA, $patientId);
    }

    /** Gate (edit ANTROPOMETRIA + escopo) e papel de equipe: a familia so registra pelo portal. */
    private function authorizeWrite(Request $r, int $patientId): array
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'edit', Policy::ANTROPOMETRIA, $patientId, $r);
        if (!in_array($user['role'], self::STAFF_WRITE, true)) {
            throw new HttpException(403);
        }
        return $user;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function measurement(int $id): array
    {
        $m = Container::measurements()->find($id);
        if ($m === null) {
            throw new HttpException(404);
        }
        return $m;
    }

    /** @return array{0:array,1:string[]} */
    private function parse(Request $r, string $birth): array
    {
        $errors = [];
        $data = ['measured_on' => null];
        try {
            $data['measured_on'] = Dates::parseBr((string) $r->input('data_medida', ''));
        } catch (\InvalidArgumentException) {
            $errors[] = 'Informe a data da medida no formato dd/mm/aaaa.';
        }
        $fields = [
            'peso' => ['weight_kg', 'Peso'], 'altura' => ['height_cm', 'Altura'], 'cintura' => ['waist_cm', 'Cintura'],
            'quadril' => ['hip_cm', 'Quadril'], 'pc' => ['head_cm', 'Perímetro cefálico'],
            'sistolica' => ['systolic', 'Pressão sistólica'], 'diastolica' => ['diastolic', 'Pressão diastólica'],
        ];
        foreach ($fields as $input => [$col, $label]) {
            try {
                $v = Num::parse((string) $r->input($input, ''));
            } catch (\InvalidArgumentException) {
                $errors[] = $label . ' inválido(a): use números, com vírgula para decimais.';
                $v = null;
            }
            if ($v !== null && in_array($col, ['systolic', 'diastolic'], true)) {
                if ($v != floor($v)) {
                    $errors[] = $label . ' deve ser um número inteiro.';
                    $v = null;
                } else {
                    $v = (int) $v;
                }
            }
            $data[$col] = $v;
        }
        $pos = (string) $r->input('posicao', '');
        $data['position'] = in_array($pos, ['deitado', 'em_pe'], true) ? $pos : null;
        if ($errors === []) {
            $errors = MeasurementRepository::validate($data, $birth, date('Y-m-d'));
        }
        return [$data, $errors];
    }

    private function blank(): array
    {
        return [
            'data_medida' => date('d/m/Y'), 'peso' => '', 'altura' => '', 'posicao' => '', 'cintura' => '',
            'quadril' => '', 'pc' => '', 'sistolica' => '', 'diastolica' => '',
        ];
    }

    private function posted(Request $r): array
    {
        $out = [];
        foreach (array_keys($this->blank()) as $k) {
            $out[$k] = (string) $r->input($k, '');
        }
        return $out;
    }

    private function fromRow(array $m): array
    {
        $f = static fn(mixed $v, int $dec): string => $v === null ? '' : fmt_num((float) $v, $dec);
        return [
            'data_medida' => fmt_date((string) $m['measured_on']),
            'peso' => $m['weight_kg'] === null ? '' : rtrim(rtrim(fmt_num((float) $m['weight_kg'], 3), '0'), ','),
            'altura' => $f($m['height_cm'], 1), 'posicao' => (string) $m['position'],
            'cintura' => $f($m['waist_cm'], 1), 'quadril' => $f($m['hip_cm'], 1), 'pc' => $f($m['head_cm'], 1),
            'sistolica' => $m['systolic'] === null ? '' : (string) $m['systolic'],
            'diastolica' => $m['diastolic'] === null ? '' : (string) $m['diastolic'],
        ];
    }

    private function form(Request $r, array $patient, ?array $measurement, array $values, array $errors): Response
    {
        return $this->page('Growth/views/form.php', $measurement === null ? 'Nova medida' : 'Corrigir medida', $r, [
            'patient' => $patient, 'measurement' => $measurement, 'values' => $values, 'errors' => $errors,
        ]);
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        $user = $r->attributes['user'];
        $family = in_array($user['role'], ['paciente', 'responsavel', 'crianca'], true);
        return Response::html(View::render($template, $data + [
            'title' => $title,
            'userName' => $user['name'],
            'active' => '/pacientes',
        ], $family ? 'Layout/views/layout_portal.php' : 'Layout/views/layout.php'));
    }
}
