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
 * \file       htdocs/custom/fintssync/sync.php
 * \ingroup    fintssync
 * \brief      Manual synchronisation with TAN entry.
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

require_once __DIR__.'/class/fintssyncclient.class.php';
require_once __DIR__.'/class/fintssyncservice.class.php';
require_once __DIR__.'/lib/fintssync.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('fintssync@fintssync'));

if (!$user->hasRight('fintssync', 'write')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$filter = GETPOST('filter', 'aZ09') ?: 'review';
$client = new FintsSyncClient($db);
$result = null;

/*
 * Actions
 */
if ($action == 'start') {
	$result = $client->start();
} elseif ($action == 'tan') {
	$result = $client->submitTan(trim(GETPOST('tan', 'alphanohtml')));
} elseif ($action == 'cancel') {
	$client->clearState();
	setEventMessages($langs->trans('FintsSyncStateCleared'), null, 'mesgs');
} elseif (in_array($action, array('confirm', 'ignore', 'reopen'))) {
	fintssyncHandleListAction($action, GETPOSTINT('id'));
}

$stats = null;
if ($result !== null && $result['status'] == 'done') {
	$service = new FintsSyncService($db);
	$stats = $service->ingest($user, $result);
	setEventMessages($langs->trans('FintsSyncResult', $stats['new'], $stats['booked'], $stats['review'], $stats['unmatched']), null, 'mesgs');
} elseif ($result !== null && $result['status'] == 'error') {
	setEventMessages($langs->trans($result['message']) ?: $result['message'], null, 'errors');
}

/*
 * View
 */
llxHeader('', $langs->trans('FintsSyncSyncNow'));
$pending = $client->hasPending();
if ($pending) {
	$message = ($result !== null && !empty($result['message'])) ? $result['message'] : '';
	print '<div class="info">'.$langs->trans('FintsSyncPendingTan').'<br>'.dol_escape_htmltag($message).'</div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="tan">';
	print $langs->trans('FintsSyncTan').': <input type="text" name="tan" class="width150" autocomplete="off"> ';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('FintsSyncSubmitTan')).'">';
	print '</form><br>';
	$lefthtml = '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=cancel&token='.newToken().'">'.$langs->trans('Cancel').'</a>';
} else {
	$lefthtml = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin: 0;">';
	$lefthtml .= '<input type="hidden" name="token" value="'.newToken().'">';
	$lefthtml .= '<input type="hidden" name="action" value="start">';
	$lefthtml .= '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('FintsSyncSyncNow')).'">';
	$lefthtml .= '</form>';
}

// Sync button (or cancel while a TAN is pending) left, filter buttons right, in one row
fintssyncPrintList($filter, $_SERVER['PHP_SELF'], $lefthtml);

llxFooter();
$db->close();
