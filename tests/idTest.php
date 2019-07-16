<?php
/*
 * This file contains tests checking if ACDH ids are handled properly by the doorkeeper
 * 
 * Unfortunately tests are run against a repository software stack instance so
 * a working repository stack deployment is required.
 */

require_once 'init.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;
use GuzzleHttp\Exception\ClientException;
use EasyRdf\Graph;

$fedora = new Fedora();
$idProp = RC::idProp();
$idNmsp = RC::idNmsp();
$meta = (new Graph())->resource('.');
$meta->addLiteral(RC::titleProp(), 'test resource');
/*
##########
echo "id is assigned automatically\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://my.namespace/id');
$res1 = $fedora->createResource($meta1);
$res1->getId();
if (count($res1->getIds()) != 2) {
    throw new Exception('incorect number of ids');
}
$fedora->rollback();

##########
echo "non-ontology resources must have at least one 'non-ACDH id' identifier\n";
$fedora->begin();
$meta1 = $meta->copy();
try {
    $fedora->createResource($meta1);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|at least one "non-ACDH id" identifier|', $resp->getBody())) {
        throw $e;
    }    
}
$fedora->rollback();

##########
echo "only one ACDH id is allowed\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, $idNmsp . 'a' . rand());
$meta1->addResource($idProp, $idNmsp . 'b' . rand());
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
$meta1 = $meta->copy();
$meta1->addLiteral($idProp, RC::idNmsp() . rand());
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
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://some.random/id/a' . rand());
$res1 = $fedora->createResource($meta1);
$fedora->commit();

$meta2 = $meta->copy();
$meta2->addResource($idProp, 'https://some.random/id/b' . rand());
$meta2->addResource($idProp, $res1->getId());
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
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://some.random/id/a' . rand());
$res1 = $fedora->createResource($meta1);
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'https://some.random/id/b' . rand());
$meta2->addResource($idProp, $res1->getId());
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
$id = 'https://some.random/id/a' . rand();
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, $id);
$res1 = $fedora->createResource($meta1);
$fedora->commit();

$meta2 = $meta->copy();
$meta2->addResource($idProp, $id);
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
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://some.random/id/a' . rand());
$res1 = $fedora->createResource($meta1);
$meta1 = $res1->getMetadata();
$meta1->addLiteral('http://some.property/x', 'sample value');
$res1->setMetadata($meta1);
$res1->updateMetadata();
$meta1->delete($idProp);
$meta1->addResource($idProp, 'https://some.random/id/a' . rand());
$meta1->addResource($idProp, $idNmsp . rand());
$res1->setMetadata($meta1);
$res1->updateMetadata();
$fedora->commit();

##########
echo "ACDH id of an existing resource can not be changed\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://some.random/id/a' . rand());
$res1 = $fedora->createResource($meta1);
$fedora->commit();

$id = $res1->getId();
$fedora->begin();
$res1 = $fedora->getResourceByUri($res1->getUri());
$meta1 = $res1->getMetadata();
$meta1->delete($idProp);
$meta1->addResource($idProp, $idNmsp . rand());
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
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://some.random/id/a' . rand());
$res1 = $fedora->createResource($meta1);
$fedora->commit();

$id = $res1->getId();
$fedora->begin();
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'https://some.random/id/b' . rand());
$meta2->addResource('http://my.own/property', $id);
$res2 = $fedora->createResource($meta2);
$fedora->commit();

$fedora->begin();
$meta3 = $meta->copy();
$meta3->addResource($idProp, 'https://some.random/id/c' . rand());
$meta3->addResource('http://my.own/property', RC::idNmsp() . 'non-existing-resource');
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
*/
##########
echo "geonames id are being checked\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://www.geonames.org/2761367/wien.html');
try {
    $res1 = $fedora->createResource($meta1);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|a geonames id URI has to match|', $resp->getBody())) {
        throw $e;
    }   
}
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://www.geonames.org/2761367/wien.html');
try {
    $res1 = $fedora->createResource($meta1);
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|a geonames id URI has to match|', $resp->getBody())) {
        throw $e;
    }   
}
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'https://www.geonames.org/2761367');
$res1 = $fedora->createResource($meta1);
$fedora->rollback();
