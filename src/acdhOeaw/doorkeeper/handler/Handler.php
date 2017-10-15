<?php

/**
 * The MIT License
 *
 * Copyright 2016 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\doorkeeper\handler;

use DateTime;
use EasyRdf\Resource;
use zozlak\util\UUID;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\NoAcdhId;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasTriple;
use acdhOeaw\fedora\metadataQuery\QueryParameter;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\epicHandle\HandleService;
use acdhOeaw\util\RepoConfig as RC;
use RuntimeException;
use LogicException;
use GuzzleHttp\Exception\RequestException;

/**
 * Implements the ACDH business logic
 *
 * @author zozlak
 */
class Handler {

    static private $fedoraExtentProp  = 'http://www.loc.gov/premis/rdf/v1#hasSize';
    static private $fedoraBinaryClass = 'http://fedora.info/definitions/v4/repository#Binary';
    static private $subclassProp      = 'http://www.w3.org/2000/01/rdf-schema#subClassOf';
    static private $classProp         = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    static private $repoResClasses;

    /**
     * Checks resources at the end of transaction
     * 
     * Any errors found should be reported by throwing a \LogicException.
     * @param array $modResources array of FedoraResource objects being created
     *   or modified in this transaction
     * @param array $delUris URIs of resources deleted during this transaction
     * @param \acdhOeaw\doorkeeper\Doorkeeper $d the doorkeeper instance
     * @throws \LogicException
     */
    static public function checkTransaction(array $modResources, array $delUris,
                                            Doorkeeper $d) {
        $d->log(" pre transaction commit handler for: " . $d->getTransactionId());

        foreach ($modResources as $i) {
            $d->log('  ' . $i->getUri());
            self::checkIdProp($i, $modResources, $d);
            self::checkTitleProp($i, $d);
            self::checkRelProp($i, $modResources, $delUris, $d);
            self::checkIdRef($i, $modResources, $delUris, $d);
        }

        foreach ($delUris as $i) {
            $d->log('  ' . $i);
            self::checkOrphanedRelProp($i, $delUris, $d);
        }
    }

    static public function postTransaction(array $modResources, array $delUris,
                                           Doorkeeper $d) {
        $d->log(" post transaction commit handler for: " . $d->getTransactionId());

        self::updateCollectionExtent($modResources, $d);
    }

    /**
     * Checks a resource after creation
     * 
     * Be aware that binary resources have almost no metadata upon creation
     * (their metadata are provided in a separate request being resource
     * modyfication not creation) so most checks must be postponed.
     * 
     * Any errors found should be reported by throwing a \LogicException.
     * @param \acdhOeaw\doorkeeper\FedoraResource $res created resource
     * @param \acdhOeaw\doorkeeper\Doorkeeper $d the doorkeeper instance
     * @throws \LogicException
     * @see checkEdit()
     */
    static public function checkCreate(FedoraResource $res, Doorkeeper $d) {
        $d->log(' post create handler for: ' . $res->getUri());
    }

    /**
     * Checks resource after modification.
     * 
     * Be aware that even setting resource metadata upon creation is in fact
     * a resource modification.
     * 
     * It should be considered a bad practice to check relationships between
     * resources here. Implement them as transaction end checks instead.
     * 
     * Any errors found should be reported by throwing a \LogicException.
     * @param \acdhOeaw\doorkeeper\FedoraResource $res created resource
     * @param \acdhOeaw\doorkeeper\Doorkeeper $d the doorkeeper instance
     * @throws \LogicException
     */
    static public function checkEdit(FedoraResource $res, Doorkeeper $d) {
        $d->log(' post edit handler for: ' . $d->getMethod() . ' ' . $res->getUri());

        try {
            $res->getMetadata();

            $update = false;
            $update |= self::checkIdProp($res, array(), $d);
            $update |= self::checkTitleProp($res, $d);
            $update |= self::generatePid($res, $d);
            $update |= self::maintainHosting($res, $d);
            $update |= self::maintainAvailableDate($res, $d);
            $update |= self::maintainExtent($res, $d);

            if ($update) {
                $d->log('  updating resource after checks');
                $res->updateMetadata();
            }
        } catch (RequestException $e) {
            $d->log('  ' . $e->getCode() . ' ' . $e->getMessage());
            if ($e->getCode() !== 410) {
                throw $e;
            }
        } catch (Deleted $e) {
            $d->log('  ' . $e->getCode() . ' ' . $e->getMessage());
        }
    }

