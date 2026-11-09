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

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
dol_include_once('/fintssync/class/fintssyncexport.class.php');
dol_include_once('/fintssync/class/fintssynccron.class.php');

/**
 * API of module FintsSync: monthly CSV exports of the imported bank transactions.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class FintsSyncApi extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Check month format and rights.
	 *
	 * @param  string $month Month YYYY-MM
	 * @param  string $right read|write
	 * @return array         array(year, month)
	 *
	 * @throws RestException
	 */
	protected function checkMonth($month, $right = 'read')
	{
		if (!DolibarrApiAccess::$user->hasRight('fintssync', $right)) {
			throw new RestException(403);
		}
		$m = array();
		if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m) || $m[2] < 1 || $m[2] > 12) {
			throw new RestException(400, 'Month must be formatted YYYY-MM');
		}
		return array((int) $m[1], (int) $m[2]);
	}

	/**
	 * Always run a fresh bank sync before exporting.
	 *
	 * @return FintsSyncCron Job (see ->output, ->waitingTan, ->error)
	 */
	protected function freshSync()
	{
		global $user;
		$user = DolibarrApiAccess::$user;
		$job = new FintsSyncCron($this->db);
		$job->run();
		return $job;
	}

	/**
	 * List the export files that exist.
	 *
	 * @return array List of array('month','file','complete','size','date')
	 *
	 * @url GET exports
	 *
	 * @throws RestException 403 Not allowed
	 */
	public function listExports()
	{
		if (!DolibarrApiAccess::$user->hasRight('fintssync', 'read')) {
			throw new RestException(403);
		}
		$out = array();
		$files = glob(FintsSyncExport::exportDir().'/transactions_*.csv');
		rsort($files);
		foreach ((array) $files as $f) {
			$n = basename($f);
			$out[] = array('month' => substr($n, 13, 7), 'file' => $n, 'complete' => (strpos($n, '_INCOMPLETE') === false), 'size' => filesize($f), 'date' => filemtime($f));
		}
		return $out;
	}

	/**
	 * Get the CSV of a month. A fresh bank sync is always run first; 'complete' is false if it failed or needs a TAN.
	 *
	 * Returns the file as base64 like the Dolibarr documents API.
	 *
	 * @param  string $month Month, format YYYY-MM
	 * @return array         array('filename','content-type','filesize','content','encoding','complete','sync_message')
	 *
	 * @url GET exports/{month}
	 *
	 * @throws RestException 400 Bad month
	 * @throws RestException 403 Not allowed
	 * @throws RestException 500 Error
	 */
	public function getExport($month)
	{
		list($y, $m) = $this->checkMonth($month);
		$job = $this->freshSync();
		$exp = new FintsSyncExport($this->db);
		$csv = $exp->buildCsv($y, $m);
		if ($csv === false) {
			throw new RestException(500, $exp->error);
		}
		return array(
			'filename' => 'transactions_'.$month.'.csv',
			'content-type' => 'text/csv',
			'filesize' => strlen($csv),
			'content' => base64_encode($csv),
			'encoding' => 'base64',
			'complete' => (!$job->waitingTan && !$job->error),
			'sync_message' => $job->output,
		);
	}

	/**
	 * Get the transactions of a month as JSON (runs a fresh bank sync first).
	 *
	 * @param  string $month Month, format YYYY-MM
	 * @return array         List of transactions
	 *
	 * @url GET transactions/{month}
	 *
	 * @throws RestException 400 Bad month
	 * @throws RestException 403 Not allowed
	 */
	public function getTransactions($month)
	{
		global $conf;
		list($y, $m) = $this->checkMonth($month);
		$this->freshSync();
		$from = sprintf('%04d-%02d-01', $y, $m);
		$to = date('Y-m-d', strtotime($from.' +1 month'));
		$sql = "SELECT rowid, datetx, amount, fee, currency, source, invoice_ref, txtype, own_iban, name, account, eref, purpose, status, fk_soc, fk_paiement FROM ".MAIN_DB_PREFIX."fintssync_tx";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND datetx >= '".$this->db->escape($from)."' AND datetx < '".$this->db->escape($to)."' ORDER BY own_iban, datetx, rowid";
		$resql = $this->db->query($sql);
		$out = array();
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			$out[] = array(
				'id' => (int) $o->rowid, 'date' => date('Y-m-d', $this->db->jdate($o->datetx)), 'amount' => (float) $o->amount, 'fee' => (float) $o->fee, 'currency' => $o->currency, 'source' => $o->source, 'invoice_ref' => $o->invoice_ref, 'type' => $o->txtype,
				'own_iban' => $o->own_iban, 'name' => $o->name, 'counter_iban' => $o->account, 'end_to_end_id' => $o->eref,
				'purpose' => $o->purpose, 'status' => (int) $o->status, 'thirdparty_id' => (int) $o->fk_soc, 'payment_id' => (int) $o->fk_paiement,
			);
		}
		return $out;
	}

	/**
	 * Run a fresh bank sync, then write the export file of a month to the documents folder.
	 *
	 * @param  string $month Month, format YYYY-MM
	 * @return array         array('file')
	 *
	 * @url POST exports/{month}
	 *
	 * @throws RestException 400 Bad month
	 * @throws RestException 403 Not allowed
	 * @throws RestException 500 Error
	 */
	public function createExport($month)
	{
		list($y, $m) = $this->checkMonth($month, 'write');
		global $user;
		$user = DolibarrApiAccess::$user;
		$job = new FintsSyncCron($this->db);
		$res = $job->syncAndExport($y, $m);
		if ($res === false) {
			throw new RestException(500, $job->error);
		}
		return array('file' => basename($res['file']), 'complete' => $res['complete'], 'sync_message' => $res['message']);
	}
}
