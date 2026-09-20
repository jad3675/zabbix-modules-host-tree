<?php declare(strict_types = 1);

/*
** Component Tree-View extension for BGmot/zabbix-module-hosts-tree.
**
** Renders a single host's grouped items as <tr> rows injected beneath the host
** leaf row. Structure: bucket header -> [instance sub-header] -> item rows.
**
** Buckets and instances are both collapsible (LM/SL1-style progressive
** disclosure): collapsed by default, auto-expanded when they carry an active
** problem. Instance collapse uses its own hide class so it layers cleanly under
** the bucket collapse (re-expanding a bucket keeps its instances' state).
** Percentage metrics render as inline bar gauges coloured by severity/threshold.
**
** A per-host filter box sits at the top of the block. Rows carry a
** data-bgcomp-text haystack (lowercased) so the JS can filter client-side
** without re-querying. Instance headers show the LLD alias (ifAlias, i.e. the
** interface description) parsed out of the item name, and it is part of the
** haystack, so ports can be found by their neighbour's name.
**
** $data:
**   hostid                 string
**   grouped                bucket => [ instance|__direct => [ itemid => item ] ]
**   interfaces             [] (reserved)
**   item_severity          itemid => severity (int)
**   allowed_ui_latest_data bool
**/

/**
 * Number of columns in the host-tree table, so component rows span the full width.
 */
if (!defined('BGCOMP_COLSPAN')) {
	define('BGCOMP_COLSPAN', 10);
}

$hostid = $data['hostid'];
$grouped = $data['grouped'];
$item_severity = $data['item_severity'];
$allowed_ui_latest_data = $data['allowed_ui_latest_data'];

$rows = [];

