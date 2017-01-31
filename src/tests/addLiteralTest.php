<?php

require_once '../../vendor/autoload.php';

use zozlak\util\Config;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;



$cfg = new Config('config.ini');
$fedora = new Fedora($cfg);
$fedora->begin();


$graph = new \EasyRdf_Graph();            
$meta = $graph->resource('acdh');
$meta->addLiteral("https://vocabs.acdh.oeaw.ac.at/#depositor", "test-value");
$meta->addLiteral("http://purl.org/dc/elements/1.1/title", "new testing22");
$meta->addResource("http://purl.org/dc/terms/identifier", "https://id.acdh.oeaw.ac.at/7c3ff91a-4b67-c773-fb51-b819a4020626");

//$meta->addLiteral("http://purl.org/dc/elements/1.1/title", "2");
$res = $fedora->createResource($meta);
$fedora->commit();
$uri = $res->getUri();
$uri = preg_replace('|/tx:[-a-zA-Z0-9]+/|', '/', $uri);
$uri = $uri.'/fcr:metadata';

echo $uri;





