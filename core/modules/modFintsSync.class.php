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
 *  \defgroup   fintssync     Module FintsSync
 *  \brief      FinTS bank synchronisation: imports bank transactions and marks customer invoices as paid.
 *
 *  \file       htdocs/custom/fintssync/core/modules/modFintsSync.class.php
 *  \ingroup    fintssync
 *  \brief      Description and activation file for module FintsSync
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module FintsSync
 */
class modFintsSync extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;

		$this->numero = 500250;
		$this->rights_class = 'fintssync';
		$this->family = 'financial';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'FintsSyncDescription';
		$this->descriptionlong = 'FintsSyncDescription';
		$this->editor_name = 'Cline';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bank';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array("/fintssync");
		$this->config_page_url = array("setup.php@fintssync");

		// Dependencies: only customer invoices. The Bank module is NOT required.
		$this->hidden = false;
		$this->depends = array('modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("fintssync@fintssync");
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(19, 0);

		$this->const = array(
			1 => array('FINTSSYNC_URL', 'chaine', 'https://fints2.atruvia.de/cgi-bin/hbciservlet', 'FinTS URL', 1, 'current', 0),
			2 => array('FINTSSYNC_DEFAULT_MODE', 'chaine', 'review', 'Default booking mode (auto|review)', 1, 'current', 0),
		);

		// Tab on the third party card to configure allowed skonto
		$this->tabs = array(
			'thirdparty:+fintssync:FintsSyncSkonto:fintssync@fintssync:$user->hasRight("fintssync", "read") && $object->client > 0:/fintssync/customer.php?socid=__ID__',
		);

		$this->dictionaries = array();
		$this->boxes = array();

		// Cron job: fetch transactions and match them
		$this->cronjobs = array(
			0 => array(
				'label' => 'FintsSyncCronJob',
				'jobtype' => 'method',
				'class' => '/fintssync/class/fintssynccron.class.php',
				'objectname' => 'FintsSyncCron',
				'method' => 'run',
				'parameters' => '',
				'comment' => 'FintsSyncCronJobComment',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("fintssync")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'FintsSyncCronMonthly',
				'jobtype' => 'method',
				'class' => '/fintssync/class/fintssynccron.class.php',
				'objectname' => 'FintsSyncCron',
				'method' => 'runMonthly',
				'parameters' => '',
				'comment' => 'FintsSyncCronMonthlyComment',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("fintssync")',
				'priority' => 60,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Read bank transactions imported by FintsSync';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Synchronise bank, book payments and edit skonto settings';
		$this->rights[$r][4] = 'write';
		$this->rights[$r][5] = '';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'FintsSyncMenu',
			'prefix' => img_picto('', 'bank', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'fintssync',
			'url' => '/fintssync/list.php',
			'langs' => 'fintssync@fintssync',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("fintssync")',
			'perms' => '$user->hasRight("fintssync", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=fintssync',
			'type' => 'left',
			'titre' => 'FintsSyncSyncNow',
			'mainmenu' => 'billing',
			'leftmenu' => 'fintssync_sync',
			'url' => '/fintssync/sync.php',
			'langs' => 'fintssync@fintssync',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("fintssync")',
			'perms' => '$user->hasRight("fintssync", "write")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=fintssync',
			'type' => 'left',
			'titre' => 'FintsSyncExport',
			'mainmenu' => 'billing',
			'leftmenu' => 'fintssync_export',
			'url' => '/fintssync/export.php',
			'langs' => 'fintssync@fintssync',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("fintssync")',
			'perms' => '$user->hasRight("fintssync", "read")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 * Function called when module is enabled. Creates tables and permissions.
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int            1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/fintssync/sql/');
		if ($result < 0) {
			return -1;
		}

		// Upgrade of an existing table: add columns that are missing
		$cols = array(
			'own_iban' => "varchar(40) AFTER name",
			'source' => "varchar(8) DEFAULT 'bank' AFTER own_iban",
			'currency' => "varchar(3) DEFAULT 'EUR' AFTER source",
			'fee' => "double(24,8) DEFAULT 0 AFTER currency",
			'invoice_ref' => "varchar(128) AFTER fee",
			'txtype' => "varchar(16) AFTER invoice_ref",
		);
		foreach ($cols as $col => $def) {
			$resql = $this->db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."fintssync_tx LIKE '".$col."'");
			if ($resql && $this->db->num_rows($resql) == 0) {
				if (!$this->db->query("ALTER TABLE ".MAIN_DB_PREFIX."fintssync_tx ADD COLUMN ".$col." ".$def)) {
					$this->error = $this->db->lasterror();
					return -1;
				}
			}
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled. Data is kept.
	 *
	 * @param  string $options Options when disabling module
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