if (!$grouped) {
	// Empty host: a single, non-fatal "no items" row.
	$rows[] = (new CRow(
		(new CCol([bgcomp_indent(1), (new CSpan(_('No items')))->addClass(ZBX_STYLE_GREY)]))
			->setColSpan(BGCOMP_COLSPAN)
	))->addClass('bgcomp-row');
}
else {
	$total_items = 0;

	foreach ($grouped as $bucket_instances) {
		$total_items += bgcomp_count_items($bucket_instances);
	}

	// Per-host filter box. Deliberately unnamed: this block is injected inside
	// the host-view form, and a named input would be submitted with the filter.
	$filter_input = (new CTag('input', false))
		->setAttribute('type', 'text')
		->setAttribute('placeholder', _('Filter components'))
		->setAttribute('autocomplete', 'off')
		->setAttribute('data-hostid', $hostid)
		->addClass('bgcomp-filter-input')
		->addClass('js-bgcomp-filter');

	$rows[] = (new CRow(
		(new CCol([
			bgcomp_indent(1),
			$filter_input,
			(new CSimpleButton(_('clear')))
				->addClass('bgcomp-filter-clear')
				->addClass('js-bgcomp-filter-clear')
				->setAttribute('data-hostid', $hostid),
			(new CSpan($total_items.' '._('items')))
				->addClass('bgcomp-filter-count')
				->addClass('js-bgcomp-filter-count')
				->setAttribute('data-hostid', $hostid)
		]))->setColSpan(BGCOMP_COLSPAN)
	))->addClass('bgcomp-row bgcomp-filter-row');

	// Lightweight column-header row so the expansion reads as its own table.
	$rows[] = (new CRow([
		(new CCol(_('Component / Item')))->setColSpan(BGCOMP_COLSPAN - 3)->addClass('bgcomp-colhead'),
		(new CCol(_('Trend')))->addClass('bgcomp-colhead bgcomp-spark-col'),
		(new CCol(_('Value')))->addClass('bgcomp-colhead bgcomp-value-col'),
		(new CCol(_('Age')))->addClass('bgcomp-colhead bgcomp-age-col')
	]))->addClass('bgcomp-row bgcomp-colhead-row');

	$bucket_idx = 0;

	foreach ($grouped as $bucket => $instances) {
		$bucket_idx++;
		$bucket_key = $hostid.'-'.$bucket_idx;

		$bucket_severity = bgcomp_max_severity($instances, $item_severity);
		$item_count = bgcomp_count_items($instances);

		// Auto-expand buckets that have an active problem; collapse the rest.
		$expanded = ($bucket_severity !== null);

		// Bucket header row: chevron + label + count + status dot.
		$chevron = (new CSpan())->addClass($expanded ? ZBX_STYLE_ARROW_DOWN : ZBX_STYLE_ARROW_RIGHT);
		$toggle = (new CSimpleButton())
			->addClass(ZBX_STYLE_TREEVIEW)
			->addClass('js-bgcomp-bucket-toggle')
			->setAttribute('data-bucket', $bucket_key)
			->addItem($chevron);

		$header_parts = [
			bgcomp_indent(1),
			$toggle,
			(new CSpan(bgcomp_bucket_label($bucket)))
				->addClass('bgcomp-bucket-name')
				->addClass('js-bgcomp-bucket-label')
				->setAttribute('data-bucket', $bucket_key),
			(new CSpan('('.$item_count.')'))->addClass('bgcomp-count')
		];

		if ($bucket_severity !== null) {
			$header_parts[] = bgcomp_severity_dot($bucket_severity);
		}

		// Opt-in "trends" toggle: lazily fetches sparklines for this bucket's
		// numeric items only when clicked. No history is fetched otherwise.
		if ($allowed_ui_latest_data) {
			$header_parts[] = (new CSimpleButton(_('trends')))
				->addClass('bgcomp-trends-toggle')
				->addClass('js-bgcomp-trends-toggle')
				->setAttribute('data-bucket', $bucket_key)
				->setAttribute('data-hostid', $hostid);
		}

		$bucket_col = (new CCol($header_parts))->setColSpan(BGCOMP_COLSPAN);
		$rows[] = (new CRow($bucket_col))
			->addClass('bgcomp-row bgcomp-bucket-row')
			->setAttribute('data-bgcomp-bucket-head', $bucket_key)
			->setAttribute('data-bgcomp-text', bgcomp_haystack([bgcomp_bucket_label($bucket), $bucket]));

		// Zebra striping resets at each bucket header for readability.
		$stripe = false;

		$instance_idx = 0;

		foreach ($instances as $instance => $items) {
			$is_direct = ($instance === '__direct');
			$instance_idx++;
			$instance_key = $bucket_key.'-'.$instance_idx;

			// Hidden when the parent bucket is collapsed.
			$child_hidden = !$expanded;

			// Items under a real instance are hidden until that instance is expanded.
			// __direct items have no instance header, so they only follow the bucket.
			$instance_expanded = true;

			if (!$is_direct) {
				$instance_severity = bgcomp_items_max_severity($items, $item_severity);

				// Same rule as buckets: open only if something underneath is on fire.
				$instance_expanded = ($instance_severity !== null);

				$instance_toggle = (new CSimpleButton())
					->addClass(ZBX_STYLE_TREEVIEW)
					->addClass('js-bgcomp-instance-toggle')
					->setAttribute('data-instance', $instance_key)
					->addItem(
						(new CSpan())->addClass($instance_expanded ? ZBX_STYLE_ARROW_DOWN : ZBX_STYLE_ARROW_RIGHT)
					);

				// ifAlias / description, lifted out of the item name (see
				// bgcomp_instance_alias). Empty for templates that don't carry one.
				$instance_alias = bgcomp_instance_alias($instance, $items);

				$instance_parts = [
					bgcomp_indent(2),
					$instance_toggle,
					(new CSpan($instance))
						->addClass('bgcomp-instance-name')
						->addClass('js-bgcomp-instance-label')
						->setAttribute('data-instance', $instance_key)
				];

				if ($instance_alias !== '') {
					$instance_parts[] = (new CSpan($instance_alias))
						->addClass('bgcomp-instance-alias')
						->addClass('js-bgcomp-instance-label')
						->setAttribute('data-instance', $instance_key)
						->setAttribute('title', $instance_alias);
				}

				$instance_parts[] = (new CSpan('('.count($items).')'))->addClass('bgcomp-count');

				if ($instance_severity !== null) {
					$instance_parts[] = bgcomp_severity_dot($instance_severity);
				}

				$instance_col = (new CCol($instance_parts))->setColSpan(BGCOMP_COLSPAN);
				$instance_row = (new CRow($instance_col))
					->addClass('bgcomp-row bgcomp-instance-row')
					->setAttribute('data-bgcomp-bucket', $bucket_key)
					->setAttribute('data-bgcomp-instance-head', $instance_key)
					->setAttribute('data-bgcomp-text', bgcomp_haystack([$instance, $instance_alias]));

				if ($child_hidden) {
					$instance_row->addClass('bgcomp-hide-bucket');
				}

				$rows[] = $instance_row;

				// Restart zebra striping inside each instance.
				$stripe = false;
			}

			$item_indent = $is_direct ? 2 : 3;

			foreach ($items as $itemid => $item) {
				$severity = array_key_exists($itemid, $item_severity) ? (int) $item_severity[$itemid] : null;
				$row = bgcomp_item_row($item, $itemid, $item_indent, $allowed_ui_latest_data, $severity, $stripe);
				$row->setAttribute('data-bgcomp-bucket', $bucket_key);

				if ($child_hidden) {
					$row->addClass('bgcomp-hide-bucket');
				}

				if (!$is_direct) {
					$row->setAttribute('data-bgcomp-instance', $instance_key);

					if (!$instance_expanded) {
						$row->addClass('bgcomp-hide-instance');
					}
				}

				$rows[] = $row;
				$stripe = !$stripe;
			}
		}
	}
}

