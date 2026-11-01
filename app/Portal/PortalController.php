<?php
declare(strict_types=1);

namespace App\Portal;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\Age;
use App\Growth\AdultAnthropometry;
use App\Growth\GrowthController;
use App\Growth\MeasurementRepository;
use App\Support\Dates;
use App\Support\Num;

/** Portal da familia: paciente adulto e responsavel. Linguagem simples, sem jargao clinico. */
final class PortalController
{
    private const FAMILY = ['paciente', 'responsavel'];
    private const MIN_PASSWORD = 10;
    private const GENTLE_HIGH = 'Acima da faixa esperada — a equipe vai conversar com vocês sobre isso.';
    private const GENTLE_LOW = 'Abaixo da faixa esperada — a equipe vai acompanhar.';
    private const GENTLE_OK = 'Peso adequado para a idade.';
    private const GENTLE_ADULT_OK = 'Dentro da faixa esperada.';

    public function index(Request $r): Response
    {
        if (($redirect = $this->guard($r)) !== null) {
            return $redirect;
        }
        $user = $r->attributes['user'];
        $patients = $this->patientsOf($user);
        if (count($patients) === 1) {
            return Response::redirect('/portal/p/' . (int) $patients[0]['id']);
        }
        $today = date('Y-m-d');
        $items = array_map(fn(array $p): array => [
            'id' => (int) $p['id'],
            'first' => $this->firstName($p),
            'age' => Age::label((string) $p['birth_date'], $today),
        ], $patients);
        return $this->page('Portal/views/choose_patient.php', 'Escolha quem acompanhar', $user, ['items' => $items]);
    }

