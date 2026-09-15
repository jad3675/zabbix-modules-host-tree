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

namespace Modules\BGmotHostsComponents\Actions;

use CControllerResponseData;
use CRoleHelper;
use CUrl;

/**
 * Controller for the Hosts | Components asynchronous tree refresh.
 */
class CControllerBGHostViewRefresh extends CControllerBGHostView {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function doAction(): void {
		$filter = static::FILTER_FIELDS_DEFAULT;

		$this->getInputs($filter, ['name', 'groupids', 'ip', 'dns', 'port', 'status', 'evaltype', 'tags',
			'maintenance_status', 'sort', 'sortorder'
		]);
		$filter = $this->cleanInput($filter);
		$filter = self::sanitizeFilter($filter);

		$view_url = (new CUrl())->setArgument('action', 'bghostcomp.view');

		$data = [
			'filter' => $filter,
			'view_curl' => $view_url,
			'sort' => $filter['sort'],
			'sortorder' => $filter['sortorder'],
			'allowed_ui_latest_data' => $this->checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA),
			'allowed_ui_problems' => $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS)
		] + $this->getData($filter);

		$response = new CControllerResponseData($data);
		$this->setResponse($response);
	}
}
