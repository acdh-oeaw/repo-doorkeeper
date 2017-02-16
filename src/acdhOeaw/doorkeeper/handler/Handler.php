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
use zozlak\util\Config;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasTriple;
use acdhOeaw\util\EasyRdfUtil;
use acdhOeaw\epicHandle\HandleService;
use RuntimeException;
use LogicException;

/**
 * Implements the ACDH business logic
 *
 * @author zozlak
 */
class Handler {

    static private $logfile;

    static public function init(Config $cfg) {
        self::$logfile = fopen($cfg->get('doorkeeperLogFile'), 'a');
    }

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
        self::log("transaction commit handler for: " . $d->getTransactionId());

        foreach ($modResources as $i) {
            self::log('  ' . $i->getUri());
            self::checkIdProp($i, $modResources, $d);
            self::checkTitleProp($i, $d);
            self::checkRelProp($i, $modResources, $d);
            self::checkIdRef($i, $modResources, $d);
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
        self::log('post create handler for: ' . $d->getTransactionId());
        self::log("  " . $res->getUri());
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
        self::log('post edit handler for: ' . $d->getMethod() . ' ' . $d->getTransactionId());
        self::log("  " . $res->getUri());

        self::checkIdProp($res, array(), $d);
        self::checkTitleProp($res, $d);
        self::generatePid($res, $d);
    }

    /**
     * Writes a message to the doorkeeper log.
     * 
     * @param type $msg message to write
     */
    static private function log($msg) {
        fwrite(self::$logfile, $msg . "\n");
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

        $titles = $metadata->allLiterals(EasyRdfUtil::fixPropName($titleProp));

        // property is missing
        if (count($titles) == 0) {
            self::log("    no title property");
            throw new \LogicException("fedoraTitleProp is missing");
        }

        // more the one property
        if (count($titles) > 1) {
            self::log("    more than one title");
            throw new \LogicException("more than one fedoraTitleProp");
        } else if (trim($titles[0]) == '') {
            self::log("    empty title property value");
            throw new \LogicException("fedoraTitleProp value is empty");
        }
    }

