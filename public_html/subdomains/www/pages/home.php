<?php
ob_start();
$scriptsLib = '../scripts';
require_once $scriptsLib.'/gen/popup.js';
ob_end_clean();
?>

<p>HOME.PHP</p>

<?= getPopupDiv()['html']; ?>

<script>
<?= getPopupDiv()['js']; ?>

<?php if (!isset($_COOKIE['sid'])): ?>
popupDiv.insertAdjacentHTML('beforeend',`<?= getConnexionForm()['html']; ?>`);
popupDiv.openTo('#connexionForm');
<?= getConnexionForm()['js']; ?>

connexionForm.openTo('#connexionForm_connect');
<?php endif; ?>
</script>