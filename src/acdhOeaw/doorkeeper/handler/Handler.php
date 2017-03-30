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

use zozlak\util\UUID;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasTriple;
use acdhOeaw\epicHandle\HandleService;
use acdhOeaw\util\EasyRdfUtil;
use RuntimeException;
use LogicException;
use GuzzleHttp\Exception\RequestException;

/**
 * Implements the ACDH business logic
 *
 * @author zozlak
 */
class Handler {

    /**
     * Checks resources at the end of transaction
     * 
     * Any errors found should be reported by throwing a \LogicException.
     * @param array $modResources array of FedoraResource objects being created
     *   or modified in this transaction
     * @param \acdhOeaw\doorkeeper\Doorkeeper $d the doorkeeper instance
     * @throws \LogicException
     */
    static public function checkTransaction(array $modResources, Doorkeeper $d) {
        $d->log("transaction commit handler for: " . $d->getTransactionId());

        $delUris = array();
        $resources = array();
        foreach ($modResources as $i) {
            try {
                $i->getMetadata();
                $resources[] = $i;
            } catch (RequestException $e) {
                if ($e->getCode() === 410) {
                    $delUris[] = $i->getUri(true);
                } else {
                    throw $e;
                }
            }
        }

        foreach ($resources as $i) {
            $d->log('  ' . $i->getUri());
            self::checkIdProp($i, $resources, $delUris, $d);
            self::checkTitleProp($i, $d);
            self::checkRelProp($i, $resources, $delUris, $d);
            self::checkIdRef($i, $resources, $delUris, $d);
        }

        foreach ($delUris as $i) {
            $d->log('  ' . $i);
            self::checkOrphanedRelProp($i, $delUris, $d);
        }
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
        $d->log('post create handler for: ' . $res->getUri());
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
        $d->log('post edit handler for: ' . $d->getMethod() . ' ' . $res->getUri());

        try {
            $res->getMetadata();

            // if edit action was not DELETE
            self::checkIdProp($res, array(), array(), $d);
            self::checkTitleProp($res, $d);
            self::generatePid($res, $d);
        } catch (RequestException $e) {
            if ($e->getCode() !== 410) {
                throw $e;
            }
        }
    }

    static private function getPath(string $uri, Doorkeeper $d): string {
        $tx = $d->getTransactionId();
        $pos = strpos($uri, $tx);
        if ($pos === false) {
            throw new RuntimeException('transaction id not found in the URI');
        }
        return substr($uri, $pos + strlen($tx));
    }

    static private function checkTitleProp(FedoraResource $res, Doorkeeper $d) {
        $metadata = $res->getMetadata();
        $ontologyLoc = $d->getConfig('doorkeeperOntologyLocation');
        $resLoc = self::getPath($res->getUri(), $d);
        if (strpos($resLoc, $ontologyLoc) === 0) {
            $titleProp = $d->getConfig('doorkeeperOntologyLabelProp');
        } else {
            $titleProp = $d->getConfig('fedoraTitleProp');
        }

        $titles = $metadata->allLiterals($titleProp);

        // property is missing
        if (count($titles) == 0) {
            throw new LogicException("fedoraTitleProp is missing");
        }

        // more the one property
        if (count($titles) > 1) {
            throw new LogicException("more than one fedoraTitleProp");
        } else if (trim($titles[0]) == '') {
            throw new LogicException("fedoraTitleProp value is empty");
        }
    }

