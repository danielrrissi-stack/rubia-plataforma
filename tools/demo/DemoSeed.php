<?php
declare(strict_types=1);

namespace Tools\Demo;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;

/**
 * Clinica ficticia para as fotos do manual. SO na maquina de desenvolvimento, no banco local rubia_demo
 * (tools/demo/seed.php chama assertSafeTarget antes). Todos os nomes sao inventados.
 * Senha e segredo do codigo de seguranca abaixo sao de DEMONSTRACAO, usados so pelo roteiro de fotos local.
 */
final class DemoSeed
{
    public const DB_NAME = 'rubia_demo';

    /** Login de cada conta ficticia (e-mail; a crianca usa nome de usuario). */
    public const LOGINS = [
        'medico' => 'medica@demo.test',
        'nutricionista' => 'nutricionista@demo.test',
        'secretaria' => 'secretaria@demo.test',
        'responsavel' => 'responsavel@demo.test',
        'paciente' => 'paciente@demo.test',
        'crianca' => 'demo.lia',
        'novo' => 'nova.equipe@demo.test',
    ];

    public const PASSWORD = 'Demo-Manual-2026';
    private const CARD_TITLE = 'Água é a melhor bebida';

    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** Tabelas de referencia carregadas pelo migrate (OMS, PA, TACO/POF, exames, metas e cartoes prontos): ficam. */
    private const KEEP = ['schema_migrations', 'who_lms', 'bp_reference', 'foods', 'food_measures', 'lab_tests', 'lab_ranges', 'goal_templates', 'edu_cards'];

    public static function assertSafeTarget(array $db): void
    {
        $host = (string) ($db['host'] ?? '');
        if (!in_array($host, ['localhost', '127.0.0.1'], true) || ($db['name'] ?? '') !== self::DB_NAME) {
            throw new \RuntimeException('Recusado: a clinica ficticia so pode ser criada no banco local ' . self::DB_NAME . ' (host localhost).');
        }
    }

    /** Apaga os dados de aplicacao e monta a clinica. Datas relativas a $today. @return array<string,int|string> ids */
    public static function run(Database $db, string $today): array
    {
        return (new self($db, $today))->build();
    }

    private Request $r;
    /** @var array<string,array> */
    private array $u = [];

