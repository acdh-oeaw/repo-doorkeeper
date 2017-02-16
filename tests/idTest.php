<?php
/*
 * This file contains tests checking if ACDH ids are handled properly by the doorkeeper
 * 
 * Unfortunately tests are run against a repository software stack instance so
 * a working repository stack deployment is required.
 */

require_once '../vendor/autoload.php';

use zozlak\util\Config;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\EasyRdfUtil;
use GuzzleHttp\Exception\ClientException;

$cfg = new Config('config.ini');
$fedora = new Fedora($cfg);
$idProp = EasyRdfUtil::fixPropName($cfg->get('fedoraIdProp'));
$meta = (new EasyRdf_Graph())->resource('.');
$meta->addLiteral($cfg->get('fedoraTitleProp'), 'test resource');


// Id is assigned automatically
$fedora->begin();
$res = $fedora->createResource($meta);
$id = $res->getMetadata()->allResources($idProp);
if (count($id) != 1) {
    throw new Exception('ACDH id was not automatically assigned');
}
$fedora->rollback();


// id must be in the right namespace
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
$meta1->addResource($idProp, 'http://my.namespace/id');
try {
    $fedora->createResource($meta1);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraIdProp in a wrong namespace|', $resp->getBody())) {
        throw $e;
    }
}
$fedora->rollback();


// only one id is allowed
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
$meta1->addResource($idProp, $cfg->get('fedoraIdNamespace') . rand());
$meta1->addResource($idProp, $cfg->get('fedoraIdNamespace') . rand());
try {
    $fedora->createResource($meta1);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|many fedoraIdProp|', $resp->getBody())) {
        throw $e;
    }
}
$fedora->rollback();


// id can not be literal
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
$meta1->addLiteral($idProp, $cfg->get('fedoraIdNamespace') . rand());
try {
    $fedora->createResource($meta1);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraIdProp is a literal|', $resp->getBody())) {
        throw $e;
    }
}
$fedora->rollback();


// Id duplicated between transactions
$fedora->begin();
$res1 = $fedora->createResource($meta);
$fedora->commit();
$meta2 = $res1->getMetadata();
$fedora->begin();
try {
    $res2 = $fedora->createResource($meta2);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|duplicated fedoraIdProp|', $resp->getBody())) {
        throw $e;
    }
}


// id duplicated within transaction
$fedora->begin();
$res1 = $fedora->createResource($meta);
$meta2 = $res1->getMetadata();
$res2 = $fedora->createResource($meta2);
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|duplicated fedoraIdProp|', $resp->getBody())) {
        throw $e;
    }
}


// it is not a problem to modify the same resource more than once within a transaction and even id can be changed
$fedora->begin();
$res1 = $fedora->createResource($meta);
$meta1 = $res1->getMetadata();
$meta1->addLiteral('http://some.property/x', 'sample value');
$res1->setMetadata($meta1);
$res1->updateMetadata();
$meta1->delete($idProp);
$meta1->addResource($idProp, $cfg->get('fedoraIdNamespace') . rand());
$res1->setMetadata($meta1);
$res1->updateMetadata();
$fedora->commit();


// id of an existing resource can not be changed
$fedora->begin();
$res1 = $fedora->createResource($meta);
$fedora->commit();
$id = $res1->getId();
$fedora->begin();
$res1 = $fedora->getResourceByUri($res1->getUri());
$meta1 = $res1->getMetadata();
$meta1->delete($idProp);
$meta1->addResource($idProp, $cfg->get('fedoraIdNamespace') . rand());
$res1->setMetadata($meta1);
try {
    $res1->updateMetadata();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraIdProp changed|', $resp->getBody())) {
        throw $e;
    }
}
$fedora->rollback();


// any property with an object being URI in the id namespace must resolve to an existing resource
$fedora->begin();
$res1 = $fedora->createResource($meta);
$fedora->commit();
$id = $res1->getId();
$fedora->begin();
$meta2 = EasyRdfUtil::cloneResource($meta);
$meta2->addResource('http://my.own/property', $id);
$res2 = $fedora->createResource($meta2);
$fedora->commit();
$fedora->begin();
$meta3 = EasyRdfUtil::cloneResource($meta);
$meta3->addResource('http://my.own/property', $cfg->get('fedoraIdNamespace') . 'non-existing-resource');
$res3 = $fedora->createResource($meta3);
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|metadata refer to a non-existing fedoraId|', $resp->getBody())) {
        throw $e;
    }
}


