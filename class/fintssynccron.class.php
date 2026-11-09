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
 * \file       htdocs/custom/fintssync/class/fintssynccron.class.php
 * \ingroup    fintssync
 * \brief      Scheduled job: fetch bank transactions and book matches.
 */
require_once __DIR__.'/fintssyncclient.class.php';
require_once __DIR__.'/fintssyncservice.class.php';
require_once __DIR__.'/fintssyncexport.class.php';
require_once __DIR__.'/fintssyncpaypal.class.php';

/**
 * Cron entry point
 */
class FintsSyncCron
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $output = '';

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var bool True if the last run stopped on a TAN request
	 */
	public $waitingTan = false;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Run the synchronisation. If the bank asks for a TAN, the session is saved and the job reports
	 * it: the TAN must then be entered on the Sync page.
	 *
	 * @return int 0 if OK, 1 if KO (cron convention)
	 */
	public function run()
	{
		global $langs, $user;

		$langs->load('fintssync@fintssync');

		// PayPal first: it needs no TAN and is independent of the bank session
		$ppError = '';
		$ppInfo = '';
		if (FintsSyncPaypal::isConfigured()) {
			$pp = new FintsSyncPaypal();
			$days = max(1, getDolGlobalInt('FINTSSYNC_DAYS', 5));
			$txs = $pp->fetch(dol_now() - $days * 86400, dol_now());
			if ($txs === false) {
				$ppError = $pp->error;
			} else {
				$service = new FintsSyncService($this->db);
				$n = $service->storeTransactions($txs);
				if ($n < 0) {
					$ppError = $service->error;
				} else {
					$ppInfo = 'PayPal: '.count($txs).' / '.$n.' new. ';
					dolibarr_set_const($this->db, 'FINTSSYNC_PAYPAL_LASTSYNC', (string) dol_now(), 'chaine', 0, '', 0);
				}
			}
		}
		$ret = $this->runBank($ppInfo);
		if ($ppError !== '') {
			$this->error = trim($this->error.' '.$ppError);
			$this->output .= ' PayPal ERROR: '.$ppError;
			return 1;
		}
		return $ret;
	}

	/**
	 * Bank part of the synchronisation (see run()).
	 *
	 * @param  string $prefix Text put in front of the output
	 * @return int            0 if OK, 1 if KO
	 */
	protected function runBank($prefix = '')
	{
		global $langs, $user;
		$client = new FintsSyncClient($this->db);
		if ($client->hasPending()) {
			$this->waitingTan = true;
			$this->output = $prefix.$langs->trans('FintsSyncPendingTan');
			return 0;
		}

		$result = $client->start();
		if ($result['status'] == 'done') {
			$service = new FintsSyncService($this->db);
			$stats = $service->ingest($user, $result);
			$this->output = $prefix.$langs->trans('FintsSyncResult', $stats['new'], $stats['booked'], $stats['review'], $stats['unmatched']);
			return 0;
		}
		if ($result['status'] == 'error') {
			$this->error = $result['message'];
			$this->output = $prefix.$result['message'];
			return 1;
		}
		$this->waitingTan = true;
		$this->output = $prefix.$langs->trans('FintsSyncTanRequired').' '.$result['message'];
		return 0;
	}

	/**
	 * Daily job: run the normal sync (last days), then export the previous month if it is not exported yet.
	 * The export is created on the 1st, or later if that run was missed. If the sync did not complete
	 * (TAN, error) or the last successful sync is older than the pull window, the file is marked INCOMPLETE
	 * and is replaced by a complete one once a later run succeeds.
	 *
	 * @return int 0 if OK, 1 if KO
	 */
	public function runMonthly()
	{
		global $langs;
		$langs->load('fintssync@fintssync');

		$ret = $this->run();
		$out = $this->output;

		$prev = strtotime(date('Y-m-01').' -1 month');
		$ym = date('Y-m', $prev);
		$done = getDolGlobalString('FINTSSYNC_LASTEXPORT');
		if ($done >= $ym) {
			return $ret;
		}

		$complete = ($ret == 0 && !$this->waitingTan);
		$exp = new FintsSyncExport($this->db);
		$file = $exp->export((int) date('Y', $prev), (int) date('n', $prev), !$complete);
		if ($file === false) {
			$this->error = $exp->error;
			$this->output = $out.' '.$exp->error;
			return 1;
		}
		if ($complete) {
			dolibarr_set_const($this->db, 'FINTSSYNC_LASTEXPORT', $ym, 'chaine', 0, '', 0);
		}
		$this->output = $out.' '.$langs->trans($complete ? 'FintsSyncExported' : 'FintsSyncExportedIncomplete', basename($file));
		return 0;
	}

	/**
	 * Always run a fresh sync first, then write the export file of a month.
	 * If the sync fails or waits for a TAN, the file is still written but named _INCOMPLETE.
	 *
	 * @param  int $year  Year
	 * @param  int $month Month 1-12
	 * @return array|false array('file'=>full path,'complete'=>bool,'message'=>string), false on write error ($this->error)
	 */
	public function syncAndExport($year, $month)
	{
		$ret = $this->run();
		$complete = ($ret == 0 && !$this->waitingTan);
		$exp = new FintsSyncExport($this->db);
		$file = $exp->export((int) $year, (int) $month, !$complete);
		if ($file === false) {
			$this->error = $exp->error;
			return false;
		}
		return array('file' => $file, 'complete' => $complete, 'message' => $this->output);
	}
}
