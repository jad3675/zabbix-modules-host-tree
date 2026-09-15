<?php
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

?>

<script type="text/javascript">
	// Transfer information about groups from PHP into JavaScript data Object
	var groups = [];
	var data = <?php
		echo '{';
		foreach ($data['host_groups'] as $group_name => $group) {
			if (count($group['children']) > 0) {
				echo "'".$group['groupid']."':[";
				print_children($data, $group);
				echo "],";
			}
		}
		echo '}';
		function print_children($data, $group) {
			$num_of_children = count($group['children']);
			$index = 0;
			foreach($group['children'] as $child_group_name) {
				$child_group_id = $data['host_groups'][$child_group_name]['groupid'];

				echo "'" . $child_group_id . "'";
				if ($index < $num_of_children-1) {
					echo ',';
				}

				$index++;
			}
		} ?>;

	function toggleChevronCollapsed($chevron, collapsed) {
		$chevron
			.removeClass(collapsed ? '<?= ZBX_STYLE_ARROW_DOWN ?>' : '<?= ZBX_STYLE_ARROW_RIGHT ?>')
			.addClass(collapsed ? '<?= ZBX_STYLE_ARROW_RIGHT ?>' : '<?= ZBX_STYLE_ARROW_DOWN ?>');
	}

	function isChevronCollapsed($chevron) {
		return $chevron.hasClass('<?= ZBX_STYLE_ARROW_RIGHT ?>');
	}

	function toggleGroup(group_id, collapsed) {
		var $chevron = $('.js-toggle[data-group_id_' + group_id + '="' + group_id + '"] span'),
			$rows = $('tr[data-group_id_' + group_id + '="' + group_id + '"]');

		toggleChevronCollapsed($chevron, collapsed);

		$rows.toggleClass('<?= ZBX_STYLE_DISPLAY_NONE ?>', collapsed);
	}

	$('.js-toggle').on('click', function() {
		var $toggle = $(this),
			collapsed = !isChevronCollapsed($toggle.find('span'));
		var group_id = 0;
		for (const key  in $toggle[0].attributes) {
			var attr = $toggle[0].attributes[key];
			if (attr.name.startsWith('data-')) {
				group_id = attr.value
				break;
			}
		};
		toggleGroup(group_id, collapsed);
		if (collapsed) {
			if (group_id in data) {
				collapseSubgroup(group_id);
			}
		}
	});

	function collapseSubgroup(group_id) {
		toggleGroup(group_id, true);
		for (var i = 0; i < data[group_id].length; i++) {
			if (data[group_id][i] in data) {
				collapseSubgroup(data[group_id][i]);
			} else {
				toggleGroup(data[group_id][i], true);
			}
		}
	}

	// Component tree-view: lazy-load a host's grouped items on expander click.
	// Cache fetched HTML so re-expanding doesn't refetch.
	var bgcomp_cache = {};

	$('.js-component-toggle').on('click', function() {
		var $toggle = $(this),
			$chevron = $toggle.find('span'),
			hostid = $toggle.attr('data-hostid'),
			$host_row = $('tr[data-host_row="' + hostid + '"]'),
			expanded = $chevron.hasClass('<?= ZBX_STYLE_ARROW_DOWN ?>');

		if (expanded) {
			// Collapse: hide previously injected rows (own class, not the group
			// machinery's DISPLAY_NONE, so the two layers stay independent).
			$chevron
				.removeClass('<?= ZBX_STYLE_ARROW_DOWN ?>')
				.addClass('<?= ZBX_STYLE_ARROW_RIGHT ?>');
			$('tr[data-component_of="' + hostid + '"]').addClass('bgcomp-hide-host');
			return;
		}

		$chevron
			.removeClass('<?= ZBX_STYLE_ARROW_RIGHT ?>')
			.addClass('<?= ZBX_STYLE_ARROW_DOWN ?>');

		// If already cached and injected, just show again.
		var $existing = $('tr[data-component_of="' + hostid + '"]');
		if ($existing.length) {
			$existing.removeClass('bgcomp-hide-host');
			return;
		}

		var url = new Curl('zabbix.php');
		url.setArgument('action', 'bghostcomp.component.view');
		url.setArgument('hostid', hostid);

		$.ajax({
			url: url.getUrl(),
			type: 'get',
			dataType: 'json'
		}).done(function(response) {
			if (!response || typeof response.body === 'undefined') {
				return;
			}

			bgcomp_cache[hostid] = response.body;

			var $rows = $(response.body);
			$rows.attr('data-component_of', hostid);

			// Indent the injected block so it nests under the host row instead of sitting at the same indent.
			// The host's tree depth is stamped on its row; component rows read it via a CSS custom property.
			var tree_level = parseInt($host_row.attr('data-tree-level'), 10);
			if (isNaN(tree_level)) {
				tree_level = 0;
			}
			var host_indent = (tree_level * 20) + 'px';
			$rows.each(function() {
				this.style.setProperty('--bgcomp-host-indent', host_indent);
			});

			// Stamp injected rows with the host row's group attributes so the tree's
			// collapse/expand machinery hides and shows them along with their host.
			if ($host_row.length) {
				var host_attrs = $host_row[0].attributes;
				for (var i = 0; i < host_attrs.length; i++) {
					var attr = host_attrs[i];
					if (attr.name.indexOf('data-group_id_') === 0) {
						$rows.attr(attr.name, attr.value);
					}
				}
			}

			$host_row.after($rows);
		}).fail(function() {
			// Revert chevron on failure so the user can retry.
			$chevron
				.removeClass('<?= ZBX_STYLE_ARROW_DOWN ?>')
				.addClass('<?= ZBX_STYLE_ARROW_RIGHT ?>');
		});
	});

	// Component bucket collapse/expand. Delegated so it works on injected rows.
	$(document).on('click', '.js-bgcomp-bucket-toggle', function() {
		var $toggle = $(this),
			$chevron = $toggle.find('span'),
			bucket_key = $toggle.attr('data-bucket'),
			collapsed = $chevron.hasClass('<?= ZBX_STYLE_ARROW_RIGHT ?>');

		$chevron
			.toggleClass('<?= ZBX_STYLE_ARROW_RIGHT ?>', !collapsed)
			.toggleClass('<?= ZBX_STYLE_ARROW_DOWN ?>', collapsed);

		$('tr[data-bgcomp-bucket="' + bucket_key + '"]').toggleClass('bgcomp-hide-bucket', !collapsed);
	});

	// Opt-in lazy sparklines: fetch history only for the clicked bucket's numeric
	// items, only on demand. Cached per bucket so re-toggling doesn't refetch.
	var bgcomp_spark_cache = {};

	$(document).on('click', '.js-bgcomp-trends-toggle', function() {
		var $btn = $(this),
			bucket_key = $btn.attr('data-bucket'),
			hostid = $btn.attr('data-hostid'),
			active = $btn.hasClass('bgcomp-trends-on');

		if (active) {
			// Turn off: hide sparkline cells for this bucket.
			$btn.removeClass('bgcomp-trends-on');
			$('tr[data-bgcomp-bucket="' + bucket_key + '"] .bgcomp-spark').removeClass('bgcomp-spark-shown');
			return;
		}

		$btn.addClass('bgcomp-trends-on');

		// Collect this bucket's numeric itemids.
		var itemids = [];
		$('tr[data-bgcomp-bucket="' + bucket_key + '"][data-bgcomp-itemid]').each(function() {
			itemids.push($(this).attr('data-bgcomp-itemid'));
		});

		if (itemids.length === 0) {
			return;
		}

		var render = function(sparklines) {
			$('tr[data-bgcomp-bucket="' + bucket_key + '"] .bgcomp-spark').each(function() {
				var $cell = $(this),
					itemid = $cell.attr('data-spark-itemid');
				if (sparklines && itemid in sparklines) {
					$cell.html(sparklines[itemid]).addClass('bgcomp-spark-shown');
				}
			});
		};

		if (bucket_key in bgcomp_spark_cache) {
			render(bgcomp_spark_cache[bucket_key]);
			return;
		}

		$btn.addClass('bgcomp-trends-loading');

		var url = new Curl('zabbix.php');
		url.setArgument('action', 'bghostcomp.component.sparkline');

		$.ajax({
			url: url.getUrl(),
			type: 'post',
			dataType: 'json',
			data: {hostid: hostid, itemids: itemids}
		}).done(function(response) {
			var sparklines = (response && response.sparklines) ? response.sparklines : {};
			bgcomp_spark_cache[bucket_key] = sparklines;
			render(sparklines);
		}).fail(function() {
			$btn.removeClass('bgcomp-trends-on');
		}).always(function() {
			$btn.removeClass('bgcomp-trends-loading');
		});
	});
</script>
