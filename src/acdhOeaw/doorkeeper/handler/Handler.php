<?php

/**
 * The MIT License
 *
 * Copyright 2017 Austrian Centre for Digital Humanities at the Austrian Academy of Sciences
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
use LogicException;
use RuntimeException;
use EasyRdf\Literal;
use EasyRdf\Literal\Boolean as lBoolean;
use EasyRdf\Literal\Date as lDate;
use EasyRdf\Literal\DateTime as lDateTime;
use EasyRdf\Literal\Decimal as lDecimal;
use EasyRdf\Literal\Integer as lInteger;
use EasyRdf\Resource;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\acl\WebAclRule as WAR;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\NoAcdhId;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasTriple;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\epicHandle\HandleService;
use acdhOeaw\util\RepoConfig as RC;
use zozlak\util\UUID;

/**
 * Implements the ACDH business logic
 *
 * @author zozlak
 */
class Handler {

    const FEDORA_CONTAINER            = 'http://fedora.info/definitions/v4/repository#Container';
    const FEDORA_EXTENT_PROP          = 'http://www.loc.gov/premis/rdf/v1#hasSize';
    const FEDORA_BINARY_CLASS         = 'http://fedora.info/definitions/v4/repository#Binary';
    const FEDORA_MIME_TYPE_PROP       = 'http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#hasMimeType';
    const OWL_DATATYPE_PROPERTY_CLASS = 'http://www.w3.org/2002/07/owl#DatatypeProperty';
    const RDF_CLASS_PROP              = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    const RDFS_SUBCLASS_PROP          = 'http://www.w3.org/2000/01/rdf-schema#subClassOf';
    const RDFS_RANGE_PROP             = 'http://www.w3.org/2000/01/rdf-schema#range';
    const XSD_NMSP                    = 'http://www.w3.org/2001/XMLSchema#';
    const XSD_STRING                  = 'http://www.w3.org/2001/XMLSchema#string';
    const XSD_DATE                    = 'http://www.w3.org/2001/XMLSchema#date';
    const XSD_DATETIME                = 'http://www.w3.org/2001/XMLSchema#dateTime';
    const XSD_BOOLEAN                 = 'http://www.w3.org/2001/XMLSchema#boolean';
    const XSD_INTEGER                 = 'http://www.w3.org/2001/XMLSchema#integer';
    const XSD_DECIMAL                 = 'http://www.w3.org/2001/XMLSchema#decimal';
    const XSD_FLOAT                   = 'http://www.w3.org/2001/XMLSchema#float';
    const XSD_DOUBLE                  = 'http://www.w3.org/2001/XMLSchema#double';

    static private $repoResClasses;
    static private $propertyRanges;

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

        $modUris = array();
        foreach ($modResources as $n => $i) {
            $d->log('  ' . $i->getUri() . " (" . ($n + 1) . "/" . count($modResources) . ")");
            $modUris[] = $i->getUri(true);

            self::checkIdProp($i, $modResources, $d);
            self::checkTitleProp($i, $d);
            self::checkRelProp($i, $modResources, $delUris, $d);
            self::checkIdRef($i, $modResources, $delUris, $d);
        }