    static private function loadOntology(Doorkeeper $d) {
        if (self::$repoResClasses === null) {
            $query                = "SELECT ?class where {?class (^?@ / ?@)+ ?@}";
            $param                = array(RC::idProp(), self::$subclassProp, 'https://vocabs.acdh.oeaw.ac.at/schema#RepoObject');
            $query                = new SimpleQuery($query, $param);
            $results              = $d->getFedora()->runQuery($query);
            self::$repoResClasses = array();
            foreach ($results as $i) {
                self::$repoResClasses[] = $i->class->getUri();
            }
        }
    }

    static private function getPath(string $uri, Doorkeeper $d): string {
        $tx  = $d->getTransactionId();
        $pos = strpos($uri, $tx);
        if ($pos === false) {
            throw new RuntimeException('transaction id not found in the URI');
        }
        return substr($uri, $pos + strlen($tx));
    }

    /**
     * Every resource must have a title property (cfg:fedoraTitleProp).
     * 
     * If there are acdh:hasFirstName and acdh:hasLastName available, concatenation
     * of them overwrites the previous title.
     * This is used to provide right titles for resources representing persons
     * when the detailed data about a person was imported after the resource
     * was created (e.g. when it was created because someone mentioned given
     * person id)
     * 
     * If there is no title nor acdh:hasFirstName and acdh:hasLastName available
     * a number of alternative properties is searched and the first one found
     * is taken as a title.
     * It this wasn't enough to find a title, an error is raised.
     * 
     * @param FedoraResource $res
     * @param Doorkeeper $d
     * @throws LogicException
     */
    static private function checkTitleProp(FedoraResource $res, Doorkeeper $d) {
        $searchProps = array(
            'http://purl.org/dc/elements/1.1/title',
            'http://purl.org/dc/terms/title',
            'http://www.w3.org/2004/02/skos/core#prefLabel',
            'http://www.w3.org/2000/01/rdf-schema#label',
            'http://xmlns.com/foaf/0.1/name',
        );
        $titleProp   = RC::titleProp();

        $metadata = $res->getMetadata();

        // special case acdh:hasFirstName and acdh:hasLastName
        $first = $metadata->getLiteral('https://vocabs.acdh.oeaw.ac.at/schema#hasFirstName');
        $last  = $metadata->getLiteral('https://vocabs.acdh.oeaw.ac.at/schema#hasLastName');
        $title = trim((string) $first . ' ' . (string) $last);

        $titles = $metadata->allLiterals($titleProp);
        if (count($titles) > 1) {
            throw new LogicException("more than one fedoraTitleProp");
        } elseif (count($titles) == 1) {
            $tmp = (string) $titles[0];
            if (trim($tmp) === '') {
                throw new LogicException("fedoraTitleProp value is empty");
            } elseif ($title === '' || $title === $tmp) {
                return false;
            }
        }

        if ($title === '') {
            // no direct hit - search for candidates
            foreach ($searchProps as $prop) {
                $matches = $metadata->allLiterals($prop);
                if (count($matches) > 0 && trim($matches[0]) !== '') {
                    $title = trim((string) $matches[0]);
                }
            }
        }

        // special case - foaf:givenName and foaf:familyName
        if ($title === '') {
            $given  = $metadata->getLiteral('http://xmlns.com/foaf/0.1/givenName');
            $family = $metadata->getLiteral('http://xmlns.com/foaf/0.1/familyName');
            $title  = trim((string) $given . ' ' . (string) $family);
        }

        if ($title === '') {
            throw new LogicException("fedoraTitleProp is missing");
        }

        // if we are here, the title has to be updated
        $d->log('    setting title to ' . $title);
        $metadata->delete($titleProp);
        $metadata->addLiteral($titleProp, $title);
        $res->setMetadata($metadata);
        return true;
    }

