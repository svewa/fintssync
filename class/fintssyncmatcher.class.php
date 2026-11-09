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
 * \file       htdocs/custom/fintssync/class/fintssyncmatcher.class.php
 * \ingroup    fintssync
 * \brief      Pure matching logic between a bank transaction and open customer invoices (no DB access).
 */

/**
 * Matches one incoming bank transaction to open invoices, taking per-customer skonto into account.
 *
 * Transaction: array('date'=>timestamp, 'amount'=>float (>0 = credit), 'account'=>IBAN, 'purpose'=>string)
 * Invoices (open): list of array('id','ref','socid','date'=>timestamp,'remain'=>float)
 * Settings: array(socid => array('percent'=>float,'days'=>int,'mode'=>'auto'|'review'))
 * ibanToSoc: array(normalized IBAN => socid), used when no reference is found in the purpose.
 *
 * Result: array(
 *   'result' => 'none'|'review'|'book',
 *   'kind' => 'full'|'skonto'|'',
 *   'socid' => int,
 *   'allocations' => array(invoiceid => amount received as payment),
 *   'skonto' => array(invoiceid => skonto amount forgiven),
 *   'note' => string (language key suffix)
 * )
 */
class FintsSyncMatcher
{
	/**
	 * @var string Mode used for customers without own setting
	 */
	public $defaultMode = 'review';

	/**
	 * Constructor
	 *
	 * @param string $defaultMode Default mode 'auto' or 'review'
	 */
	public function __construct($defaultMode = 'review')
	{
		$this->defaultMode = ($defaultMode == 'auto' ? 'auto' : 'review');
	}

	/**
	 * Normalize an IBAN.
	 *
	 * @param  string $iban IBAN
	 * @return string       Upper case IBAN without spaces
	 */
	public static function normalizeIban($iban)
	{
		return strtoupper(preg_replace('/\s+/', '', (string) $iban));
	}

	/**
	 * Convert amount to integer cents.
	 *
	 * @param  float $amount Amount
	 * @return int           Cents
	 */
	protected static function cents($amount)
	{
		return (int) round($amount * 100);
	}

	/**
	 * Join purpose parts and remove duplicated text (banks/CAMT parsers often deliver the same
	 * text in several fields, or repeat it inside one field: "X X").
	 *
	 * @param  array $parts Purpose parts (strings)
	 * @return string
	 */
	public static function dedupePurpose($parts)
	{
		$kept = array();
		foreach ($parts as $part) {
			$part = trim(preg_replace('/\s+/u', ' ', (string) $part));
			if ($part === '') {
				continue;
			}
			$lower = mb_strtolower($part);
			$skip = false;
			foreach ($kept as $i => $k) {
				$kl = mb_strtolower($k);
				if (mb_strpos($kl, $lower) !== false) {
					$skip = true; // already contained in an earlier part
					break;
				}
				if (mb_strpos($lower, $kl) !== false) {
					unset($kept[$i]); // earlier part is contained in this one: replace it
				}
			}
			if (!$skip) {
				$kept[] = $part;
			}
			$kept = array_values($kept);
		}
		$text = implode(' ', $kept);

		// Text consisting of n identical repetitions ("X X", "X X X")
		$words = $text === '' ? array() : explode(' ', $text);
		$n = count($words);
		for ($rep = $n; $rep >= 2; $rep--) {
			if ($n % $rep != 0) {
				continue;
			}
			$size = $n / $rep;
			$first = array_slice($words, 0, $size);
			$same = true;
			for ($j = 1; $j < $rep && $same; $j++) {
				$same = (mb_strtolower(implode(' ', array_slice($words, $j * $size, $size))) === mb_strtolower(implode(' ', $first)));
			}
			if ($same) {
				return implode(' ', $first);
			}
		}
		return $text;
	}

	/**
	 * Does the purpose text contain the invoice reference (bounded by non alphanumeric chars).
	 *
	 * @param  string $purpose Purpose
	 * @param  string $ref     Invoice ref
	 * @return bool
	 */
	public static function purposeContainsRef($purpose, $ref)
	{
		if ($ref === '') {
			return false;
		}
		return (bool) preg_match('/(?<![A-Za-z0-9])'.preg_quote($ref, '/').'(?![A-Za-z0-9])/i', $purpose);
	}

