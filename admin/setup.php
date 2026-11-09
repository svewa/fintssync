<?php
/* Copyright (C) 2026 Cline
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/fintssync/admin/setup.php
 * \ingroup fintssync
 * \brief   FintsSync setup page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once '../lib/fintssync.lib.php';
require_once '../class/fintssyncclient.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'fintssync@fintssync'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Text parameters. The *_PASSWORD constant is encrypted by dolibarr_set_const().
$params = array('FINTSSYNC_URL', 'FINTSSYNC_BLZ', 'FINTSSYNC_USER', 'FINTSSYNC_PRODUCT_ID', 'FINTSSYNC_TANMODE', 'FINTSSYNC_TANMEDIUM', 'FINTSSYNC_DAYS', 'FINTSSYNC_IGNORE_IBANS', 'FINTSSYNC_PAYPAL_CLIENTID', 'FINTSSYNC_DEFAULT_MODE', 'FINTSSYNC_BANKACCOUNT');

/*
 * Actions
 */
$tanmodes = array();
$accounts = array();
if ($action == 'update') {
	foreach ($params as $key) {
		dolibarr_set_const($db, $key, trim(GETPOST($key, 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	}
	if (GETPOST('FINTSSYNC_PAYPAL_SECRET', 'none') !== '') {
		dolibarr_set_const($db, 'FINTSSYNC_PAYPAL_SECRET', GETPOST('FINTSSYNC_PAYPAL_SECRET', 'none'), 'chaine', 0, '', $conf->entity);
	}
	if (GETPOST('FINTSSYNC_PASSWORD', 'none') !== '') {
		dolibarr_set_const($db, 'FINTSSYNC_PASSWORD', GETPOST('FINTSSYNC_PASSWORD', 'none'), 'chaine', 0, '', $conf->entity);
	}
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'tanmodes') {
	try {
		$client = new FintsSyncClient($db);
		$tanmodes = $client->listTanModes();
	} catch (\Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
}
if ($action == 'accounts') {
	try {
		$client = new FintsSyncClient($db);
		$accounts = $client->listAccounts();
	} catch (\Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
}
if ($action == 'clearstate') {
	$client = new FintsSyncClient($db);
	$client->clearState();
	setEventMessages($langs->trans('FintsSyncStateCleared'), null, 'mesgs');
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $langs->trans('FintsSyncSetup'));

print load_fiche_titre($langs->trans('FintsSyncSetup'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');
print dol_get_fiche_head(fintssyncAdminPrepareHead(), 'settings', $langs->trans('FintsSyncSetup'), -1, 'bank');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

$rows = array(
	'FINTSSYNC_URL' => 'FintsSyncUrl',
	'FINTSSYNC_BLZ' => 'FintsSyncBlz',
	'FINTSSYNC_USER' => 'FintsSyncUser',
	'FINTSSYNC_PRODUCT_ID' => 'FintsSyncProductId',
	'FINTSSYNC_TANMODE' => 'FintsSyncTanMode',
	'FINTSSYNC_TANMEDIUM' => 'FintsSyncTanMedium',
	'FINTSSYNC_DAYS' => 'FintsSyncDays',
	'FINTSSYNC_IGNORE_IBANS' => 'FintsSyncIgnoreIbans',
	'FINTSSYNC_PAYPAL_CLIENTID' => 'FintsSyncPaypalClientId',
);
foreach ($rows as $key => $label) {
	print '<tr class="oddeven"><td>'.$langs->trans($label).'</td><td>';
	print '<input type="text" class="minwidth400" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'">';
	print '</td></tr>';
}

print '<tr class="oddeven"><td>'.$langs->trans('FintsSyncPin').'</td><td>';
print '<input type="password" class="minwidth200" name="FINTSSYNC_PASSWORD" value="" autocomplete="new-password" placeholder="'.(getDolGlobalString('FINTSSYNC_PASSWORD') !== '' ? '********' : '').'">';
print ' <span class="opacitymedium">'.$langs->trans('FintsSyncPinHelp').'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('FintsSyncPaypalSecret').'</td><td>';
print '<input type="password" class="minwidth200" name="FINTSSYNC_PAYPAL_SECRET" value="" autocomplete="new-password" placeholder="'.(getDolGlobalString('FINTSSYNC_PAYPAL_SECRET') !== '' ? '********' : '').'">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('FintsSyncDefaultMode').'</td><td>';
print $form->selectarray('FINTSSYNC_DEFAULT_MODE', array('review' => $langs->trans('FintsSyncModeReview'), 'auto' => $langs->trans('FintsSyncModeAuto')), getDolGlobalString('FINTSSYNC_DEFAULT_MODE', 'review'), 0);
print '</td></tr>';

if (isModEnabled('bank')) {
	print '<tr class="oddeven"><td>'.$langs->trans('FintsSyncBankAccount').'</td><td>';
	print $form->select_comptes(getDolGlobalInt('FINTSSYNC_BANKACCOUNT'), 'FINTSSYNC_BANKACCOUNT', 0, '', 1);
	print ' <span class="opacitymedium">'.$langs->trans('FintsSyncBankAccountHelp').'</span></td></tr>';
}
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br><div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=accounts&token='.newToken().'">'.$langs->trans('FintsSyncListAccounts').'</a>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=tanmodes&token='.newToken().'">'.$langs->trans('FintsSyncListTanModes').'</a>';
if ((new FintsSyncClient($db))->hasPending()) {
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=clearstate&token='.newToken().'">'.$langs->trans('FintsSyncClearPending').'</a>';
}
print '</div>';

if (!empty($accounts)) {
	print '<table class="noborder"><tr class="liste_titre"><td>IBAN</td><td>'.$langs->trans('Label').'</td></tr>';
	foreach ($accounts as $iban => $label) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($iban).(in_array($iban, FintsSyncClient::ignoredIbans()) ? ' <span class="opacitymedium">('.$langs->trans('FintsSyncIgnored').')</span>' : '').'</td><td>'.dol_escape_htmltag($label).'</td></tr>';
	}
	print '</table><br>';
}

if (!empty($tanmodes)) {
	print '<table class="noborder"><tr class="liste_titre"><td>ID</td><td>'.$langs->trans('Label').'</td></tr>';
	foreach ($tanmodes as $id => $name) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($id).'</td><td>'.dol_escape_htmltag($name).'</td></tr>';
	}
	print '</table>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
