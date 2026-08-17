<?php
// Debug: mostra o estado de todas as tools no ToolState
require '/home/developehad/public_html/init.php';
require '/home/developehad/public_html/modules/addons/whmcs_mcp_server/whmcs_mcp_server.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$rows = Capsule::table('mod_whmcs_mcp_tools')->orderBy('name')->get();
foreach ($rows as $r) {
    printf("%-32s %-24s enabled=%d\n", $r->name, $r->group, $r->enabled);
}
$s = Capsule::table('tbladdonmodules')->where('module', 'whmcs_mcp_server')->get();
echo "--- settings ---\n";
foreach ($s as $x) {
    printf("%s = %s\n", $x->setting, $x->value);
}
