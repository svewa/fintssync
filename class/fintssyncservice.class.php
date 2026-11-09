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
 * \file       htdocs/custom/fintssync/class/fintssyncservice.class.php
 * \ingroup    fintssync
 * \brief      Database side of FintsSync: store transactions, match them, book payments.
 */
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once __DIR__.'/fintssyncmatcher.class.php';

/**
 * Service class for transactions, settings and booking
 */
class FintsSyncService
{
	const STATUS_NEW = 0;
	const STATUS_BOOKED = 1;
	const STATUS_REVIEW = 2;
	const STATUS_IGNORED = 3;

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
	 * Hash identifying a bank transaction (for duplicate detection).
	 *
	 * @param  array $tx Transaction (date timestamp, amount, name, account, eref, purpose)
	 * @return string
	 */
	public static function hashTx($tx)
	{
		if (($tx['source'] ?? 'bank') == 'paypal') {
			return sha1('paypal|'.$tx['eref']);
		}
		// purpose_hash = raw bank text before de-duplication, keeps hashes of earlier imports valid
		return sha1(implode('|', array($tx['own_iban'] ?? '', date('Y-m-d', $tx['date']), number_format($tx['amount'], 2, '.', ''), $tx['account'], $tx['eref'], $tx['purpose_hash'] ?? $tx['purpose'])));
	}

	/**
	 * Store new transactions, skipping those already known.
	 *
	 * @param  array $txs List of transactions
	 * @return int        Number of newly stored transactions, <0 on error
	 */
	public function storeTransactions($txs)
	{
		global $conf;
		$new = 0;
		foreach ($txs as $tx) {
			$hash = self::hashTx($tx);
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."fintssync_tx WHERE entity = ".((int) $conf->entity)." AND tx_hash = '".$this->db->escape($hash)."'";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			if ($this->db->num_rows($resql) > 0) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."fintssync_tx(entity, tx_hash, datetx, amount, name, own_iban, source, currency, fee, invoice_ref, txtype, account, eref, purpose, status, datec)";
			$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($hash)."', '".$this->db->idate($tx['date'])."', ".((float) $tx['amount']);
			$sql .= ", '".$this->db->escape(dol_trunc($tx['name'], 250, 'right', 'UTF-8', 1))."', '".$this->db->escape($tx['own_iban'] ?? '')."', '".$this->db->escape($tx['source'] ?? 'bank')."', '".$this->db->escape($tx['currency'] ?? 'EUR')."', ".((float) ($tx['fee'] ?? 0)).", '".$this->db->escape($tx['invoice_ref'] ?? '')."', '".$this->db->escape($tx['txtype'] ?? '')."', '".$this->db->escape($tx['account'])."'";
			$sql .= ", '".$this->db->escape($tx['eref'])."', '".$this->db->escape($tx['purpose'])."', ".(($tx['source'] ?? 'bank') == 'paypal' ? self::STATUS_IGNORED : self::STATUS_NEW).", '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$new++;
		}
		return $new;
	}

