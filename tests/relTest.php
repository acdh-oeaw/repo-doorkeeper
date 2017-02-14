<?php
/*
 * This file contains test checking if the relation property is properly handled
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
$relProp = EasyRdfUtil::fixPropName($cfg->get('fedoraRelProp'));
$meta = (new EasyRdf_Graph())->resource('.');
$meta->addLiteral($cfg->get('fedoraTitleProp'), 'test resource');

// relation property must be a resource
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
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

// relation property must be in the ACDH id namespace
$fedora->begin();
$meta1 = EasyRdfUtil::cloneResource($meta);
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

// relation property can not point to the resource itself
$fedora->begin();
$res1 = $fedora->createResource($meta);
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

// relation can not point to an unexisting resource
$fedora->begin();
$res1 = $fedora->createResource($meta);
$meta1 = $res1->getMetadata();
$meta1->addResource($relProp, $cfg->get('fedoraIdNamespace') . 'non-existing-resource');
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

// single relation in separate transactions
$fedora->begin();
$res1 = $fedora->createResource($meta);
$id1 = $res1->getId();
$fedora->commit();
$fedora->begin();
$meta2 = EasyRdfUtil::cloneResource($meta);
$meta2->addResource($relProp, $id1);
$res2 = $fedora->createResource($meta2);
$fedora->commit();

// single relation in single transaction
$fedora->begin();
$res1 = $fedora->createResource($meta);
$id1 = $res1->getId();
$meta2 = EasyRdfUtil::cloneResource($meta);
$meta2->addResource($relProp, $id1);
$res2 = $fedora->createResource($meta2);
$fedora->commit();

// many relations in separate transactions
$fedora->begin();
$res1 = $fedora->createResource($meta);
$id1 = $res1->getId();
$res2 = $fedora->createResource($meta);
$id2 = $res2->getId();
$fedora->commit();
$fedora->begin();
$meta3 = EasyRdfUtil::cloneResource($meta);
$meta3->addResource($relProp, $id1);
$meta3->addResource($relProp, $id2);
$res3 = $fedora->createResource($meta3);
$fedora->commit();

// single relation in single transaction
$fedora->begin();
$res1 = $fedora->createResource($meta);
$id1 = $res1->getId();
$res2 = $fedora->createResource($meta);
$id2 = $res2->getId();
$meta3 = EasyRdfUtil::cloneResource($meta);
$meta3->addResource($relProp, $id1);
$meta3->addResource($relProp, $id2);
$res3 = $fedora->createResource($meta3);
$fedora->commit();

// single transaction with the parent id change
$fedora->begin();
$res1 = $fedora->createResource($meta);
$meta1 = $res1->getMetadata();
$meta1->delete($idProp);
$id1 = $cfg->get('fedoraIdNamespace') . rand();
$meta1->addResource($idProp, $id1);
$res1->setMetadata($meta1);
$res1->updateMetadata();
$meta2 = EasyRdfUtil::cloneResource($meta);
$meta2->addResource($relProp, $id1);
$res2 = $fedora->createResource($meta2);
$fedora->commit();
