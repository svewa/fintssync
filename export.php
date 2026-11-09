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
 * \file       htdocs/custom/fintssync/export.php
 * \ingroup    fintssync
 * \brief      Create and download the monthly CSV exports.
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

require_once __DIR__.'/class/fintssyncexport.class.php';
require_once __DIR__.'/class/fintssynccron.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('fintssync@fintssync'));

if (!$user->hasRight('fintssync', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$dir = FintsSyncExport::exportDir();

// Download: only plain file names of our own export folder
if ($action == 'download') {
	$name = basename(GETPOST('file', 'alphanohtml'));
	if (!preg_match('/^transactions_\d{4}-\d{2}(_INCOMPLETE)?\.csv$/', $name) || !is_file($dir.'/'.$name)) {
		accessforbidden();
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.$name.'"');
	header('Content-Length: '.filesize($dir.'/'.$name));
	readfile($dir.'/'.$name);
	exit;
}

if ($action == 'create' && $user->hasRight('fintssync', 'write')) {
	$ym = GETPOST('month', 'alphanohtml');
	if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) && $m[2] >= 1 && $m[2] <= 12) {
		// Always sync with the bank first
		$job = new FintsSyncCron($db);
		$res = $job->syncAndExport((int) $m[1], (int) $m[2]);
		if ($res) {
			setEventMessages($job->output, null, 'mesgs');
			if ($res['complete']) {
				setEventMessages($langs->trans('FintsSyncExported', basename($res['file'])), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('FintsSyncExportedIncomplete', basename($res['file'])), null, 'warnings');
			}
		} else {
			setEventMessages($job->error, null, 'errors');
		}
	}
}

llxHeader('', $langs->trans('FintsSyncExport'));
print load_fiche_titre($langs->trans('FintsSyncExport'), '', 'bank');

// Cache status, to see at a glance whether a sync has stored anything
$resql = $db->query("SELECT COUNT(*) as nb, MIN(datetx) as mn, MAX(datetx) as mx, SUM(amount < 0) as debits FROM ".MAIN_DB_PREFIX."fintssync_tx WHERE entity = ".((int) $conf->entity));
if ($resql && ($o = $db->fetch_object($resql))) {
	$last = getDolGlobalInt('FINTSSYNC_LASTSYNC');
	print '<div class="info">Cache: '.((int) $o->nb).' transactions ('.((int) $o->debits).' debits)';
	if ($o->nb > 0) {
		print ', '.dol_escape_htmltag($o->mn).' .. '.dol_escape_htmltag($o->mx);
	}
	print '. Last successful sync: '.($last ? dol_print_date($last, 'dayhour') : '-').'</div><br>';
}

if ($user->hasRight('fintssync', 'write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create">';
	print $langs->trans('FintsSyncExportMonth').': <input type="month" name="month" value="'.date('Y-m', strtotime(date('Y-m-01').' -1 month')).'"> ';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('FintsSyncExportNow')).'">';
	print '</form><br>';
}

$files = is_dir($dir) ? glob($dir.'/transactions_*.csv') : array();
rsort($files);
if (empty($files)) {
	print '<span class="opacitymedium">'.$langs->trans('FintsSyncNoExports').'</span>';
} else {
	print '<table class="noborder"><tr class="liste_titre"><td>'.$langs->trans('File').'</td><td>'.$langs->trans('Date').'</td></tr>';
	foreach ($files as $f) {
		$n = basename($f);
		print '<tr class="oddeven"><td><a href="'.$_SERVER['PHP_SELF'].'?action=download&file='.urlencode($n).'">'.dol_escape_htmltag($n).'</a></td><td>'.dol_print_date(filemtime($f), 'dayhour').'</td></tr>';
	}
	print '</table>';
}

llxFooter();
$db->close();
