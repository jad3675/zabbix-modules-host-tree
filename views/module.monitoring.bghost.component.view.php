<?php declare(strict_types = 1);

/*
** Component Tree-View extension for BGmot/zabbix-module-hosts-tree.
**
** AJAX (layout.json) response for the bghost.component.view action. Mirrors the
** existing module.monitoring.bghost.view.refresh.php pattern: render a partial
** and echo it as JSON so the JS can inject the rows beneath the host row.
**/

$output = [
	'hostid' => $data['hostid'],
	'body' => (new CPartial('module.monitoring.bghost.component.rows', $data))->getOutput()
];

if (($messages = getMessages()) !== null) {
	$output['messages'] = $messages->toString();
}

// JSON_INVALID_UTF8_SUBSTITUTE: a single invalid byte in any item value (SNMP
// OCTET STRINGs are the usual suspect) otherwise makes json_encode() return
// false, which echoes an empty body and the expand silently fails.
$json = json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE);

if ($json === false) {
	$json = json_encode([
		'hostid' => $data['hostid'],
		'bgcomp_error' => 'Response encoding failed: '.json_last_error_msg()
	]);
}

echo $json;
