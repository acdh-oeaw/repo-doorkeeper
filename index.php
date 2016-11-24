<?php

require_once __DIR__ . '/vendor/autoload.php';

use zozlak\util\Config;
use zozlak\util\ClassLoader;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\fedora\FedoraResource;

$cl = new ClassLoader();

$config = new Config('config.ini');

$dbFile = 'db.sqlite';
$initDb = !file_exists($dbFile);
$pdo = new PDO('sqlite:' . $dbFile);
if ($initDb) {
    Doorkeeper::initDb($pdo);
}
$doorkeeper = new Doorkeeper($config, $pdo);

// najważniejszy sposród handlerów
$doorkeeper->registerCommitHandler(function(array $modResources, Doorkeeper $d) {
    $d->e("transaction commit handler for:\n");
    foreach ($modResources as $i) {
        $d->e($i->getUri() . "\n");
    }
});

// preEdit i preCreate handlery byłyby fajne, ale zaimplementowanie ich jest bez porównania
// trudniejsze i na razie po prostu ich nie będzie
// jakkolwiek rzucanie błędami w postCreate handlerze mogłoby mieć sens (i powodować usunięcie zasobu),
// to nie jest zaimplementowane, więc w tej chwili nie ma co niczym rzucać
// 
// to, co na pewno należałoby tu zaimplementować (jak również w postEdit handlerze),
// to dbanie o istnienie właściwości dct:identifier
$doorkeeper->registerPostCreateHandler(function(FedoraResource $res, Doorkeeper $d) {
    $d->e('post create handler for ' . $res->getUri() . "\n");
});
// nie ma sensu rzucanie błędami w postEdit handlerze, bo i tak nie ma jak wycofać takiej zmiany
// (chyba że razem z całą transakcją, ale to sprawdza commitHandler)
$doorkeeper->registerPostEditHandler(function(FedoraResource $res, Doorkeeper $d) {
    $d->e('post edit handler for ' . $res->getUri() . "\n");
});

$doorkeeper->handleRequest();
