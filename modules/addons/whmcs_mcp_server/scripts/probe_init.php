<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require '/home/developehad/public_html/init.php';
echo "INIT_OK\n";
echo "DB=" . get_class(\Illuminate\Database\Capsule\Manager::connection()->getPdo()) . "\n";