    /**
     * Every resource must have:
     * 
     * - Exactly one ACDH repo ID - either a random URI in 
     *   the cfg:fedoraIdNamespace or an immutable URI in 
     *   the cfg:fedoraVocabsNamespace.
     *   If it does not, a random URI is automatically generated.
     * - At least one non-ACDH repo ID or an immutable URI in
     *   the cfg:fedoraVocabsNamespace.
     *   This is because random ACDH repo IDs are random. Therefore it is
     *   very unlikely anyone knows them and uses them in the resources'
     *   metadata which causes a serious risk of generating new ACDH repo ID
     *   on every ingestion.
     * 
     * Obviosuly all ID values must be unique.
     * 
     * @param FedoraResource $res
     * @param array $txRes
     * @param Doorkeeper $d
     * @throws LogicException
     */
    static private function checkIdProp(FedoraResource $res, array $txRes,
                                        Doorkeeper $d): bool {
        $prop         = RC::idProp();
        $namespace    = RC::idNmsp();
        $ontologyPart = $d->isOntologyPart($res->getUri());
        $metadata     = $res->getMetadata();
        $update       = false;

        if (count($metadata->allLiterals($prop)) > 0) {
            throw new LogicException("fedoraIdProp is a literal");
        }

        $ids         = $metadata->allResources($prop);
        $acdhIdCount = 0;
        foreach ($ids as $id) {
            $id = $id->getUri();

            // ACDH ids
            if (strpos($id, $namespace) === 0) {
                $acdhIdCount++;

                // only one id in ACDH namespace allowed
                if ($acdhIdCount > 1) {
                    throw new LogicException("many fedoraIdProp in fedoraIdNamespace");
                }

                // ACDH id is immutable (can not be changed)
                // (we can compare it only to the state before transaction as changes within transaction are not saved anywhere)
                $uri    = $res->getUri(true);
                $query  = (new Query())->setDistinct(true)->setSelect(array('?id'));
                $query->addParameter(new HasTriple($uri, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', '?a'));
                $query->addParameter((new HasTriple($uri, RC::idProp(), '?id'))->setOptional(true));
                $result = $d->getFedora()->runQuery($query);
                if (count($result) > 0 && strpos($result[0]->id, $namespace) === 0 && $result[0]->id != $id) {
                    throw new LogicException("fedoraIdProp changed from " . $result[0]->id . ' to ' . $id);
                }
            }

            // every id must be unique
            $matches = array();
            try {
                $matches[] = $d->getFedora()->getResourceById($id)->getUri(true);
            } catch (NotFound $e) {
                
            }
            foreach ($txRes as $i) {
                foreach ($i->getIds() as $j) {
                    if ($j === $id) {
                        $matches[] = $i->getUri(true);
                    }
                }
            }
            $matches = array_unique($matches);

            if (count($matches) > 1 || count($matches) == 1 && $matches[0] !== $res->getUri(true)) {
                throw new LogicException("duplicated fedoraIdProp " . $id . ": " . implode(",", $matches));
            }
        }

        // no ACDH id (and not part of the ontology) - generate one
        if ($acdhIdCount == 0 && !$ontologyPart) {
            do {
                $id = $namespace . UUID::v4();
            } while (self::checkIfIdExists($id, $txRes, array(), $d));
            $d->log("  no ACDH id - assigned " . $id);

            $metadata->addResource($prop, $id);
            $res->setMetadata($metadata);
            $update = true;
        }

        // part of the ontology - exactly one id required
        if ($ontologyPart && count($ids) !== 1) {
            throw new LogicException('ontology resources must have exactly one fedoraIdProp triple (' . count($ids) . ')');
        }
        if (!$ontologyPart && count($ids) - $acdhIdCount == 0) {
            throw new LogicException('non-ontology resources must have at least one "non-ACDH id" identifier');
        }

        return $update;
    }

    static private function generatePid(FedoraResource $res, Doorkeeper $d): bool {
        $pidProp = RC::get('epicPidProp');

        $metadata = $res->getMetadata();
        if ($metadata->getLiteral($pidProp) !== null) {
            $metadata->delete($pidProp);

            $uri = $res->getId();
            $ps  = new HandleService(RC::get('epicUrl'), RC::get('epicPrefix'), RC::get('epicUser'), RC::get('epicPswd'));
            $pid = $ps->create($uri);
            $pid = str_replace(RC::get('epicUrl'), RC::get('epicResolver'), $pid);
            $d->log('  registered PID ' . $pid . ' pointing to ' . $uri);

            $metadata->addResource($pidProp, $pid);
            $res->setMetadata($metadata);
            return true;
        }
        return false;
    }

    static private function checkIdRef(FedoraResource $res, array $txRes,
                                       array $delUris, Doorkeeper $d) {
        $idNmsp = RC::idNmsp();
        $meta   = $res->getMetadata();

        foreach ($meta->propertyUris() as $prop) {
            foreach ($meta->allResources($prop) as $uri) {
                $uri = $uri->getUri();
                if (strpos($uri, $idNmsp) === 0 && !self::checkIfIdExists($uri, $txRes, $delUris, $d)) {
                    throw new LogicException('metadata refer to a non-existing fedoraId');
                }
            }
        }
    }

    static private function checkRelProp(FedoraResource $res, array $txRes,
                                         array $delUris, Doorkeeper $d) {
        $prop     = RC::relProp();
        $idNmsp   = RC::idNmsp();
        $metadata = $res->getMetadata();
        $resId    = $d->isOntologyPart($res->getUri()) ? null : $res->getId();

        if (count($metadata->allLiterals($prop)) > 0) {
            throw new LogicException("fedoraRelProp is a literal");
        }

        $rels = $metadata->allResources($prop);
        foreach ($rels as $i) {
            $id = trim($i->getUri());

            if (!(strpos($id, $idNmsp) === 0)) {
                throw new LogicException("fedoraRelProp in a wrong namespace " . $id . ' ' . $idNmsp);
            }

            if ($id === $resId) {
                throw new LogicException("fedoraRelProp is pointing to itself");
            }

            if (!self::checkIfIdExists($id, $txRes, $delUris, $d)) {
                throw new LogicException("fedoraRelProp does not exist in the repository: " . $id);
            }
        }
    }

    static private function checkIfIdExists(string $uri, array $resources,
                                            array $delUris, Doorkeeper $d) {
        $validNamespace = RC::idNmsp();
        if (strpos($uri, $validNamespace) !== 0) {
            return true; // resource outside our repository, we believe it exists
        }

        try {
            $res = $d->getFedora()->getResourceById($uri);
            if (!in_array($res->getUri(true), $delUris)) {
                return true;
            }
        } catch (NotFound $e) {
            
        }

        foreach ($resources as $i) {
            try {
                if ($i->getId() === $uri) {
                    return true;
                }
            } catch (NoAcdhId $e) {
                if (!$d->isOntologyPart($i->getUri())) {
                    throw $e;
                }
            }
        }

        return false;
    }

    static private function checkOrphanedRelProp($delUri, array $delUris,
                                                 Doorkeeper $d) {
        $delId   = QueryParameter::escapeUri($d->getDeletedResourceId($delUri));
        $query   = sprintf('SELECT DISTINCT ?res WHERE {?res ?prop %s}', $delId);
        $orphans = $d->getFedora()->runSparql($query);
        foreach ($orphans as $i) {
            if (!in_array($i->res, $delUris)) {
                throw new LogicException('orphaned reference to fedoraIdProp in ' . $i->res);
            }
        }
    }

    static private function maintainExtent(FedoraResource $res, Doorkeeper $d): bool {
        $meta = $res->getMetadata();
        $size = $meta->getLiteral(self::$fedoraExtentProp);
        if ($size !== null) {
            $acdhSize = $meta->getLiteral(RC::get('fedoraExtentProp'));
            if ($acdhSize === null || $acdhSize->getValue() !== $size->getValue()) {
                $meta->addLiteral(RC::get('fedoraExtentProp'), $size);
                $res->setMetadata($meta);
                return true;
            }
        }
        return false;
    }

    static private function maintainHosting(FedoraResource $res, Doorkeeper $d): bool {
        self::loadOntology($d);
        $meta = $res->getMetadata();

        if (!self::resIsA($meta, self::$repoResClasses)) {
            return false;
        }

        $prop    = RC::get('fedoraHostingProp');
        $hosting = $meta->getResource($prop);
        if ($hosting === null) {
            $meta->addResource($prop, RC::get('fedoraHostingPropDefault'));
            $res->setMetadata($meta);
            $d->log('  ' . $prop . ' added');
            return true;
        }
        return false;
    }

    static private function maintainAvailableDate(FedoraResource $res,
                                                  Doorkeeper $d): bool {
        self::loadOntology($d);
        $meta = $res->getMetadata();

        if (!self::resIsA($meta, self::$repoResClasses)) {
            return false;
        }

        $prop    = RC::get('fedoraAvailableDateProp');
        $hosting = $meta->getLiteral($prop);
        if ($hosting === null) {
            $meta->addLiteral($prop, new DateTime());
            $res->setMetadata($meta);
            $d->log('  ' . $prop . ' added');
            return true;
        }
        return false;
    }

    static private function updateCollectionExtent(array $resources,
                                                   Doorkeeper $d) {
        if (count($resources) == 0) {
            return;
        }

        $fedora    = $d->getFedora();
        $extProp   = RC::get('fedoraExtentProp');
        $countProp = RC::get('fedoraCountProp');

        $resQueryParam = array('', RC::relProp(), RC::idProp());
        $resQueryTmpl  = new SimpleQuery('{?@ (?@ / ^?@)+ ?col}');
        $resQuery      = array();
        foreach ($resources as $res) {
            $resQueryParam[0] = $res->getUri(true);
            $resQueryTmpl->setValues($resQueryParam);
            $resQuery[]       = $resQueryTmpl->getQuery();
        }
        $resQuery = implode("\nUNION ", $resQuery);

        $query   = "
            SELECT ?col (sum(?colResSize) as ?size) (count(distinct ?colRes) as ?count) 
            WHERE {
                {
                    SELECT DISTINCT ?col 
                    WHERE {
                        " . $resQuery . "
                    }
                }
                ?col (?@ / ^?@)* ?colRes .
                ?colRes ?@ ?colResSize .
            }
            GROUP BY ?col
        ";
        $param   = array(RC::idProp(), RC::relProp(), self::$fedoraExtentProp);
        $query   = new SimpleQuery($query, $param);
        $results = $fedora->runQuery($query);

        foreach ($results as $i) {
            $res  = $fedora->getResourceByUri((string) $i->col);
            $meta = $res->getMetadata();
            $meta->delete($extProp);
            $meta->delete($countProp);
            $meta->addLiteral($extProp, $i->size->getValue());
            $meta->addLiteral($countProp, $i->count->getValue());
            $res->setMetadata($meta);
            $res->updateMetadata();
            $d->log("  Extent data updated for " . (string) $i->col);
        }
    }

    /**
     * Checks if a given EasyRdf resource (representing a repository resource)
     * belongs to a given class set.
     * @param \EasyRdf\Resource $res
     * @param array $classes
     */
    static private function resIsA(Resource $res, array $classes): bool {
        foreach ($res->allResources(self::$classProp) as $type) {
            if (in_array($type->getUri(), $classes)) {
                return true;
            }
        }
        return false;
    }

}