        $d->log(" pre transaction commit handler - checking orphaned relations");
        foreach ($delUris as $n => $i) {
            $d->log('  ' . $i . " (" . ($n + 1) . "/" . count($delUris) . ")");
            self::checkOrphanedRelProp($i, $delUris, $modUris, $d);
        }
    }

    static public function postTransaction(array $modResources, array $delUris,
                                           array $parents, Doorkeeper $d) {
        $d->log(" post transaction commit handler for " . $d->getTransactionId() . "...");

        self::updateCollectionExtent($parents, $d);
        self::maintainAccessRights($modResources, $d);

        $d->log("  ...done");
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
            $update |= self::maintainPid($res, $d);
            $update |= self::maintainHosting($res, $d);
            $update |= self::maintainAvailableDate($res, $d);
            $update |= self::maintainExtent($res, $d);
            $update |= self::maintainFormat($res, $d);
            $update |= self::maintainAccessRestriction($res, $d);
            $update |= self::maintainPropertyRange($res, $d);

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
            $param                = [RC::idProp(), self::RDFS_SUBCLASS_PROP, RC::get('fedoraRepoObjectClass')];
            $query                = new SimpleQuery($query, $param);
            $results              = $d->getFedora()->runQuery($query);
            self::$repoResClasses = [RC::get('fedoraRepoObjectClass')];
            foreach ($results as $i) {
                self::$repoResClasses[] = $i->class->getUri();
            }
        }
        if (self::$propertyRanges === null) {
            $query   = "
                SELECT ?id ?type WHERE {
                    ?prop ?@ ?type . 
                    ?prop ?@ ?id .
                    ?prop a ?@ .
                }
            ";
            $param   = [self::RDFS_RANGE_PROP, RC::idProp(), self::OWL_DATATYPE_PROPERTY_CLASS];
            $query   = new SimpleQuery($query, $param);
            $results = $d->getFedora()->runQuery($query);
            foreach ($results as $i) {
                $uri = $i->type->getUri();
                if (strpos($uri, self::XSD_NMSP) === 0) {
                    self::$propertyRanges[$i->id->getUri()] = $uri;
                }
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
        $pidProp      = RC::get('epicPidProp');
        $namespace    = RC::idNmsp();
        $ontologyPart = $d->isOntologyPart($res->getUri());
        $metadata     = $res->getMetadata();
        $update       = false;

        if (count($metadata->allLiterals($prop)) > 0) {
            throw new LogicException("fedoraIdProp is a literal");
        }

        $ids         = array_merge($metadata->allResources($prop), $metadata->allResources($pidProp));
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

    static private function maintainPid(FedoraResource $res, Doorkeeper $d): bool {
        $pidProp = RC::get('epicPidProp');

        $metadata = $res->getMetadata();
        if ($metadata->getLiteral($pidProp) !== null) {
            if (RC::get('epicPswd') === '') {
                $d->log('  skipping PID generation - no EPIC password provided');
                return false;
            }

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
                    throw new LogicException('metadata refer to a non-existing fedoraId ' . $uri);
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
                                                 array $modUris, Doorkeeper $d) {
        $query   = new SimpleQuery('SELECT DISTINCT ?res WHERE {?res ?prop ?@}');
        $query->setValues(array($d->getDeletedResourceId($delUri)));
        $orphans = $d->getFedora()->runQuery($query);
        foreach ($orphans as $i) {
            if (!in_array($i->res, $delUris) && !in_array($i->res, $modUris)) {
                throw new LogicException('orphaned reference to fedoraIdProp in ' . $i->res);
            }
        }
    }

    static private function maintainPropertyRange(FedoraResource $res,
                                                  Doorkeeper $d): bool {
        self::loadOntology($d);
        
        $changes = false;
        $meta    = $res->getMetadata();
        foreach ($meta->propertyUris() as $prop) {
            $range = self::$propertyRanges[$prop] ?? null;
            if ($range === null) {
                continue;
            }
            foreach ($meta->allLiterals($prop) as $l) {
                /* @var $l \EasyRdf\Literal */
                $type = $l->getDatatypeUri() ?? self::XSD_STRING;
                if ($type === $range) {
                    continue;
                }
$d->log("# $prop\n\t$type\n\t$range");
                if ($range === self::XSD_STRING) {
                    $meta->delete($prop, $l);
                    $meta->addLiteral($prop, (string) $l);
                    $changes = true;
                    $d->log('    casting ' . $prop . ' value from ' . $type . ' to string');
                } elseif ($type === self::XSD_STRING) {
                    try {
                        $value   = self::castLiteral($l, $range);
                        $meta->delete($prop, $l);
                        $meta->addLiteral($prop, $value);
                        $changes = true;
                        $d->log('    casting ' . $prop . ' value from ' . $type . ' to ' . $range);
                    } catch (RuntimeException $ex) {
                        $d->log('    ' . $ex->getMessage());
                    }
                } else {
                        $d->log('    unknown type: ' . $type);
                }
            }
        }
        if ($changes) {
            $res->setMetadata($meta);
        }
        return $changes;
    }

    static private function maintainExtent(FedoraResource $res, Doorkeeper $d): bool {
        $meta = $res->getMetadata();
        $size = $meta->getLiteral(self::FEDORA_EXTENT_PROP);
        if ($size !== null && $res->isA(self::FEDORA_BINARY_CLASS)) {
            $prop     = RC::get('fedoraExtentProp');
            $acdhSize = $meta->getLiteral($prop);
            if ($acdhSize === null || $acdhSize->getValue() !== $size->getValue()) {
                $meta->delete($prop);
                $meta->addLiteral($prop, $size);
                $res->setMetadata($meta);
                $d->log('  ' . $prop . ' added/updated');
                return true;
            }
        }
        return false;
    }

    static private function maintainFormat(FedoraResource $res, Doorkeeper $d): bool {
        $meta   = $res->getMetadata();
        $format = $meta->getLiteral(self::FEDORA_MIME_TYPE_PROP);
        if ($format !== null && $res->isA(self::FEDORA_BINARY_CLASS)) {
            $prop       = RC::get('fedoraFormatProp');
            $acdhFormat = $meta->getLiteral($prop);
            if ($acdhFormat === null || $acdhFormat->getValue() !== $format->getValue()) {
                $meta->delete($prop);
                $meta->addLiteral($prop, $format);
                $res->setMetadata($meta);
                $d->log('  ' . $prop . ' added/updated');
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
            $uuid = $res->getFedora()->getResourceById(RC::get('fedoraHostingPropDefault'))->getId();
            $meta->addResource($prop, $uuid);
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

    static private function maintainAccessRestriction(FedoraResource $res,
                                                      Doorkeeper $d): bool {
        self::loadOntology($d);
        $meta = $res->getMetadata();

        if (!self::resIsA($meta, self::$repoResClasses)) {
            return false;
        }

        $prop      = RC::get('fedoraAccessRestrictionProp');
        $resources = $meta->allResources($prop);
        $literals  = $meta->allLiterals($prop);
        $allowed   = ['public', 'academic', 'restricted'];
        $condCount = count($literals) == 0 || count($resources) > 0 || count($literals) > 1;
        $condValue = count($literals) > 0 && !in_array($literals[0]->getValue(), $allowed);
        if ($condCount || $condValue) {
            $default = RC::get('doorkeeperAccessRestrictionDefault');
            $meta->delete($prop);
            $meta->addLiteral($prop, $default);
            $res->setMetadata($meta);
            $d->log('  ' . $prop . ' = ' . $default . ' added');
            return true;
        }
        return false;
    }

    static private function updateCollectionExtent(array $parents, Doorkeeper $d) {
        $fedora    = $d->getFedora();
        $extProp   = RC::get('fedoraExtentProp');
        $countProp = RC::get('fedoraCountProp');

        $collections = array();
        $query       = new SimpleQuery('SELECT * WHERE {?@ ^?@ / (?@ / ^?@)* ?col}');
        $queryParam  = array('', RC::idProp(), RC::relProp(), RC::idProp());
        foreach ($parents as $n => $i) {
            $d->log("  Collecting parent resources list (" . ($n + 1) . "/" . count($parents) . ")");
            $queryParam[0] = $i;
            $query->setValues($queryParam);
            $results       = $fedora->runQuery($query);
            foreach ($results as $j) {
                $collections[] = $j->col->getUri();
            }
        }
        $collections = array_values(array_unique($collections));

        $query = new SimpleQuery("
            SELECT (sum(?colResSize) as ?size) (count(?res) as ?count) 
            WHERE {
                {SELECT DISTINCT (?colRes AS ?res) WHERE { ?@ (?@ / ^?@)* ?colRes . }}
                ?res ?@ ?colResSize .
            }
        ");
        $param = array('', RC::idProp(), RC::relProp(), self::FEDORA_EXTENT_PROP);
        foreach ($collections as $n => $i) {
            $d->log("  Updating extent for $i ... (" . ($n + 1) . "/" . count($collections) . ")");
            $param[0] = $i;
            $query->setValues($param);
            $results  = $fedora->runQuery($query);
            if (count($results) > 0) {
                $size  = $results[0]->size->getValue();
                $count = $results[0]->count->getValue();
            } else {
                $size  = $count = 0;
            }
            $res  = $fedora->getResourceByUri($i);
            $meta = $res->getMetadata(true);
            $meta->delete($extProp);
            $meta->delete($countProp);
            $meta->addLiteral($extProp, $size);
            $meta->addLiteral($countProp, $count);
            $res->setMetadata($meta);
            $res->updateMetadata();
            $d->log("    ...size $size count $count");
        }
    }

    /**
     * Checks if a given EasyRdf resource (representing a repository resource)
     * belongs to a given class set.
     * @param \EasyRdf\Resource $res
     * @param array $classes
     */
    static private function resIsA(Resource $res, array $classes): bool {
        foreach ($res->allResources(self::RDF_CLASS_PROP) as $type) {
            if (in_array($type->getUri(), $classes)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Access rights should be maintained according to the 
     * cfg:fedoraAccessRestrictionProp:
     * 
     * - in all cases write access should be revoked from the public
     * - when `public` read access to the public should be granted
     * - when `academic` read access to the cfg:academicGroup should be granted
     *   and public read access should be revoked
     * - when `restricted` read rights should be revoked from both public and
     *   cfg:academicGroup
     * @param array $resources array of resources modified in the transaction
     * @param Doorkeeper $d a doorkeeper instance
     */
    static public function maintainAccessRights(array $resources, Doorkeeper $d) {
        self::loadOntology($d);

        foreach ($resources as $n => $res) {
            /* @var $res \acdhOeaw\fedora\FedoraResource */
            $meta        = $res->getMetadata();
            $accessRestr = (string) $meta->getLiteral(RC::get('fedoraAccessRestrictionProp'));
            if ($accessRestr === '') {
                continue;
            }

            $d->log('  maintaining access rights for ' . $res->getUri(true) . ' (' . ($n + 1) . '/' . count($resources) . ')');
            $acl       = $res->getAcl();
            $acl->createAcl();
            $prevState = (string) $acl;
            $acl->setAutosave(false);
            $acl->revoke(WAR::USER, WAR::PUBLIC_USER, WAR::WRITE);

            if ($accessRestr === 'public') {
                $acl->grant(WAR::USER, WAR::PUBLIC_USER, WAR::READ);
                $d->log('    public');
            } else {
                $acl->revoke(WAR::USER, WAR::PUBLIC_USER, WAR::READ);
                if ($accessRestr === 'academic') {
                    $acl->grant(WAR::USER, RC::get('academicGroup'), WAR::READ);
                    $d->log('    academic');
                } else {
                    $acl->revoke(WAR::USER, RC::get('academicGroup'), WAR::READ);
                    $d->log('    restricted');
                }
            }

            if ((string) $acl !== $prevState) {
                $acl->save();
            } else {
                $d->log('    no access rights changes');
            }
        }
    }

    static private function castLiteral(Literal $l, string $range): Literal {
        switch ($range) {
            case self::XSD_DATE:
                $value = new lDate((string) $l, null, $range);
                break;
            case self::XSD_DATETIME:
                $value = new lDateTime((string) $l, null, $range);
                break;
            case self::XSD_DECIMAL:
            case self::XSD_FLOAT:
            case self::XSD_DOUBLE:
                $value = new lDecimal((string) $l, null, $range);
                break;
            case self::XSD_INTEGER:
                $value = new lInteger((string) $l, null, $range);
                break;
            case self::XSD_BOOLEAN:
                $value = new lBoolean((string) $l, null, $range);
                break;
            default:
                throw new RuntimeException('Unknown range data type: ' . $range);
        }
        return $value;
    }

}
