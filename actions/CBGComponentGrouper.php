<?php declare(strict_types = 1);

/*
** Component Tree-View extension for BGmot/zabbix-module-hosts-tree.
**
** Pure, side-effect-free grouping helper. Kept in its own file so it can be
** unit-tested in isolation (no Zabbix API calls, no globals).
**/

namespace Modules\BGmotHostsComponents\Actions;

class CBGComponentGrouper {

	/**
	 * Fixed display order for buckets so the UI is stable regardless of item order.
	 */
	const BUCKET_ORDER = ['cpu', 'memory', 'storage', 'network', 'system', 'other'];

	/**
	 * Default grouping tag name. Override via constructor.
	 */
	const DEFAULT_TAG = 'component';

	/**
	 * Default key-pattern map (first match wins). Includes SNMP key forms, not
	 * just agent keys. Patterns are matched case-insensitively against item key_.
	 */
	const DEFAULT_PATTERN_MAP = [
		'cpu' => [
			'system.cpu', 'cpu', 'processor', 'load', 'hrprocessorload', '.la['
		],
		'memory' => [
			'vm.memory', 'memory', 'mem.', 'hrstorageram', 'hrmemory', 'swap'
		],
		'storage' => [
			'vfs.fs', 'vfs.dev', 'disk', 'storage', 'hrstorage', 'filesystem',
			'dskpercent', 'mount'
		],
		'network' => [
			'net.if', 'net.tcp', 'ifin', 'ifout', 'ifhc', 'ifoper', 'ifadmin',
			'ifspeed', 'network', 'interface', 'bandwidth'
		],
		'system' => [
			'system.', 'agent.', 'zabbix', 'uptime', 'sysuptime', 'proc.',
			'boottime', 'sysdescr', 'sysname'
		]
	];

	/**
	 * Default bucket -> candidate instance-tag names. First existing tag wins.
	 */
	const DEFAULT_INSTANCE_TAG_MAP = [
		'storage' => ['mountpoint', 'filesystem', 'fsname', 'mount'],
		'network' => ['ifname', 'interface', 'ifdescr', 'ifalias', 'if']
	];

	/** @var string */
	private $tag_name;

	/** @var array */
	private $pattern_map;

	/** @var array */
	private $instance_tag_map;

	public function __construct(string $tag_name = self::DEFAULT_TAG, ?array $pattern_map = null,
			?array $instance_tag_map = null) {
		$this->tag_name = $tag_name;
		$this->pattern_map = $pattern_map ?? self::DEFAULT_PATTERN_MAP;
		$this->instance_tag_map = $instance_tag_map ?? self::DEFAULT_INSTANCE_TAG_MAP;
	}

	/**
	 * Phase 1: group items into an ordered associative array bucket => items[].
	 *
	 * Resolution order per item:
	 *   1. tag whose name == configured grouping tag -> tag value is the bucket;
	 *   2. else first matching key pattern;
	 *   3. else 'other'.
	 *
	 * @param array $items  Items keyed however the caller likes (keys preserved).
	 *
	 * @return array  Ordered bucket => items[] (only non-empty buckets).
	 */
	public function group(array $items): array {
		$buckets = [];

		foreach ($items as $key => $item) {
			$bucket = $this->resolveBucket($item);
			$buckets[$bucket][$key] = $item;
		}

		return $this->orderBuckets($buckets);
	}

	/**
	 * Phase 2: two-level grouping bucket => [ instance|__direct => items[] ].
	 *
	 * Items with no resolvable instance land under the '__direct' pseudo-instance,
	 * which the renderer shows at bucket level with no extra indent.
	 *
	 * @param array $items
	 *
	 * @return array
	 */
	public function groupWithInstances(array $items): array {
		$grouped = $this->group($items);
		$result = [];

		foreach ($grouped as $bucket => $bucket_items) {
			// Array keys: a bucket named "7" (numeric component tag value) comes
			// back as int, and resolveInstance() is typed string under strict_types.
			$bucket = (string) $bucket;
			$instances = [];

			foreach ($bucket_items as $key => $item) {
				$instance = $this->resolveInstance($item, $bucket);
				$instance_key = ($instance === null || $instance === '') ? '__direct' : $instance;
				$instances[$instance_key][$key] = $item;
			}

			$result[$bucket] = $this->orderInstances($instances);
		}

		return $result;
	}

