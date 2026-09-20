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
	window.bgcomp_state = window.bgcomp_state || {hosts: {}, buckets: {}, instances: {}, filters: {}};
	window.bgcomp_state.filters = window.bgcomp_state.filters || {};

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

		// Re-apply the filter last, so it wins over the restored collapse state.
		if (bgcomp_state.filters[hostid]) {
			$('.js-bgcomp-filter[data-hostid="' + hostid + '"]').val(bgcomp_state.filters[hostid]);
			bgcompApplyFilter(hostid, bgcomp_state.filters[hostid]);
		}
	}

	// ---------------------------------------------------------------------------
	// Per-host filter.
	//
	// Everything for an expanded host is already in the DOM, so this is pure class
	// toggling: no round trip, no debounce-induced lag on the data itself.
	//
	// Layering: while a filter is active every row in the block carries
	// .bgcomp-filtering, whose CSS neutralises .bgcomp-hide-bucket and
	// .bgcomp-hide-instance. Visibility then comes solely from .bgcomp-hide-filter,
	// so matches surface out of collapsed buckets without the stored collapse state
	// being touched. Clearing the box hands control straight back to that state.
	// ---------------------------------------------------------------------------

	var bgcomp_filter_timers = {};

	function bgcompMatches(haystack, terms) {
		for (var i = 0; i < terms.length; i++) {
			if (haystack.indexOf(terms[i]) === -1) {
				return false;
			}
		}

		return true;
	}

	// Chevrons would otherwise read as collapsed while their children are on show.
	// Pre-filter state is parked on the element, not in bgcomp_state, because it is
	// display sugar and must not survive a clear.
	function bgcompFilterChevrons(hostid, active) {
		$('tr[data-component_of="' + hostid + '"]')
			.find('.js-bgcomp-bucket-toggle span, .js-bgcomp-instance-toggle span')
			.each(function() {
				var $chevron = $(this);

				if (active) {
					$chevron.data('bgcomp-pre-filter', $chevron.hasClass('<?= ZBX_STYLE_ARROW_DOWN ?>'));
					bgcompChevron($chevron, true);
				}
				else {
					var pre = $chevron.data('bgcomp-pre-filter');

					if (typeof pre !== 'undefined') {
						bgcompChevron($chevron, pre);
						$chevron.removeData('bgcomp-pre-filter');
					}
				}
			});
	}

	function bgcompApplyFilter(hostid, term) {
		var $rows = $('tr[data-component_of="' + hostid + '"]');

		if (!$rows.length) {
			return;
		}

		var $count = $('.js-bgcomp-filter-count[data-hostid="' + hostid + '"]'),
			$items = $rows.filter('.bgcomp-item-row'),
			total = $items.length,
			was_active = $rows.first().hasClass('bgcomp-filtering');

		term = $.trim(term || '');

		if (term === '') {
			delete bgcomp_state.filters[hostid];
			$rows.removeClass('bgcomp-filtering bgcomp-hide-filter');

			if (was_active) {
				bgcompFilterChevrons(hostid, false);

				// A bucket or instance toggled while the filter was up wrote real
				// state that the chevron restore above just overwrote. State wins.
				bgcompApplyState(hostid);
			}

			$count.text(total + ' ' + <?= json_encode(_('items')) ?>);

			return;
		}

		bgcomp_state.filters[hostid] = term;

		var terms = term.toLowerCase().split(/\s+/),
			bucket_text = {},
			instance_text = {};

		$rows.filter('[data-bgcomp-bucket-head]').each(function() {
			bucket_text[$(this).attr('data-bgcomp-bucket-head')] = $(this).attr('data-bgcomp-text') || '';
		});

		$rows.filter('[data-bgcomp-instance-head]').each(function() {
			instance_text[$(this).attr('data-bgcomp-instance-head')] = $(this).attr('data-bgcomp-text') || '';
		});

		// An item matches on its own name plus its instance's and bucket's text, so
		// "network errors" or "ethernet1/2 bits" narrow the way you would expect,
		// and a match on an instance drags its whole item list along with it.
		var shown_buckets = {},
			shown_instances = {},
			matches = 0;

		$items.each(function() {
			var $row = $(this),
				bucket_key = $row.attr('data-bgcomp-bucket'),
				instance_key = $row.attr('data-bgcomp-instance'),
				haystack = (bucket_text[bucket_key] || '') + ' '
					+ (instance_key ? (instance_text[instance_key] || '') : '') + ' '
					+ ($row.attr('data-bgcomp-text') || ''),
				ok = bgcompMatches(haystack, terms);

			$row.toggleClass('bgcomp-hide-filter', !ok);

			if (ok) {
				matches++;
				shown_buckets[bucket_key] = true;

				if (instance_key) {
					shown_instances[instance_key] = true;
				}
			}
		});

		// Headers follow their children: no visible items, no header.
		$rows.filter('[data-bgcomp-instance-head]').each(function() {
			var key = $(this).attr('data-bgcomp-instance-head');
			$(this).toggleClass('bgcomp-hide-filter', !shown_instances[key]);
		});

		$rows.filter('[data-bgcomp-bucket-head]').each(function() {
			var key = $(this).attr('data-bgcomp-bucket-head');
			$(this).toggleClass('bgcomp-hide-filter', !shown_buckets[key]);
		});

		$rows.filter('.bgcomp-colhead-row').toggleClass('bgcomp-hide-filter', matches === 0);

		// The box itself stays put, otherwise there is no way to undo the filter.
		$rows.addClass('bgcomp-filtering');
		$rows.filter('.bgcomp-filter-row').removeClass('bgcomp-hide-filter');

		if (!was_active) {
			bgcompFilterChevrons(hostid, true);
		}

		$count.text(matches + ' ' + <?= json_encode(_('of')) ?> + ' ' + total);
	}

	$(document).off('input.bgcomp keydown.bgcomp', '.js-bgcomp-filter')
		.on('input.bgcomp', '.js-bgcomp-filter', function() {
			var hostid = $(this).attr('data-hostid'),
				value = $(this).val();

			clearTimeout(bgcomp_filter_timers[hostid]);
			bgcomp_filter_timers[hostid] = setTimeout(function() {
				bgcompApplyFilter(hostid, value);
			}, 120);
		})
		.on('keydown.bgcomp', '.js-bgcomp-filter', function(e) {
			// Enter would submit the host-view filter form; Escape clears the box
			// rather than bubbling up to whatever else listens for it.
			if (e.key === 'Enter') {
				e.preventDefault();
				clearTimeout(bgcomp_filter_timers[$(this).attr('data-hostid')]);
				bgcompApplyFilter($(this).attr('data-hostid'), $(this).val());
			}
			else if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				$(this).val('');
				bgcompApplyFilter($(this).attr('data-hostid'), '');
			}
		});

	$(document).off('click.bgcomp', '.js-bgcomp-filter-clear')
		.on('click.bgcomp', '.js-bgcomp-filter-clear', function() {
			var hostid = $(this).attr('data-hostid');

			$('.js-bgcomp-filter[data-hostid="' + hostid + '"]').val('').focus();
			bgcompApplyFilter(hostid, '');
		});

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

	// In-flight requests live on window so a refresh (which re-runs this script)
	// doesn't fire a second fetch for a big host whose first one is still running.
	// Without this, a host slower than the refresh interval gets one extra request
	// per refresh, all against the same PHP memory limit.
	window.bgcomp_inflight = window.bgcomp_inflight || {};

	function bgcompStatusRow(hostid, kind, content) {
		bgcompClearStatus(hostid);

		var $host_row = $('tr[data-host_row="' + hostid + '"]');

		if (!$host_row.length) {
			return;
		}

		// Not data-component_of: these rows must never be mistaken for a loaded
		// block by the "already injected" check below.
		var $row = $('<tr>')
			.addClass('bgcomp-row bgcomp-status-row bgcomp-status-' + kind)
			.attr('data-bgcomp-status-for', hostid)
			.append($('<td>').attr('colspan', 10).append(content));

		$host_row.after($row);
	}

	function bgcompClearStatus(hostid) {
		$('tr[data-bgcomp-status-for="' + hostid + '"]').remove();
	}

	function bgcompFail(hostid, message) {
		// Forget the host so every refresh doesn't retry a request that is going to
		// fail the same way. Clicking the chevron again retries.
		delete bgcomp_state.hosts[hostid];
		bgcompChevron($('.js-component-toggle[data-hostid="' + hostid + '"] span'), false);

		bgcompStatusRow(hostid, 'error', [
			$('<span>').addClass('bgcomp-status-label').text(<?= json_encode(_('Could not load components:')) ?>),
			' ',
			$('<span>').text(message)
		]);
	}

	// Pull something readable out of an error body: PHP fatals, Zabbix error
	// JSON, or an HTML error page.
	function bgcompErrorText(jqXHR, text_status) {
		var parts = [];

		if (jqXHR.status) {
			parts.push('HTTP ' + jqXHR.status);
		}

		if (text_status === 'timeout') {
			parts.push(<?= json_encode(_('request timed out')) ?>);
		}
		else if (text_status === 'parsererror') {
			parts.push(<?= json_encode(_('response was not valid JSON')) ?>);
		}

		var body = jqXHR.responseText || '';

		if (body) {
			var snippet = $('<div>').html(body).text().replace(/\s+/g, ' ').trim();

			if (snippet) {
				parts.push(snippet.length > 300 ? snippet.substr(0, 300) + '\u2026' : snippet);
			}
		}
		else if (text_status === 'parsererror') {
			parts.push(<?= json_encode(_('(empty body, check the PHP error log for memory_limit or max_execution_time)')) ?>);
		}

		return parts.join(' | ');
	}

	// Expand one host: show already-injected rows, or fetch and inject them.
	function bgcompExpandHost(hostid) {
		var $chevron = $('.js-component-toggle[data-hostid="' + hostid + '"] span'),
			$existing = $('tr[data-component_of="' + hostid + '"]');

		bgcompChevron($chevron, true);

		if ($existing.length) {
			bgcompClearStatus(hostid);
			$existing.removeClass('bgcomp-hide-host');
			bgcompApplyState(hostid);
			return;
		}

		bgcompStatusRow(hostid, 'loading', [
			$('<span>').addClass('bgcomp-spinner'),
			$('<span>').text(<?= json_encode(_('Loading components...')) ?>)
		]);

		if (window.bgcomp_inflight[hostid]) {
			return;
		}

		var url = new Curl('zabbix.php');
		url.setArgument('action', 'bghostcomp.component.view');
		url.setArgument('hostid', hostid);

		window.bgcomp_inflight[hostid] = $.ajax({
			url: url.getUrl(),
			type: 'get',
			dataType: 'json',
			timeout: 120000
		}).done(function(response) {
			// Collapsed while loading: drop the result, the next expand refetches.
			if (!bgcomp_state.hosts[hostid]) {
				bgcompClearStatus(hostid);
				return;
			}

			if (!response || typeof response.body === 'undefined') {
				var msg = <?= json_encode(_('unexpected response')) ?>;

				if (response && response.bgcomp_error) {
					msg = response.bgcomp_error;
				}
				else if (response && response.error) {
					msg = [response.error.title || ''].concat(response.error.messages || []).join(' ').trim()
						|| msg;
				}

				bgcompFail(hostid, msg);
				return;
			}

			bgcompClearStatus(hostid);

			// A refresh may have landed first and a newer request injected already.
			if ($('tr[data-component_of="' + hostid + '"]').length) {
				return;
			}

			bgcompInjectRows(hostid, response.body);
		}).fail(function(jqXHR, text_status) {
			// Page unload. Nothing to report.
			if (text_status === 'abort') {
				bgcompClearStatus(hostid);
				return;
			}

			bgcompFail(hostid, bgcompErrorText(jqXHR, text_status));
		}).always(function() {
			delete window.bgcomp_inflight[hostid];
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
			bgcompClearStatus(hostid);
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
					// Required. chart.php feeds these to getTimeSelectorPeriod(), whose
					// first branch is "if profileIdx is null, replace from/to with the
					// system default period". Without it every range draws the same graph.
					// chart.php only ever reads this profile, never writes it, and we
					// always send from/to, so nothing of the user's is touched.
					profileIdx: 'web.bgcomp.graph.filter',
					profileIdx2: itemid,
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
