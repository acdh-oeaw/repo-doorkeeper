<?php

require_once '../vendor/autoload.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\ResourceFactory as RF;

RC::init('../config.ini');
RC::set('sparqlUrl', 'https://fedora.localhost/blazegraph/sparql');
$fedora = new Fedora();
RF::init($fedora);

$fedora->begin();
$res = RF::create([
        'https://vocabs.acdh.oeaw.ac.at/schema#hasUpdatedDate' => '2017-04-03 03:02:01'
    ]);
$fedora->commit();
echo $res->getUri(true) . "\n";
echo $res->getMetadata()->getLiteral('https://vocabs.acdh.oeaw.ac.at/schema#hasUpdatedDate')->getDatatype() . "\n";

