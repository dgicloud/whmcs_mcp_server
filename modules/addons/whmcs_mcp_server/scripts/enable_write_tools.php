<?php
// Libera as tools de escrita de clientes, faturas, pedidos e tickets
require '/home/developehad/public_html/init.php';
require '/home/developehad/public_html/modules/addons/whmcs_mcp_server/whmcs_mcp_server.php';

use HadCloud\Mcp\ToolState;

$writeTools = [
    'create_client', 'update_client',
    'create_invoice', 'add_invoice_payment',
    'accept_order', 'cancel_order',
    'open_ticket', 'update_ticket',
];

foreach ($writeTools as $tool) {
    ToolState::set($tool, true);
    printf("ENABLED %s\n", $tool);
}

echo "--- estado final ---\n";
foreach (ToolState::all() as $name => $enabled) {
    printf("%-32s %s\n", $name, $enabled ? 'ENABLED' : 'disabled');
}
