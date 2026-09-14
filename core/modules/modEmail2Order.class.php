<?php
/* Copyright (C) 2026 dolibarr-email2order contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       core/modules/modEmail2Order.class.php
 * \ingroup    email2order
 * \brief      Email2Order module descriptor.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Email2Order module descriptor.
 */
class modEmail2Order extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 550100;
		$this->rights_class = 'email2order';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Email2OrderDescription';
		$this->descriptionlong = 'Email2OrderDescriptionLong';
		$this->editor_name = 'dolibarr-email2order contributors';
		$this->editor_url = 'https://github.com/vanyolai/dolibarr-email2order';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'supplier_order';

		$this->module_parts = array(
			'hooks' => array(
				'data' => array(
					'emailcollectorcard',
					// Dolibarr 23.0 uses this spelling in EmailCollector::doCollectOneCollector().
					'emailcolector',
				),
			),
		);

		$this->dirs = array('/email2order/temp');
		$this->config_page_url = array();
		$this->hidden = false;
		$this->depends = array('always' => array('modFournisseur', 'modEmailCollector'));
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('email2order@email2order');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(23, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();

		if (!isset($conf->email2order)) {
			$conf->email2order = new stdClass();
			$conf->email2order->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * @param string $options Activation options
	 * @return int
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/email2order/sql/');
		if ($result < 0) {
			return -1;
		}

		return $this->_init(array(), $options);
	}

	/**
	 * @param string $options Removal options
	 * @return int
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
