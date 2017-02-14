<?php

require_once '../../vendor/autoload.php';

use zozlak\util\Config;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;



$cfg = new Config('config.ini');
$fedora = new Fedora($cfg);
$fedora->begin();


$fr = $fedora->getResourceByUri("http://fedora.localhost/rest/73/83/0c/de/73830cde-b156-4ced-a243-277a1ef58fb3");

//get the existing metadata
$meta = $fr->getMetadata();
$meta2 = $meta->get(EasyRdfUtil::fixPropName("http://purl.org/dc/terms/identifier"))->__toString();
echo "<pre>";
var_dump($meta2);
echo "</pre>";

/*
$meta->delete("http://purl.org/dc/elements/1.1/title");
//insert the property with the new key
$meta->addLiteral("http://purl.org/dc/elements/1.1/title", "116222");
try {

	$fr->setMetadata($meta);
    $res = $fr->updateMetadata();		
	$fedora->commit();
	
	
} catch (Exception $ex) {

	$fedora->rollback();	
	
}
*/
/*$uri = $res->getUri();
$uri = preg_replace('|/tx:[-a-zA-Z0-9]+/|', '/', $uri);
$uri = $uri.'/fcr:metadata';

echo $uri;*/





