#!/usr/bin/php
<?php
/**
 * Prints meta information from file
 * 
 * @example ./fileInfo.php "http://your.sharepoint.site/sites/Test Site/Shared Documents/Sample File.txt"
 */
require_once('../sharesoap.php');

header('Content-Type: text/plain; charset=utf-8');

if($argc < 2) {
	die("Usage: 'fileInfo.php <url>\n");
}
$fileName = $argv[1];

$cfg = parse_ini_file('config.ini',true);

echo "* File info from '$fileName' on site '{$cfg['site']}' (username: '{$cfg['username']}'):\n\n";
$sp = new \ShareSoap\Sharepoint($cfg['site'],$cfg['username'],$cfg['password']);
$meta = $sp->getFileInfo($fileName);

foreach(array('Content-Type','Last-Modified','Content-Length') as $item) {
	echo $item . ': ' . $meta[$item] . "\n";
}

