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
 * \file       htdocs/custom/fintssync/list.php
 * \ingroup    fintssync
 * \brief      List of imported bank transactions (same list as shown below the sync button).
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

require_once __DIR__.'/class/fintssyncservice.class.php';
require_once __DIR__.'/lib/fintssync.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('fintssync@fintssync', 'bills'));

if (!$user->hasRight('fintssync', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$filter = GETPOST('filter', 'aZ09') ?: 'review';

/*
 * Actions
 */
fintssyncHandleListAction($action, GETPOSTINT('id'));

/*
 * View
 */
llxHeader('', $langs->trans('FintsSyncMenu'));
print load_fiche_titre($langs->trans('FintsSyncMenu'), '', 'bank');

fintssyncPrintList($filter, $_SERVER['PHP_SELF']);

llxFooter();
$db->close();