    public function home(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r, 'view');
        if ($redirect !== null) {
            return $redirect;
        }
        return $this->page('Portal/views/home.php', 'Início', $user, [
            'patient' => $patient,
            'first' => $this->firstName($patient),
            'many' => count($this->patientsOf($user)) > 1,
        ]);
    }

    public function growth(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r, 'view');
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $adult = $this->isAdult($patient);
        $last = Container::measurements()->latest($pid);
        return $this->page('Portal/views/growth.php', 'Crescimento', $user, [
            'patient' => $patient,
            'first' => $this->firstName($patient),
            'adult' => $adult,
            'last' => $last === null ? null : $this->describeLast($patient, $last),
        ]);
    }

    public function measureForm(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r, 'edit');
        if ($redirect !== null) {
            return $redirect;
        }
        return $this->form($user, $patient, ['data_medida' => date('d/m/Y'), 'peso' => '', 'altura' => '', 'cintura' => '', 'posicao' => 'deitado'], []);
    }

    public function measureStore(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r, 'edit');
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $values = [];
        foreach (['data_medida', 'peso', 'altura', 'cintura', 'posicao'] as $k) {
            $values[$k] = trim((string) $r->input($k, ''));
        }
        $errors = [];
        $data = ['measured_on' => null, 'head_cm' => null, 'hip_cm' => null, 'systolic' => null, 'diastolic' => null];
        try {
            $data['measured_on'] = Dates::parseBr($values['data_medida']);
        } catch (\InvalidArgumentException) {
            $errors[] = 'Informe a data no formato dia/mês/ano, por exemplo 25/03/2026.';
        }
        foreach (['peso' => ['weight_kg', 'peso'], 'altura' => ['height_cm', 'altura'], 'cintura' => ['waist_cm', 'cintura']] as $in => [$col, $label]) {
            try {
                $data[$col] = Num::parse($values[$in]);
            } catch (\InvalidArgumentException) {
                $errors[] = 'Confira o valor do ' . $label . ': use apenas números, com vírgula para os decimais.';
                $data[$col] = null;
            }
        }
        if ($errors === [] && $data['weight_kg'] === null && $data['height_cm'] === null && $data['waist_cm'] === null) {
            $errors[] = 'Preencha pelo menos uma medida: peso, altura ou cintura.';
        }
        $under2 = Age::years((string) $patient['birth_date'], date('Y-m-d')) < 2;
        $data['position'] = $under2 && $values['posicao'] === 'deitado' ? 'deitado' : 'em_pe';
        if ($errors === []) {
            $errors = MeasurementRepository::validate($data, (string) $patient['birth_date'], date('Y-m-d'));
        }
        if ($errors !== []) {
            return $this->form($user, $patient, $values + ['posicao' => 'deitado'], $errors);
        }
        Container::measurements()->create($pid, $data, 'familia', (int) $user['id'], $r);
        Session::flash('ok', 'Medida registrada. Obrigado!');
        return Response::redirect('/portal/p/' . $pid . '/crescimento');
    }

    public function account(Request $r): Response
    {
        if (($redirect = $this->guard($r)) !== null) {
            return $redirect;
        }
        return $this->accountPage($r->attributes['user'], []);
    }

    public function changePassword(Request $r): Response
    {
        if (($redirect = $this->guard($r)) !== null) {
            return $redirect;
        }
        $user = $r->attributes['user'];
        $current = (string) $r->input('atual', '');
        $new = (string) $r->input('nova', '');
        $confirm = (string) $r->input('confirmacao', '');
        $errors = [];
        if (!is_string($user['password_hash'] ?? null) || !password_verify($current, $user['password_hash'])) {
            $errors[] = 'A senha atual não confere.';
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            $errors[] = 'A nova senha precisa ter pelo menos ' . self::MIN_PASSWORD . ' caracteres.';
        } elseif ($new !== $confirm) {
            $errors[] = 'A confirmação não é igual à nova senha.';
        }
        if ($errors !== []) {
            return $this->accountPage($user, $errors);
        }
        Container::users()->setPassword((int) $user['id'], $new);
        Container::audit()->record((int) $user['id'], 'update', 'users', (int) $user['id'], null, null, ['campo' => 'senha'], $r);
        Session::regenerate();
        Session::flash('ok', 'Senha alterada com sucesso.');
        return Response::redirect('/portal/conta');
    }

    // ---- helpers ----

    /** Equipe nao usa o portal. */
    private function guard(Request $r): ?Response
    {
        return in_array($r->attributes['user']['role'] ?? '', self::FAMILY, true) ? null : Response::redirect('/');
    }

    /** @return array{0:array,1:array,2:?Response} usuario, paciente, redirecionamento */
    private function open(Request $r, string $action): array
    {
        if (($redirect = $this->guard($r)) !== null) {
            return [[], [], $redirect];
        }
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, $action, Policy::ANTROPOMETRIA, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null) {
            throw new HttpException(404);
        }
        return [$user, $patient, null];
    }

    /** Pacientes visiveis: o proprio cadastro (paciente adulto) e os vinculados como responsavel. */
    private function patientsOf(array $user): array
    {
        $byId = [];
        $own = Container::db()->one('SELECT * FROM patients WHERE user_id = ? AND deleted_at IS NULL', [$user['id']]);
        if ($own !== null) {
            $byId[(int) $own['id']] = $own;
        }
        foreach (Container::guardians()->patientsForGuardian((int) $user['id']) as $p) {
            $byId[(int) $p['id']] ??= $p;
        }
        return array_values($byId);
    }

    private function firstName(array $p): string
    {
        $name = trim((string) ($p['social_name'] ?: $p['full_name']));
        return explode(' ', $name)[0] ?? $name;
    }

    private function isAdult(array $patient): bool
    {
        return Age::months((string) $patient['birth_date'], date('Y-m-d')) >= GrowthController::ADULT_MONTHS;
    }

    /** @return array{date:string,weight:?string,height:?string,note:?string,tone:string} */
    private function describeLast(array $patient, array $m): array
    {
        $w = $m['weight_kg'] === null ? null : (float) $m['weight_kg'];
        $h = $m['height_cm'] === null ? null : (float) $m['height_cm'];
        $status = null;
        if ($w !== null && $h !== null) {
            $on = (string) $m['measured_on'];
            $birth = (string) $patient['birth_date'];
            if (Age::months($birth, $on) >= GrowthController::ADULT_MONTHS) {
                $status = AdultAnthropometry::bmiClass(AdultAnthropometry::bmi($w, $h));
            } else {
                $ind = Container::growth()->indicators((string) $patient['sex'], $birth, $on, $w, $h, $m['position'], null);
                $status = $ind['bfa']['status'] ?? null;
            }
        }
        [$note, $tone] = $this->gentle($status, $this->isAdult($patient));
        return [
            'date' => fmt_date((string) $m['measured_on']),
            'weight' => $w === null ? null : rtrim(rtrim(fmt_num($w, 3), '0'), ',') . ' kg',
            'height' => $h === null ? null : fmt_num($h, 1) . ' cm',
            'note' => $note,
            'tone' => $tone,
        ];
    }

    /** @return array{0:?string,1:string} texto acolhedor e tom (ok|atencao) */
    private function gentle(?string $status, bool $adult): array
    {
        if ($status === null) {
            return [null, 'ok'];
        }
        if ($status === 'Eutrofia') {
            return [$adult ? self::GENTLE_ADULT_OK : self::GENTLE_OK, 'ok'];
        }
        if (in_array($status, ['Magreza', 'Magreza acentuada', 'Baixo peso'], true)) {
            return [self::GENTLE_LOW, 'atencao'];
        }
        return [self::GENTLE_HIGH, 'atencao'];
    }

    private function form(array $user, array $patient, array $values, array $errors): Response
    {
        return $this->page('Portal/views/measure_form.php', 'Registrar medida', $user, [
            'patient' => $patient,
            'first' => $this->firstName($patient),
            'values' => $values,
            'errors' => $errors,
            'under2' => Age::years((string) $patient['birth_date'], date('Y-m-d')) < 2,
        ]);
    }

    private function accountPage(array $user, array $errors): Response
    {
        $fresh = Container::users()->find((int) $user['id']) ?? $user;
        return $this->page('Portal/views/account.php', 'Minha conta', $user, [
            'errors' => $errors,
            'totpOn' => (int) ($fresh['totp_enabled'] ?? 0) === 1,
        ]);
    }

    private function page(string $template, string $title, array $user, array $data): Response
    {
        return Response::html(View::render($template, $data + ['title' => $title, 'userName' => $user['name']], 'Layout/views/layout_portal.php'));
    }
}