// Emit the rows as a bare HTML fragment (no wrapping <table>) for JS injection.
$out = '';
foreach ($rows as $row) {
	$out .= $row->toString();
}
echo $out;

/**
 * Build one item row: name (linked to history if allowed), sparkline slot,
 * value/gauge, age.
 */
function bgcomp_item_row(array $item, $itemid, int $indent, bool $allowed_ui_latest_data, ?int $severity,
		bool $stripe): CRow {
	$name = $item['name'];
	$is_numeric = in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true);

	if ($allowed_ui_latest_data) {
		$history_url = (new CUrl('history.php'))
			->setArgument('action', $is_numeric ? HISTORY_GRAPH : HISTORY_VALUES)
			->setArgument('itemids', (array) $itemid);

		// Plain click opens a popup (see refresh JS); href kept so ctrl/middle-click
		// still opens the full history page in a new tab.
		$name_cell = (new CLink($name, $history_url->getUrl()))
			->addClass('js-bgcomp-history')
			->setAttribute('data-itemid', $itemid)
			->setAttribute('data-numeric', $is_numeric ? '1' : '0')
			->setAttribute('data-name', $name);
	}
	else {
		$name_cell = new CSpan($name);
	}

	$name_col = (new CCol([bgcomp_indent($indent), $name_cell]))->setColSpan(BGCOMP_COLSPAN - 3);

	// Sparkline placeholder, filled in lazily by JS when "trends" is toggled.
	$spark_col = (new CCol(
		$is_numeric ? (new CSpan())->addClass('bgcomp-spark')->setAttribute('data-spark-itemid', $itemid) : ''
	))->addClass('bgcomp-spark-col');

	$value_col = (new CCol(bgcomp_value_cell($item, $severity)))
		->addClass(ZBX_STYLE_NOWRAP)
		->addClass('bgcomp-value-col');

	$age = ($item['lastclock'] > 0)
		? (new CSpan(zbx_date2age($item['lastclock'])))
			->addClass('bgcomp-age')
			->setHint(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['lastclock']))
		: (new CSpan('-'))->addClass(ZBX_STYLE_GREY);
	$age_col = (new CCol($age))->addClass(ZBX_STYLE_NOWRAP)->addClass('bgcomp-age-col');

	$row = (new CRow([$name_col, $spark_col, $value_col, $age_col]))->addClass('bgcomp-row bgcomp-item-row');

	// Severity accent wins over zebra striping; only stripe non-severity rows.
	if ($severity !== null) {
		$row->addClass('bgcomp-item-sev')->addClass('bgcomp-sev-'.$severity);
	}
	elseif ($stripe) {
		$row->addClass('bgcomp-stripe');
	}

	if ($is_numeric) {
		$row->setAttribute('data-bgcomp-itemid', $itemid);
	}

	$row->setAttribute('data-bgcomp-text', bgcomp_haystack([$name]));

	return $row;
}

/**
 * Lowercased, space-joined match text for the client-side filter.
 */
function bgcomp_haystack(array $parts): string {
	$parts = array_filter(array_map('trim', $parts), static function ($part) {
		return $part !== '';
	});

	return mb_strtolower(implode(' ', $parts));
}

/**
 * Pull an instance's alias out of its item names.
 *
 * Zabbix's SNMP network templates name item prototypes
 * "Interface {#IFNAME}({#IFALIAS}): Bits received", so for instance
 * "Ethernet1/2" the parenthetical right after the name is ifAlias, i.e. the
 * description configured on the port. Nothing carries it in a tag, so the name
 * is the only place to get it without forking the stock templates.
 *
 * Returns '' when the template doesn't follow that pattern, when the alias is
 * empty (unconfigured port), or when it merely repeats the instance name.
 */
