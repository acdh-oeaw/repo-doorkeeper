<?php

/*
 * This file contains tests checking if deleting resources is handled properly
 * by the doorkeeper
 * 
 * Unfortunately tests are run against a repository software stack instance so
 * a working repository stack deployment is required.
 */

require_once '../vendor/autoload.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\RepoConfig as RC;
use GuzzleHttp\Exception\ClientException;
use EasyRdf\Graph;

RC::init('config.ini');
$fedora = new Fedora();
$idProp = RC::idProp();
$meta = (new Graph())->resource('.');
$meta->addLiteral(RC::titleProp(), 'test resource');
$meta->addResource($idProp, 'http://random.id/' . rand());


##########
echo "a resource can be deleted within the session\n";
$fedora->begin();
$res = $fedora->createResource($meta);
$res->delete();
$fedora->commit();

##########
echo "a resource can be deleted between sessions\n";
$fedora->begin();
$res = $fedora->createResource($meta);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
$fedora->begin();
$res = new FedoraResource($fedora, $fedora->sanitizeUri($res->getUri()));
$res->delete();
$fedora->commit();

##########
echo "references to deleted resource are checked\n";
$fedora->begin();
$res1 = $fedora->createResource($meta);
$meta2 = $res1->getMetadata();
$meta2->delete($idProp);
$meta2->addResource('https://my.ow/property', $res1->getId());
$meta2->addResource($idProp, 'http://random.id/' . rand());
$res2 = $fedora->createResource($meta2);
$fedora->commit();
sleep(2); // give triplestore time to synchronize
$fedora->begin();
$res1 = new FedoraResource($fedora, $fedora->sanitizeUri($res1->getUri()));
$res1->delete();
try {
    $fedora->commit();
    throw new Exception('no error');
} catch (ClientException $e) {
    $resp = $e->getResponse();
    if ($resp->getStatusCode() != 400 || !preg_match('|orphaned reference to fedoraIdProp|', $resp->getBody())) {
        throw $e;
    }
}
