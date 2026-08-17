<?php
// Limpa os dados de teste criados pelo E2E e mostra o audit recente
require '/home/developehad/public_html/init.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// deleta o cliente de teste (id 8) e seus tickets
$client = Capsule::table('tblclients')->where('email', 'hermes-e2e@teste.hadcloud.local')->first();
if ($client) {
    $r = localAPI('DeleteClient', ['clientid' => $client->id]);
    printf("DeleteClient id=%d => %s\n", $client->id, $r['result'] ?? '?');
} else {
    echo "Cliente de teste nao encontrado (ja limpo)\n";
}

// garante que o ticket de teste foi fechado
$t = Capsule::table('tbltickets')->where('title', 'Teste Hermes N1 - conexao')->first();
if ($t) {
    $r = localAPI('UpdateTicket', ['ticketid' => $t->id, 'status' => 'Closed']);
    printf("CloseTicket id=%d => %s\n", $t->id, $r['result'] ?? '?');
} else {
    echo "Ticket de teste nao encontrado\n";
}

echo "--- audit recente ---\n";
$rows = Capsule::table('mod_whmcs_mcp_audit')
    ->orderByDesc('id')
    ->limit(8)
    ->get();
foreach ($rows as $r) {
    printf("[%s] %-16s %-8s %dms key=%s\n", $r->created_at, $r->tool, $r->status, $r->duration_ms, $r->key_label);
}
