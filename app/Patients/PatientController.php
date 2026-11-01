<?php
declare(strict_types=1);

namespace App\Patients;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\Age;
use App\Support\Dates;
use App\Users\UserRepository;

final class PatientController
{
    private const STAFF = ['medico', 'nutricionista', 'secretaria'];

    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'view', Policy::CADASTRO_BASICO, null, $r);
        if (!in_array($user['role'], self::STAFF, true)) {
            return Response::redirect('/portal');
        }
        $q = trim((string) $r->input('q', ''));
        $ids = Container::patientScope()->accessiblePatientIds($user);
        $today = date('Y-m-d');
        return $this->page('Patients/views/index.php', 'Pacientes', $r, [
            'patients' => Container::patients()->search($q, $ids, 50, Container::gate()->check($user, 'view', Policy::CADASTRO, null)),
            'q' => $q,
            'today' => $today,
            'canCreate' => Container::gate()->check($user, 'edit', Policy::CADASTRO, null),
        ]);
    }

    public function create(Request $r): Response
    {
        $this->authorizeEdit($r, null);
        return $this->form($r, null, self::blank(), []);
    }

    public function store(Request $r): Response
    {
        $actor = $this->authorizeEdit($r, null);
        [$data, $errors] = $this->validate($r);
        if ($errors !== []) {
            return $this->form($r, null, $this->posted($r), $errors);
        }
        $id = Container::patients()->create($data, (int) $actor['id'], $r);
        Session::flash('ok', 'Paciente cadastrado.');
        return Response::redirect('/pacientes/' . $id);
    }

    public function show(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::CADASTRO_BASICO, $id, $r);
        if (!in_array($user['role'], self::STAFF, true)) {
            return Response::redirect('/portal/p/' . $id);
        }
        $patient = $this->patient($id);
        Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, null, $r);
        $today = date('Y-m-d');
        $gate = Container::gate();
        return $this->page('Patients/views/show.php', 'Paciente', $r, [
            'patient' => $patient,
            'today' => $today,
            'canCadastro' => $gate->check($user, 'view', Policy::CADASTRO, $id),
            'canEdit' => $gate->check($user, 'edit', Policy::CADASTRO, $id),
            'canCrescimento' => $gate->check($user, 'view', Policy::ANTROPOMETRIA, $id),
            'growth' => $gate->check($user, 'view', Policy::ANTROPOMETRIA, $id) ? \App\Growth\GrowthController::summary($patient, $today) : null,
            'canProntuario' => $gate->check($user, 'view', Policy::CLINICO, $id),
            'evolutions' => $gate->check($user, 'view', Policy::CLINICO, $id) ? Container::clinicalEntries()->evolutions($id) : [],
            'canAcessoProf' => $gate->check($user, 'edit', Policy::CLINICO, $id),
            'canEnergia' => $gate->check($user, 'view', Policy::ENERGIA, $id),
            'energy' => $gate->check($user, 'view', Policy::ENERGIA, $id) ? Container::energyGoals()->current($id) : null,
            'adult' => Age::years((string) $patient['birth_date'], $today) >= 18,
            'professional' => Container::users()->find((int) $patient['responsible_professional_id']),
            'guardians' => $gate->check($user, 'view', Policy::CADASTRO, $id) ? Container::guardians()->forPatient($id) : [],
            'active' => '/pacientes',
        ]);
    }

    public function edit(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $this->authorizeEdit($r, $id);
        $patient = $this->patient($id);
        return $this->form($r, $patient, $this->fromRow($patient), []);
    }

    public function update(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorizeEdit($r, $id);
        $patient = $this->patient($id);
        [$data, $errors] = $this->validate($r);
        if ($errors !== []) {
            return $this->form($r, $patient, $this->posted($r), $errors);
        }
        Container::patients()->update($id, $data, (int) $actor['id'], $r);
        Session::flash('ok', 'Cadastro atualizado.');
        return Response::redirect('/pacientes/' . $id);
    }

    /** Cria o login do paciente adulto (>= 18 anos, sem login ainda) e envia o convite. */
    public function grantAccess(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $actor = $this->authorizeEdit($r, $id);
        $patient = $this->patient($id);
        $email = UserRepository::normalizeEmail((string) $r->input('email', ''));
        $error = null;
        if ($patient['user_id'] !== null) {
            $error = 'Este paciente já possui acesso.';
        } elseif (Age::years((string) $patient['birth_date'], date('Y-m-d')) < 18) {
            $error = 'O acesso próprio só pode ser criado para pacientes com 18 anos ou mais.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $error = 'Informe um e-mail válido.';
        } elseif (Container::users()->findByEmail($email) !== null) {
            $error = 'Já existe um usuário com este e-mail.';
        }
        if ($error !== null) {
            Session::flash('erro', $error);
            return Response::redirect('/pacientes/' . $id);
        }
        $users = Container::users();
        Container::db()->transaction(function () use ($users, $patient, $email, $actor, $id, $r): void {
            $uid = $users->create([
                'name' => $patient['social_name'] ?: $patient['full_name'],
                'email' => $email, 'role' => 'paciente', 'password' => null, 'active' => 1,
            ]);
            Container::db()->run('UPDATE patients SET user_id = ? WHERE id = ?', [$uid, $id]);
            Container::audit()->record((int) $actor['id'], 'create', 'users', $uid, $id, null,
                ['email' => $email, 'role' => 'paciente'], $r);
            FamilyInvites::send($users->find($uid));
        });
        Session::flash('ok', 'Acesso criado. Convite enviado por e-mail.');
        return Response::redirect('/pacientes/' . $id);
    }

    public function showConsent(Request $r): Response
    {
        $user = $r->attributes['user'];
        $pending = Container::consents()->pendingFor($user);
        if ($pending === []) {
            return Response::redirect('/');
        }
        return $this->page('Patients/views/consent.php', 'Termo de consentimento', $r, ['pending' => $pending, 'erro' => null]);
    }

    public function acceptConsent(Request $r): Response
    {
        $user = $r->attributes['user'];
        $version = (string) $r->input('term_version', '');
        $pidRaw = (string) $r->input('patient_id', '');
        $pid = $pidRaw === '' ? null : (int) $pidRaw;
        if ((string) $r->input('aceito', '') === '') {
            Session::flash('erro', 'Marque "Li e aceito" para continuar.');
            return Response::redirect('/consentimento');
        }
        try {
            Container::consents()->accept((int) $user['id'], $pid, $version, $r);
        } catch (\InvalidArgumentException) {
            Session::flash('erro', 'Este consentimento não está pendente.');
            return Response::redirect('/consentimento');
        }
        return Response::redirect(Container::consents()->pendingFor($user) === [] ? '/' : '/consentimento');
    }

    // ---- helpers ----

    private function authorizeEdit(Request $r, ?int $patientId): array
    {
        $user = $r->attributes['user'];
        Container::gate()->authorize($user, 'edit', Policy::CADASTRO, $patientId, $r);
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

    /** @return array{0:array,1:string[]} */
    private function validate(Request $r): array
    {
        $errors = [];
        $name = trim((string) $r->input('nome', ''));
        $social = trim((string) $r->input('nome_social', ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Informe o nome completo (até 150 caracteres).';
        }
        if (mb_strlen($social) > 150) {
            $errors[] = 'O nome social deve ter até 150 caracteres.';
        }

        $birth = null;
        try {
            $birth = Dates::parseBr((string) $r->input('data_nascimento', ''));
            $today = date('Y-m-d');
            if ($birth > $today) {
                $errors[] = 'Data de nascimento não pode ser futura.';
            } elseif (Age::years($birth, $today) > 120) {
                $errors[] = 'Data de nascimento inválida (mais de 120 anos).';
            }
        } catch (\InvalidArgumentException) {
            $errors[] = 'Informe a data de nascimento no formato dd/mm/aaaa.';
        }

        $sex = (string) $r->input('sexo', '');
        if (!in_array($sex, ['M', 'F'], true)) {
            $errors[] = 'Selecione o sexo biológico.';
        }

        $cpfRaw = trim((string) $r->input('cpf', ''));
        $cpf = null;
        if ($cpfRaw !== '') {
            $digits = preg_replace('/\D+/', '', $cpfRaw) ?? '';
            if (!self::validCpf($digits)) {
                $errors[] = 'CPF inválido.';
            } else {
                $cpf = substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
            }
        }

        $cnsRaw = trim((string) $r->input('cns', ''));
        $cns = null;
        if ($cnsRaw !== '') {
            $cns = preg_replace('/\D+/', '', $cnsRaw) ?? '';
            if (strlen($cns) !== 15) {
                $errors[] = 'O CNS deve ter 15 dígitos.';
            }
        }

        $phone = trim((string) $r->input('telefone', ''));
        if (mb_strlen($phone) > 30) {
            $errors[] = 'O telefone deve ter até 30 caracteres.';
        }

        $email = UserRepository::normalizeEmail((string) $r->input('email', ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            $errors[] = 'Informe um e-mail válido.';
        }

        $address = trim((string) $r->input('endereco', ''));
        $notes = trim((string) $r->input('observacoes', ''));

        $profRaw = (string) $r->input('profissional', '');
        $prof = $profRaw === '' ? $this->defaultProfessional() : (int) $profRaw;
        if ($prof === null || Container::db()->one(
            "SELECT 1 FROM users WHERE id = ? AND role = 'medico' AND active = 1",
            [$prof]
        ) === null) {
            $errors[] = 'Selecione um profissional responsável (médico ativo).';
        }

        return [[
            'full_name' => $name,
            'social_name' => $social === '' ? null : $social,
            'birth_date' => $birth,
            'sex' => $sex,
            'cpf' => $cpf,
            'cns' => $cns,
            'phone' => $phone,
            'email' => $email === '' ? null : $email,
            'address' => $address === '' ? null : $address,
            'admin_notes' => $notes === '' ? null : $notes,
            'responsible_professional_id' => $prof,
        ], $errors];
    }

    private function defaultProfessional(): ?int
    {
        $row = Container::db()->one(
            "SELECT id FROM users WHERE role = 'medico' AND active = 1 ORDER BY is_admin DESC, id LIMIT 1"
        );
        return $row === null ? null : (int) $row['id'];
    }

    public static function validCpf(string $d): bool
    {
        if (!preg_match('/^\d{11}$/', $d) || preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * ($t + 1 - $i);
            }
            if ((int) $d[$t] !== (($sum * 10) % 11) % 10) {
                return false;
            }
        }
        return true;
    }

    private static function blank(): array
    {
        return [
            'nome' => '', 'nome_social' => '', 'data_nascimento' => '', 'sexo' => '', 'cpf' => '', 'cns' => '',
            'telefone' => '', 'email' => '', 'endereco' => '', 'observacoes' => '', 'profissional' => '',
        ];
    }

    private function fromRow(array $p): array
    {
        return [
            'nome' => $p['full_name'], 'nome_social' => (string) $p['social_name'],
            'data_nascimento' => fmt_date($p['birth_date']), 'sexo' => $p['sex'], 'cpf' => (string) $p['cpf'],
            'cns' => (string) $p['cns'], 'telefone' => $p['phone'], 'email' => (string) $p['email'],
            'endereco' => (string) $p['address'], 'observacoes' => (string) $p['admin_notes'],
            'profissional' => (string) $p['responsible_professional_id'],
        ];
    }

    private function posted(Request $r): array
    {
        $out = [];
        foreach (array_keys(self::blank()) as $k) {
            $out[$k] = (string) $r->input($k, '');
        }
        return $out;
    }

    private function form(Request $r, ?array $patient, array $values, array $errors): Response
    {
        $title = $patient === null ? 'Novo paciente' : 'Editar paciente';
        return $this->page('Patients/views/form.php', $title, $r, [
            'patient' => $patient, 'values' => $values, 'errors' => $errors,
            'doctors' => Container::db()->all("SELECT id, name FROM users WHERE role = 'medico' AND active = 1 ORDER BY name"),
            'defaultDoctor' => $this->defaultProfessional(),
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
