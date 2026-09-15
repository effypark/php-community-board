<?php

function getDB(): PDO
{
	static $db;

	if ($db instanceof PDO) {
		return $db;
	}

	$config = require __DIR__ . '/../config/database.php';
	$dsn = sprintf(
		'mysql:host=%s;port=%s;dbname=%s;charset=%s',
		$config['host'],
		$config['port'],
		$config['name'],
		$config['charset']
	);

	$db = new PDO($dsn, $config['user'], $config['password'], [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);

	return $db;
}
