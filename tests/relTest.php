<?php

/*
 * This file contains test checking if the relation property is properly handled
 * 
 * Unfortunately tests are run against a repository software stack instance so
 * a working repository stack deployment is required.
 */

require_once 'init.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;
use GuzzleHttp\Exception\ClientException;
use EasyRdf\Graph;

$fedora  = new Fedora();
$idProp  = RC::idProp();
$relProp = RC::relProp();
$meta    = (new Graph())->resource('.');
$meta->addLiteral(RC::titleProp(), 'test resource');

##########
echo "relation property must be a resource\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$meta1->addLiteral($relProp, 'http://my.value/123');
$fedora->createResource($meta1);
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraRelProp is a literal|', $resp->getBody())) {
        throw $e;
    }
}

##########
echo "relation property must be in the ACDH id namespace\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$meta1->addResource($relProp, 'http://my.value/123');
$fedora->createResource($meta1);
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraRelProp in a wrong namespace|', $resp->getBody())) {
        throw $e;
    }
}

##########
echo "relation property can not point to the resource itself\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$meta1 = $res1->getMetadata();
$meta1->addResource($relProp, $res1->getId());
$res1->setMetadata($meta1);
$res1->updateMetadata();
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraRelProp is pointing to itself|', $resp->getBody())) {
        throw $e;
    }
}

##########
echo "relation can not point to an unexisting resource\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$meta1 = $res1->getMetadata();
$meta1->addResource($relProp, RC::idNmsp() . 'non-existing-resource');
$res1->setMetadata($meta1);
$res1->updateMetadata();
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|fedoraRelProp does not exist in the repository|', $resp->getBody())) {
        throw $e;
    }
}

##########
echo "single relation in separate transactions\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$id1   = $res1->getId();
$fedora->commit();

$fedora->begin();
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'http://random.id/' . rand());
$meta2->addResource($relProp, $id1);
$res2  = $fedora->createResource($meta2);
$fedora->commit();

##########
echo "single relation in single transaction\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$id1   = $res1->getId();
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'http://random.id/' . rand());
$meta2->addResource($relProp, $id1);
$res2  = $fedora->createResource($meta2);
$fedora->commit();

##########
echo "many relations in separate transactions\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$id1   = $res1->getId();
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'http://random.id/' . rand());
$res2  = $fedora->createResource($meta2);
$id2   = $res2->getId();
$fedora->commit();

$fedora->begin();
$meta3 = $meta->copy();
$meta3->addResource($idProp, 'http://random.id/' . rand());
$meta3->addResource($relProp, $id1);
$meta3->addResource($relProp, $id2);
$res3  = $fedora->createResource($meta3);
$fedora->commit();

##########
echo "single relation in single transaction\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$id1   = $res1->getId();
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'http://random.id/' . rand());
$res2  = $fedora->createResource($meta2);
$id2   = $res2->getId();
$meta3 = $meta->copy();
$meta3->addResource($idProp, 'http://random.id/' . rand());
$meta3->addResource($relProp, $id1);
$meta3->addResource($relProp, $id2);
$res3  = $fedora->createResource($meta3);
$fedora->commit();

##########
echo "single transaction with the parent id change\n";
$fedora->begin();
$meta1 = $meta->copy();
$meta1->addResource($idProp, 'http://random.id/' . rand());
$res1  = $fedora->createResource($meta1);
$meta1 = $res1->getMetadata();
$meta1->delete($idProp);
$meta1->addResource($idProp, 'http://random.id/' . rand());
$id1   = RC::idNmsp() . rand();
$meta1->addResource($idProp, $id1);
$res1->setMetadata($meta1);
$res1->updateMetadata();
$meta2 = $meta->copy();
$meta2->addResource($idProp, 'http://random.id/' . rand());
$meta2->addResource($relProp, $id1);
$res2  = $fedora->createResource($meta2);
$fedora->commit();
