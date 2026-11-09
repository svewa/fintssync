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
 * \file       htdocs/custom/fintssync/class/fintssyncpaypal.class.php
 * \ingroup    fintssync
 * \brief      PayPal Transaction Search (reporting API) client. Only reads, never books.
 */

/**
 * PayPal client. Constants: FINTSSYNC_PAYPAL_CLIENTID, FINTSSYNC_PAYPAL_SECRET (encrypted by Dolibarr).
 */
class FintsSyncPaypal
{
	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var string
	 */
	protected $base = 'https://api-m.paypal.com';

	/**
	 * Is PayPal configured?
	 *
	 * @return bool
	 */
	public static function isConfigured()
	{
		return getDolGlobalString('FINTSSYNC_PAYPAL_CLIENTID') !== '' && getDolGlobalString('FINTSSYNC_PAYPAL_SECRET') !== '';
	}

	/**
	 * HTTP request.
	 *
	 * @param  string $method  GET|POST
	 * @param  string $url     URL
	 * @param  array  $headers Headers
	 * @param  string $body    POST body
	 * @param  string $userpwd user:password for basic auth
	 * @return array|false     Decoded JSON, false on error ($this->error)
	 */
	protected function request($method, $url, $headers = array(), $body = '', $userpwd = '')
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $headers));
		if ($method == 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		if ($userpwd !== '') {
			curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
		}
		$out = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($out === false) {
			$this->error = 'PayPal: '.$err;
			return false;
		}
		$json = json_decode($out, true);
		if ($code >= 300 || !is_array($json)) {
			$msg = is_array($json) ? ($json['error_description'] ?? $json['message'] ?? $json['error'] ?? '') : '';
			$this->error = 'PayPal HTTP '.$code.' '.$msg;
			return false;
		}
		return $json;
	}

	/**
	 * Get an access token.
	 *
	 * @return string|false
	 */
	protected function token()
	{
		$r = $this->request('POST', $this->base.'/v1/oauth2/token', array('Accept: application/json'), 'grant_type=client_credentials', getDolGlobalString('FINTSSYNC_PAYPAL_CLIENTID').':'.getDolGlobalString('FINTSSYNC_PAYPAL_SECRET'));
		return ($r && !empty($r['access_token'])) ? $r['access_token'] : false;
	}

	/**
	 * Convert one PayPal transaction_details entry into our transaction array.
	 *
	 * @param  array $d Entry
	 * @return array|null Transaction, null if not usable
	 */
	public static function convert($d)
	{
		$ti = $d['transaction_info'] ?? array();
		if (empty($ti['transaction_id']) || ($ti['transaction_status'] ?? '') !== 'S' || !isset($ti['transaction_amount']['value'])) {
			return null;
		}
		$ts = strtotime($ti['transaction_initiation_date'] ?? '');
		if (!$ts) {
			return null;
		}
		$items = array();
		foreach (($d['cart_info']['item_details'] ?? array()) as $it) {
			$items[] = trim(($it['item_name'] ?? '').' '.($it['item_description'] ?? ''));
		}
		$purpose = trim(implode(' | ', array_filter(array_merge($items, array($ti['transaction_subject'] ?? '', $ti['transaction_note'] ?? '')))));
		$payer = $d['payer_info'] ?? array();
		$name = $payer['payer_name']['alternate_full_name'] ?? ($d['shipping_info']['name'] ?? '');
		$name = trim((string) $name);
		return array(
			'source' => 'paypal',
			'own_iban' => 'PAYPAL',
			'date' => $ts,
			'amount' => (float) $ti['transaction_amount']['value'],
			'fee' => (float) ($ti['fee_amount']['value'] ?? 0),
			'currency' => $ti['transaction_amount']['currency_code'] ?? 'EUR',
			'name' => $name,
			'account' => (string) ($payer['email_address'] ?? ''),
			'eref' => (string) $ti['transaction_id'],
			'purpose' => $purpose,
			'invoice_ref' => (string) ($ti['invoice_id'] ?? ''),
			'txtype' => (string) ($ti['transaction_event_code'] ?? ''),
		);
	}

	/**
	 * Fetch the transactions of a period (max 31 days, paged).
	 *
	 * @param  int  $from Timestamp
	 * @param  int  $to   Timestamp
	 * @param  bool $raw  Return raw entries instead of converted transactions
	 * @return array|false
	 */
	public function fetch($from, $to, $raw = false)
	{
		$tok = $this->token();
		if (!$tok) {
			if ($this->error === '') {
				$this->error = 'PayPal: no access token';
			}
			return false;
		}
		$from = max($from, $to - 31 * 86400 + 60);
		$out = array();
		$page = 1;
		do {
			$url = $this->base.'/v1/reporting/transactions?fields=all&page_size=500&page='.$page;
			$url .= '&start_date='.rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $from)).'&end_date='.rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $to));
			$r = $this->request('GET', $url, array('Authorization: Bearer '.$tok, 'Accept: application/json'));
			if ($r === false) {
				return false;
			}
			foreach (($r['transaction_details'] ?? array()) as $d) {
				$t = $raw ? $d : self::convert($d);
				if ($t !== null) {
					$out[] = $t;
				}
			}
			$pages = (int) ($r['total_pages'] ?? 1);
			$page++;
		} while ($page <= $pages && $page < 50);
		return $out;
	}
}
