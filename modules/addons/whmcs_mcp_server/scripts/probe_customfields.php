<?php
// Descobre o formato de customfields que o AddClient do WHMCS 8.13 aceita
require '/home/developehad/public_html/init.php';

function tryCreate(string $label, array $params): void {
    try {
        $r = localAPI('AddClient', $params);
        $status = $r['result'] ?? '?';
        $clientid = $r['clientid'] ?? '-';
        printf("%-28s result=%s clientid=%s msg=%s\n", $label, $status, $clientid,
            $r['message'] ?? '');
        if ($status === 'success') {
            localAPI('DeleteClient', ['clientid' => $clientid]);
        }
    } catch (\Throwable $e) {
        printf("%-28s EXCEPTION: %s\n", $label, $e->getMessage());
    }
}

$base = [
    'firstname' => 'Teste', 'lastname' => 'CF',
    'email' => 'cf-' . time() . '@teste.local',
    'address1' => 'Rua X', 'city' => 'SP', 'state' => 'SP',
    'postcode' => '01000-000', 'country' => 'BR',
    'phonenumber' => '11999999999', 'password2' => 'SenhaTeste#2026',
    'noemail' => 'true',
];

tryCreate('base64-serialize', $base + ['customfields' => base64_encode(serialize(['1' => '12.345.678/0001-99']))]);
tryCreate('base64-json', $base + ['customfields' => base64_encode(json_encode(['1' => '12.345.678/0001-99']))]);
tryCreate('plain-array', $base + ['customfields' => ['1' => '12.345.678/0001-99']]);
tryCreate('key-literal', $base + ['customfields[1]' => '12.345.678/0001-99']);
