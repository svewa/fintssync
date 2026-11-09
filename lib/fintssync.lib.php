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
 * \file       htdocs/custom/fintssync/lib/fintssync.lib.php
 * \ingroup    fintssync
 * \brief      Helper functions of module FintsSync
 */

/**
 * Prepare admin tabs
 *
 * @return array<int,array<int,string>>
 */
function fintssyncAdminPrepareHead()
{
	global $langs;

	$langs->load('fintssync@fintssync');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/fintssync/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($GLOBALS['conf'], $langs, null, $head, $h, 'fintssync@fintssync');
	complete_head_from_modules($GLOBALS['conf'], $langs, null, $head, $h, 'fintssync@fintssync', 'remove');

	return $head;
}

/**
 * Prepare tabs of the third party skonto page (only the skonto tab is built here, the rest comes from the thirdparty lib)
 *
 * @param  Societe $object Thirdparty
 * @return array<int,array<int,string>>
 */
function fintssyncThirdpartyHead($object)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
	return societe_prepare_head($object);
}

/**
 * Handle confirm / ignore / reopen of a transaction of the review list.
 *
 * @param  string $action Action (confirm, ignore, reopen)
 * @param  int    $id     Transaction id
 * @return void
 */
function fintssyncHandleListAction($action, $id)
{
	global $db, $conf, $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once __DIR__.'/../class/fintssyncservice.class.php';

	if ($id <= 0 || !$user->hasRight('fintssync', 'write') || !in_array($action, array('confirm', 'ignore', 'reopen'))) {
		return;
	}

	$service = new FintsSyncService($db);
	$resql = $db->query("SELECT status, fk_soc, match_kind, match_json FROM ".MAIN_DB_PREFIX."fintssync_tx WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
	$tx = $resql ? $db->fetch_object($resql) : null;
	if ($tx && $action == 'confirm' && $tx->status == FintsSyncService::STATUS_REVIEW) {
		$data = json_decode((string) $tx->match_json, true);
		if (!empty($data['allocations'])) {
			// Re-validate: the invoices must still be open with at least the proposed amount remaining
			$ok = true;
			foreach ($data['allocations'] as $invid => $amount) {
				$inv = new Facture($db);
				if ($inv->fetch((int) $invid) <= 0 || $inv->paye || $inv->getRemainToPay() + 0.005 < (float) $amount) {
					$ok = false;
				}
			}
			if ($ok && $service->book($user, $id, array('allocations' => $data['allocations'], 'skonto' => $data['skonto'] ?? array(), 'late' => $data['late'] ?? array(), 'kind' => $tx->match_kind, 'socid' => (int) $tx->fk_soc)) > 0) {
				setEventMessages($langs->trans('FintsSyncBooked'), null, 'mesgs');
			} else {
				setEventMessages($ok ? $service->error : $langs->trans('FintsSyncInvoiceChanged'), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('FintsSyncNoProposal'), null, 'warnings');
		}
	} elseif ($tx && $action == 'ignore' && $tx->status != FintsSyncService::STATUS_BOOKED) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."fintssync_tx SET status = ".FintsSyncService::STATUS_IGNORED." WHERE rowid = ".((int) $id));
	} elseif ($tx && $action == 'reopen' && $tx->status == FintsSyncService::STATUS_IGNORED) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."fintssync_tx SET status = ".FintsSyncService::STATUS_NEW." WHERE rowid = ".((int) $id));
	}
}

/**
 * Print the filter tabs and the table of imported transactions.
 *
 * @param  string $filter  Filter key (review, booked, ignored, all)
 * @param  string $selfurl Url of the page that shows the list (forms and filter links point to it)
 * @param  string $lefthtml Optional html (e.g. a button) shown left aligned in the same row as the filter buttons
 * @return void
 */
