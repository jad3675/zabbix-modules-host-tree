# Component Tree-View — Implementation Guide

Extension to `BGmot/zabbix-module-hosts-tree` (forked, running on Zabbix 7.4).

When a host row in the existing tree is expanded, lazy-load that host's items, group
them into component buckets, and render them as nested rows beneath the host. Must work
against **any** host that has items, with zero assumptions about template, agent vs SNMP,
or tag scheme. A host with no useful tags must still render a flat readable list — never
an error.

This guide is written to be handed to a coding agent working against the cloned repo. The
agent must read the existing code first and match its conventions. The controller base
class, response objects, and manifest schema are version-sensitive — the repo is the
source of truth, not assumed API signatures.

---

## Context

- Base module: `BGmot/zabbix-module-hosts-tree`, v7.0.1 (the 7.0 rewrite), confirmed
  loading and working on Zabbix 7.4.
- Target scale: ~2,500 hosts (Cincinnati Children's), multi-tenant. Per-host lazy loading
  is mandatory, not optional.
- This is the **internal operator view**. Tenant-facing views route through Grafana and
  the Encore Portal, not this module.
- Zabbix has no "components" object. Interfaces, drives, memory, CPU are all just **items**.
  The entire problem reduces to: fetch a host's items and group them by a sensible key.

---

## Hard constraints (read before writing anything)

- Never fetch items for more than one host at a time. Fetch happens on host-row expand,
  per host, via AJAX. Never iterate all hosts.
- All data access goes through the Zabbix frontend `API` layer in the logged-in user
  context. No raw SQL. No admin/service permission override. Host-group permissions must
  be inherited automatically so tenant isolation holds.
- Do not modify the existing `bghost.view` tree-building logic beyond adding one expander
  hook on host leaf rows.
- Match the module's existing controller base class, response objects, and `manifest.json`
  schema exactly. Do not import classes from a different Zabbix major version.
- Keep new classes under the existing namespace (`Modules\BGmotHosts`). Do not rename the
  module directory (`zabbix-module-hosts-tree`).

---

## Step 0: Orient

Read these and report conventions back **before coding**:

- `Module.php` — namespace, how `bghost.view` is registered.
- `actions/` — the controller for `bghost.view`: base class, `checkInput()` /
  `checkPermissions()` / `doAction()`, how it sets its response.
- `partials/module.monitoring.host.view.html.php` — the `addGroupRow()` recursion that
  renders tree rows, and how host leaf rows are emitted.
- `views/module.monitoring.bghost.view.refresh.php` — the existing AJAX partial pattern.
  This is the template for the new action's return.
- `manifest.json` — action + assets registration schema.
- The JS driving the existing group expand/collapse.

**Stop after Step 0 and confirm with the maintainer the exact controller base class and
response object names found.** This is the one place a wrong assumption costs the most.

---

## Phase 1 — Flat-to-grouped component view (v1)

Narrow on purpose. Prove the expand → fetch → group → render loop. No LLD sub-nesting,
no trigger coloring, no click-through.

### Step 1.1: New controller action `bghost.component.view`

New file in `actions/`, same base class and structure as the existing controller.

- `checkInput()`: require a single `hostid`, validated as a DB id.
- `checkPermissions()`: gate on user type as the existing action does; rely on API
  host-group permission inheritance for actual scoping.
- `doAction()`: fetch, group, hand a partial to the response.

The load-bearing fetch:

```php
$items = API::Item()->get([
    'output'       => ['itemid','name','key_','lastvalue','lastclock','units','value_type','status'],
    'selectTags'   => ['tag','value'],
    'hostids'      => $hostid,
    'webitems'     => false,
    'filter'       => ['status' => ITEM_STATUS_ACTIVE],
    'sortfield'    => 'name',
    'preservekeys' => true,
]);

$interfaces = API::HostInterface()->get([
    'output'  => ['interfaceid','ip','dns','useip','type','main'],
    'hostids' => $hostid,
]);
```

If `$items` is empty, return a single "no items" row — not an error.

### Step 1.2: Grouping helper

Separate class/file so it's unit-testable in isolation. Signature roughly
`group(array $items, string $tagName, array $patternMap): array` returning an **ordered**
associative array of `bucket => items[]`.

Resolution order per item:

1. If the item has a tag whose name equals the configured grouping tag (default
   `component`), the tag value is the bucket.
2. Else run the key against the pattern map (first match wins).
3. Else bucket `other`.

Default pattern map (config-overridable constant; include SNMP key forms, not just agent
keys):
Preserve a fixed display order (cpu, memory, storage, network, system, other) so the UI is
stable regardless of item order.

### Step 1.3: Render partial

New partial in `partials/`, modeled on the existing host-view partial. Per bucket: a
component header row, then one item row each showing name, formatted last value with units,
and relative timestamp from `lastclock`. Use existing CSS classes and indent conventions so
it nests visually under the host row. Add new selectors to `views/css/bghost.css` rather
than inlining styles.

Value formatting: route `lastvalue` through the same convert/units helper the Zabbix
frontend uses (find what Latest data uses) so `B`, `bps`, `unixtime`, `%` render correctly.
Do not hand-format.

### Step 1.4: Wire the expander

On host leaf rows, add an expander reusing the group expander's markup/JS. On click:

- AJAX GET `zabbix.php?action=bghost.component.view&hostid=N`
- Inject the returned partial HTML beneath the host row.
- Toggle expanded/collapsed state and cache the fetched HTML so re-expanding doesn't refetch.

Register new JS/CSS in `manifest.json` assets the same way existing assets are registered.

### Step 1.5: Register the action

Add `bghost.component.view` to `manifest.json` actions, matching the existing entry's shape
(class, view, and whatever layout key the refresh view uses for partial/AJAX responses).

### Phase 1 acceptance tests

Run against three deliberately different hosts and eyeball each:

1. **Templated Linux agent host** — expect clean cpu/memory/storage/network/system buckets
   from the `component` tag.
2. **Cisco SNMP host (WLC template)** — expect grouping from whatever tags exist, with
   key-pattern fallback filling gaps. Verify nothing throws on SNMP key forms.
3. **Deliberately ugly host** — sparse items, no useful tags. Expect everything in `other`,
   rendered as a flat readable list. No fatal, no blank page.

**Pass condition:** all three expand to something sensible, no PHP fatal in the web
container log, collapse/re-expand uses the cache.

Do not start Phase 2 until all three pass.

---

## Phase 2 — LLD instance sub-grouping

Adds a third tree level: bucket → instance → items. Within "storage" one sub-row per
mount; within "network" one sub-row per interface.

### The instance key

Zabbix has no explicit instance object either. The discovered instance lives in one of two
places:

1. **A tag value** — well-templated items carry a tag like `mountpoint`/`filesystem` or
   the interface name. Prefer this.
2. **The item key parameter** — e.g. `vfs.fs.size[/var,used]` → instance `/var`;
   `net.if.in[eth0]` → instance `eth0`. Parse the bracketed parameter as fallback.

### Step 2.1: Instance resolver

Extend the grouping helper (or a sibling) with `resolveInstance(array $item, string $bucket): ?string`:

1. Look for a configured instance tag for that bucket (config map:
   `storage → mountpoint`, `network → ifName`, etc.).
2. Else parse the first meaningful key parameter from inside the brackets.
3. Else return `null` (item renders directly under the bucket, no instance row).

Make the bucket→instance-tag map config-overridable, same as the pattern map.

### Step 2.2: Two-level grouping

Grouping output becomes `bucket => [ instance|__direct => items[] ]`. Items with no
resolved instance go in a `__direct` pseudo-instance rendered without an extra indent.
Preserve ordering: bucket order fixed as Phase 1; instances sorted naturally (so `/`,
`/boot`, `/var` and `eth0`, `eth1` read in order).

### Step 2.3: Render the instance level

Extend the partial: bucket header → instance sub-header (e.g. the mountpoint or interface
name) → item rows. Reuse existing indent classes for the third level. `__direct` items
render at bucket level with no instance header.

### Phase 2 acceptance tests

1. Linux host with several filesystems and NICs — expect per-mount and per-interface
   sub-rows, correctly named.
2. SNMP host with discovered interfaces — expect per-interface grouping via key-param parse
   when tags are absent.
3. Phase 1 ugly host — must still render flat (everything `__direct` under `other`),
   unchanged from Phase 1.

---

## Phase 3 — Status and navigation polish

Independent, can be done in any order once Phase 2 passes.

### Step 3.1: Trigger severity bleed-through

Fetch active problems for the host and color the bucket (and instance) header at the
highest severity of any item beneath it, so a failed disk lights up the `storage` bucket at
a glance.

```php
$problems = API::Problem()->get([
    'output'      => ['eventid','severity','name','objectid'],
    'hostids'     => $hostid,
    'recent'      => false,
    'severities'  => range(TRIGGER_SEVERITY_INFORMATION, TRIGGER_SEVERITY_DISASTER),
]);
```

Map problem → trigger → items to attribute severity to the right bucket. Use Zabbix's
standard severity CSS classes; don't invent colors.

### Step 3.2: Click-through

Make each item row link to its history/graph — the same destination Latest data's "History"
link uses. Build the URL the way the frontend does; don't hardcode.

### Step 3.3: Value mapping

If an item has a value map, display the mapped label (e.g. `ifOperStatus` 1 → "up") instead
of the raw number. The fetch already needs `valuemapid` added to `output`; resolve via the
standard frontend helper.

---

## Known gotcha

The `filemtime(): stat failed` error reported in upstream issue #20 is a permissions/path
symptom, not a code bug — the web process can't stat a bundled asset. If new CSS/JS triggers
it, the fix is **host-side file readability** (world-readable on the host side of the
read-only volume mount), not PHP. Don't chase a phantom in the partial.

---

## Out of scope (entirely)

- Replacing or competing with the Zabbix frontend.
- Anything tenant-facing — that's Grafana + the Encore Portal.
- Multi-host bulk fetch of any kind.