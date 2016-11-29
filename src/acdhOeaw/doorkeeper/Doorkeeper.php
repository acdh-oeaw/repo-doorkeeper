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
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use zozlak\util\Config;
use acdhOeaw\doorkeeper\Proxy;
use acdhOeaw\fedora\Fedora;

/**
 * Description of Doorkeeper
 *
 * @author zozlak
 */
class Doorkeeper {

    static private $exclResources = array(
        'fcr:backup',
        'fcr:restore'
    );

    static public function initDb(PDO $pdo) {
        $pdo->query("CREATE TABLE transactions (transaction_id varchar(255) primary key, created timestamp not null)");

        $pdo->query("
            CREATE TABLE resources (
                transaction_id varchar(255) references transactions (transaction_id) on delete cascade, 
                resource_id varchar(255), 
                primary key (transaction_id, resource_id)
            )
        ");
    }

    static public function getAuthHeader() {
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        return null;
    }

    private $cfg;
    private $baseUrl;
    private $proxyBaseUrl;
    private $transactionId;
    private $resourceId;
    private $method;
    private $pdo;
    private $fedora;
    private $proxy;
    private $pass = false;
    private $commitHandlers = array();
    private $postCreateHandlers = array();
    private $postEditHandlers = array();

    public function __construct(Config $cfg, PDO $pdo) {
        $this->method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        $this->proxy = new Proxy($cfg->get('fedoraHost'));
        $this->fedora = new Fedora($cfg);
        $this->pdo = $pdo;
        $this->cfg = $cfg;

        $this->baseUrl = preg_replace('|/$|', '', $cfg->get('doorkeeperBaseUrl')) . '/';
        $this->proxyBaseUrl = preg_replace('|/$|', '', $cfg->get('fedoraBaseUrl')) . '/';

        $reqUri = filter_input(INPUT_SERVER, 'REQUEST_URI');
        if (!preg_match('|^' . $this->baseUrl . '|', $reqUri)) {
            // request outside Fedora API
            $this->proxyUrl = $this->proxyBaseUrl . substr($reqUri, 1);
            $this->pass = true;
        } else {
            $tmp = mb_substr($reqUri, strlen($this->baseUrl));
            if (preg_match('|^tx:[-a-z0-9]+|', $tmp)) {
                $this->transactionId = preg_replace('|/.*$|', '', $tmp) . '/';
                $tmp = substr($tmp, strlen($this->transactionId));

                $this->fedora->setTransactionId(substr($this->proxyBaseUrl . 'rest/' . $this->transactionId, 0, -1));
            }
            $this->resourceId = $tmp;
            $this->proxyUrl = $this->proxyBaseUrl . 'rest/' . $this->transactionId . $this->resourceId;

            if (in_array($this->resourceId, self::$exclResources)) {
                $this->pass = true;
            }
        }

        if ($cfg->get('doorkeeperDefaultUserPswd') && !isset($_SERVER['PHP_AUTH_USER'])) {
           $credentials = explode(':', $cfg->get('doorkeeperDefaultUserPswd'));
           $_SERVER['PHP_AUTH_USER'] = $credentials[0];
           $_SERVER['PHP_AUTH_PW'] = $credentials[1];
        }
    }

    public function getConfig($prop){
        return $this->config->get($prop);
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
        if ($this->isMethodReadOnly() || $this->pass) {
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
        $query = $this->pdo->prepare("INSERT INTO resources (transaction_id, resource_id) VALUES (?, ?)");
        try {
            $response = $this->proxy->proxy($this->proxyUrl);
            if ($this->method === 'POST') {
                $location = $this->parseLocations($response);
                $resourceId = preg_replace('|^.*/tx:[-a-z0-9]+/|', '', $location);
                $query->execute(array($this->transactionId, $resourceId));

                foreach ($this->postCreateHandlers as $i) {
                    try {
                        $res = $this->fedora->getResourceByUri($this->resourceId);
                        $i($res, $this);
                    } catch (Exception $e) {
                        
                    }
                }
            } else {
                $query->execute(array($this->transactionId, $this->resourceId));

                foreach ($this->postEditHandlers as $i) {
                    try {
                        $res = $this->fedora->getResourceByUri($this->resourceId);
                        $i($res, $this);
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
            $query = $this->pdo->prepare("SELECT resource_id FROM resources WHERE transaction_id = ?");
            $query->execute(array($this->transactionId));
            $resources = array();
            while ($i = $query->fetch(PDO::FETCH_OBJ)) {
                $resources[] = $this->fedora->getResourceByUri($i->resource_id);
            }
            foreach ($this->commitHandlers as $i) {
                try {
                    $i($resources, $this);
                } catch (LogicException $e) {
                    $errors[] = $e;
                }
            }
            if (count($errors) > 0) {
                $rollbackUrl = $this->proxyBaseUrl . 'rest/' . $this->transactionId . 'fcr:tx/fcr:rollback';
                try {
                    $this->sendRequest('POST', $rollbackUrl);
                } catch (Exception $e) {
                    
                }
                header('HTTP/1.1 400 Bad Request - doorkeeper checks failed');
                foreach ($errors as $i) {
                    echo $i->getMessage() . "\n\n";
                }
            }
        }

        // COMIT / ROLLBACK
        $query = $this->pdo->prepare("DELETE FROM resources WHERE transaction_id = ?");
        $query->execute(array($this->transactionId));
        $query = $this->pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
        $query->execute(array($this->transactionId));

        if (count($errors) == 0) {
            try {
                $this->proxy->proxy($this->proxyUrl);
            } catch (RequestException $e) {
                
            } catch (Exception $e) {
                
            }
        }
    }

    private function handleTransactionBegin() {
// pass request, check if it was successfull and if so, save the transaction id in the database
        try {
            $response = $this->proxy->proxy($this->proxyUrl);

            $location = $this->parseLocations($response);
            $transactionId = preg_replace('|^.*/([^/]+)|', '\\1', $location) . '/';

            if ($response->getStatusCode() === 201 && $transactionId !== $location . '/') {
                $query = $this->pdo->prepare("INSERT INTO transactions VALUES (?, current_timestamp)");
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

        $query = $this->pdo->prepare("SELECT count(*) FROM transactions WHERE transaction_id = ?");
        $query->execute(array($this->transactionId));
        if ((int) $query->fetchColumn() !== 1) {
            header('HTTP/1.1 400 Bad Request - unknown transaction id ' . $this->transactionId);
            return false;
        }

        if (!in_array($this->method, array('GET', 'OPTIONS', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'))) {
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

    private function sendRequest($method, $url, $headers = array(), $body = null): Response {
        $headers['Authorization'] = self::getAuthHeader();
        $request = new Request('POST', $url, $headers, $body);
        $client = new Client();
        return $client->send($request);
    }

}
