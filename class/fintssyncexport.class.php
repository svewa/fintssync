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
 * \file       htdocs/custom/fintssync/class/fintssyncexport.class.php
 * \ingroup    fintssync
 * \brief      Monthly CSV export of the cached bank transactions (all accounts).
 */

/**
 * Export of stored transactions
 */
class FintsSyncExport
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $error = '';

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
	 * Directory where exports are stored.
	 *
	 * @return string
	 */
	public static function exportDir()
	{
		global $conf;
		$base = !empty($conf->fintssync->multidir_output[$conf->entity]) ? $conf->fintssync->multidir_output[$conf->entity] : DOL_DATA_ROOT.'/fintssync';
		return $base.'/export';
	}

	/**
	 * The FinTS library returns the full purpose in two description fields, so it often appears twice
	 * ("A B A B"). Return one copy in that case.
	 *
	 * @param  string $text Purpose
	 * @return string
	 */
	public static function dedupePurpose($text)
	{
		$words = preg_split('/\s+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
		$n = count($words);
		if ($n >= 2 && $n % 2 == 0 && array_slice($words, 0, $n / 2) === array_slice($words, $n / 2)) {
			$words = array_slice($words, 0, $n / 2);
		}
		return implode(' ', $words);
	}

	/**
	 * Format a CSV line (; separated, quoted when needed).
	 *
	 * @param  array $fields Fields
	 * @return string
	 */
	public static function csvLine($fields)
	{
		$out = array();
		foreach ($fields as $f) {
			$f = str_replace(array("\r", "\n"), ' ', (string) $f);
			if (preg_match('/[;"]/', $f)) {
				$f = '"'.str_replace('"', '""', $f).'"';
			}
			$out[] = $f;
		}
		return implode(';', $out)."\r\n";
	}

	/**
	 * Build CSV content of a month.
	 *
	 * @param  int $year  Year
	 * @param  int $month Month 1-12
	 * @return string|false CSV (UTF-8 with BOM), false on error
	 */
	public function buildCsv($year, $month)
	{
		global $conf;
		$from = sprintf('%04d-%02d-01', $year, $month);
		$to = date('Y-m-d', strtotime($from.' +1 month'));
		$sql = "SELECT datetx, amount, fee, currency, source, own_iban, name, account, eref, invoice_ref, txtype, purpose FROM ".MAIN_DB_PREFIX."fintssync_tx";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND datetx >= '".$this->db->escape($from)."' AND datetx < '".$this->db->escape($to)."'";
		$sql .= " ORDER BY datetx, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$csv = "\xEF\xBB\xBF".self::csvLine(array('Datum', 'Quelle', 'Konto', 'Gegenpartei', 'Gegenkonto', 'Verwendungszweck', 'Betrag', 'Gebuehr', 'Waehrung', 'Referenz', 'Rechnungsnummer', 'Typ'));
		while ($o = $this->db->fetch_object($resql)) {
			$csv .= self::csvLine(array(
				date('d.m.Y', $this->db->jdate($o->datetx)),
				(($o->source ?: 'bank') == 'paypal' ? 'PayPal' : 'Bank'),
				$o->own_iban,
				$o->name,
				$o->account,
				self::dedupePurpose($o->purpose),
				number_format((float) $o->amount, 2, ',', ''),
				number_format((float) $o->fee, 2, ',', ''),
				$o->currency ?: 'EUR',
				$o->eref,
				$o->invoice_ref,
				$o->txtype,
			));
		}
		return $csv;
	}

	/**
	 * Write the CSV of a month to the documents folder.
	 *
	 * @param  int  $year       Year
	 * @param  int  $month      Month
	 * @param  bool $incomplete Mark the file name as incomplete
	 * @return string|false     Full path or false
	 */
	public function export($year, $month, $incomplete = false)
	{
		$csv = $this->buildCsv($year, $month);
		if ($csv === false) {
			return false;
		}
		$dir = self::exportDir();
		dol_mkdir($dir);
		$file = $dir.'/transactions_'.sprintf('%04d-%02d', $year, $month).($incomplete ? '_INCOMPLETE' : '').'.csv';
		if (file_put_contents($file, $csv) === false) {
			$this->error = 'Cannot write '.$file;
			return false;
		}
		dolChmod($file);
		return $file;
	}
}
