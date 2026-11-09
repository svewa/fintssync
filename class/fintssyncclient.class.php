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
 * \file       htdocs/custom/fintssync/class/fintssyncclient.class.php
 * \ingroup    fintssync
 * \brief      Wrapper around the nemiah/php-fints library (login, accounts, statement, TAN handling).
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/fintssyncmatcher.class.php';

use Fhp\FinTs;
use Fhp\Options\FinTsOptions;
use Fhp\Options\Credentials;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetStatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction;

/**
 * FinTS client. Result of start()/submitTan(): array(
 *   'status' => 'done'|'tan'|'decoupled'|'error',
 *   'message' => string, 'transactions' => array)
 * When status is tan/decoupled, the session is stored (encrypted constant FINTSSYNC_STATE_SECRET)
 * and the process can be resumed later with submitTan().
 */
class FintsSyncClient
{
	/**
	 * @var DoliDB
	 */
	protected $db;

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
	 * Create FinTs instance from module configuration.
	 *
	 * @param  string|null $persisted Persisted FinTs state or null
	 * @return FinTs
	 */
	public function createFints($persisted = null)
	{
		$options = new FinTsOptions();
		$options->url = getDolGlobalString('FINTSSYNC_URL');
		$options->bankCode = getDolGlobalString('FINTSSYNC_BLZ');
		$options->productName = getDolGlobalString('FINTSSYNC_PRODUCT_ID');
		$options->productVersion = '1.0';
		$credentials = Credentials::create(getDolGlobalString('FINTSSYNC_USER'), getDolGlobalString('FINTSSYNC_PASSWORD'));
		$fints = FinTs::new($options, $credentials, $persisted);
		$fints->setTimeouts(15, 60);
		$tanmode = getDolGlobalString('FINTSSYNC_TANMODE');
		if ($tanmode !== '') {
			$medium = getDolGlobalString('FINTSSYNC_TANMEDIUM');
			$fints->selectTanMode((int) $tanmode, $medium !== '' ? $medium : null);
		}
		return $fints;
	}

	/**
	 * List available TAN modes (for the setup page).
	 *
	 * @return array id => name
	 */
	public function listTanModes()
	{
		$out = array();
		$fints = $this->createFints();
		foreach ($fints->getTanModes() as $id => $mode) {
			$out[$id] = $mode->getName();
		}
		return $out;
	}

	/**
	 * Save session state in an encrypted constant.
	 *
	 * @param  FinTs $fints  Instance
	 * @param  mixed $action Pending action
	 * @param  array $ctx    Context (stage, account)
	 * @return void
	 */
	protected function saveState($fints, $action, $ctx)
	{
		$state = serialize(array($fints->persist(), serialize($action), $ctx));
		dolibarr_set_const($this->db, 'FINTSSYNC_STATE_SECRET', base64_encode($state), 'chaine', 0, '', 0);
	}

	/**
	 * Forget a saved state.
	 *
	 * @return void
	 */
	public function clearState()
	{
		dolibarr_del_const($this->db, 'FINTSSYNC_STATE_SECRET', 0);
	}

	/**
	 * Is there a pending, TAN-waiting session?
	 *
	 * @return bool
	 */
	public function hasPending()
	{
		return getDolGlobalString('FINTSSYNC_STATE_SECRET') !== '';
	}

