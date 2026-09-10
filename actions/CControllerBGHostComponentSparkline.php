<?php declare(strict_types = 1);

/*
** Component Tree-View extension for BGmot/zabbix-module-hosts-tree.
**
** Opt-in lazy sparklines. Fetches recent history for a bounded set of itemids
** belonging to ONE host (the items of a single, currently-expanded bucket) and
** returns a small inline SVG per item. Never fetches history unless the user
** clicks the per-bucket "trends" toggle, and never spans more than one host.
**/

namespace Modules\BGmotHostsComponents\Actions;

use API;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;

class CControllerBGHostComponentSparkline extends CControllerBGHost {

	// How far back to pull history for the sparkline (seconds).
	const WINDOW = 3600;

	// Hard cap on items per request, so a huge bucket can't trigger a heavy fetch.
	const MAX_ITEMS = 60;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid',
			'itemids' => 'required|array_db items.itemid'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA);
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$itemids = array_slice($this->getInput('itemids'), 0, self::MAX_ITEMS);

		// Re-resolve items through the API in user context: this both enforces
		// host-group permissions and confirms the items really belong to $hostid.
		// Only numeric items can have a sparkline.
		$items = API::Item()->get([
			'output' => ['itemid', 'value_type'],
			'itemids' => $itemids,
			'hostids' => $hostid,
			'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'webitems' => false,
			'preservekeys' => true
		]);

		$sparklines = [];

		if ($items) {
			$time_from = time() - self::WINDOW;

			// History lives in a different table per value_type, so query per type.
			$by_type = [];
			foreach ($items as $itemid => $item) {
				$by_type[(int) $item['value_type']][] = $itemid;
			}

			$series = [];
			foreach ($by_type as $value_type => $type_itemids) {
				$history = API::History()->get([
					'output' => ['itemid', 'clock', 'value'],
					'history' => $value_type,
					'itemids' => $type_itemids,
					'time_from' => $time_from,
					'sortfield' => 'clock',
					'sortorder' => ZBX_SORT_UP
				]);

				foreach ($history as $point) {
					$series[$point['itemid']][] = (float) $point['value'];
				}
			}

			foreach ($items as $itemid => $item) {
				if (array_key_exists($itemid, $series) && count($series[$itemid]) > 1) {
					$sparklines[$itemid] = self::renderSparkline($series[$itemid]);
				}
			}
		}

		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => json_encode(['sparklines' => $sparklines])
			]))->disableView()
		);
	}

	/**
	 * Render a dependency-free inline SVG sparkline (polyline) from a value series.
	 * Flat series render as a centred flat line. Output is safe static markup.
	 *
	 * @param array $values  Ordered list of floats.
	 *
	 * @return string  SVG markup.
	 */
	private static function renderSparkline(array $values): string {
		$w = 90;
		$h = 20;
		$pad = 2;

		$min = min($values);
		$max = max($values);
		$range = ($max - $min) > 0 ? ($max - $min) : 1.0;
		$n = count($values);
		$step = ($n > 1) ? ($w - 2 * $pad) / ($n - 1) : 0;

		$points = [];
		foreach ($values as $i => $v) {
			$x = $pad + $i * $step;
			// Invert y so higher values sit higher on screen.
			$y = $pad + ($h - 2 * $pad) * (1 - (($v - $min) / $range));
			$points[] = round($x, 1).','.round($y, 1);
		}

		$polyline = implode(' ', $points);

		return '<svg class="bgcomp-spark-svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"'
			.' preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">'
			.'<polyline fill="none" stroke="currentColor" stroke-width="1" stroke-linejoin="round"'
			.' stroke-linecap="round" points="'.$polyline.'"/>'
			.'</svg>';
	}
}