	/**
	 * Per customer settings.
	 *
	 * @return array socid => array(percent, days, mode)
	 */
	public function loadSettings()
	{
		global $conf;
		$out = array();
		$sql = "SELECT fk_soc, skonto_percent, skonto_days, mode FROM ".MAIN_DB_PREFIX."fintssync_customer WHERE entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[(int) $obj->fk_soc] = array('percent' => (float) $obj->skonto_percent, 'days' => (int) $obj->skonto_days, 'mode' => $obj->mode);
			}
		}
		return $out;
	}

	/**
	 * Map of normalized customer IBANs to thirdparty id (from thirdparty bank accounts).
	 *
	 * @return array
	 */
	public function loadIbanMap()
	{
		global $conf;
		$out = array();
		$sql = "SELECT rib.fk_soc, rib.iban_prefix FROM ".MAIN_DB_PREFIX."societe_rib as rib";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = rib.fk_soc";
		$sql .= " WHERE s.entity IN (".getEntity('societe').") AND rib.iban_prefix IS NOT NULL AND rib.iban_prefix <> ''";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[FintsSyncMatcher::normalizeIban($obj->iban_prefix)] = (int) $obj->fk_soc;
			}
		}
		return $out;
	}

	/**
	 * Open (validated, unpaid) customer invoices with their remaining amount.
	 *
	 * @return array
	 */
	public function loadOpenInvoices()
	{
		$out = array();
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE entity IN (".getEntity('invoice').")";
		$sql .= " AND fk_statut = ".Facture::STATUS_VALIDATED." AND paye = 0 AND type <> ".Facture::TYPE_CREDIT_NOTE;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $out;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$inv = new Facture($this->db);
			if ($inv->fetch($obj->rowid) > 0) {
				$remain = (float) $inv->getRemainToPay();
				if ($remain > 0) {
					$out[] = array('id' => (int) $inv->id, 'ref' => $inv->ref, 'socid' => (int) $inv->socid, 'date' => $inv->date, 'remain' => $remain);
				}
			}
		}
		return $out;
	}

	/**
	 * Store the transactions of a finished client result and run the matching.
	 *
	 * @param  User  $user   User
	 * @param  array $result Result of FintsSyncClient (status done)
	 * @return array         array('new'=>int,'booked'=>int,'review'=>int,'unmatched'=>int)
	 */
	public function ingest($user, $result)
	{
		$stats = array('new' => 0, 'booked' => 0, 'review' => 0, 'unmatched' => 0);
		$new = $this->storeTransactions($result['transactions']);
		if ($new < 0) {
			return $stats;
		}
		$stats['new'] = $new;
		return array_merge($stats, $this->processNew($user));
	}

	/**
	 * Match all new transactions; book the clear ones of auto customers, queue the others for review.
	 *
	 * @param  User $user User doing the booking
	 * @return array      array('booked'=>int,'review'=>int,'unmatched'=>int)
	 */
	public function processNew($user)
	{
		global $conf;
		$stats = array('booked' => 0, 'review' => 0, 'unmatched' => 0);

		$matcher = new FintsSyncMatcher(getDolGlobalString('FINTSSYNC_DEFAULT_MODE', 'review'));
		$settings = $this->loadSettings();
		$ibans = $this->loadIbanMap();

		$sql = "SELECT rowid, datetx, amount, account, purpose FROM ".MAIN_DB_PREFIX."fintssync_tx";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND status = ".self::STATUS_NEW." ORDER BY datetx, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $stats;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}

		foreach ($rows as $obj) {
			// Reload open invoices each time, previous bookings change the remaining amounts
			$invoices = $this->loadOpenInvoices();
			$tx = array('date' => $this->db->jdate($obj->datetx), 'amount' => (float) $obj->amount, 'account' => (string) $obj->account, 'purpose' => (string) $obj->purpose);
			$res = $matcher->match($tx, $invoices, $settings, $ibans);

			if ($res['result'] == 'none') {
				if ($tx['amount'] > 0) {
					$stats['unmatched']++;
					$this->setTxStatus($obj->rowid, self::STATUS_REVIEW, $res, 'NoMatch');
				} else {
					// Outgoing payments are not handled by this module
					$this->setTxStatus($obj->rowid, self::STATUS_IGNORED, $res, '');
				}
				continue;
			}
			if ($res['result'] == 'book') {
				if ($this->book($user, $obj->rowid, $res) > 0) {
					$stats['booked']++;
					continue;
				}
				$res['note'] = 'BookingFailed';
			}
			$this->setTxStatus($obj->rowid, self::STATUS_REVIEW, $res, $res['note']);
			$stats['review']++;
		}
		return $stats;
	}

	/**
	 * Save match proposal and status on a transaction.
	 *
	 * @param  int    $id     Transaction id
	 * @param  int    $status Status
	 * @param  array  $res    Matcher result
	 * @param  string $note   Note key
	 * @return void
	 */
	protected function setTxStatus($id, $status, $res, $note)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."fintssync_tx SET status = ".((int) $status);
		$sql .= ", fk_soc = ".($res['socid'] > 0 ? (int) $res['socid'] : "NULL");
		$sql .= ", match_kind = '".$this->db->escape($res['kind'])."'";
		$sql .= ", match_json = '".$this->db->escape(json_encode(array('allocations' => $res['allocations'], 'skonto' => $res['skonto'], 'late' => $res['late'] ?? array())))."'";
		$sql .= ", match_note = '".$this->db->escape($note)."'";
		$sql .= " WHERE rowid = ".((int) $id);
		$this->db->query($sql);
	}

	/**
	 * Re-run the matching for a transaction in review (used for rows stored before a late skonto
	 * could be acknowledged). Only updates rows still in review.
	 *
	 * @param  int $txid Transaction id
	 * @return int       1 if the proposal was updated, 0 if not
	 */
	public function rematch($txid)
	{
		global $conf;

		$sql = "SELECT rowid, datetx, amount, account, purpose FROM ".MAIN_DB_PREFIX."fintssync_tx";
		$sql .= " WHERE rowid = ".((int) $txid)." AND entity = ".((int) $conf->entity)." AND status = ".self::STATUS_REVIEW;
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return 0;
		}
		$matcher = new FintsSyncMatcher(getDolGlobalString('FINTSSYNC_DEFAULT_MODE', 'review'));
		$tx = array('date' => $this->db->jdate($obj->datetx), 'amount' => (float) $obj->amount, 'account' => (string) $obj->account, 'purpose' => (string) $obj->purpose);
		$res = $matcher->match($tx, $this->loadOpenInvoices(), $this->loadSettings(), $this->loadIbanMap());
		if ($res['result'] == 'none' || empty($res['allocations'])) {
			return 0;
		}
		// Never book from here, only refresh the proposal
		$this->setTxStatus($obj->rowid, self::STATUS_REVIEW, $res, $res['note']);
		return 1;
	}

	/**
	 * Book a matched transaction: create the payment through core Paiement class, close invoices.
	 * A skonto is not hidden in the payment: invoice gets the payment, then is closed with code 'discount_vat'.
	 *
	 * @param  User  $user  User
	 * @param  int   $txid  Transaction id
	 * @param  array $res   Matcher result (allocations, skonto, kind, socid)
	 * @return int          >0 if OK, <0 if KO
	 */
	public function book($user, $txid, $res)
	{
		global $langs;

		$sql = "SELECT datetx, amount, name, eref, status FROM ".MAIN_DB_PREFIX."fintssync_tx WHERE rowid = ".((int) $txid);
		$resql = $this->db->query($sql);
		$tx = $resql ? $this->db->fetch_object($resql) : null;
		if (!$tx || $tx->status == self::STATUS_BOOKED || empty($res['allocations'])) {
			$this->error = 'Nothing to book';
			return -1;
		}

		$this->db->begin();
		$error = 0;
		$skontoClosed = array();

		$paiement = new Paiement($this->db);
		$paiement->datepaye = $this->db->jdate($tx->datetx);
		$paiement->amounts = array();
		foreach ($res['allocations'] as $invid => $amount) {
			$paiement->amounts[(int) $invid] = (float) $amount;
		}
		$paiement->paiementcode = 'VIR';
		$paiement->paiementid = dol_getIdFromCode($this->db, 'VIR', 'c_paiement', 'code', 'id', 1);
		// Short payment number: the payer's end-to-end id is often long, padded or NOTPROVIDED and the
		// invoice PDF column is narrow. The full text stays in the transaction cache.
		$num = trim(preg_replace('/\s+/', ' ', (string) $tx->eref));
		$paiement->num_payment = (strlen($num) > 20 || strtoupper($num) == 'NOTPROVIDED') ? '' : $num;
		$paiement->note_private = 'FintsSync: '.dol_trunc((string) $tx->name, 100, 'right', 'UTF-8', 1);

		// Fully paid invoices are closed by the core (closepaidinvoices=1)
		$paiementId = $paiement->create($user, 1);
		if ($paiementId <= 0) {
			$this->error = $paiement->error;
			$error++;
		}

		// Optional link to a Dolibarr bank account, only if the Bank module is enabled and an account is configured
		if (!$error && isModEnabled('bank') && getDolGlobalInt('FINTSSYNC_BANKACCOUNT') > 0) {
			$label = '(CustomerInvoicePayment)';
			if ($paiement->addPaymentToBank($user, 'payment', $label, getDolGlobalInt('FINTSSYNC_BANKACCOUNT'), (string) $tx->name, '') < 0) {
				$this->error = $paiement->error;
				$error++;
			}
		}

		// Skonto: close the invoice with the discount close code
		if (!$error && !empty($res['skonto'])) {
			foreach ($res['skonto'] as $invid => $skontoAmount) {
				$inv = new Facture($this->db);
				if ($inv->fetch((int) $invid) <= 0) {
					$error++;
					continue;
				}
				if ($inv->paye) {
					continue;
				}
				$note = 'Skonto '.price($skontoAmount).' ('.dol_print_date(dol_now(), 'day').', FintsSync)';
				if (!empty($res['late'][$invid])) {
					$note = 'Skonto '.price($skontoAmount).' ('.dol_print_date(dol_now(), 'day').', FintsSync, '.((int) $res['late'][$invid]).' d)';
				}
				if ($inv->setPaid($user, Facture::CLOSECODE_DISCOUNTVAT, $note) < 0) {
					$this->error = $inv->error;
					$error++;
				} else {
					$skontoClosed[] = (int) $invid;
				}
			}
		}

		if (!$error) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."fintssync_tx SET status = ".self::STATUS_BOOKED.", fk_soc = ".((int) $res['socid']);
			$sql .= ", match_kind = '".$this->db->escape($res['kind'])."', match_note = '', fk_paiement = ".((int) $paiementId);
			$sql .= ", match_json = '".$this->db->escape(json_encode(array('allocations' => $res['allocations'], 'skonto' => $res['skonto'], 'late' => $res['late'] ?? array())))."'";
			$sql .= " WHERE rowid = ".((int) $txid);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$error++;
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		// The core regenerates the PDF inside Paiement::create(), i.e. before the skonto close above, so the
		// document still shows the discount as open amount. Regenerate now that the invoice is closed.
		// Done after commit: a PDF problem must not undo a correct booking.
		if (!empty($skontoClosed) && !getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
			foreach ($skontoClosed as $invid) {
				$this->regenerateInvoiceDocument((int) $invid);
			}
		}
		return 1;
	}

	/**
	 * Regenerate the PDF of an invoice (same language/options handling as core after a payment).
	 *
	 * @param  int $invid Invoice id
	 * @return int        >0 if OK, <=0 if KO (only logged)
	 */
	protected function regenerateInvoiceDocument($invid)
	{
		global $conf, $langs;

		$inv = new Facture($this->db);
		if ($inv->fetch($invid) <= 0) {
			return 0;
		}
		$outputlangs = $langs;
		if (getDolGlobalInt('MAIN_MULTILANGS')) {
			$inv->fetch_thirdparty();
			if (is_object($inv->thirdparty) && !empty($inv->thirdparty->default_lang)) {
				$outputlangs = new Translate('', $conf);
				$outputlangs->setDefaultLang($inv->thirdparty->default_lang);
			}
		}
		$hidedetails = getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS') ? 1 : 0;
		$hidedesc = getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') ? 1 : 0;
		$hideref = getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_REF') ? 1 : 0;

		$result = $inv->generateDocument($inv->model_pdf, $outputlangs, $hidedetails, $hidedesc, $hideref);
		if ($result <= 0) {
			dol_syslog(get_class($this).'::regenerateInvoiceDocument failed for invoice '.$invid.': '.$inv->error, LOG_WARNING);
		}
		return $result;
	}
}
