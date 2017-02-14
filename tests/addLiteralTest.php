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
$meta->addLiteral("http://purl.org/dc/elements/1.1/title", "new Add test 2017 - ".date("h:i:s"));

//the ispartof is the TRAC
$meta->addResource("http://purl.org/dc/terms/isPartOf", "https://id.acdh.oeaw.ac.at/0e08a54f-4909-df66-994a-9abf7d68b8d6");
$meta->addResource("http://purl.org/dc/terms/isPartOf", "https://id.acdh.oeaw.ac.at/0e08a54f-4909-df66-994a-9abf7d68b8d66");
//$meta->addResource("http://purl.org/dc/terms/isPartOf", "http://aaaa.com");
//$meta->addResource("http://purl.org/dc/terms/identifier", "https://id.acdh.oeaw.ac.at/83bb2b70-50c6-cdb9-d3fc-47878e5e5b85");

//$meta->addLiteral("http://purl.org/dc/elements/1.1/title", "2");
$res = $fedora->createResource($meta);
$fedora->commit();
$uri = $res->getUri();
$uri = preg_replace('|/tx:[-a-zA-Z0-9]+/|', '/', $uri);
$uri = $uri.'/fcr:metadata';

echo $uri;





