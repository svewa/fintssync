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
 * \file       htdocs/custom/fintssync/customer.php
 * \ingroup    fintssync
 * \brief      Tab on third party: allowed skonto, deadline and booking mode.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'fintssync@fintssync'));

$socid = GETPOSTINT('socid');
$action = GETPOST('action', 'aZ09');

if (!$user->hasRight('fintssync', 'read')) {
	accessforbidden();
}
$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
	accessforbidden();
}
// Standard check of access to this thirdparty
$result = restrictedArea($user, 'societe', $socid, '&societe');

/*
 * Actions
 */
if ($action == 'save' && $user->hasRight('fintssync', 'write')) {
	$percent = price2num(GETPOST('skonto_percent', 'alphanohtml'));
	$days = GETPOSTINT('skonto_days');
	$mode = (GETPOST('mode', 'aZ09') == 'auto' ? 'auto' : 'review');
	if ($percent < 0 || $percent > 100) {
		setEventMessages($langs->trans('ErrorBadValueForParameter', $percent, 'skonto_percent'), null, 'errors');
	} else {
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."fintssync_customer WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $socid));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."fintssync_customer(entity, fk_soc, skonto_percent, skonto_days, mode)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $socid).", ".((float) $percent).", ".((int) $days).", '".$db->escape($mode)."')";
		if ($db->query($sql)) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}
}

$setting = array('skonto_percent' => 0, 'skonto_days' => 0, 'mode' => getDolGlobalString('FINTSSYNC_DEFAULT_MODE', 'review'));
$resql = $db->query("SELECT skonto_percent, skonto_days, mode FROM ".MAIN_DB_PREFIX."fintssync_customer WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $socid));
if ($resql && ($obj = $db->fetch_object($resql))) {
	$setting = array('skonto_percent' => (float) $obj->skonto_percent, 'skonto_days' => (int) $obj->skonto_days, 'mode' => $obj->mode);
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $langs->trans('FintsSyncSkonto'));

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'fintssync', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($object, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print '<div class="underbanner clearboth"></div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('FintsSyncSkontoPercent').'</td><td><input type="text" class="width75" name="skonto_percent" value="'.dol_escape_htmltag(price2num($setting['skonto_percent'])).'"> %</td></tr>';
print '<tr><td>'.$langs->trans('FintsSyncSkontoDays').'</td><td><input type="number" class="width75" min="0" name="skonto_days" value="'.((int) $setting['skonto_days']).'"> '.$langs->trans('Days').' <span class="opacitymedium">'.$langs->trans('FintsSyncSkontoDaysHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('FintsSyncMode').'</td><td>';
print $form->selectarray('mode', array('review' => $langs->trans('FintsSyncModeReview'), 'auto' => $langs->trans('FintsSyncModeAuto')), $setting['mode'], 0);
print '</td></tr>';
print '</table>';
print dol_get_fiche_end();
if ($user->hasRight('fintssync', 'write')) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
}
print '</form>';

llxFooter();
$db->close();
