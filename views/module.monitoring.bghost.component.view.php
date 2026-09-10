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

echo json_encode($output);