    static private function checkIdProp(FedoraResource $res, array $txRes, Doorkeeper $d) {
        $prop = EasyRdfUtil::fixPropName($d->getConfig('fedoraIdProp'));
        $namespace = $d->getConfig('fedoraIdNamespace');
        $metadata = $res->getMetadata();

        if (count($metadata->allLiterals($prop)) > 0) {
            self::log("  fedoraIdProp being a literal");
            throw new \LogicException("fedoraIdProp is a literal");
        }

        $ids = $metadata->allResources($prop);
        if (count($ids) > 1) {
            self::log("  many fedoraIdProp");
            throw new \LogicException("many fedoraIdProp");
        }

        if (count($ids) == 0) {
            // no id - generate one
            do {
                $id = $namespace . UUID::v4();
            } while (self::checkIfIdExists($id, $txRes, $d));

            $metadata->addResource($prop, $id);
            $res->setMetadata($metadata);
            $res->updateMetadata();
        } else {
            // there is an id - check it
            $id = $res->getId();

            $ontologyLoc = $d->getConfig('doorkeeperOntologyLocation');
            $resLoc = self::getPath($res->getUri(), $d);
            if (!(strpos($id, $d->getConfig('fedoraIdNamespace')) === 0) && !(strpos($resLoc, $ontologyLoc) === 0)) {
                self::log("  fedoraIdProp in a wrong namespace");
                throw new \LogicException("fedoraIdProp in a wrong namespace");
            }

            $matches = array();
            foreach ($d->getFedora()->getResourcesById($id) as $i) {
                $matches[] = $i->getUri();
            }
            foreach ($txRes as $i) {
                if ($i->getId() === $id) {
                    $matches[] = $i->getUri();
                }
            }
            $matches = array_unique($matches);

            if (count($matches) > 1 || count($matches) == 1 && $matches[0] !== $res->getUri()) {
                self::log("  duplicated fedoraIdProp: " . implode(', ', $matches));
                throw new \LogicException("duplicated fedoraIdProp");
            }

            // check if URI did not change for existing resource
            // (we can compare it only to the state before transaction as changes within transaction are not saved anywhere)
            $uri = $d->getFedora()->standardizeUri($res->getUri());
            $query = (new Query())->setDistinct(true)->setSelect(array('?id'));
            $query->addParameter(new HasTriple($uri, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', '?a'));
            $query->addParameter((new HasTriple($uri, $d->getConfig('fedoraIdProp'), '?id'))->setOptional(true));
            $result = $d->getFedora()->runQuery($query);
            if (count($result) > 0 && $result[0]->id != $id) {
                self::log("  fedoraIdProp changed from " . $result[0]->id . ' to ' . $id);
                throw new \LogicException("fedoraIdProp changed from " . $result[0]->id . ' to ' . $id);
            }
        }
    }

    static private function generatePid(FedoraResource $res, Doorkeeper $d) {
        $pidProp = EasyRdfUtil::fixPropName($d->getConfig('epicPidProp'));

        $metadata = $res->getMetadata();
        if ($metadata->getLiteral($pidProp) !== null) {
            $metadata->delete($pidProp);

            $uri = $metadata->getResource(EasyRdfUtil::fixPropName($d->getConfig('fedoraIdProp')))->getUri();
            $ps = new HandleService($d->getConfig('epicUrl'), $d->getConfig('epicPrefix'), $d->getConfig('epicUser'), $d->getConfig('epicPswd'));
            $pid = $ps->create($uri);

            $metadata->add($pidProp, $pid);
            $res->setMetadata($metadata);
            $res->updateMetadata();
        }
    }

    static private function checkIdRef(FedoraResource $res, array $txRes, Doorkeeper $d) {
        $idNmsp = $d->getConfig('fedoraIdNamespace');
        $meta = $res->getMetadata();

        foreach ($meta->propertyUris() as $prop) {
            $prop = EasyRdfUtil::fixPropName($prop);
            foreach ($meta->allResources($prop) as $uri) {
                $uri = $uri->getUri();
                if (strpos($uri, $idNmsp) === 0 && !self::checkIfIdExists($uri, $txRes, $d)) {
                    self::log("  metadata refer to a non-existing fedoraId");
                    throw new LogicException('metadata refer to a non-existing fedoraId');
                }
            }
        }
    }

    static private function checkRelProp(FedoraResource $res, array $txRes, Doorkeeper $d) {
        $prop = EasyRdfUtil::fixPropName($d->getConfig('fedoraRelProp'));
        $idNmsp = $d->getConfig('fedoraIdNamespace');
        $metadata = $res->getMetadata();
        $resId = $res->getId();

        if (count($metadata->allLiterals($prop)) > 0) {
            self::log("  fedoraRelProp is a literal");
            throw new LogicException("fedoraRelProp is a literal");
        }

        $rels = $metadata->allResources($prop);
        foreach ($rels as $i) {
            $id = trim($i->getUri());

            if (!(strpos($id, $idNmsp) === 0)) {
                self::log("  fedoraRelProp in a wrong namespace");
                throw new \LogicException("fedoraRelProp in a wrong namespace");
            }

            if ($id === $resId) {
                self::log("  fedoraRelProp is pointing to itself");
                throw new \LogicException("fedoraRelProp is pointing to itself");
            }

            if (!self::checkIfIdExists($id, $txRes, $d)) {
                self::log("  fedoraRelProp does not exist in the repository: " . $id);
                throw new \LogicException("fedoraRelProp does not exist in the repository: " . $id);
            }
        }
    }

    static private function checkIfIdExists(string $uri, array $resources, Doorkeeper $d) {
        $validNamespace = $d->getConfig('fedoraIdNamespace');
        if (strpos($uri, $validNamespace) !== 0) {
            return true; // resource outside our repository, we believe it exists
        }

        $res = $d->getFedora()->getResourcesById($uri);
        if (count($res) > 0) {
            return true;
        }

        foreach ($resources as $i) {
            if ($i->getId() === $uri) {
                return true;
            }
        }

        return false;
    }

}
