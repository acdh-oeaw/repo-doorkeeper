<?php

require_once __DIR__ . '/vendor/autoload.php';

use zozlak\util\Config;
use zozlak\util\ClassLoader;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\doorkeeper\handler\Handler;
use EasyRdf\RdfNamespace;

$cl = new ClassLoader();
RdfNamespace::set('dct', 'http://purl.org/dc/terms/');

$config = new Config('config.ini');

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    $_SERVER['PHP_AUTH_USER'] = 'user';
    $_SERVER['PHP_AUTH_PW'] = $config->get('fedoraUserPswd');
}

$dbFile = 'db.sqlite';
$initDb = !file_exists($dbFile);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($initDb) {
    Doorkeeper::initDb($pdo);
}

$doorkeeper = new Doorkeeper($config, $pdo);
$doorkeeper->registerCommitHandler('\acdhOeaw\doorkeeper\handler\Handler::checkTransaction');
$doorkeeper->registerPostCreateHandler('\acdhOeaw\doorkeeper\handler\Handler::checkCreate');
$doorkeeper->registerPostEditHandler('\acdhOeaw\doorkeeper\handler\Handler::checkEdit');

$doorkeeper->handleRequest();
