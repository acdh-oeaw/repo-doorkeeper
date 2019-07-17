<?php

require_once 'init.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\acl\WebAclRule as AR;
use acdhOeaw\util\Indexer;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\ResourceFactory as RF;
use acdhOeaw\util\metaLookup\MetaLookupConstant;

RC::set('containerDir', __DIR__ . '/..');
RC::set('containerToUriPrefix', 'test://');
RC::set('indexerDefaultBinaryClass', 'https://vocabs.acdh.oeaw.ac.at/schema#Resource');
RC::set('indexerDefaultCollectionClass', 'https://vocabs.acdh.oeaw.ac.at/schema#Collection');
$rightsProp = RC::get('fedoraAccessRestrictionProp');
$roleProp   = RC::get('fedoraAccessRoleProp');
$metaStub   = [
    'type'                                                     => RC::get('fedoraRepoObjectClass'),
    'https://vocabs.acdh.oeaw.ac.at/schema#hasMetadataCreator' => 'https://me',
    'https://vocabs.acdh.oeaw.ac.at/schema#hasDepositor'       => 'https://me',
    'https://vocabs.acdh.oeaw.ac.at/schema#hasLicensor'        => 'https://me',
    'https://vocabs.acdh.oeaw.ac.at/schema#hasOwner'           => 'https://me',
    'https://vocabs.acdh.oeaw.ac.at/schema#hasRightsHolder'    => 'https://me',
];
$fedora     = new Fedora();
RF::init($fedora);

echo "Access restriction is automatically assigned\n";
try {
    $fedora->begin();
    $res = RF::create($metaStub);
    $fedora->commit();

    assert(AR::READ === $res->getAcl(true)->getMode(AR::USER, AR::PUBLIC_USER));
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($res)) {
        $fedora->begin();
        $res->delete(true, true, true);
        $fedora->commit();
    }
}

echo "Academic access restriction is properly granted\n";
try {
    $fedora->begin();
    $res = RF::create(array_merge($metaStub, [$rightsProp => 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/academic']));
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl->getMode(AR::USER, RC::get('academicGroup')));
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($res)) {
        $fedora->begin();
        $res->delete(true, true, true);
        $fedora->commit();
    }
}

echo "Restricted access restriction is properly granted\n";
try {
    $fedora->begin();
    $res = RF::create(array_merge($metaStub, [$rightsProp => 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/restricted']));
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, RC::get('academicGroup')));
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($res)) {
        $fedora->begin();
        $res->delete(true, true, true);
        $fedora->commit();
    }
}

echo "Escalating access rights works\n";
try {
    $fedora->begin();
    $res = RF::create(array_merge($metaStub, [$rightsProp => 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/restricted']));
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, RC::get('academicGroup')));

    $fedora->begin();
    $meta = $res->getMetadata();
    $meta->delete($rightsProp);
    $meta->addLiteral($rightsProp, 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/public');
    $res->setMetadata($meta);
    $res->updateMetadata();
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($res)) {
        $fedora->begin();
        $res->delete(true, true, true);
        $fedora->commit();
    }
}

echo "Limiting access rights works\n";
try {
    $fedora->begin();
    $res = RF::create(array_merge($metaStub, [$rightsProp => 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/public']));
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));

    $fedora->begin();
    $meta = $res->getMetadata();
    $meta->delete($rightsProp);
    $meta->addResource($rightsProp, 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/restricted');
    $res->setMetadata($meta);
    $res->updateMetadata();
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, RC::get('academicGroup')));
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($res)) {
        $fedora->begin();
        $res->delete(true, true, true);
        $fedora->commit();
    }
}

echo "hasAccessRole works for restricted resources\n";
try {
    $fedora->begin();
    $res = RF::create(array_merge($metaStub, [
            $rightsProp => 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/restricted',
            $roleProp   => 'testUser'
    ]));
    $fedora->commit();

    $acl = $res->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, 'testUser'));
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($res)) {
        $fedora->begin();
        $res->delete(true, true, true);
        $fedora->commit();
    }
}

echo "Larger import works\n";
$ml = new MetaLookupConstant(RF::createMeta($metaStub));
try {
    $resources = [];

    $fedora->begin();
    $coll      = RF::create($metaStub);
    $ind       = new Indexer($coll);
    $ind->setMetaLookup($ml);
    $ind->setBinaryClass(RC::get('fedoraRepoObjectClass'));
    $ind->setCollectionClass(RC::get('fedoraRepoObjectClass'));
    $ind->setPaths(['src']);
    $resources = $ind->index();
    $fedora->commit();

    foreach ($resources as $res) {
        assert(AR::READ === $res->getAcl(true)->getMode(AR::USER, AR::PUBLIC_USER));
    }
} catch (Exception $e) {
    $fedora->rollback();
    throw $e;
} finally {
    if (isset($coll)) {
        $fedora->begin();
        $coll->delete(true, true, true);
        $fedora->commit();
    }
}