function fintssyncPrintList($filter, $selfurl, $lefthtml = '')
{
	global $db, $conf, $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once __DIR__.'/../class/fintssyncservice.class.php';

	$canwrite = $user->hasRight('fintssync', 'write');

	$filters = array(
		'review' => FintsSyncService::STATUS_REVIEW,
		'booked' => FintsSyncService::STATUS_BOOKED,
		'ignored' => FintsSyncService::STATUS_IGNORED,
		'all' => -1,
	);
	if (!isset($filters[$filter])) {
		$filter = 'review';
	}

	print '<div class="tabsAction" style="display: flex; justify-content: space-between; align-items: center;">';
	print '<div class="fintssync-left">'.$lefthtml.'</div>';
	print '<div class="fintssync-filters">';
	foreach ($filters as $key => $st) {
		print '<a class="butAction'.($filter == $key ? '' : 'Refused').'" href="'.$selfurl.'?filter='.$key.'">'.$langs->trans('FintsSyncFilter_'.$key).'</a>';
	}
	print '</div>';
	print '</div>';

	// Rows stored with a missed skonto deadline before it could be acknowledged have no proposal yet: refresh them
	$resql = $db->query("SELECT rowid, match_json FROM ".MAIN_DB_PREFIX."fintssync_tx WHERE entity = ".((int) $conf->entity)." AND status = ".FintsSyncService::STATUS_REVIEW." AND match_note = 'SkontoDeadlineMissed'");
	if ($resql && $db->num_rows($resql) > 0) {
		$service = new FintsSyncService($db);
		while ($row = $db->fetch_object($resql)) {
			$d = json_decode((string) $row->match_json, true);
			if (empty($d['allocations'])) {
				$service->rematch((int) $row->rowid);
			}
		}
	}

	$sql = "SELECT t.rowid, t.datetx, t.amount, t.name, t.purpose, t.status, t.fk_soc, t.match_kind, t.match_json, t.match_note";
	$sql .= " FROM ".MAIN_DB_PREFIX."fintssync_tx as t WHERE t.entity = ".((int) $conf->entity);
	if ($filters[$filter] >= 0) {
		$sql .= " AND t.status = ".((int) $filters[$filter]);
	}
	$sql .= " ORDER BY t.datetx DESC, t.rowid DESC LIMIT 500";
	$resql = $db->query($sql);

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td class="right">'.$langs->trans('Amount').'</td><td>'.$langs->trans('FintsSyncSender').'</td>';
	print '<td>'.$langs->trans('FintsSyncPurpose').'</td><td>'.$langs->trans('FintsSyncProposal').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';

	$soc = new Societe($db);
	$inv = new Facture($db);
	while ($resql && ($t = $db->fetch_object($resql))) {
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($t->datetx), 'day').'</td>';
		print '<td class="right nowraponall">'.price($t->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		print '<td>'.dol_escape_htmltag($t->name).'</td>';
		print '<td class="small">'.dol_escape_htmltag(dol_trunc($t->purpose, 120)).'</td>';

		print '<td class="small">';
		if ($t->fk_soc > 0 && $soc->fetch($t->fk_soc) > 0) {
			print $soc->getNomUrl(1).'<br>';
		}
		$data = json_decode((string) $t->match_json, true);
		if (!empty($data['allocations'])) {
			foreach ($data['allocations'] as $invid => $amount) {
				if ($inv->fetch((int) $invid) > 0) {
					print $inv->getNomUrl(1).' '.price($amount);
					if (!empty($data['skonto'][$invid])) {
						print ' <span class="opacitymedium">(Skonto '.price($data['skonto'][$invid]).')</span>';
					}
					if (!empty($data['late'][$invid]) && $t->status == FintsSyncService::STATUS_REVIEW) {
						print ' <span class="warning">'.$langs->trans('FintsSyncLateDays', (int) $data['late'][$invid]).'</span>';
					}
					print '<br>';
				}
			}
		}
		if ($t->match_note) {
			print '<span class="warning">'.$langs->trans('FintsSyncNote_'.$t->match_note).'</span>';
		}
		print '</td>';

		print '<td>'.$langs->trans('FintsSyncStatus_'.$t->status).'</td><td class="right nowraponall">';
		if ($canwrite) {
			print '<form method="POST" action="'.$selfurl.'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.((int) $t->rowid).'"><input type="hidden" name="filter" value="'.dol_escape_htmltag($filter).'">';
			if ($t->status == FintsSyncService::STATUS_REVIEW) {
				if (!empty($data['allocations'])) {
					print '<button class="button smallpaddingimp" name="action" value="confirm">'.$langs->trans('FintsSyncConfirm').'</button> ';
				}
				print '<button class="button smallpaddingimp" name="action" value="ignore">'.$langs->trans('FintsSyncIgnore').'</button>';
			} elseif ($t->status == FintsSyncService::STATUS_IGNORED) {
				print '<button class="button smallpaddingimp" name="action" value="reopen">'.$langs->trans('FintsSyncReopen').'</button>';
			}
			print '</form>';
		}
		print '</td></tr>';
	}
	print '</table>';
}
