<?php

require_once '../vendor/autoload.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\acl\WebAclRule as AR;
use acdhOeaw\util\Indexer;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\ResourceFactory as RF;

RC::init('../config.ini');
RC::set('sparqlUrl', 'https://fedora.localhost/blazegraph/sparql');
RC::set('containerDir', __DIR__ . '/..');
RC::set('containerToUriPrefix', 'test://');
RC::set('indexerDefaultBinaryClass', 'https://vocabs.acdh.oeaw.ac.at/schema#Resource');
RC::set('indexerDefaultCollectionClass', 'https://vocabs.acdh.oeaw.ac.at/schema#Collection');
$rightsProp = RC::get('fedoraAccessRestrictionProp');
$roleProp = RC::get('fedoraAccessRoleProp');
$fedora     = new Fedora();
RF::init($fedora);

echo "Access restriction is automatically assigned\n";
try {
    $fedora->begin();
    $res = RF::create(['type' => RC::get('fedoraRepoObjectClass')]);
    $fedora->commit();

    assert(AR::READ === $res->getAcl(true)->getMode(AR::USER, AR::PUBLIC_USER));
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    $res->delete(true, true, true);
    $fedora->commit();
}

echo "Academic access restriction is properly granted\n";
try {
    $fedora->begin();
    $res = RF::create(['type' => RC::get('fedoraRepoObjectClass'), $rightsProp => 'academic']);
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl->getMode(AR::USER, RC::get('academicGroup')));
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    $res->delete(true, true, true);
    $fedora->commit();
}

echo "Restricted access restriction is properly granted\n";
try {
    $fedora->begin();
    $res = RF::create(['type' => RC::get('fedoraRepoObjectClass'), $rightsProp => 'restricted']);
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, RC::get('academicGroup')));
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    $res->delete(true, true, true);
    $fedora->commit();
}

echo "Escalating access rights works\n";
try {
    $fedora->begin();
    $res = RF::create(['type' => RC::get('fedoraRepoObjectClass'), $rightsProp => 'restricted']);
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, RC::get('academicGroup')));

    $fedora->begin();
    $meta = $res->getMetadata();
    $meta->delete($rightsProp);
    $meta->addLiteral($rightsProp, 'public');
    $res->setMetadata($meta);
    $res->updateMetadata();
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    $res->delete(true, true, true);
    $fedora->commit();
}

echo "Limiting access rights works\n";
try {
    $fedora->begin();
    $res = RF::create(['type' => RC::get('fedoraRepoObjectClass'), $rightsProp => 'public']);
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));

    $fedora->begin();
    $meta = $res->getMetadata();
    $meta->delete($rightsProp);
    $meta->addLiteral($rightsProp, 'restricted');
    $res->setMetadata($meta);
    $res->updateMetadata();
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, RC::get('academicGroup')));
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    $res->delete(true, true, true);
    $fedora->commit();
}

echo "hasAccessRole works for restricted resources\n";
try {
    $fedora->begin();
    $res = RF::create([
        'type' => RC::get('fedoraRepoObjectClass'), 
        $rightsProp => 'restricted',
        $roleProp => 'testUser'
    ]);
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, 'testUser'));
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    $res->delete(true, true, true);
    $fedora->commit();
}

echo "Larger import works\n";
try {
    $fedora->begin();
    $coll      = RF::create(['type' => RC::get('fedoraRepoObjectClass')]);
    $ind       = new Indexer($coll);
    $ind->setPaths(['src']);
    $resources = $ind->index();
    $fedora->commit();

    foreach ($resources as $res) {
        assert(AR::READ === $res->getAcl(true)->getMode(AR::USER, AR::PUBLIC_USER));
    }
} catch (Exception $e) {
    $fedora->rollback();
} finally {
    $fedora->begin();
    foreach ($resources as $res) {
        $res->delete(true, true, true);
    }
    $coll->delete(true, true, true);
    $fedora->commit();
}
