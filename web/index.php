<?php

require_once __DIR__ . '/../vendor/autoload.php';

use zozlak\util\ClassLoader;
use acdhOeaw\doorkeeper\Doorkeeper;

$cl = new ClassLoader('../src');

$pdo = new PDO('sqlite:db.sqlite');
$doorkeeper = new Doorkeeper('/rest/', 'http://fedora.localhost/rest/', $pdo);

// najważniejszy sposród handlerów
$doorkeeper->registerCommitHandler(function(array $modifiedResourceURIs, Doorkeeper $d) {
    
});

// preEdit i preCreate handlery byłyby fajne, ale zaimplementowanie ich jest bez porównania
// trudniejsze i na razie po prostu ich nie będzie

// jakkolwiek rzucanie błędami w postCreate handlerze mogłoby mieć sens (i powodować usunięcie zasobu),
// to nie jest zaimplementowane, więc w tej chwili nie ma co niczym rzucać
// 
// to, co na pewno należałoby tu zaimplementować (jak również w postEdit handlerze),
// to dbanie o istnienie właściwości dct:identifier
$doorkeeper->registerPostCreateHandler(function(string $resourceURI, Doorkeeper $d) {
    
});
// nie ma sensu rzucanie błędami w postEdit handlerze, bo i tak nie ma jak wycofać takiej zmiany
// (chyba że razem z całą transakcją, ale to sprawdza commitHandler)
$doorkeeper->registerPostEditHandler(function(string $resourceURI, Doorkeeper $d) {
    
});

$doorkeeper->handleRequest();
