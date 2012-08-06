#!/usr/bin/php
<?php
/**
 * Prints items in a list
 * 
 * @example ./listItems.php {8D936FEE-1FE3-44DB-90B3-6DA60C1B523C}
 */
require_once('../sharesoap.php');

header('Content-Type: text/plain; charset=utf-8');

if($argc < 2) {
	die("Usage: 'deleteFolder.php <path>'\n");
}
$path = $argv[1];

$cfg = parse_ini_file('config.ini',true);

echo "* Deleting folder '$path' on site '{$cfg['site']}' (username: '{$cfg['username']}'):\n\n";
$sp = new \ShareSoap\Sharepoint($cfg['site'],$cfg['username'],$cfg['password']);
$sp->deleteFolder($path);