function bgcomp_instance_alias(string $instance, array $items): string {
	if ($instance === '') {
		return '';
	}

	$pattern = '/'.preg_quote($instance, '/').'\s*\(([^)]*)\)/';

	foreach ($items as $item) {
		$name = isset($item['name']) ? (string) $item['name'] : '';

		if ($name === '' || !preg_match($pattern, $name, $m)) {
			continue;
		}

		$alias = trim($m[1]);

		if ($alias !== '' && strcasecmp($alias, $instance) !== 0) {
			return $alias;
		}
	}

	return '';
}

/**
 * Value cell: inline bar gauge for percentage metrics, otherwise the formatted
 * value. Gauge colour follows active severity, falling back to value thresholds.
 */
function bgcomp_value_cell(array $item, ?int $severity) {
	$has_value = array_key_exists('lastvalue', $item) && $item['lastvalue'] !== null && $item['lastclock'] != 0;

	if (!$has_value) {
		return (new CSpan('-'))->addClass(ZBX_STYLE_GREY);
	}

	$is_numeric = in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true);
	$is_percent = $is_numeric && isset($item['units']) && $item['units'] === '%';

	$label = formatHistoryValue((string) $item['lastvalue'], $item);

	if ($is_percent) {
		$pct = (float) $item['lastvalue'];
		$pct = max(0.0, min(100.0, $pct));
		$fill_class = 'bgcomp-gauge-fill--'.bgcomp_gauge_level($pct, $severity);

		$gauge = (new CDiv([
			(new CDiv())
				->addClass('bgcomp-gauge-fill')
				->addClass($fill_class)
				->setAttribute('style', 'width:'.((int) round($pct)).'%'),
		]))->addClass('bgcomp-gauge');

		return (new CDiv([
			$gauge,
			(new CSpan($label))->addClass('bgcomp-gauge-label')
		]))->addClass('bgcomp-gauge-wrap');
	}

	return new CSpan($label);
}

/**
 * Gauge colour level: severity wins, else value thresholds.
 */
function bgcomp_gauge_level(float $pct, ?int $severity): string {
	if ($severity !== null) {
		if ($severity >= TRIGGER_SEVERITY_HIGH) {
			return 'crit';
		}
		if ($severity >= TRIGGER_SEVERITY_WARNING) {
			return 'warn';
		}
		return 'ok';
	}

	if ($pct >= 90) {
		return 'crit';
	}
	if ($pct >= 75) {
		return 'warn';
	}

	return 'ok';
}

/**
 * Small coloured status dot using Zabbix severity colours.
 */
function bgcomp_severity_dot(int $severity): CTag {
	return (new CSpan())
		->addClass('bgcomp-dot')
		->addClass(CSeverityHelper::getStatusStyle($severity))
		->setAttribute('title', CSeverityHelper::getName($severity));
}

/**
 * Count items across all instances of a bucket.
 */
function bgcomp_count_items(array $instances): int {
	$n = 0;

	foreach ($instances as $items) {
		$n += count($items);
	}

	return $n;
}

/**
 * Highest severity across all items of all instances in a bucket, or null.
 */
function bgcomp_max_severity(array $instances, array $item_severity): ?int {
	$max = null;

	foreach ($instances as $items) {
		$s = bgcomp_items_max_severity($items, $item_severity);

		if ($s !== null && ($max === null || $s > $max)) {
			$max = $s;
		}
	}

	return $max;
}

/**
 * Highest severity across a flat set of items, or null.
 */
function bgcomp_items_max_severity(array $items, array $item_severity): ?int {
	$max = null;

	foreach (array_keys($items) as $itemid) {
		if (array_key_exists($itemid, $item_severity)) {
			$s = (int) $item_severity[$itemid];

			if ($max === null || $s > $max) {
				$max = $s;
			}
		}
	}

	return $max;
}

/**
 * Human-readable bucket label.
 */
function bgcomp_bucket_label(string $bucket): string {
	$labels = [
		'cpu' => _('CPU'),
		'memory' => _('Memory'),
		'storage' => _('Storage'),
		'network' => _('Network'),
		'system' => _('System'),
		'other' => _('Other')
	];

	return array_key_exists($bucket, $labels) ? $labels[$bucket] : ucfirst($bucket);
}

/**
 * Indentation span; widths handled by CSS classes in bghost.css.
 */
function bgcomp_indent(int $level): CTag {
	return (new CSpan())->addClass('bgcomp-indent')->addClass('bgcomp-indent-'.$level);
}
