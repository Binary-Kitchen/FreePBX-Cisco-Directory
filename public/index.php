<?php
require __DIR__ . '/../vendor/autoload.php';

use Overdesign\PsrCache\FileCacheDriver;
use Noodlehaus\Config;

$cacheDir = __DIR__ . '/../cache';
$cache = new FileCacheDriver($cacheDir);

$config = new Config(__DIR__ . '/../config');

header("content-type: text/xml");
header("Connection: close");
header("Expires: -1");

if (!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

$mode = array_key_exists('mode', $_GET) ? $_GET['mode'] : NULL;
$url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
$xtn = array_key_exists('xtn', $_GET) ? $_GET['xtn'] : NULL;
$page = array_key_exists('page', $_GET) ? $_GET['page'] : 1;

switch ($mode) {
case "extensions":
	echo directoryShow('extensions', $url, $xtn, $page);
	break;
case "eventphone":
	echo directoryShow('eventphone', $url, $xtn, $page);
	break;
case "tools":
	echo toolsShow($url, $xtn);
	break;
default:
	echo directoryMenu($url, xtn);
}

function directoryMenu($url, $xtn)
{
	$menuData = array(
		array('Internal Phonebook', "$url?mode=extensions"),
		array('EventPhone Phonebook', "$url?mode=eventphone"),
		array('System Tools', "$url?mode=tools"),
	);

	$xml = new SimpleXMLElement('<CiscoIPPhoneMenu/>');
	$xml -> addChild('Prompt', 'Select a directory');

	foreach ($menuData as $menuItem) {
		$item = $xml->addChild('MenuItem');
		$item->addChild('Name', $menuItem[0]);
		$item->addChild('URL', $menuItem[1]);
	}

	return $xml->asXML();
}

function toolsShow($url, $xtn)
{
	$menuData = array(
		array('Speaking Clock', '*60'),
		array('Echo Test', '*43'),
	);

	$xml = new SimpleXMLElement('<CiscoIPPhoneDirectory/>');
	$xml->addChild('Title', 'System Tools');
	$xml->addChild('Prompt', 'Select a tool');
	foreach ($menuData as $menuItem){
		addDirectoryEntry($xml, $menuItem[0], $menuItem[1]);
	}

	return $xml->asXML();
}

function directoryShow($mode, $url, $xtn, $page)
{
	global $db, $cache, $config;

	define('MAX_ENTRIES', 32);

	$results = array();
	switch ($mode) {
	case "extensions":
		$sql = "SELECT name, extension FROM users WHERE name NOT LIKE '%FAX%' ORDER BY name";
		$results = $db->getAll($sql, DB_FETCHMODE_ORDERED);
		$title = 'Internal directory';
		$prompt = 'Select a name';
		break;
	case "eventphone":
		$cacheResults = $cache->getItem('ldap_results');
		if (!$cacheResults->isHit()) {
			$ldap_host = $config['eventphone']['ldap']['host'];
			$ldap_port = $config['eventphone']['ldap']['port'];
			$ldap_basedn = $config['eventphone']['ldap']['basedn'];
			$ldap_filter = $config['eventphone']['ldap']['filter'];
			$ldap_dial_prefix = $config['eventphone']['prefix'];

			$ds = @ldap_connect($ldap_host, $ldap_port);
			if ($ds === false)
				exit("ldap_connect problem: " . ldap_error($ds));

			$search_result = @ldap_search($ds, $ldap_basedn, $ldap_filter);
			if ($search_result === false)
				exit("ldap_search problem: " . ldap_error($ds));

			$ldap_result = @ldap_get_entries($ds, $search_result);
			if ($ldap_result === false)
				exit("ldap_get_entries problem: " . ldap_error($ds));


			$uncachedResults = array();
			for ($i = 0; $i < $ldap_result["count"]; $i++) {
				if (!isset($ldap_result[$i]["telephonenumber"]))
					continue;
				if (!isset($ldap_result[$i]["sn"]))
					continue;
				$r_ar = array();
				$r_ar[0]=$ldap_result[$i]["sn"][0];
				$r_ar[1]=$ldap_dial_prefix . $ldap_result[$i]["telephonenumber"][0];
				array_push($uncachedResults, $r_ar);
			}

			$cacheResults->set($uncachedResults);
			$cacheResults->expiresAfter($config['eventphone']['cache']['ttl']);
			$cache->save($cacheResults);
		}

		$results = $cacheResults->get();
		$title = 'Eventphone';
		$prompt = 'Select a name';
		break;
	default:
		return;
	}

	array_multisort(array_column($results, 0), SORT_ASC, $results);

	$numrows = count($results);
	$xml = new SimpleXMLElement('<CiscoIPPhoneDirectory/>');
	$xml->addChild('Title', $title);
	$xml->addChild('Prompt', $prompt);

	for ($row = MAX_ENTRIES * ($page - 1); $row < MAX_ENTRIES * $page; $row++) {
		if ($row == $numrows)
			break;

		if (!is_null($results[$row][0])) {
			addDirectoryEntry($xml, $results[$row][0], $results[$row][1]);
		}
	}

	addSoftKey($xml, 'Dial', 'SoftKey:Dial', 1);
	if ($page > 1) {
		addSoftKey($xml, 'Prev', "$url?mode=$mode&page=" . ($page - 1), 2);
	}

	if ($page < ceil($numrows / MAX_ENTRIES)) {
		addSoftKey($xml, 'Next', "$url?mode=$mode&page=" . ($page + 1), 3);
	}
	
	addSoftKey($xml, 'Exit', 'SoftKey:Exit', 4);

	return $xml->asXML();
}

function addSoftKey($xml, $name, $url, $position)
{
	$softKeyDial = $xml->addChild('SoftKeyItem');
	$softKeyDial->addChild('Name', htmlspecialchars($name));
	$softKeyDial->addChild('URL', htmlspecialchars($url));
	$softKeyDial->addChild('Position', $position);
}

function addDirectoryEntry($xml, $name, $telephone)
{
        $item = $xml->addChild('DirectoryEntry');
	$item->addChild('Name', htmlspecialchars($name));
	$item->addChild('Telephone', htmlspecialchars($telephone));
}
