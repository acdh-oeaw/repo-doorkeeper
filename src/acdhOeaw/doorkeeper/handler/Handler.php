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
use acdhOeaw\util\EasyRdfUtil;
use acdhOeaw\epicHandle\HandleService;

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

    static public function checkTransaction(array $modResources, Doorkeeper $d) {
        self::log("transaction commit handler for: " . $d->getTransactionId());
        foreach ($modResources as $i) {
            self::log('  ' . $i->getUri());
        }
    }

    /**
     * preEdit i preCreate handlery byłyby fajne, ale zaimplementowanie ich jest bez porównania
     * trudniejsze i na razie po prostu ich nie będzie
     * jakkolwiek rzucanie błędami w postCreate handlerze mogłoby mieć sens (i powodować usunięcie zasobu),
     * to nie jest zaimplementowane, więc w tej chwili nie ma co niczym rzucać
     */
    static public function checkCreate(FedoraResource $res, Doorkeeper $d) {
        self::log('post create handler for: ' . $d->getTransactionId());
        self::log("  " . $res->getUri());

        self::checkIdProp($res, $d);
        self::generatePid($res, $d);
    }

    /**
     *  nie ma sensu rzucanie błędami w postEdit handlerze, bo i tak nie ma jak wycofać takiej zmiany
     *  (chyba że razem z całą transakcją, ale to sprawdza commitHandler)
     */
    static public function checkEdit(FedoraResource $res, Doorkeeper $d) {
        self::log('post edit handler for: ' . $d->getMethod() . ' ' . $d->getTransactionId());
        self::log("  " . $res->getUri());

        self::checkIdProp($res, $d);
        self::generatePid($res, $d);
    }

    static private function log($msg) {
        fwrite(self::$logfile, $msg . "\n");
    }

    static private function checkIdProp(FedoraResource $res, Doorkeeper $d) {
        $prop = $d->getConfig('fedoraIdProp');
        $namespace = $d->getConfig('fedoraIdNamespace');
        $metadata = $res->getMetadata();
        if (!$metadata->hasProperty(EasyRdfUtil::fixPropName($prop))) {
            if ($namespace === null) {
                return false;
            }

            self::log("    no id property - adding");
            $metadata->addResource($prop, $namespace . UUID::v4());
            $res->setMetadata($metadata);
            $res->updateMetadata();
        }
        return true;
    }

    static private function generatePid(FedoraResource $res, Doorkeeper $d){
        $pidProp = EasyRdfUtil::fixPropName($d->getConfig('epicPidProp'));
        
        $metadata = $res->getMetadata();
        if($metadata->getLiteral($pidProp) !== null) {
            $metadata->delete($pidProp);
            
            $uri = $metadata->getResource(EasyRdfUtil::fixPropName($d->getConfig('fedoraIdProp')))->getUri();
            
            $ps = new HandleService($d->getConfig('epicUrl'), $d->getConfig('epicPrefix'), $d->getConfig('epicUser'), $d->getConfig('epicPswd'));
            $pid = $ps->create($uri);
            
            $metadata->add($pidProp, $pid);
            $res->setMetadata($metadata);
            $res->updateMetadata();
        }
    }
}
