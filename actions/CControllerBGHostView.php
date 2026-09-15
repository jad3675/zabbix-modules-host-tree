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

use API;
use CArrayHelper;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CUrl;

class CControllerBGHostView extends CControllerBGHost {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'name' =>			'string',
			'groupids' =>			'array_id',
			'ip' =>				'string',
			'dns' =>			'string',
			'port' =>			'string',
			'status' =>			'in -1,'.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'evaltype' =>			'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'tags' =>			'array',
			'maintenance_status' =>		'in '.HOST_MAINTENANCE_STATUS_OFF.','.HOST_MAINTENANCE_STATUS_ON,
			'sort' =>			'in name,status',
			'sortorder' =>			'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1'
		];

		$ret = $this->validateInput($fields);

		// Validate tags filter.
		if ($ret && $this->hasInput('tags')) {
			foreach ($this->getInput('tags') as $filter_tag) {
				if (count($filter_tag) != 3
						|| !array_key_exists('tag', $filter_tag) || !is_string($filter_tag['tag'])
						|| !array_key_exists('value', $filter_tag) || !is_string($filter_tag['value'])
						|| !array_key_exists('operator', $filter_tag) || !is_string($filter_tag['operator'])) {
					$ret = false;
					break;
				}
			}
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		// Simple, self-contained filter: read the current values straight from the request (or fall back to
		// defaults on reset). No CTabFilterProfile, no shared 'web.monitoring.hosts' state, no templated tab
		// machinery -- that indirection is what kept breaking the host-group multiselect.
		$filter = static::FILTER_FIELDS_DEFAULT;

		if (!$this->hasInput('filter_rst')) {
			$this->getInputs($filter, ['name', 'groupids', 'ip', 'dns', 'port', 'status', 'evaltype', 'tags',
				'maintenance_status', 'sort', 'sortorder'
			]);
		}

		$filter = $this->cleanInput($filter);
		$filter = self::sanitizeFilter($filter);

		// Pre-selected host groups for the multiselect widget (chips shown on load).
		$groups_multiselect = [];

		if ($filter['groupids']) {
			$groups = API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids'],
				'preservekeys' => true
			]);
			$groups_multiselect = CArrayHelper::renameObjectsKeys(array_values($groups), ['groupid' => 'id']);
		}

		// Carry the current filter on the AJAX refresh URL so the initial tree load already reflects it.
		$refresh_curl = (new CUrl('zabbix.php'))->setArgument('action', 'bghostcomp.view.refresh');

		foreach (['name', 'status', 'evaltype', 'maintenance_status', 'sort', 'sortorder'] as $key) {
			$refresh_curl->setArgument($key, $filter[$key]);
		}

		if ($filter['groupids']) {
			$refresh_curl->setArgument('groupids', $filter['groupids']);
		}

		if ($filter['tags']) {
			$refresh_curl->setArgument('tags', $filter['tags']);
		}

		$data = [
			'refresh_url' => $refresh_curl->getUrl(),
			'refresh_interval' => 0,
			'filter' => $filter,
			'groups_multiselect' => $groups_multiselect,
			'filter_groupids' => $filter['groupids'],
			'can_create_hosts' => $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Hosts'));
		$this->setResponse($response);
	}
}
