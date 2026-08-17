<?php
require '/home/developehad/public_html/init.php';
require '/home/developehad/public_html/modules/addons/whmcs_mcp_server/whmcs_mcp_server.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$rows = Capsule::table('mod_whmcs_mcp_audit')
    ->orderByDesc('id')
    ->limit(5)
    ->get();

foreach ($rows as $r) {
    printf("[%s] %s %s %dms key=%s ip=%s\n  MSG: %s\n  ARGS: %s\n\n",
        $r->created_at, $r->tool, $r->status, $r->duration_ms,
        $r->key_label, $r->ip,
        mb_substr((string) $r->message, 0, 400),
        mb_substr((string) $r->args, 0, 300));
}