	/**
	 * Start a synchronisation.
	 *
	 * @return array Result
	 */
	public function start()
	{
		try {
			$fints = $this->createFints();
			$login = $fints->login();
			return $this->afterAction($fints, 'login', $login, array());
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => $e->getMessage(), 'transactions' => array());
		}
	}

	/**
	 * Resume a pending session with the TAN (or confirm decoupled authentication when $tan is empty).
	 *
	 * @param  string $tan TAN
	 * @return array       Result
	 */
	public function submitTan($tan)
	{
		try {
			$raw = base64_decode(getDolGlobalString('FINTSSYNC_STATE_SECRET'), true);
			if ($raw === false || $raw === '') {
				return array('status' => 'error', 'message' => 'NoPendingSession', 'transactions' => array());
			}
			list($persisted, $serializedAction, $ctx) = unserialize($raw);
			$fints = $this->createFints($persisted);
			$action = unserialize($serializedAction);
			if ($tan === '' && $fints->getSelectedTanMode() && $fints->getSelectedTanMode()->isDecoupled()) {
				if (!$fints->checkDecoupledSubmission($action)) {
					$this->saveState($fints, $action, $ctx);
					return array('status' => 'decoupled', 'message' => 'WaitingForConfirmation', 'transactions' => array());
				}
			} else {
				$fints->submitTan($action, $tan);
			}
			$this->clearState();
			return $this->afterAction($fints, $ctx['stage'], $action, $ctx, true);
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => $e->getMessage(), 'transactions' => array());
		}
	}

	/**
	 * Advance the login -> accounts -> statement sequence until done or a TAN is required.
	 *
	 * @param  FinTs  $fints   Instance
	 * @param  string $stage   Stage of $action: login|accounts|statement
	 * @param  mixed  $action  Action that was executed (may need a TAN) or has just been completed
	 * @param  array  $ctx     Context
	 * @param  bool   $resumed True if the action was completed by a TAN
	 * @return array           Result
	 */
	protected function afterAction($fints, $stage, $action, $ctx, $resumed = false)
	{
		while (true) {
			if (!$resumed && $action->needsTan()) {
				$ctx['stage'] = $stage;
				$this->saveState($fints, $action, $ctx);
				$request = $action->getTanRequest();
				$decoupled = $fints->getSelectedTanMode() && $fints->getSelectedTanMode()->isDecoupled();
				$msg = (string) $request->getChallenge();
				if ($request->getTanMediumName()) {
					$msg .= ' ['.$request->getTanMediumName().']';
				}
				return array('status' => ($decoupled ? 'decoupled' : 'tan'), 'message' => $msg, 'transactions' => array());
			}
			$resumed = false;

			if ($stage == 'login') {
				$action = GetSEPAAccounts::create();
				$stage = 'accounts';
				$fints->execute($action);
				continue;
			}

			if ($stage == 'accounts') {
				// Every account of the login except the ignored ones
				$ctx['queue'] = array();
				$ctx['txs'] = array();
				foreach ($action->getAccounts() as $acc) {
					if (!in_array(FintsSyncMatcher::normalizeIban($acc->getIban()), self::ignoredIbans())) {
						$ctx['queue'][] = $acc;
					}
				}
				$stage = 'next';
			}

			if ($stage == 'statement') {
				$own = FintsSyncMatcher::normalizeIban($ctx['cur']->getIban());
				foreach ($this->extractTransactions($action) as $t) {
					$t['own_iban'] = $own;
					$ctx['txs'][] = $t;
				}
				$stage = 'next';
			}

			if ($stage == 'next') {
				if (empty($ctx['queue'])) {
					$fints->close();
					dolibarr_set_const($this->db, 'FINTSSYNC_LASTSYNC', (string) dol_now(), 'chaine', 0, '', 0);
					return array('status' => 'done', 'message' => '', 'transactions' => $ctx['txs']);
				}
				$ctx['cur'] = array_shift($ctx['queue']);
				$days = max(1, getDolGlobalInt('FINTSSYNC_DAYS', 5));
				$action = GetStatementOfAccount::create($ctx['cur'], new \DateTime('-'.$days.' days'), new \DateTime(), false, false);
				$stage = 'statement';
				$fints->execute($action);
				continue;
			}
		}
	}

	/**
	 * Normalized IBANs the user excluded in the setup (comma separated constant).
	 *
	 * @return array
	 */
	public static function ignoredIbans()
	{
		$out = array();
		foreach (explode(',', getDolGlobalString('FINTSSYNC_IGNORE_IBANS')) as $i) {
			if (trim($i) !== '') {
				$out[] = FintsSyncMatcher::normalizeIban($i);
			}
		}
		return $out;
	}

	/**
	 * List all accounts the login returns (for the setup page).
	 *
	 * @return array iban => description
	 */
	public function listAccounts()
	{
		$fints = $this->createFints();
		$login = $fints->login();
		if ($login->needsTan()) {
			throw new \Exception('TAN required, run a sync first');
		}
		$action = GetSEPAAccounts::create();
		$fints->execute($action);
		$out = array();
		foreach ($action->getAccounts() as $acc) {
			$out[FintsSyncMatcher::normalizeIban($acc->getIban())] = trim($acc->getIban().' '.$acc->getAccountNumber().' '.$acc->getBlz());
		}
		$fints->close();
		return $out;
	}

	/**
	 * Convert library transactions into plain arrays (credits positive, debits negative).
	 *
	 * @param  GetStatementOfAccount $action Completed action
	 * @return array
	 */
	protected function extractTransactions($action)
	{
		$out = array();
		foreach ($action->getStatement()->getStatements() as $statement) {
			foreach ($statement->getTransactions() as $t) {
				// Only booked entries are requested (includeUnbooked=false). Do not filter on getBooked():
				// the library's MT940 parser wrongly flags this bank's entries as unbooked.
				$date = $t->getBookingDate() ?: $t->getValutaDate();
				if (!$date) {
					continue;
				}
				$amount = (float) $t->getAmount();
				if ($t->getCreditDebit() == Transaction::CD_DEBIT) {
					$amount = -$amount;
				}
				$parts = array($t->getMainDescription(), $t->getDescription1(), $t->getDescription2());
				// Raw text is kept for the duplicate hash so that already imported rows stay recognised
				$purposeRaw = trim(implode(' ', array_filter($parts)));
				$purpose = FintsSyncMatcher::dedupePurpose($parts);
				$out[] = array(
					'date' => $date->getTimestamp(),
					'amount' => $amount,
					'name' => $t->getName(),
					'account' => $t->getAccountNumber(),
					'eref' => $t->getEndToEndID(),
					'purpose' => $purpose,
					'purpose_hash' => $purposeRaw,
				);
			}
		}
		return $out;
	}
}
