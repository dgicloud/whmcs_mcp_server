<?php
require '/home/developehad/public_html/init.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$rows = Capsule::table('tblcustomfields')
    ->where('type', 'client')
    ->get(['id', 'fieldname', 'required', 'fieldtype', 'adminonly']);
foreach ($rows as $r) {
    printf("id=%d name='%s' required=%d type=%s adminonly=%d\n",
        $r->id, $r->fieldname, $r->required, $r->fieldtype, $r->adminonly);
}
