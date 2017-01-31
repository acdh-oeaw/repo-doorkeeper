<?php

require_once '../../vendor/autoload.php';

use zozlak\util\Config;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;



$cfg = new Config('config.ini');
$fedora = new Fedora($cfg);
$fedora->begin();

//$graph = new \EasyRdf_Graph();            
//$meta = $graph->resource('acdh');

$fr = $fedora->getResourceByUri("http://fedora.localhost/rest/4f/30/5e/b3/4f305eb3-7e80-4c1d-8cf3-438e136bf719");
//get the existing metadata
$meta = $fr->getMetadata();

$meta->delete("http://purl.org/dc/elements/1.1/title");
//insert the property with the new key
$meta->addLiteral("http://purl.org/dc/elements/1.1/title", "testing the edit2");

$meta->addResource("http://purl.org/dc/terms/isPartOf", "https://id.acdh.oeaw.ac.at/0e08a54f-4909-df66-994a-9abf7d68b8d6");
$meta->addResource("http://purl.org/dc/terms/isPartOf", "https://id.acdh.oeaw.ac.at/0e08a54f-4909-df66-994a-9abf7d68b8d66");
//$meta->addLiteral("http://purl.org/dc/terms/identifier", "http://aaaa222222233111.com");

//try {

	$fr->setMetadata($meta);
    $res = $fr->updateMetadata();		
	$fedora->commit();
	
	
/*} catch (Exception $ex) {

	$fedora->rollback();	
	
}*/

/*$uri = $res->getUri();
$uri = preg_replace('|/tx:[-a-zA-Z0-9]+/|', '/', $uri);
$uri = $uri.'/fcr:metadata';

echo $uri;*/