	/**
	 * Amount to pay for an invoice if skonto is taken.
	 *
	 * @param  array $inv      Invoice
	 * @param  array $setting  Customer setting (percent, days) or empty
	 * @param  int   $txdate   Transaction date (timestamp)
	 * @return array           array(discounted amount|null if no skonto configured, deadlineMissed bool, days overdue int)
	 */
	protected static function skontoAmount($inv, $setting, $txdate)
	{
		if (empty($setting) || empty($setting['percent']) || $setting['percent'] <= 0) {
			return array(null, false, 0);
		}
		$discounted = round($inv['remain'] * (1 - $setting['percent'] / 100), 2);
		$days = (int) ($setting['days'] ?? 0);
		if ($days > 0) {
			$deadline = strtotime(date('Y-m-d', $inv['date']).' +'.$days.' days');
			$txday = strtotime(date('Y-m-d', $txdate));
			if ($txday > $deadline) {
				return array($discounted, true, (int) round(($txday - $deadline) / 86400));
			}
		}
		return array($discounted, false, 0);
	}

	/**
	 * Try to explain $amount using the given invoices, each paid in full or with skonto.
	 *
	 * @param  array $invoices  Invoices of one customer
	 * @param  array $setting   Customer setting
	 * @param  float $amount    Credited amount
	 * @param  int   $txdate    Transaction date
	 * @return array            array(kind|null, allocations, skonto, note, lateDays (invoiceid => days overdue))
	 */
	protected function explain($invoices, $setting, $amount, $txdate)
	{
		$cents = self::cents($amount);

		// Variant 1: everything paid in full
		$sumFull = 0;
		foreach ($invoices as $inv) {
			$sumFull += self::cents($inv['remain']);
		}
		if ($sumFull == $cents) {
			$alloc = array();
			foreach ($invoices as $inv) {
				$alloc[$inv['id']] = round($inv['remain'], 2);
			}
			return array('full', $alloc, array(), '', array());
		}

		// Variant 2: skonto on every invoice where it is still allowed
		$sum = 0;
		$alloc = array();
		$skonto = array();
		$anySkonto = false;
		$missed = false;
		foreach ($invoices as $inv) {
			list($disc, $late) = self::skontoAmount($inv, $setting, $txdate);
			if ($disc !== null && !$late) {
				$alloc[$inv['id']] = $disc;
				$skonto[$inv['id']] = round($inv['remain'] - $disc, 2);
				$sum += self::cents($disc);
				$anySkonto = true;
			} else {
				$alloc[$inv['id']] = round($inv['remain'], 2);
				$sum += self::cents($inv['remain']);
				if ($disc !== null && $late) {
					$missed = true;
				}
			}
		}
		if ($anySkonto && $sum == $cents) {
			return array('skonto', $alloc, $skonto, '', array());
		}

		// Variant 3: amount fits the skonto price but the deadline was missed -> a human decides.
		// A proposal is returned so that it can be acknowledged, together with the days overdue.
		if ($missed) {
			$sumDisc = 0;
			$alloc = array();
			$skonto = array();
			$late = array();
			foreach ($invoices as $inv) {
				list($disc, $isLate, $daysLate) = self::skontoAmount($inv, $setting, $txdate);
				if ($disc !== null) {
					$alloc[$inv['id']] = $disc;
					$skonto[$inv['id']] = round($inv['remain'] - $disc, 2);
					$sumDisc += self::cents($disc);
					if ($isLate) {
						$late[$inv['id']] = $daysLate;
					}
				} else {
					$alloc[$inv['id']] = round($inv['remain'], 2);
					$sumDisc += self::cents($inv['remain']);
				}
			}
			if ($sumDisc == $cents) {
				return array('skonto', $alloc, $skonto, 'SkontoDeadlineMissed', $late);
			}
		}
		return array(null, array(), array(), 'AmountMismatch', array());
	}

