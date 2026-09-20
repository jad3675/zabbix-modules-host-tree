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
	//
	// Which hosts, buckets and instances are open is kept on window, not in a
	// script-local var, because the auto-refresh replaces the whole form and
	// re-runs this script. Rows are refetched on restore rather than replayed from
	// a cache, so a restored block shows current values instead of a stale snapshot.
	window.bgcomp_state = window.bgcomp_state || {hosts: {}, buckets: {}, instances: {}};

	var bgcomp_state = window.bgcomp_state;

	function bgcompChevron($chevron, expanded) {
		$chevron
			.toggleClass('<?= ZBX_STYLE_ARROW_DOWN ?>', expanded)
			.toggleClass('<?= ZBX_STYLE_ARROW_RIGHT ?>', !expanded);
	}

	function bgcompSetBucket(bucket_key, expanded) {
		bgcompChevron($('.js-bgcomp-bucket-toggle[data-bucket="' + bucket_key + '"] span'), expanded);

		// Only the bucket-level class. Instance-collapsed items carry their own
		// bgcomp-hide-instance class and stay hidden when the bucket reopens.
		$('tr[data-bgcomp-bucket="' + bucket_key + '"]').toggleClass('bgcomp-hide-bucket', !expanded);
	}

	function bgcompSetInstance(instance_key, expanded) {
		bgcompChevron($('.js-bgcomp-instance-toggle[data-instance="' + instance_key + '"] span'), expanded);
		$('tr[data-bgcomp-instance="' + instance_key + '"]').toggleClass('bgcomp-hide-instance', !expanded);
	}

	// Re-apply remembered bucket/instance state to a freshly injected block. Keys
	// are hostid-derived and deterministic, so they survive a refetch as long as
	// the host's grouping is unchanged. Buckets first: an instance hidden by its
	// own class stays hidden either way.
	function bgcompApplyState(hostid) {
		$('tr[data-component_of="' + hostid + '"][data-bgcomp-bucket-head]').each(function() {
			var key = $(this).attr('data-bgcomp-bucket-head');

			if (key in bgcomp_state.buckets) {
				bgcompSetBucket(key, bgcomp_state.buckets[key]);
			}
		});

		$('tr[data-component_of="' + hostid + '"][data-bgcomp-instance-head]').each(function() {
			var key = $(this).attr('data-bgcomp-instance-head');

			if (key in bgcomp_state.instances) {
				bgcompSetInstance(key, bgcomp_state.instances[key]);
			}
		});
	}

	function bgcompInjectRows(hostid, html) {
		var $host_row = $('tr[data-host_row="' + hostid + '"]');

		if (!$host_row.length) {
			return;
		}

		var $rows = $(html);
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
		var host_attrs = $host_row[0].attributes;
		for (var i = 0; i < host_attrs.length; i++) {
			var attr = host_attrs[i];
			if (attr.name.indexOf('data-group_id_') === 0) {
				$rows.attr(attr.name, attr.value);
			}
		}

		$host_row.after($rows);
		bgcompApplyState(hostid);
	}

	// Expand one host: show already-injected rows, or fetch and inject them.
	function bgcompExpandHost(hostid) {
		var $chevron = $('.js-component-toggle[data-hostid="' + hostid + '"] span'),
			$existing = $('tr[data-component_of="' + hostid + '"]');

		bgcompChevron($chevron, true);

		if ($existing.length) {
			$existing.removeClass('bgcomp-hide-host');
			bgcompApplyState(hostid);
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

			// The tree may have been replaced by a refresh while this was in flight.
			if (!bgcomp_state.hosts[hostid] || $('tr[data-component_of="' + hostid + '"]').length) {
				return;
			}

			bgcompInjectRows(hostid, response.body);
		}).fail(function(jqXHR) {
			// Ignore aborts caused by page unload or a refresh.
			if (jqXHR.status === 0) {
				return;
			}

			// Revert chevron and state on failure so the user can retry.
			delete bgcomp_state.hosts[hostid];
			bgcompChevron($('.js-component-toggle[data-hostid="' + hostid + '"] span'), false);
		});
	}

	$('.js-component-toggle').on('click', function() {
		var $toggle = $(this),
			$chevron = $toggle.find('span'),
			hostid = $toggle.attr('data-hostid'),
			expanded = $chevron.hasClass('<?= ZBX_STYLE_ARROW_DOWN ?>');

		if (expanded) {
			// Collapse: hide previously injected rows (own class, not the group
			// machinery's DISPLAY_NONE, so the two layers stay independent).
			bgcompChevron($chevron, false);
			delete bgcomp_state.hosts[hostid];
			$('tr[data-component_of="' + hostid + '"]').addClass('bgcomp-hide-host');
			return;
		}

		bgcomp_state.hosts[hostid] = true;
		bgcompExpandHost(hostid);
	});

	// Restore whatever was open before this refresh replaced the tree. Hosts that
	// the current filter no longer shows are skipped, but their state is kept so
	// they come back expanded if the filter brings them back.
	Object.keys(bgcomp_state.hosts).forEach(function(hostid) {
		if ($('tr[data-host_row="' + hostid + '"]').length) {
			bgcompExpandHost(hostid);
		}
	});

	// NOTE: this script is re-emitted on every auto-refresh of the tree. Delegated
	// handlers on document therefore use a .bgcomp namespace and .off() first,
	// otherwise each refresh stacks another copy and toggles fire N times.

	// Component bucket collapse/expand. Delegated so it works on injected rows.
	$(document).off('click.bgcomp', '.js-bgcomp-bucket-toggle, .js-bgcomp-bucket-label')
		.on('click.bgcomp', '.js-bgcomp-bucket-toggle, .js-bgcomp-bucket-label', function() {
		var bucket_key = $(this).attr('data-bucket'),
			expanded = !$('.js-bgcomp-bucket-toggle[data-bucket="' + bucket_key + '"] span')
				.hasClass('<?= ZBX_STYLE_ARROW_DOWN ?>');

		window.bgcomp_state.buckets[bucket_key] = expanded;
		bgcompSetBucket(bucket_key, expanded);
	});

	// Instance (interface / mountpoint / etc.) collapse/expand.
	$(document).off('click.bgcomp', '.js-bgcomp-instance-toggle, .js-bgcomp-instance-label')
		.on('click.bgcomp', '.js-bgcomp-instance-toggle, .js-bgcomp-instance-label', function() {
		var instance_key = $(this).attr('data-instance'),
			expanded = !$('.js-bgcomp-instance-toggle[data-instance="' + instance_key + '"] span')
				.hasClass('<?= ZBX_STYLE_ARROW_DOWN ?>');

		window.bgcomp_state.instances[instance_key] = expanded;
		bgcompSetInstance(instance_key, expanded);
	});

	// Item history in a popup instead of navigating away.
	//   numeric items   -> in-page overlay with a chart.php graph + period buttons
	//   text/log items  -> separate browser window on history.php (values list)
	// Ctrl/Cmd/Shift/middle-click fall through to the normal href (new tab).
	var bgcomp_periods = [
		{label: '1h', from: 'now-1h'},
		{label: '3h', from: 'now-3h'},
		{label: '12h', from: 'now-12h'},
		{label: '24h', from: 'now-24h'},
		{label: '7d', from: 'now-7d'},
		{label: '30d', from: 'now-30d'}
	];

	function bgcompOpenWindow(href, itemid) {
		var w = Math.min(1200, screen.availWidth - 80),
			h = Math.min(800, screen.availHeight - 80),
			left = Math.max(0, Math.round((screen.availWidth - w) / 2)),
			top = Math.max(0, Math.round((screen.availHeight - h) / 2)),
			win = window.open(href, 'bgcomp_history_' + itemid,
				'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
			);

		// Popup blocked: fall back to a new tab rather than silently doing nothing.
		if (!win) {
			window.open(href, '_blank');
		}
		else {
			win.focus();
		}
	}

	function bgcompOpenGraph(link) {
		var $link = $(link),
			itemid = $link.attr('data-itemid'),
			name = $link.attr('data-name') || $link.text(),
			href = $link.attr('href');

		if (typeof overlayDialogue !== 'function') {
			bgcompOpenWindow(href, itemid);
			return;
		}

		var $periods = $('<div>').addClass('bgcomp-graph-periods'),
			$frame = $('<div>').addClass('bgcomp-graph-frame'),
			$img = $('<img>').addClass('bgcomp-graph-img').attr('alt', name),
			$full = $('<a>')
				.addClass('bgcomp-graph-full')
				.attr({href: href, target: '_blank', rel: 'noopener'})
				.text(<?= json_encode(_('Open full history')) ?>),
			$content = $('<div>').addClass('bgcomp-graph-popup').append($periods, $frame.append($img), $full);

		var overlay = overlayDialogue({
			title: name,
			class: 'modal-popup modal-popup-large bgcomp-graph-dialogue',
			dialogueid: 'bgcomp_graph_' + itemid,
			content: $content,
			buttons: [{
				title: <?= json_encode(_('Close')) ?>,
				class: 'btn-alt',
				cancel: true,
				action: function() { return true; }
			}]
		}, link);

		var load = function(from) {
			// chart.php width is the plot area; leave room for its axes/margins.
			var avail = $frame.width() || 900,
				params = {
					itemids: [itemid],
					from: from,
					to: 'now',
					type: 0,
					width: Math.max(300, Math.round(avail - 110)),
					height: 260,
					legend: 1,
					_: Date.now()
				};

			$periods.find('button').removeClass('bgcomp-period-active');
			$periods.find('button[data-from="' + from + '"]').addClass('bgcomp-period-active');

			$frame.addClass('is-loading');
			$img.off('load.bgcomp error.bgcomp')
				.one('load.bgcomp error.bgcomp', function() { $frame.removeClass('is-loading'); })
				.attr('src', 'chart.php?' + $.param(params));
		};

		$.each(bgcomp_periods, function(i, p) {
			$('<button>')
				.attr({type: 'button', 'data-from': p.from})
				.addClass('btn-alt bgcomp-period-btn')
				.text(p.label)
				.on('click', function() { load(p.from); })
				.appendTo($periods);
		});

		// Size the graph after the dialogue is laid out so it fits the modal.
		setTimeout(function() { load('now-1h'); }, 0);

		return overlay;
	}

	$(document).off('click.bgcomp', '.js-bgcomp-history')
		.on('click.bgcomp', '.js-bgcomp-history', function(e) {
		if (e.ctrlKey || e.metaKey || e.shiftKey || e.which === 2) {
			return;
		}

		e.preventDefault();

		if ($(this).attr('data-numeric') === '1') {
			bgcompOpenGraph(this);
		}
		else {
			bgcompOpenWindow($(this).attr('href'), $(this).attr('data-itemid'));
		}
	});

	// Opt-in lazy sparklines: fetch history only for the clicked bucket's numeric
	// items, only on demand. Cached per bucket so re-toggling doesn't refetch.
	var bgcomp_spark_cache = {};

	$(document).off('click.bgcomp', '.js-bgcomp-trends-toggle')
		.on('click.bgcomp', '.js-bgcomp-trends-toggle', function() {
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
