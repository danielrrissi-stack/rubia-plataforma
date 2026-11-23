<?php
/**
 * Folha timbrada (so PDF; medidas em mm no papel.css).
 * @var string $title @var string $background @var array<string,string> $icons
 * @var string $doctorName @var string $doctorSize classe pt-cNN @var string $specialty @var list<string> $credentials
 * @var string $phone @var string $phoneSize @var string $address @var string $city @var string $addressSize
 * @var string $patientName @var string $patientSize
 * @var ?array{0:string,1:string,2:string} $date @var ?array{0:string,1:string,2:string} $birth
 */
$d = $date ?? ['', '', ''];
$b = $birth ?? ['', '', ''];
?><img class="pt-fundo" src="<?= e($background) ?>" alt="">
<div class="pt-medica">
  <div class="pt-nome <?= e($doctorSize) ?>"><?= e($doctorName) ?></div>
  <?php if ($specialty !== ''): ?><div class="pt-cred"><?= e($specialty) ?></div><?php endif; ?>
  <?php if ($credentials !== []): ?><div class="pt-cred"><?= implode(' &nbsp;', array_map('e', $credentials)) ?></div><?php endif; ?>
  <table class="pt-contato">
    <?php if ($phone !== ''): ?><tr><td class="pt-icone"><img src="<?= e($icons['whatsapp']) ?>" alt=""></td>
      <td class="pt-fone">WhatsApp: <span class="<?= e($phoneSize) ?>"><?= e($phone) ?></span></td></tr><?php endif; ?>
    <?php if ($address !== '' || $city !== ''): ?><tr><td class="pt-icone"><img src="<?= e($icons['local']) ?>" alt=""></td>
      <td class="<?= e($addressSize) ?>"><?= e($address) ?><?= $address !== '' && $city !== '' ? '<br>' : '' ?><?= e($city) ?></td></tr><?php endif; ?>
    <tr><td class="pt-icone"><img src="<?= e($icons['site']) ?>" alt=""></td>
      <td class="pt-site"><b>Site: <?= e(\App\Letterhead\LetterheadDoc::SITE) ?></b> | Aplicativo: <?= e(\App\Letterhead\LetterheadDoc::APP) ?></td></tr>
  </table>
</div>
<div class="pt-rotulo pt-l1 pt-paciente">Paciente:</div>
<div class="pt-valor pt-l1 pt-paciente-valor <?= e($patientSize) ?>"><?= e($patientName) ?></div>
<div class="pt-linha pt-r1 pt-paciente-linha"></div>
<div class="pt-rotulo pt-l1 pt-data">Data:</div>
<div class="pt-num pt-l1 pt-d1"><?= e($d[0]) ?></div><div class="pt-barra pt-l1 pt-db1">/</div>
<div class="pt-num pt-l1 pt-d2"><?= e($d[1]) ?></div><div class="pt-barra pt-l1 pt-db2">/</div>
<div class="pt-num pt-l1 pt-d3"><?= e($d[2]) ?></div>
<div class="pt-linha pt-r1 pt-d1"></div><div class="pt-linha pt-r1 pt-d2"></div><div class="pt-linha pt-r1 pt-d3"></div>
<div class="pt-rotulo pt-l2 pt-nasc">Data de nascimento:</div>
<div class="pt-num pt-l2 pt-n1"><?= e($b[0]) ?></div><div class="pt-barra pt-l2 pt-nb1">/</div>
<div class="pt-num pt-l2 pt-n2"><?= e($b[1]) ?></div><div class="pt-barra pt-l2 pt-nb2">/</div>
<div class="pt-num pt-l2 pt-n3"><?= e($b[2]) ?></div>
<div class="pt-linha pt-r2 pt-n1"></div><div class="pt-linha pt-r2 pt-n2"></div><div class="pt-linha pt-r2 pt-n3"></div>
<h1 class="pt-titulo"><?= e($title) ?></h1>