    static private function checkIdProp(FedoraResource $res, array $txRes, array $delUris, Doorkeeper $d) {
        $prop = $d->getConfig('fedoraIdProp');
        $namespace = $d->getConfig('fedoraIdNamespace');
        $ontologyPart = $d->isOntologyPart($res->getUri());
        $metadata = $res->getMetadata();

        if (count($metadata->allLiterals($prop)) > 0) {
            throw new LogicException("fedoraIdProp is a literal");
        }

        $ids = $metadata->allResources($prop);
        $acdhIdCount = 0;
        foreach ($ids as $id) {
            $id = $id->getUri();

            // ACDH ids
            if (strpos($id, $d->getConfig('fedoraIdNamespace')) === 0) {
                $acdhIdCount++;

                // only one id in ACDH namespace allowed
                if ($acdhIdCount > 1) {
                    throw new LogicException("many fedoraIdProp in fedoraIdNamespace");
                }

                // ACDH id is immutable (can not be changed)
                // (we can compare it only to the state before transaction as changes within transaction are not saved anywhere)
                $uri = $res->getUri(true);
                $query = (new Query())->setDistinct(true)->setSelect(array('?id'));
                $query->addParameter(new HasTriple($uri, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', '?a'));
                $query->addParameter((new HasTriple($uri, $d->getConfig('fedoraIdProp'), '?id'))->setOptional(true));
                $result = $d->getFedora()->runQuery($query);
                if (count($result) > 0 && $result[0]->id != $id) {
                    throw new LogicException("fedoraIdProp changed from " . $result[0]->id . ' to ' . $id);
                }
            }

            // every id must be unique
            $matches = array();
            foreach ($d->getFedora()->getResourcesById($id) as $i) {
                $matches[] = $i->getUri();
            }
            foreach ($txRes as $i) {
                foreach ($i->getIds() as $j) {
                    if ($j === $id) {
                        $matches[] = $i->getUri();
                    }
                }
            }
            $matches = array_unique($matches);

            if (count($matches) > 1 || count($matches) == 1 && $matches[0] !== $res->getUri()) {
                throw new LogicException("duplicated fedoraIdProp");
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
            $res->updateMetadata();
        }

        // part of the ontology - exactly one id required
        if ($ontologyPart && count($ids) !== 1) {
            throw new LogicException('ontology resources must have exactly one fedoraIdProp triple');
        }
    }

    static private function generatePid(FedoraResource $res, Doorkeeper $d) {
        $pidProp = $d->getConfig('epicPidProp');

        $metadata = $res->getMetadata();
        if ($metadata->getLiteral($pidProp) !== null) {
            $metadata->delete($pidProp);

            $uri = $metadata->getResource($d->getConfig('fedoraIdProp'))->getUri();
            $ps = new HandleService($d->getConfig('epicUrl'), $d->getConfig('epicPrefix'), $d->getConfig('epicUser'), $d->getConfig('epicPswd'));
            $pid = $ps->create($uri);

            $metadata->add($pidProp, $pid);
            $res->setMetadata($metadata);
            $res->updateMetadata();
        }
    }

    static private function checkIdRef(FedoraResource $res, array $txRes, array $delUris, Doorkeeper $d) {
        $idNmsp = $d->getConfig('fedoraIdNamespace');
        $meta = $res->getMetadata();

        foreach ($meta->propertyUris() as $prop) {
            foreach ($meta->allResources($prop) as $uri) {
                $uri = $uri->getUri();
                if (strpos($uri, $idNmsp) === 0 && !self::checkIfIdExists($uri, $txRes, $delUris, $d)) {
                    throw new LogicException('metadata refer to a non-existing fedoraId');
                }
            }
        }
    }

    static private function checkRelProp(FedoraResource $res, array $txRes, array $delUris, Doorkeeper $d) {
        $prop = $d->getConfig('fedoraRelProp');
        $idNmsp = $d->getConfig('fedoraIdNamespace');
        $metadata = $res->getMetadata();
        $resId = $d->isOntologyPart($res->getUri()) ? null : $res->getId();

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

    static private function checkIfIdExists(string $uri, array $resources, array $delUris, Doorkeeper $d) {
        $validNamespace = $d->getConfig('fedoraIdNamespace');
        if (strpos($uri, $validNamespace) !== 0) {
            return true; // resource outside our repository, we believe it exists
        }

        $res = $d->getFedora()->getResourcesById($uri);
        foreach ($res as $i) {
            if (!in_array($i->getUri(true), $delUris)) {
                return true;
            }
        }

        foreach ($resources as $i) {
            try {
                if ($i->getId() === $uri) {
                    return true;
                }
            } catch (RuntimeException $e) {
                if (!$d->isOntologyPart($i->getUri())) {
                    throw $e;
                }
            }
        }

        return false;
    }

    static private function checkOrphanedRelProp($delUri, array $delUris, Doorkeeper $d) {
        $delId = EasyRdfUtil::escapeUri($d->getDeletedResourceId($delUri));
        $query = sprintf('SELECT DISTINCT ?res WHERE {?res ?prop %s}', $delId);
        $orphans = $d->getFedora()->runSparql($query);
        foreach ($orphans as $i) {
            if (!in_array($i->res, $delUris)) {
                throw new LogicException('orphaned reference to fedoraIdProp');
            }
        }
    }

}