	/**
	 * Resolve the component bucket for a single item.
	 */
	public function resolveBucket(array $item): string {
		// 1. Explicit grouping tag.
		if (array_key_exists('tags', $item) && is_array($item['tags'])) {
			foreach ($item['tags'] as $tag) {
				if (isset($tag['tag']) && strcasecmp($tag['tag'], $this->tag_name) === 0
						&& isset($tag['value']) && $tag['value'] !== '') {
					return (string) $tag['value'];
				}
			}
		}

		// 2. Key pattern (first match wins, in fixed bucket order).
		$key = isset($item['key_']) ? strtolower((string) $item['key_']) : '';

		if ($key !== '') {
			foreach (self::BUCKET_ORDER as $bucket) {
				if ($bucket === 'other' || !array_key_exists($bucket, $this->pattern_map)) {
					continue;
				}

				foreach ($this->pattern_map[$bucket] as $needle) {
					if (strpos($key, strtolower($needle)) !== false) {
						return $bucket;
					}
				}
			}
		}

		// 3. Fallback.
		return 'other';
	}

	/**
	 * Resolve the LLD instance for an item within a bucket.
	 *
	 *   1. configured instance tag for that bucket;
	 *   2. else first meaningful bracketed key parameter;
	 *   3. else null (item renders directly under the bucket).
	 *
	 * @return string|null
	 */
	public function resolveInstance(array $item, string $bucket): ?string {
		// 1. Instance tag.
		$candidate_tags = $this->instance_tag_map[$bucket] ?? [];

		if ($candidate_tags && array_key_exists('tags', $item) && is_array($item['tags'])) {
			foreach ($item['tags'] as $tag) {
				if (!isset($tag['tag']) || !isset($tag['value']) || $tag['value'] === '') {
					continue;
				}

				foreach ($candidate_tags as $candidate) {
					if (strcasecmp($tag['tag'], $candidate) === 0) {
						return (string) $tag['value'];
					}
				}
			}
		}

		// 2. First meaningful bracketed key parameter, e.g.
		//    vfs.fs.size[/var,used] -> /var ; net.if.in[eth0] -> eth0.
		$key = isset($item['key_']) ? (string) $item['key_'] : '';

		if ($key !== '' && preg_match('/\[(.+)\]/', $key, $m)) {
			$param = $this->firstKeyParam($m[1]);

			if ($param !== '') {
				return $param;
			}
		}

		// 3. No instance.
		return null;
	}

	/**
	 * Split the inside of a key's brackets and return the first non-empty param,
	 * honouring Zabbix quoting (\"...\") so commas inside quotes don't split.
	 */
	private function firstKeyParam(string $params): string {
		$len = strlen($params);
		$buf = '';
		$in_quote = false;

		for ($i = 0; $i < $len; $i++) {
			$ch = $params[$i];

			if ($ch === '"') {
				$in_quote = !$in_quote;
				continue;
			}

			if ($ch === ',' && !$in_quote) {
				break;
			}

			$buf .= $ch;
		}

		return trim($buf);
	}

	/**
	 * Reorder buckets into the fixed display order; unknown (tag-derived) buckets
	 * keep their first-seen order, appended before 'other'.
	 */
	private function orderBuckets(array $buckets): array {
		$ordered = [];

		// Known buckets first, in fixed order (except 'other').
		foreach (self::BUCKET_ORDER as $bucket) {
			if ($bucket !== 'other' && array_key_exists($bucket, $buckets)) {
				$ordered[$bucket] = $buckets[$bucket];
				unset($buckets[$bucket]);
			}
		}

		// Any custom tag-derived buckets, first-seen order, excluding 'other'.
		foreach ($buckets as $bucket => $items) {
			if ($bucket !== 'other') {
				$ordered[$bucket] = $items;
			}
		}

		// 'other' always last.
		if (array_key_exists('other', $buckets)) {
			$ordered['other'] = $buckets['other'];
		}

		return $ordered;
	}

	/**
	 * Order instances naturally, keeping '__direct' first.
	 */
	private function orderInstances(array $instances): array {
		$direct = null;

		if (array_key_exists('__direct', $instances)) {
			$direct = $instances['__direct'];
			unset($instances['__direct']);
		}

		$keys = array_keys($instances);
		natcasesort($keys);

		$ordered = [];

		if ($direct !== null) {
			$ordered['__direct'] = $direct;
		}

		foreach ($keys as $key) {
			$ordered[$key] = $instances[$key];
		}

		return $ordered;
	}
}
