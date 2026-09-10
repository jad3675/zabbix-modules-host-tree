<?php declare(strict_types = 1);

/*
** Component Tree-View extension for BGmot/zabbix-module-hosts-tree.
**
** Lazy-loads a single host's items on host-row expand, groups them into
** component buckets (and LLD instances), and hands a partial to the response.
**
** Never fetches more than one host. All access goes through the frontend API
** layer in the logged-in user context, so host-group permissions are inherited.
**/

namespace Modules\BGmotHostsComponents\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;

class CControllerBGHostComponentView extends CControllerBGHost {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// Gate on user type as the base view does; actual host scoping is
		// enforced by the API through host-group permission inheritance.
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');

		// The load-bearing fetch. preservekeys so we can index by itemid.
		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type',
				'status', 'valuemapid'
			],
			'selectTags' => ['tag', 'value'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostid,
			'webitems' => false,
			'filter' => ['status' => ITEM_STATUS_ACTIVE],
			'sortfield' => 'name',
			'preservekeys' => true
		]);

		$interfaces = API::HostInterface()->get([
			'output' => ['interfaceid', 'ip', 'dns', 'useip', 'type', 'main'],
			'hostids' => $hostid
		]);

		$allowed_ui_latest_data = $this->checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);

		// Phase 3.1: attribute the highest active-problem severity to each item so
		// bucket/instance headers can bleed-through. Empty/failure tolerant.
		$item_severity = $this->getItemSeverities($hostid, array_keys($items));

		$grouper = new CBGComponentGrouper();
		$grouped = $items ? $grouper->groupWithInstances($items) : [];

		$data = [
			'hostid' => $hostid,
			'grouped' => $grouped,
			'interfaces' => $interfaces,
			'item_severity' => $item_severity,
			'allowed_ui_latest_data' => $allowed_ui_latest_data
		];

		$response = new CControllerResponseData($data);
		$this->setResponse($response);
	}

	/**
	 * Build itemid => highest active problem severity map.
	 *
	 * Maps active problems -> triggers -> items. Returns [] on no problems so the
	 * renderer can treat "no severity" uniformly. Never throws on hosts without
	 * triggers/problems.
	 *
	 * @param string $hostid
	 * @param array  $itemids  Item ids present on this host (to bound the trigger query).
	 *
	 * @return array  itemid => severity (int)
	 */
	private function getItemSeverities(string $hostid, array $itemids): array {
		if (!$itemids) {
			return [];
		}

		// Triggers on this host with the items they depend on.
		$triggers = API::Trigger()->get([
			'output' => [],
			'selectItems' => ['itemid'],
			'hostids' => $hostid,
			'monitored' => true,
			'skipDependent' => true,
			'preservekeys' => true
		]);

		if (!$triggers) {
			return [];
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'severity', 'objectid'],
			'objectids' => array_keys($triggers),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'recent' => false
		]);

		if (!$problems) {
			return [];
		}

		$itemids_lookup = array_flip(array_map('strval', $itemids));
		$item_severity = [];

		foreach ($problems as $problem) {
			$triggerid = $problem['objectid'];

			if (!array_key_exists($triggerid, $triggers)) {
				continue;
			}

			$severity = (int) $problem['severity'];

			foreach ($triggers[$triggerid]['items'] as $trigger_item) {
				$itemid = $trigger_item['itemid'];

				if (!array_key_exists($itemid, $itemids_lookup)) {
					continue;
				}

				if (!array_key_exists($itemid, $item_severity) || $item_severity[$itemid] < $severity) {
					$item_severity[$itemid] = $severity;
				}
			}
		}

		return $item_severity;
	}
}
