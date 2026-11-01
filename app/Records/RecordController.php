<?php
declare(strict_types=1);

namespace App\Records;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Support\Dates;
use App\Support\Num;

final class RecordController
{
    public const KINDS = [
        'historico_familiar' => 'Histórico familiar',
        'gestacional' => 'Histórico gestacional e neonatal',
        'antecedentes' => 'Antecedentes',
        'alergias' => 'Alergias',
        'comorbidades' => 'Comorbidades',
        'medicamentos_em_uso' => 'Medicamentos em uso',
        'tratamentos_anteriores' => 'Tratamentos anteriores',
        'evolucao' => 'Evolução clínica',
    ];
    public const TEXT_KINDS = ['antecedentes', 'alergias', 'comorbidades', 'medicamentos_em_uso', 'tratamentos_anteriores'];
    public const CONDITIONS = [
        'obesidade', 'diabetes tipo 2', 'hipertensão', 'dislipidemia',
        'doença cardiovascular precoce', 'apneia do sono', 'transtorno alimentar',
    ];
    public const KINSHIP = ['pai', 'mãe', 'avô/avó paterno(a)', 'avô/avó materno(a)', 'irmão(ã)', 'outro'];
    public const EVOLUTION_FIELDS = [
        'anamnese' => 'Anamnese', 'exame_fisico' => 'Exame físico', 'diagnostico' => 'Diagnóstico',
        'conduta' => 'Conduta', 'plano_terapeutico' => 'Plano terapêutico', 'encaminhamentos' => 'Encaminhamentos',
    ];
    private const CID_REGEX = '/^[A-Z][0-9]{2}(\.[0-9]{1,2})?$/';

    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        $pid = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::CLINICO, $pid, $r);
        Container::gate()->authorize($user, 'view', Policy::HISTORICO, $pid, $r);
        $patient = $this->patient($pid);
        Container::audit()->record((int) $user['id'], 'view', 'clinical_entries', null, $pid, null, ['section' => 'prontuario'], $r);
        $repo = Container::clinicalEntries();
        $pediatric = Container::patients()->isPediatric($patient, date('Y-m-d'));
        $history = [];
        foreach (ClinicalEntryRepository::HISTORY_KINDS as $kind) {
            if ($kind === 'gestacional' && !$pediatric) {
                continue;
            }
            $history[$kind] = $repo->currentByKind($pid, $kind);
        }
        return $this->page('Records/views/index.php', 'Prontuário', $r, [
            'patient' => $patient, 'history' => $history, 'evolutions' => $repo->evolutions($pid),
            'canEdit' => Container::gate()->check($user, 'edit', Policy::CLINICO, $pid),
        ]);
    }

    public function form(Request $r): Response
    {
        $user = $r->attributes['user'];
        $pid = (int) $r->params['id'];
        $kind = $this->kind((string) $r->params['kind']);
        Container::gate()->authorize($user, 'view', $this->section($kind), $pid, $r);
        $patient = $this->patient($pid);
        $this->guardKind($patient, $kind);
        Container::audit()->record((int) $user['id'], 'view', 'clinical_entries', null, $pid, null, ['section' => 'form', 'kind' => $kind], $r);
        Container::gate()->authorize($user, 'edit', $this->section($kind), $pid, $r);
        return $this->renderForm($r, $patient, $kind, $this->emptyValues($kind), [], null);
    }

    public function save(Request $r): Response
    {
        $user = $r->attributes['user'];
        $pid = (int) $r->params['id'];
        $kind = $this->kind((string) $r->params['kind']);
        Container::gate()->authorize($user, 'edit', $this->section($kind), $pid, $r);
        $patient = $this->patient($pid);
        $this->guardKind($patient, $kind);
        $values = $this->valuesFromPost($kind, $r->post);
        [$payload, $cid, $date, $errors] = $this->build($kind, $values);
        if ($errors !== []) {
            return $this->renderForm($r, $patient, $kind, $values, $errors, null);
        }
        Container::clinicalEntries()->add($pid, $kind, $payload, $cid, $date, (int) $user['id'], $r);
        Session::flash('ok', 'Registro salvo no prontuário.');
        return Response::redirect('/pacientes/' . $pid . '/prontuario');
    }

    public function reviseForm(Request $r): Response
    {
        $user = $r->attributes['user'];
        $entry = $this->entry((int) $r->params['entryId']);
        $pid = (int) $entry['patient_id'];
        $section = $this->section($entry['kind']);
        Container::gate()->authorize($user, 'view', $section, $pid, $r);
        Container::gate()->authorize($user, 'edit', $section, $pid, $r);
        Container::audit()->record((int) $user['id'], 'view', 'clinical_entries', (int) $entry['id'], $pid, null, ['section' => 'revisar'], $r);
        return $this->renderForm($r, $this->patient($pid), $entry['kind'], $this->valuesFromEntry($entry), [], $entry);
    }

    public function revise(Request $r): Response
    {
        $user = $r->attributes['user'];
        $entry = $this->entry((int) $r->params['entryId']);
        $pid = (int) $entry['patient_id'];
        Container::gate()->authorize($user, 'edit', $this->section($entry['kind']), $pid, $r);
        $values = $this->valuesFromPost($entry['kind'], $r->post);
        [$payload, $cid, $date, $errors] = $this->build($entry['kind'], $values, $entry['encounter_date'] ?? null);
        if ($errors !== []) {
            return $this->renderForm($r, $this->patient($pid), $entry['kind'], $values, $errors, $entry);
        }
        try {
            Container::clinicalEntries()->revise((int) $entry['id'], $payload, $cid, (int) $user['id'], $r, $entry['kind'] === 'evolucao' ? $date : null);
            Session::flash('ok', 'Correção registrada como nova versão. A versão anterior foi mantida.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/pacientes/' . $pid . '/prontuario');
    }

    public function remove(Request $r): Response
    {
        $user = $r->attributes['user'];
        $entry = $this->entry((int) $r->params['entryId']);
        $pid = (int) $entry['patient_id'];
        Container::gate()->authorize($user, 'edit', $this->section($entry['kind']), $pid, $r);
        try {
            Container::clinicalEntries()->remove((int) $entry['id'], (string) $r->input('motivo', ''), (int) $user['id'], $r);
            Session::flash('ok', 'Registro removido. Ele foi mantido no histórico de versões.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/pacientes/' . $pid . '/prontuario');
    }

    public function versions(Request $r): Response
    {
        $user = $r->attributes['user'];
        $entry = $this->entry((int) $r->params['entryId']);
        $pid = (int) $entry['patient_id'];
        Container::gate()->authorize($user, 'view', $this->section($entry['kind']), $pid, $r);
        Container::audit()->record((int) $user['id'], 'view', 'clinical_entries', (int) $entry['id'], $pid, null, ['section' => 'versoes'], $r);
        return $this->page('Records/views/versions.php', 'Versões do registro', $r, [
            'patient' => $this->patient($pid), 'entry' => $entry,
            'versions' => Container::clinicalEntries()->versions((int) $entry['id']),
        ]);
    }

    // ---- validacao / montagem do payload ----

    private function emptyValues(string $kind): array
    {
        return $this->valuesFromPost($kind, $kind === 'evolucao' ? ['encounter_date' => date('d/m/Y')] : []);
    }

    /** Valores brutos (strings) vindos do formulario, apenas campos da lista branca. */
    private function valuesFromPost(string $kind, array $post): array
    {
        $s = static fn (string $k): string => trim((string) ($post[$k] ?? ''));
        if ($kind === 'historico_familiar') {
            $cond = [];
            $par = [];
            foreach (self::CONDITIONS as $c) {
                $cond[$c] = !empty($post['cond'][$c]);
                $given = (array) ($post['parentesco'][$c] ?? []);
                $par[$c] = array_values(array_filter(self::KINSHIP, static fn ($k) => in_array($k, $given, true)));
            }
            return ['cond' => $cond, 'parentesco' => $par, 'observacoes' => $s('observacoes')];
        }
        if ($kind === 'gestacional') {
            return [
                'peso_nascer_g' => $s('peso_nascer_g'), 'comprimento_nascer_cm' => $s('comprimento_nascer_cm'),
                'idade_gestacional_semanas' => $s('idade_gestacional_semanas'), 'tipo_parto' => $s('tipo_parto'),
                'aleitamento_materno_meses' => $s('aleitamento_materno_meses'),
                'diabetes_gestacional' => !empty($post['diabetes_gestacional']) ? '1' : '',
                'intercorrencias' => $s('intercorrencias'),
            ];
        }
        if ($kind === 'evolucao') {
            $v = [];
            foreach (array_keys(self::EVOLUTION_FIELDS) as $f) {
                $v[$f] = $s($f);
            }
            return $v + ['cid10' => $s('cid10'), 'encounter_date' => $s('encounter_date')];
        }
        return ['texto' => $s('texto')];
    }

    private function valuesFromEntry(array $entry): array
    {
        $p = json_decode((string) $entry['payload'], true) ?: [];
        $kind = $entry['kind'];
        if ($kind === 'historico_familiar') {
            $post = ['observacoes' => $p['observacoes'] ?? '', 'cond' => [], 'parentesco' => []];
            foreach ((array) ($p['condicoes'] ?? []) as $row) {
                $post['cond'][$row['condicao']] = '1';
                if (($row['parentesco'] ?? null) !== null) {
                    $post['parentesco'][$row['condicao']][] = $row['parentesco'];
                }
            }
            return $this->valuesFromPost($kind, $post);
        }
        if ($kind === 'gestacional') {
            $c = $p['comprimento_nascer_cm'] ?? null;
            return $this->valuesFromPost($kind, [
                'peso_nascer_g' => (string) ($p['peso_nascer_g'] ?? ''),
                'comprimento_nascer_cm' => $c === null ? '' : fmt_num((float) $c, 1),
                'idade_gestacional_semanas' => (string) ($p['idade_gestacional_semanas'] ?? ''),
                'tipo_parto' => (string) ($p['tipo_parto'] ?? ''),
                'aleitamento_materno_meses' => (string) ($p['aleitamento_materno_meses'] ?? ''),
                'diabetes_gestacional' => !empty($p['diabetes_gestacional']) ? '1' : '',
                'intercorrencias' => (string) ($p['intercorrencias'] ?? ''),
            ]);
        }
        if ($kind === 'evolucao') {
            return $this->valuesFromPost($kind, $p + [
                'cid10' => (string) $entry['cid10'], 'encounter_date' => fmt_date($entry['encounter_date']),
            ]);
        }
        return $this->valuesFromPost($kind, ['texto' => $p['texto'] ?? '']);
    }

    /**
     * @param ?string $blankDate data (Y-m-d) usada quando encounter_date vem em branco (revisao); null = hoje
     * @return array{0:array,1:?string,2:?string,3:string[]} payload, cid10, encounter_date (Y-m-d), erros
     */
    private function build(string $kind, array $v, ?string $blankDate = null): array
    {
        $errors = [];
        $cid = null;
        $date = null;
        if ($kind === 'historico_familiar') {
            $list = [];
            foreach (self::CONDITIONS as $c) {
                if (!$v['cond'][$c]) {
                    continue;
                }
                if ($v['parentesco'][$c] === []) {
                    $list[] = ['condicao' => $c, 'parentesco' => null];
                }
                foreach ($v['parentesco'][$c] as $k) {
                    $list[] = ['condicao' => $c, 'parentesco' => $k];
                }
            }
            $payload = ['condicoes' => $list, 'observacoes' => $v['observacoes']];
        } elseif ($kind === 'gestacional') {
            $payload = [
                'peso_nascer_g' => $this->intIn($v['peso_nascer_g'], 300, 6000, 'Peso ao nascer deve estar entre 300 e 6000 g (número inteiro).', $errors),
                'comprimento_nascer_cm' => $this->numIn($v['comprimento_nascer_cm'], 20, 65, 'Comprimento ao nascer deve estar entre 20 e 65 cm.', $errors),
                'idade_gestacional_semanas' => $this->intIn($v['idade_gestacional_semanas'], 22, 44, 'Idade gestacional deve estar entre 22 e 44 semanas.', $errors),
                'tipo_parto' => in_array($v['tipo_parto'], ['normal', 'cesárea'], true) ? $v['tipo_parto'] : null,
                'aleitamento_materno_meses' => $this->intIn($v['aleitamento_materno_meses'], 0, 60, 'Aleitamento materno deve estar entre 0 e 60 meses.', $errors),
                'diabetes_gestacional' => $v['diabetes_gestacional'] === '1',
                'intercorrencias' => $v['intercorrencias'],
            ];
            if ($v['tipo_parto'] !== '' && $payload['tipo_parto'] === null) {
                $errors[] = 'Tipo de parto inválido.';
            }
        } elseif ($kind === 'evolucao') {
            $payload = [];
            foreach (array_keys(self::EVOLUTION_FIELDS) as $f) {
                $payload[$f] = $v[$f];
            }
            if (implode('', $payload) === '') {
                $errors[] = 'Preencha ao menos um campo da evolução.';
            }
            $cid = strtoupper($v['cid10']);
            if ($cid === '') {
                $cid = null;
            } elseif (!preg_match(self::CID_REGEX, $cid)) {
                $errors[] = 'CID-10 inválido (ex.: E66.0)';
            }
            if ($v['encounter_date'] === '') {
                // Na correcao, data em branco mantem a data original do atendimento; na criacao, hoje.
                $date = $blankDate ?? date('Y-m-d');
            } else {
                try {
                    $date = Dates::parseBr($v['encounter_date']);
                } catch (\InvalidArgumentException) {
                    $date = null;
                }
                if ($date === null || $date > date('Y-m-d')) {
                    $errors[] = 'Data do atendimento inválida.';
                    $date = null;
                }
            }
        } else {
            $payload = ['texto' => $v['texto']];
            if ($v['texto'] === '') {
                $errors[] = 'Preencha o texto do registro.';
            }
        }
        foreach ($payload as $val) {
            if (is_string($val) && mb_strlen($val) > 20000) {
                $errors[] = 'Texto muito longo (máximo 20.000 caracteres por campo).';
                break;
            }
        }
        return [$payload, $cid, $date, $errors];
    }

    private function numIn(string $raw, float $min, float $max, string $msg, array &$errors): ?float
    {
        if ($raw === '') {
            return null;
        }
        try {
            $n = Num::parse($raw);
        } catch (\InvalidArgumentException) {
            $n = null;
        }
        if ($n === null || $n < $min || $n > $max) {
            $errors[] = $msg;
            return null;
        }
        return round($n, 1);
    }

    private function intIn(string $raw, int $min, int $max, string $msg, array &$errors): ?int
    {
        $n = $this->numIn($raw, $min, $max, $msg, $errors);
        if ($n === null) {
            return null;
        }
        if ($n != floor($n)) {
            $errors[] = $msg;
            return null;
        }
        return (int) $n;
    }

    // ---- helpers ----

    private function renderForm(Request $r, array $patient, string $kind, array $values, array $errors, ?array $entry): Response
    {
        $tpl = $kind === 'evolucao' ? 'Records/views/evolution_form.php' : 'Records/views/history_form.php';
        $action = $entry === null
            ? '/pacientes/' . (int) $patient['id'] . '/prontuario/' . $kind
            : '/prontuario/' . (int) $entry['id'] . '/revisar';
        return $this->page($tpl, self::KINDS[$kind], $r, [
            'patient' => $patient, 'kind' => $kind, 'label' => self::KINDS[$kind], 'values' => $values,
            'errors' => $errors, 'entry' => $entry, 'action' => $action,
        ]);
    }

    private function kind(string $kind): string
    {
        if (!isset(self::KINDS[$kind])) {
            throw new HttpException(404);
        }
        return $kind;
    }

    private function section(string $kind): string
    {
        return $kind === 'evolucao' ? Policy::CLINICO : Policy::HISTORICO;
    }

    private function guardKind(array $patient, string $kind): void
    {
        if ($kind === 'gestacional' && !Container::patients()->isPediatric($patient, date('Y-m-d'))) {
            throw new HttpException(404);
        }
    }

    private function entry(int $id): array
    {
        $e = Container::clinicalEntries()->find($id);
        if ($e === null) {
            throw new HttpException(404);
        }
        return $e;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        $user = $r->attributes['user'];
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'));
    }
}