    private function __construct(private Database $db, private string $today)
    {
        $this->r = new Request('POST', '/demo');
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable($this->today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    private function age(int $years, int $months = 0): string
    {
        return (new \DateTimeImmutable($this->today))->modify('-' . $years . ' years -' . $months . ' months')->format('Y-m-d');
    }

    private function build(): array
    {
        $this->wipe();
        Container::reset();
        Container::set('db', $this->db);
        $this->users();
        $this->consentTerm();
        $ids = [];
        $ids['crianca'] = $this->child();
        $ids['adolescente'] = $this->teen();
        $ids['adulto'] = $this->adult();
        $ids['novo_paciente'] = Container::patients()->create([
            'full_name' => 'Helena Modelo Dias', 'sex' => 'F', 'birth_date' => $this->age(7, 4), 'phone' => '(11) 90000-0004',
            'responsible_professional_id' => $this->u['medico']['id'],
        ], (int) $this->u['medico']['id'], $this->r);
        foreach (['responsavel', 'paciente'] as $k) {
            foreach (Container::consents()->pendingFor($this->fresh($k)) as $p) {
                Container::consents()->accept((int) $this->u[$k]['id'], $p['patient_id'], $p['term_version'], $this->r);
            }
        }
        $ids += $this->extras($ids);
        $ids += $this->agenda($ids);
        foreach (['medico', 'nutricionista', 'secretaria', 'responsavel', 'paciente', 'crianca', 'novo'] as $k) {
            $ids['user_' . $k] = (int) $this->u[$k]['id'];
        }
        return $ids;
    }

    private function wipe(): void
    {
        $tables = array_column($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't');
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                if (!in_array($t, self::KEEP, true)) {
                    $this->db->run('DELETE FROM `' . $t . '`');
                }
            }
            // tabelas de referencia que ficam: solta as referencias as contas apagadas e tira o cartao da demonstracao
            $byCols = $this->db->all("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name LIKE '%\_by'");
            foreach ($byCols as $col) {
                if (in_array($col['t'], self::KEEP, true)) {
                    $this->db->run('UPDATE `' . $col['t'] . '` SET `' . $col['c'] . '` = NULL');
                }
            }
            $this->db->run('DELETE FROM edu_cards WHERE title = ?', [self::CARD_TITLE]);
        } finally {
            $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function users(): void
    {
        $staff = ['totp_enabled' => 1, 'totp_secret' => self::TOTP_SECRET];
        $mk = function (string $key, array $row): void {
            $id = Container::users()->create($row + ['password' => self::PASSWORD, 'active' => 1]);
            $this->u[$key] = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        };
        $mk('medico', ['name' => 'Dra. Ana Exemplo', 'email' => self::LOGINS['medico'], 'role' => 'medico', 'is_admin' => 1] + $staff);
        $mk('nutricionista', ['name' => 'Bruna Fictícia Lopes', 'email' => self::LOGINS['nutricionista'], 'role' => 'nutricionista'] + $staff);
        $mk('secretaria', ['name' => 'Cíntia Modelo Rocha', 'email' => self::LOGINS['secretaria'], 'role' => 'secretaria'] + $staff);
        $mk('responsavel', ['name' => 'Carla Exemplo Moraes', 'email' => self::LOGINS['responsavel'], 'role' => 'responsavel']);
        $mk('paciente', ['name' => 'Marcos Fictício Prado', 'email' => self::LOGINS['paciente'], 'role' => 'paciente']);
        $mk('crianca', ['name' => 'Lia', 'username' => self::LOGINS['crianca'], 'email' => null, 'role' => 'crianca']);
        $mk('novo', ['name' => 'Nova Colaboradora Exemplo', 'email' => self::LOGINS['novo'], 'role' => 'secretaria', 'totp_enabled' => 0]);
    }

    private function fresh(string $k): array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$this->u[$k]['id']]);
    }

    private function consentTerm(): void
    {
        $this->db->insert('consent_terms', [
            'version' => '1.0', 'active' => 1,
            'body_html' => '<p>Termo de consentimento de demonstração. Este texto é fictício e existe só para mostrar como o sistema funciona.</p>',
        ]);
    }

    private function doc(): int
    {
        return (int) $this->u['medico']['id'];
    }

    private function measure(int $pid, string $on, array $d, string $source, int $by): void
    {
        Container::measurements()->create($pid, ['measured_on' => $on] + $d, $source, $by, $this->r);
    }

    private function labs(int $pid, string $on, array $values, bool $release): int
    {
        $vals = [];
        foreach ($values as $code => $v) {
            $vals[(int) Container::labCatalog()->findByCode($code)['id']] = $v;
        }
        $id = Container::labs()->create($pid, ['collected_on' => $on], $vals, $this->doc(), $this->r);
        if ($release) {
            Container::labs()->release($id, $this->doc(), $this->r);
        }
        return $id;
    }

    /** Lia, 9 anos, obesidade, acompanhada ha 10 meses (consultorio todo mes + pesagens em casa nas ultimas semanas). */
    private function child(): int
    {
        $doc = $this->doc();
        $pid = Container::patients()->create([
            'full_name' => 'Lia Fictícia Moraes', 'sex' => 'F', 'birth_date' => $this->age(9, 2), 'phone' => '(11) 90000-0001',
            'responsible_professional_id' => $doc,
        ], $doc, $this->r);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$this->u['crianca']['id'], $pid]);
        Container::guardians()->link($pid, (int) $this->u['responsavel']['id'], 'mãe', $doc, $this->r);
        Container::accessGrants()->grant($pid, (int) $this->u['nutricionista']['id'], $doc);
        for ($m = 10; $m >= 0; $m--) {
            $on = $this->day(-$m * 30);
            $this->measure($pid, $on, ['weight_kg' => round(44.0 - (10 - $m) * 0.35, 1), 'height_cm' => round(131.0 + (10 - $m) * 0.5, 1),
                'position' => 'em_pe', 'waist_cm' => round(78 - (10 - $m) * 0.4, 1)], 'equipe', $doc);
        }
        foreach ([-25, -18, -11, -4] as $i => $off) {
            $this->measure($pid, $this->day($off), ['weight_kg' => round(40.9 - $i * 0.15, 1)], 'familia', (int) $this->u['responsavel']['id']);
        }
        Container::clinicalEntries()->add($pid, 'antecedentes', ['texto' => 'Nascida a termo, sem intercorrências. (Dado fictício.)'], null, null, $doc);
        Container::clinicalEntries()->add($pid, 'historico_familiar', ['texto' => 'Mãe e avó materna com obesidade. (Dado fictício.)'], null, null, $doc);
        foreach ([-300 => 'Primeira consulta. Ganho de peso acelerado nos últimos dois anos.', -120 => 'Boa adesão às metas de água e lanches.', -30 => 'Mantém evolução favorável; reduziu tempo de tela.'] as $off => $txt) {
            Container::clinicalEntries()->add($pid, 'evolucao', ['anamnese' => $txt, 'conduta' => 'Manter plano alimentar e metas da semana.', 'diagnostico' => 'Obesidade infantil'], 'E66.0', $this->day($off), $doc);
        }
        $plan = Container::mealPlans()->createDraft($pid, 'texto', [
            'title' => 'Plano da Lia', 'structure' => null,
            'body_text' => "CAFÉ DA MANHÃ\n• 1 copo de leite\n• 1 pão francês com queijo branco\n\nALMOÇO\n• Arroz, feijão, salada e frango grelhado\n\nLANCHE DA TARDE\n• 1 fruta\n\nJANTAR\n• Sopa de legumes",
            'guidance_text' => 'Beber água ao longo do dia. Evitar refrigerante.',
        ], $doc);
        Container::mealPlans()->publish($plan, $doc);
        $this->labs($pid, $this->day(-35), ['glicemia' => '88', 'triglicerides' => '132', 'hdl' => '41'], true);
        $patient = $this->db->one('SELECT * FROM patients WHERE id = ?', [$pid]);
        Container::weightGoals()->create($pid, ['kind' => 'imc_z', 'start_on' => $this->day(-300), 'start_value' => 2.6, 'target_value' => 1.8,
            'target_date' => $this->day(240), 'notes' => null], $patient, $this->today, $doc, $this->r);
        return $pid;
    }

    /** Davi, 15 anos, irmao (mesma responsavel). */
    private function teen(): int
    {
        $doc = $this->doc();
        $pid = Container::patients()->create([
            'full_name' => 'Davi Exemplo Nunes', 'sex' => 'M', 'birth_date' => $this->age(15, 5), 'phone' => '(11) 90000-0002',
            'responsible_professional_id' => $doc,
        ], $doc, $this->r);
        Container::guardians()->link($pid, (int) $this->u['responsavel']['id'], 'mãe', $doc, $this->r);
        Container::accessGrants()->grant($pid, (int) $this->u['nutricionista']['id'], $doc);
        foreach ([-150, -90, -30] as $i => $off) {
            $this->measure($pid, $this->day($off), ['weight_kg' => 88.0 - $i * 1.2, 'height_cm' => 170.0 + $i, 'position' => 'em_pe'], 'equipe', $doc);
        }
        Container::clinicalEntries()->add($pid, 'evolucao', ['anamnese' => 'Adolescente motivado; pratica futebol duas vezes por semana.'], null, $this->day(-30), $doc);
        $rx = Container::prescriptions()->create($pid, [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => $this->day(-60), 'family_logs' => 1,
            'dose' => '500 mg', 'frequency' => '1x ao dia, no jantar', 'instructions' => 'Tomar junto com a refeição.',
        ], $this->u['medico'], $this->day(-60), $this->r);
        foreach ([-2, -1, 0] as $off) {
            Container::medicationLogs()->record($rx, $this->day($off) . ' 19:30', null, $this->fresh('responsavel'), $this->day($off) . ' 20:00', $this->r);
        }
        return $pid;
    }

    /** Marcos, 42 anos, tirzepatida 2,5 -> 5 -> 7,5 mg; perdeu peso e estabilizou nas ultimas 4 semanas. */
    private function adult(): int
    {
        $doc = $this->doc();
        $pac = (int) $this->u['paciente']['id'];
        $pid = Container::patients()->create([
            'full_name' => 'Marcos Fictício Prado', 'sex' => 'M', 'birth_date' => $this->age(42, 1), 'phone' => '(11) 90000-0003',
            'user_id' => $pac, 'responsible_professional_id' => $doc,
        ], $doc, $this->r);
        // Uma pesagem por semana: ~0,8 kg/sem, depois ~0,6, depois ~0,5 e praticamente parado nas ultimas 4 semanas.
        $w = 112.0;
        for ($off = -182; $off <= -2; $off += 7) {
            $rate = $off < -126 ? 0.8 : ($off < -70 ? 0.6 : ($off < -30 ? 0.5 : 0.03));
            $w -= $rate;
            $clinic = ($off + 182) % 28 === 0;
            $d = ['weight_kg' => round($w, 1)];
            if ($clinic) {
                $d += ['height_cm' => 178.0, 'waist_cm' => round(118 - (112 - $w) * 0.8, 1), 'systolic' => 138 - (int) ((112 - $w) / 2), 'diastolic' => 88 - (int) ((112 - $w) / 3)];
            }
            $this->measure($pid, $this->day($off), $d, $clinic ? 'equipe' : 'familia', $clinic ? $doc : $pac);
        }
        $rx = Container::prescriptions()->create($pid, [
            'drug_name' => 'Tirzepatida', 'route' => 'subcutanea', 'start_date' => $this->day(-182),
            'dose' => '2,5 mg', 'frequency' => '1x por semana', 'instructions' => 'Aplicar sempre no mesmo dia da semana.', 'family_logs' => 1,
        ], $this->u['medico'], $this->day(-182), $this->r);
        Container::prescriptions()->changeDose($rx, ['effective_date' => $this->day(-126), 'dose' => '5 mg', 'frequency' => '1x por semana', 'instructions' => '', 'reason' => 'Boa tolerância.'], $doc, $this->day(-126), $this->r);
        Container::prescriptions()->changeDose($rx, ['effective_date' => $this->day(-70), 'dose' => '7,5 mg', 'frequency' => '1x por semana', 'instructions' => '', 'reason' => 'Ritmo de perda diminuiu.'], $doc, $this->day(-70), $this->r);
        Container::prescriptions()->create($pid, [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => $this->day(-182),
            'dose' => '500 mg', 'frequency' => '2x ao dia', 'instructions' => 'Tomar após o almoço e o jantar.',
        ], $this->u['medico'], $this->day(-182), $this->r);
        foreach ([-21, -14, -7, 0] as $off) {
            Container::medicationLogs()->record($rx, $this->day($off) . ' 08:30', null, $this->fresh('paciente'), $this->day($off) . ' 09:00', $this->r);
        }
        Container::adverseEvents()->report($pid, ['occurred_on' => $this->day(-12), 'description' => 'Enjoo leve no dia seguinte à aplicação.'], $this->fresh('paciente'), $this->today, $this->r);
        foreach ([-180, -90, -10] as $i => $off) {
            $this->labs($pid, $this->day($off), [
                'glicemia' => (string) (118 - $i * 10), 'hba1c' => number_format(6.4 - $i * 0.3, 1, ',', ''), 'insulina' => (string) (24 - $i * 5),
                'triglicerides' => (string) (210 - $i * 35), 'hdl' => (string) (36 + $i * 3), 'tgp' => (string) (48 - $i * 8),
            ], $off === -10);
        }
        Container::clinicalEntries()->add($pid, 'comorbidades', ['texto' => 'Pré-diabetes. Hipertensão leve. (Dado fictício.)'], null, null, $doc);
        Container::clinicalEntries()->add($pid, 'evolucao', ['anamnese' => 'Perdeu peso bem nos primeiros meses; nas últimas semanas o peso parou.', 'conduta' => 'Reforçar atividade física; reavaliar dose.'], 'E66.0', $this->day(-10), $doc);
        $plan = Container::mealPlans()->createDraft($pid, 'texto', [
            'title' => 'Plano do Marcos', 'structure' => null,
            'body_text' => "CAFÉ DA MANHÃ\n• 2 ovos mexidos\n• 1 fatia de pão integral\n\nALMOÇO\n• Salada à vontade\n• Arroz integral, feijão e peixe\n\nJANTAR\n• Omelete com legumes",
            'guidance_text' => 'Comer devagar. Água: 2 litros por dia.',
        ], $doc);
        Container::mealPlans()->publish($plan, $doc);
        $walk = Container::goals()->create($pid, ['family_text' => 'Caminhar 30 minutos', 'emoji' => '🚶', 'category' => 'atividade', 'times_per_week' => 5, 'start_date' => $this->day(-14)], $doc, $this->r);
        foreach ([-3, -2, 0] as $off) {
            Container::goals()->check($walk, $this->day($off), $this->fresh('paciente'), $this->day($off), $this->r);
        }
        $patient = $this->db->one('SELECT * FROM patients WHERE id = ?', [$pid]);
        Container::weightGoals()->create($pid, ['kind' => 'peso', 'start_on' => $this->day(-182), 'start_value' => 112.0, 'target_value' => 92.0,
            'target_date' => $this->day(120), 'notes' => 'Meta combinada na consulta.'], $patient, $this->today, $doc, $this->r);
        return $pid;
    }

    /** Metas, diario, cartao, modelos e trechos ligados a Lia. */
    private function extras(array $ids): array
    {
        $doc = $this->doc();
        $lia = $ids['crianca'];
        $out = [];
        $goals = [];
        foreach ([['Beber água em vez de suco', '💧', 'hidratacao', 7], ['Brincar ao ar livre 30 minutos', '⚽', 'atividade', 5], ['Desligar a TV no jantar', '📺', 'telas', 7]] as [$t, $e, $c, $n]) {
            $goals[] = Container::goals()->create($lia, ['family_text' => $t, 'emoji' => $e, 'category' => $c, 'times_per_week' => $n, 'start_date' => $this->day(-20)], $doc, $this->r);
        }
        $out['meta'] = $goals[0];
        $kid = $this->fresh('crianca');
        $resp = $this->fresh('responsavel');
        foreach ([-5, -4, -3, -1, 0] as $off) {
            Container::goals()->check($goals[0], $this->day($off), $kid, $this->day($off), $this->r);
        }
        foreach ([-4, -2, 0] as $off) {
            Container::goals()->check($goals[1], $this->day($off), $resp, $this->day($off), $this->r);
        }
        $out['pedido_diario'] = Container::diaryRequests()->create($lia, ['type' => 'alimentacao', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_date' => $this->day(-10)], $this->u['medico'], $this->day(-10), $this->r);
        $water = Container::diaryRequests()->create($lia, ['type' => 'agua', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_date' => $this->day(-10)], $this->u['medico'], $this->day(-10), $this->r);
        $meals = [-2 => ['almoco', '12:15', 'Arroz, feijão, frango e salada de tomate.'], -1 => ['lanche_tarde', '16:00', 'Uma maçã e um copo de leite.'], 0 => ['cafe_manha', '07:10', 'Pão com queijo branco e leite.']];
        foreach ($meals as $off => [$meal, $time, $desc]) {
            $entry = Container::diaryEntries()->record($out['pedido_diario'], $this->day($off), ['meal' => $meal, 'meal_time' => $time, 'description' => $desc], null, $resp, $this->day($off), $this->r);
            $out['registro_diario'] = $entry;
        }
        foreach ([-2, -1, 0] as $off) {
            Container::diaryEntries()->record($water, $this->day($off), ['glasses' => 5 + $off + 2], null, $resp, $this->day($off), $this->r);
        }
        Container::diaryComments()->add($out['registro_diario'], 'Ótima escolha para o café da manhã!', $this->u['medico'], $this->r);
        $card = Container::eduCards()->save(null, ['title' => self::CARD_TITLE, 'body' => 'A água ajuda o corpo a funcionar bem. Tenha sempre uma garrafinha por perto!', 'emoji' => '💧', 'bands' => ['7-10']], $doc, $this->r);
        Container::eduCards()->publish($card, $doc, $this->r);
        $out['cartao'] = $card;
        $plan = (int) $this->db->one('SELECT id FROM meal_plans WHERE patient_id = ? ORDER BY id DESC LIMIT 1', [$lia])['id'];
        $out['plano'] = $plan;
        $out['modelo'] = Container::mealPlanTemplates()->fromPlan($plan, 'Plano infantil — base', $doc);
        $out['trecho'] = Container::textSnippets()->create('Água ao longo do dia', 'Beber um copo de água ao acordar, no meio da manhã e à tarde.', $doc);
        $out['coleta'] = (int) $this->db->one('SELECT id FROM lab_collections WHERE patient_id = ? ORDER BY collected_on DESC LIMIT 1', [$ids['adulto']])['id'];
        $out['receita'] = (int) $this->db->one("SELECT id FROM prescriptions WHERE patient_id = ? AND drug_name = 'Tirzepatida'", [$ids['adulto']])['id'];
        $out['medida'] = (int) $this->db->one('SELECT id FROM measurements WHERE patient_id = ? ORDER BY measured_on DESC, id DESC LIMIT 1', [$lia])['id'];
        $out['registro_prontuario'] = (int) $this->db->one("SELECT id FROM clinical_entries WHERE patient_id = ? AND kind = 'evolucao' ORDER BY id DESC LIMIT 1", [$lia])['id'];
        return $out;
    }

    /** Horario padrao da clinica para medica e nutricionista; consultas nos proximos dias uteis. */
    private function agenda(array $ids): array
    {
        $doc = $this->doc();
        $nut = (int) $this->u['nutricionista']['id'];
        Container::schedules()->applyClinicDefault($doc, $doc, $this->r);
        Container::schedules()->applyClinicDefault($nut, $doc, $this->r);
        $now = $this->today . ' 00:00';
        $out = ['consulta' => 0, 'dia_agenda' => $this->today];
        $booked = 0;
        for ($off = 0; $off <= 14 && $booked < 4; $off++) {
            $ymd = $this->day($off);
            $slots = Container::schedules()->slotsOn($doc, $ymd);
            if (count($slots) < 4) {
                continue;
            }
            $plan = [[$ids['crianca'], 'retorno'], [$ids['adulto'], 'retorno'], [$ids['novo_paciente'], 'primeira'], [$ids['adolescente'], 'retorno']];
            foreach ($plan as $i => [$pid, $kind]) {
                $id = Container::appointments()->book($doc, $pid, $ymd . ' ' . substr($slots[$i * 2]['start'], 0, 5), $kind, null, $this->u['secretaria'], $now, $this->r);
                $out['consulta'] = $out['consulta'] ?: $id;
                $booked++;
            }
            $out['dia_agenda'] = $ymd;
        }
        return $out;
    }
}
