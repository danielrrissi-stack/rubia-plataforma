<?php
/**
 * Botoes de WhatsApp da consulta. Variaveis: $a (consulta, como AppointmentRepository::find), $today ('Y-m-d').
 * Incluido so para consulta agendada/confirmada com paciente visivel na agenda (nunca em linha riscada nem em
 * "Paciente sem liberacao"). O clique copia o aviso (data-text) e abre o wa.me em nova aba: agenda-whatsapp.js.
 */
$waContacts = \App\Core\Container::reminderText()->contacts($a);
if ($waContacts !== []):
    $waText = \App\Core\Container::reminderText()->render($a, $today); ?>
<span class="agenda-whatsapp">
  <?php foreach ($waContacts as $c): ?>
    <?php if ($c['wa'] !== null): ?>
      <a class="btn btn-quiet btn-whatsapp" href="<?= e('https://wa.me/' . $c['wa'] . '?text=' . rawurlencode($waText)) ?>" target="_blank" rel="noopener" data-text="<?= e($waText) ?>"><?= e($c['label']) ?></a>
    <?php else: ?>
      <span class="ajuda"><?= e($c['label']) ?>: telefone em formato inválido (<?= e($c['raw']) ?>)</span>
    <?php endif; ?>
  <?php endforeach; ?>
</span>
<?php endif;
