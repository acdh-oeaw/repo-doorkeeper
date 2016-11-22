<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace acdhOeaw\doorkeeper;

use PDO;
use Exception;
use LogicException;
use GuzzleHttp\Psr7\Response;
use acdhOeaw\doorkeeper\Proxy;

/**
 * Description of Doorkeeper
 *
 * @author zozlak
 */
class Doorkeeper {

    private $baseUrl;
    private $proxyBaseUrl;
    private $transactionId;
    private $resourceId;
    private $method;
    private $pdo;
    private $proxy;
    private $commitHandlers = array();
    private $postCreateHandlers = array();
    private $postEditHandlers = array();

    public function __construct(string $baseUrl, string $proxyBaseUrl, PDO $pdo) {
        $this->method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        $this->proxy = new Proxy();

        $this->pdo = $pdo;
        $this->baseUrl = preg_replace('|/$|', '', $baseUrl) . '/';
        $this->proxyBaseUrl = preg_replace('|/$|', '', $proxyBaseUrl) . '/';

        $tmp = mb_substr(filter_input(INPUT_SERVER, 'REQUEST_URI'), strlen($baseUrl));
        if (preg_match('|^tx:[-a-z0-9]+|', $tmp)) {
            $this->transactionId = preg_replace('|/.*$|', '', $tmp) . '/';
            $tmp = substr($tmp, strlen($this->transactionId));
        }
        $this->resourceId = $tmp;

        $this->proxyUrl = $this->proxyBaseUrl . $this->transactionId . $this->resourceId;
    }

    public function getProxyBaseUrl() {
        return $this->proxyBaseUrl;
    }

    public function getTransactionId() {
        return $this->transactionId;
    }

    public function registerCommitHandler($handler) {
        $this->commitHandlers[] = $handler;
    }

    public function registerPostCreateHandler($handler) {
        $this->postCreateHandlers[] = $handler;
    }

    public function registerPostEditHandler($handler) {
        $this->postEditHandlers[] = $handler;
    }

    public function handleRequest() {
        if ($this->isMethodReadOnly()) {
            $this->handleReadOnly();
        } else if ($this->resourceId === 'fcr:tx' && $this->method === 'POST') {
            $this->handleTransactionBegin();
        } else {
            if (!$this->isTransactionValid()) {
                return;
            }

            if ($this->isTransactionEnd()) {
                $this->handleTransactionEnd();
            } else {
                $this->handleResourceEdit();
            }
        }
    }

    private function handleReadOnly() {
        try {
            $this->proxy->proxy($this->proxyUrl); // pass request and return results
        } catch (RequestException $e) {
            
        } catch (Exception $e) {
            
        }
    }

    private function handleResourceEdit() {
        $query = $this->pdo->prepare("INSERT INTO resources (session_id, resource) VALUES (?, ?)");
        try {
            $response = $this->proxy->proxy($this->proxyUrl);
            if ($this->method === 'POST') {
                $location = $this->parseLocations($response);
                $resourceId = preg_replace('|^.*/tx:[-a-z0-9]+/|', '', $location);
                $this->e($resourceId);
                $query->execute(array($this->transactionId, $resourceId));

                foreach ($this->postCreateHandlers as $i) {
                    try {
                        $i($resourceId, $this);
                    } catch (Exception $e) {
                        
                    }
                }
            } else {
                $query->execute(array($this->transactionId, $this->resourceId));

                foreach ($this->postEditHandlers as $i) {
                    try {
                        $i($this->resourceId, $this);
                    } catch (Exception $e) {
                        
                    }
                }
            }
        } catch (RequestException $e) {
            
        } catch (Exception $e) {
            
        }
    }

    private function handleTransactionEnd() {
        $errors = array();
        if ($this->resourceId === 'fcr:tx/fcr:commit') {
            // COMMIT - check resources integrity
            $query = $this->pdo->prepare("SELECT resource FROM resources WHERE session_id = ?");
            $query->execute(array($this->transactionId));
            $resources = array();
            while ($i = $query->fetch(PDO::FETCH_OBJ)) {
                $resources[] = $this->proxyUrl . $this->transactionId . $i->resource;
            }
            foreach ($this->commitHandlers as $i) {
                try {
                    $i($resources, $this);
                } catch (LogicException $e) {
                    $errors[] = $e;
                }
            }
            if (count($errors) > 0) {
                $rollbackUrl = $this->proxyBaseUrl . $this->transactionId . 'fcr:tx/fcr:rollback';
                $this->proxy->proxy($rollbackUrl, true);
                header('HTTP/1.1 400 Bad Request - doorkeeper checks failed');
                foreach ($errors as $i) {
                    echo $i->getMessage() . "\n\n";
                }
            }
        }

        // COMIT / ROLLBACK
        $query = $this->pdo->prepare("DELETE FROM resources WHERE session_id = ?");
        $query->execute(array($this->transactionId));
        $query = $this->pdo->prepare("DELETE FROM sessions WHERE session_id = ?");
        $query->execute(array($this->transactionId));

        if (count($errors) == 0) {
            $this->proxy->proxy($this->proxyUrl);
        }
    }

    private function handleTransactionBegin() {
        // pass request, check if it was successfull and if so, save the transaction id in the database
        try {
            $response = $this->proxy->proxy($this->proxyUrl);

            $location = $this->parseLocations($response);
            $transactionId = preg_replace('|^.*/([^/]+)|', '\\1', $location) . '/';

            if ($response->getStatusCode() === 201 && $transactionId !== $location . '/') {
                $query = $this->pdo->prepare("INSERT INTO sessions VALUES (?)");
                $query->execute(array($transactionId));
            }
        } catch (RequestException $e) {
            
        } catch (Exception $e) {
            
        }
    }

    private function isTransactionValid(): bool {
        if (!$this->transactionId) {
            header('HTTP/1.1 400 Bad Request - begin transaction first');
            return false;
        }

        $query = $this->pdo->prepare("SELECT count(*) FROM sessions WHERE session_id = ?");
        $query->execute(array($this->transactionId));
        if ((int) $query->fetchColumn() !== 1) {
            header('HTTP/1.1 400 Bad Request - unknown transaction id');
            return false;
        }

        if (!in_array($this->method, array('GET', 'OPTIONS', 'HEAD', 'POST', 'PUT', 'PATCH'))) {
            header('HTTP/1.1 405 Method Not Supported by the doorkeeper');
            return false;
        }

        return true;
    }

    private function isTransactionEnd(): bool {
        $urlMatch = preg_match('#^fcr:tx/fcr:(commit|rollback)$#', $this->resourceId);
        $methodMatch = $this->method === 'POST';
        return $urlMatch && $methodMatch;
    }

    private function isMethodReadOnly(): bool {
        return in_array($this->method, array('GET', 'OPTIONS', 'HEAD'));
    }

    private function parseLocations(Response $response) {
        $locations = $response->getHeader('Location');
        $location = count($locations) > 0 ? $locations[0] : '';
        return $location;
    }

    public function e($str) {
        $f = fopen('php://stdout', 'w');
        fwrite($f, $str);
        fclose($f);
    }

}
