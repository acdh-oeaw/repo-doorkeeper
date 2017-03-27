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
use EasyRdf\Graph;

$cfg = new Config('config.ini');
$fedora = new Fedora($cfg);
$idProp = $cfg->get('fedoraIdProp');
$meta = (new Graph())->resource('.');
$meta->addLiteral($cfg->get('fedoraTitleProp'), 'test resource');

##########
echo "id is assigned automatically\n";
$fedora->begin();
$res = $fedora->createResource($meta);
$res->getId();
$fedora->rollback();

##########
echo "id is assigned automatically even if other id (outside ACDH namespace) is present\n";
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
$meta1->addResource($idProp, 'http://my.namespace/id');
$res1 = $fedora->createResource($meta1);
$res1->getId();
if (count($res1->getIds()) != 2) {
    throw new Exception('incorect number of ids');
}
$fedora->rollback();

##########
echo "only one ACDH id is allowed\n";
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

##########
echo "id can not be literal\n";
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

##########
echo "id in ACDH namespace duplicated between transactions\n";
$fedora->begin();
$res1 = $fedora->createResource($meta);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
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
$fedora->rollback();

##########
echo "id in ACDH namespace duplicated within transaction\n";
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

##########
echo "id outside of ACDH namespace duplicated between transactions\n";
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
$meta1->addResource($idProp, 'http://my.namespace/myId/' . rand());
$res1 = $fedora->createResource($meta1);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
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

##########
echo "it is not a problem to modify the same resource more than once within a transaction and even id can be changed\n";
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

##########
echo "ACDH id of an existing resource can not be changed\n";
$fedora->begin();
$res1 = $fedora->createResource($meta);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
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

##########
echo "any property with an object being ACDH URI in the id namespace must resolve to an existing resource\n";
$fedora->begin();
$res1 = $fedora->createResource($meta);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
$id = $res1->getId();
$fedora->begin();
$meta2 = EasyRdfUtil::cloneResource($meta);
$meta2->addResource('http://my.own/property', $id);
$res2 = $fedora->createResource($meta2);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
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


