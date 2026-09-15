<?php declare(strict_types = 1);
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * @var CView $this
 */

$this->addJsFile('layout.mode.js');
$this->addJsFile('multiselect.js');

$this->includeJsFile('monitoring.host.view.js.php', $data);

$this->enableLayoutModes();
$web_layout_mode = $this->getLayoutMode();
$nav_items = new CList();

if ($data['can_create_hosts']) {
	$nav_items->addItem(
		(new CSimpleButton(_('Create host')))
			->addClass('js-create-host')
	);
}

$nav_items->addItem(get_icon('kioskmode', ['mode' => $web_layout_mode]));

$html_page = (new CHtmlPage())
	->setTitle(_('Hosts'))
	->setWebLayoutMode($web_layout_mode)
	->setDocUrl(CDocHelper::getUrl(CDocHelper::MONITORING_HOST_VIEW))
	->setControls((new CTag('nav', true, $nav_items))
		->setAttribute('aria-label', _('Content controls'))
	);

if ($web_layout_mode == ZBX_LAYOUT_NORMAL) {
	$filter = $data['filter'];

	// Host-group multiselect. add_post_js defaults to true, so CMultiSelect emits its own inline init script and
	// builds itself on document-ready -- no dependency on the tab-filter render event that previously never fired.
	// add_post_js is disabled on purpose. CMultiSelect builds its self-init script at CONSTRUCTION time using the
	// id derived from the field name ('groupids_'); calling setId() afterwards would leave that script pointing at
	// a now-nonexistent element, so the widget would never initialize (no box, not typeable). Instead we set a
	// known id here and initialize it explicitly in the document-ready script below, against that same id.
	$groups_multiselect = (new CMultiSelect([
		'name' => 'groupids[]',
		'object_name' => 'hostGroup',
		'data' => $data['groups_multiselect'],
		'add_post_js' => false,
		'popup' => [
			'parameters' => [
				'srctbl' => 'host_groups',
				'srcfld1' => 'groupid',
				'dstfrm' => 'zbx_filter',
				'dstfld1' => 'filter_groupids',
				'with_hosts' => true,
				'enrich_parent_groups' => true
			]
		]
	]))
		->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		->setId('filter_groupids');

	$filter_column1 = (new CFormList())
		->addRow(_('Name'),
			(new CTextBox('name', $filter['name']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		)
		->addRow((new CLabel(_('Host groups'), 'filter_groupids_ms')), $groups_multiselect);

	$filter_column2 = (new CFormList())
		->addRow(_('Status'),
			(new CRadioButtonList('status', (int) $filter['status']))
				->addValue(_('Any'), -1)
				->addValue(_('Enabled'), HOST_STATUS_MONITORED)
				->addValue(_('Disabled'), HOST_STATUS_NOT_MONITORED)
				->setModern(true)
		);

	$html_page->addItem(
		(new CFilter())
			->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'bghostcomp.view'))
			->setProfile('web.monitoring.bghostcomp.filter')
			->setActiveTab(1)
			->addVar('action', 'bghostcomp.view')
			->addFilterTab(_('Filter'), [$filter_column1, $filter_column2])
	);
}

$html_page
	->addItem(
		(new CForm())
			->setName('host_view')
			->addClass('is-loading')
	)->show();
// Resolve the CSS URL from the module's own deployed path instead of hardcoding the folder name.
// The directory Zabbix installs this under is not guaranteed to be 'zabbix-module-hosts-components',
// and a wrong path makes CHtmlPageHeader::filemtime() fail (stat failed) and the styles never load.
$bghost_module = APP::ModuleManager()->getModule('bghostcomp');
$this->addCssFile(($bghost_module !== null
	? $bghost_module->getRelativePath()
	: 'modules/zabbix-module-hosts-components'
).'/views/css/bghost.css');

(new CScriptTag('
	jQuery("#filter_groupids").multiSelect();
	view.init('.json_encode([
		'refresh_url' => $data['refresh_url'],
		'refresh_interval' => $data['refresh_interval'],
		'applied_filter_groupids' => $data['filter_groupids']
	]).');
'))
	->setOnDocumentReady()
	->show();
