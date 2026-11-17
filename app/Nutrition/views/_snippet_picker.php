<?php
/**
 * "Inserir trecho": botao + caixa (preenchida por snippet-picker.js). Insere no cursor do campo escolhido.
 * @var array<string,string> $snippetTargets id do textarea => rotulo
 */
?>
<p class="barra-editor">
  <button type="button" class="btn btn-quiet" id="trechos-abrir" aria-expanded="false" aria-controls="trechos-box">Inserir trecho</button>
</p>
<p id="trechos-box" class="barra-editor" data-snippets-url="<?= e(url('/trechos.json')) ?>" hidden>
  <label for="trechos-select">Trecho</label>
  <select id="trechos-select"></select>
  <label for="trechos-alvo">em</label>
  <select id="trechos-alvo"<?= count($snippetTargets) === 1 ? ' hidden' : '' ?>>
    <?php foreach ($snippetTargets as $id => $label): ?><option value="<?= e($id) ?>"><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <button type="button" class="btn btn-quiet" id="trechos-inserir">Inserir no cursor</button>
  <span id="trechos-msg" class="ajuda" role="status"></span>
  <a href="<?= e(url('/trechos')) ?>">Gerenciar trechos</a>
</p>