	/**
	 * Match a transaction.
	 *
	 * @param  array $tx          Transaction
	 * @param  array $invoices    Open invoices (all customers)
	 * @param  array $settings    Settings by socid
	 * @param  array $ibanToSoc   Map normalized IBAN => socid
	 * @return array              Result (see class doc)
	 */
	public function match($tx, $invoices, $settings, $ibanToSoc = array())
	{
		$none = array('result' => 'none', 'kind' => '', 'socid' => 0, 'allocations' => array(), 'skonto' => array(), 'note' => '');
		if ($tx['amount'] <= 0) {
			return $none;
		}

		// 1) invoices referenced in the purpose
		$referenced = array();
		foreach ($invoices as $inv) {
			if (self::purposeContainsRef($tx['purpose'], $inv['ref'])) {
				$referenced[] = $inv;
			}
		}

		if (!empty($referenced)) {
			$socids = array_values(array_unique(array_map(function ($i) {
				return $i['socid'];
			}, $referenced)));
			if (count($socids) > 1) {
				$none['result'] = 'review';
				$none['note'] = 'MultipleCustomers';
				return $none;
			}
			$socid = (int) $socids[0];
			$setting = $settings[$socid] ?? array();
			list($kind, $alloc, $skonto, $note, $late) = $this->explain($referenced, $setting, $tx['amount'], $tx['date']);
			return $this->finish($kind, $alloc, $skonto, $note, $socid, $setting, $late);
		}

		// 2) fallback: customer from sender IBAN, one open invoice with matching amount (full or minus skonto)
		$iban = self::normalizeIban($tx['account']);
		if ($iban !== '' && isset($ibanToSoc[$iban])) {
			$socid = (int) $ibanToSoc[$iban];
			$setting = $settings[$socid] ?? array();
			$hits = array();
			foreach ($invoices as $inv) {
				if ($inv['socid'] != $socid) {
					continue;
				}
				list($kind, $alloc, $skonto, $note, $late) = $this->explain(array($inv), $setting, $tx['amount'], $tx['date']);
				if ($kind !== null) {
					$hits[] = array($kind, $alloc, $skonto, $note, $late);
				}
			}
			if (count($hits) == 1) {
				return $this->finish($hits[0][0], $hits[0][1], $hits[0][2], $hits[0][3], $socid, $setting, $hits[0][4]);
			}
			if (count($hits) > 1) {
				$none['result'] = 'review';
				$none['socid'] = $socid;
				$none['note'] = 'AmbiguousMatch';
				return $none;
			}
		}

		return $none;
	}

	/**
	 * Build the final result according to the customer mode.
	 *
	 * @param  string|null $kind    full|skonto|null
	 * @param  array       $alloc   Allocations
	 * @param  array       $skonto  Skonto amounts
	 * @param  string      $note    Note key
	 * @param  int         $socid   Customer
	 * @param  array       $setting Customer setting
	 * @param  array       $late    Days overdue per invoice (skonto deadline missed)
	 * @return array
	 */
	protected function finish($kind, $alloc, $skonto, $note, $socid, $setting, $late = array())
	{
		if ($kind === null) {
			return array('result' => 'review', 'kind' => '', 'socid' => $socid, 'allocations' => array(), 'skonto' => array(), 'note' => $note, 'late' => array());
		}
		if ($note == 'SkontoDeadlineMissed') {
			// Never booked automatically, always needs a human decision
			return array('result' => 'review', 'kind' => $kind, 'socid' => $socid, 'allocations' => $alloc, 'skonto' => $skonto, 'note' => $note, 'late' => $late);
		}
		$mode = $setting['mode'] ?? $this->defaultMode;
		return array(
			'result' => ($mode == 'auto' ? 'book' : 'review'),
			'kind' => $kind,
			'socid' => $socid,
			'allocations' => $alloc,
			'skonto' => $skonto,
			'note' => ($mode == 'auto' ? '' : 'ReviewModeCustomer'),
			'late' => array(),
		);
	}
}
