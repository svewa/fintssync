<?php
// Standalone test: php matcher_test.php
require_once __DIR__.'/../class/fintssyncmatcher.class.php';

$fail = 0;
function check($name, $cond)
{
	global $fail;
	echo ($cond ? 'ok   ' : 'FAIL ').$name."\n";
	if (!$cond) {
		$fail++;
	}
}

$d = strtotime('2026-03-01');
$inv = array(
	array('id' => 1, 'ref' => 'FA2603-0001', 'socid' => 10, 'date' => $d, 'remain' => 1190.00),
	array('id' => 2, 'ref' => 'FA2603-0002', 'socid' => 10, 'date' => $d, 'remain' => 100.00),
	array('id' => 3, 'ref' => 'FA2603-0003', 'socid' => 20, 'date' => $d, 'remain' => 500.00),
);
$settings = array(10 => array('percent' => 2, 'days' => 10, 'mode' => 'auto'), 20 => array('percent' => 0, 'days' => 0, 'mode' => 'review'));
$ibans = array('DE02120300000000202051' => 10);
$m = new FintsSyncMatcher();

$r = $m->match(array('date' => strtotime('2026-03-20'), 'amount' => 1190.00, 'account' => '', 'purpose' => 'Rechnung FA2603-0001 danke'), $inv, $settings, $ibans);
check('full pay auto => book/full', $r['result'] == 'book' && $r['kind'] == 'full' && $r['allocations'] == array(1 => 1190.0));

$r = $m->match(array('date' => strtotime('2026-03-08'), 'amount' => 1166.20, 'account' => '', 'purpose' => 'FA2603-0001'), $inv, $settings, $ibans);
check('skonto in time => book/skonto', $r['result'] == 'book' && $r['kind'] == 'skonto' && abs($r['skonto'][1] - 23.8) < 0.001);

$r = $m->match(array('date' => strtotime('2026-03-20'), 'amount' => 1166.20, 'account' => '', 'purpose' => 'FA2603-0001'), $inv, $settings, $ibans);
check('skonto too late => review with proposal and days overdue', $r['result'] == 'review' && $r['note'] == 'SkontoDeadlineMissed' && !empty($r['allocations']) && ($r['late'][1] ?? 0) == 9);

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => 1000.00, 'account' => '', 'purpose' => 'FA2603-0001'), $inv, $settings, $ibans);
check('wrong amount => review', $r['result'] == 'review' && $r['note'] == 'AmountMismatch');

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => 1166.20 + 98.00, 'account' => '', 'purpose' => 'FA2603-0001 und FA2603-0002'), $inv, $settings, $ibans);
check('two invoices both skonto => book', $r['result'] == 'book' && count($r['allocations']) == 2);

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => 500.00, 'account' => '', 'purpose' => 'FA2603-0003'), $inv, $settings, $ibans);
check('review-mode customer => review with proposal', $r['result'] == 'review' && $r['kind'] == 'full' && $r['allocations'] == array(3 => 500.0));

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => 98.00, 'account' => 'DE02 1203 0000 0000 2020 51', 'purpose' => 'ohne Referenz'), $inv, $settings, $ibans);
check('IBAN fallback with skonto => book', $r['result'] == 'book' && $r['allocations'] == array(2 => 98.0));

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => 77.00, 'account' => '', 'purpose' => 'nix'), $inv, $settings, $ibans);
check('unknown => none', $r['result'] == 'none');

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => -50.00, 'account' => '', 'purpose' => 'FA2603-0001'), $inv, $settings, $ibans);
check('debit ignored', $r['result'] == 'none');

$r = $m->match(array('date' => strtotime('2026-03-05'), 'amount' => 100.00, 'account' => '', 'purpose' => 'FA2603-00011'), $inv, $settings, $ibans);
check('ref prefix of longer number not matched', $r['result'] == 'none');

check('dedupe: identical parts', FintsSyncMatcher::dedupePurpose(array('RE FA2603-0001', 'RE FA2603-0001')) == 'RE FA2603-0001');
check('dedupe: repeated inside one field', FintsSyncMatcher::dedupePurpose(array('Rechnung FA2603-0001 Rechnung FA2603-0001')) == 'Rechnung FA2603-0001');
check('dedupe: part contained in another', FintsSyncMatcher::dedupePurpose(array('FA2603-0001', 'Zahlung FA2603-0001 Danke')) == 'Zahlung FA2603-0001 Danke');
check('dedupe: different parts kept', FintsSyncMatcher::dedupePurpose(array('Rechnung A', '', 'Kundennr 5')) == 'Rechnung A Kundennr 5');
check('dedupe: empty', FintsSyncMatcher::dedupePurpose(array(null, '')) == '');

exit($fail ? 1 : 0);
