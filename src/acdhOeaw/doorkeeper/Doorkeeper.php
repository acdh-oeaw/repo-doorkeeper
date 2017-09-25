<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace acdhOeaw\doorkeeper;

use PDO;
use Exception;
use RuntimeException;
use LogicException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\doorkeeper\Proxy;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\exceptions\NoAcdhId;
use acdhOeaw\fedora\exceptions\NotFound;

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
        $pdo->query("
            CREATE TABLE transactions (
                transaction_id varchar(255) primary key, 
                created timestamp not null
            )
        ");

        $pdo->query("
            CREATE TABLE resources (
                transaction_id varchar(255) references transactions (transaction_id) on delete cascade, 
                resource_id varchar(255), 
                acdh_id varchar(255),
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

    private $baseUrl;
    private $proxyBaseUrl;
    private $transactionId;
    private $resourceId;
    private $method;
    private $pdo;
    private $fedora;
    private $proxy;
    private $pass               = false;
    private $preCommitHandlers  = array();
    private $postCommitHandlers = array();
    private $postCreateHandlers = array();
    private $postEditHandlers   = array();
    private $logFile;
    private $routes             = array();

    public function __construct(PDO $pdo) {
        Auth::init($pdo);

        $this->method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        $this->proxy  = new Proxy($this);
        $this->fedora = new Fedora();
        $this->pdo    = $pdo;

        $this->baseUrl      = preg_replace('|/$|', '', RC::get('doorkeeperBaseUrl')) . '/';
        $this->proxyBaseUrl = substr(preg_replace('|/$|', '', RC::get('fedoraApiUrl')) . '/', 0, -strlen($this->baseUrl) + 1);

        $reqUri = filter_input(INPUT_SERVER, 'REQUEST_URI');
        if (!preg_match('|^' . $this->baseUrl . '|', $reqUri)) {
            // request outside Fedora API
            $this->proxyUrl = $this->proxyBaseUrl . substr($reqUri, 1);
            $this->pass     = true;
        } else {
            $tmp = mb_substr($reqUri, strlen($this->baseUrl));
            if (preg_match('|^tx:[-a-z0-9]+|', $tmp)) {
                $this->transactionId = preg_replace('|/.*$|', '', $tmp) . '/';
                $tmp                 = substr($tmp, strlen($this->transactionId));

                $this->fedora->setTransactionId(substr($this->proxyBaseUrl . 'rest/' . $this->transactionId, 0, -1));
            }
            $this->resourceId = $tmp;
            $this->proxyUrl   = $this->proxyBaseUrl . 'rest/' . $this->transactionId . $this->resourceId;

            if (in_array($this->resourceId, self::$exclResources)) {
                $this->pass = true;
            }
        }

        $this->logFile = fopen(RC::get('doorkeeperLogFile'), 'a');
    }

    public function __destruct() {
        fclose($this->logFile);
    }

    public function getProxyBaseUrl(): string {
        return $this->proxyBaseUrl;
    }

    public function getTransactionId(): string {
        return $this->transactionId;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getFedora(): Fedora {
        return $this->fedora;
    }

    public function registerPreCommitHandler($handler) {
        $this->preCommitHandlers[] = $handler;
    }

    public function registerPostCommitHandler($handler) {
        $this->postCommitHandlers[] = $handler;
    }

    public function registerPostCreateHandler($handler) {
        $this->postCreateHandlers[] = $handler;
    }

    public function registerPostEditHandler($handler) {
        $this->postEditHandlers[] = $handler;
    }

    public function registerRoute(Route $route) {
        $this->routes[$route->getRoute()] = $route;
    }

    public function getDeletedResourceId(string $uri): string {
        $resourceId = $this->extractResourceId($this->fedora->sanitizeUri($uri));

        $query = "SELECT acdh_id FROM resources WHERE transaction_id = ? AND resource_id = ?";
        $query = $this->pdo->prepare($query);
        $query->execute(array($this->transactionId, $resourceId));
        $id    = $query->fetch(PDO::FETCH_COLUMN);
        if (!$id) {
            throw new RuntimeException('acdh id of ' . $resourceId . ' not found ' . $this->transactionId);
        }
        return $id;
    }

    public function isOntologyPart(string $uri): bool {
        $uri      = $this->fedora->sanitizeUri($uri);
        $ontology = $this->fedora->sanitizeUri(RC::get('doorkeeperOntologyLocation'));
        return strpos($uri, $ontology) === 0;
    }

    public function handleRequest() {
        $authData = Auth::authenticate();
        $this->log(filter_input(INPUT_SERVER, 'REQUEST_METHOD') . ' ' . $this->proxyUrl . ' ' . $authData->user . '(' . (int) $authData->admin . ';' . implode(',', $authData->roles) . ')');

        $reqUri = filter_input(INPUT_SERVER, 'REQUEST_URI');
        foreach ($this->routes as $i) {
            if ($i->matches($reqUri)) {
                try {
                    $this->proxyUrl = $i->authenticate();
                    $this->handleReadOnly($i->getProxyOptions());
                } catch (RuntimeException $e) {
                    
                }
                return;
            }
        }

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

    private function handleReadOnly(ProxyOptions $opts = null) {
        try {
            $response = $this->proxy->proxy($this->proxyUrl, $opts); // pass request and return results
            $this->log('  ' . $this->proxyUrl . ' ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
        } catch (Exception $e) {
            $this->log('  ' . $e->getMessage());
        }
    }

    private function extractResourceId(string $uri): string {
        $resourceId = $uri;
        $resourceId = preg_replace('|^.*/tx:[-a-z0-9]+/|', '', $resourceId);
        $resourceId = preg_replace('|/fcr:[a-z]+$|', '', $resourceId);
        if ($resourceId === '') {
            $resourceId = '/';
        }
        return $resourceId;
    }

    private function handleResourceEdit() {
        $errors = array();
        // so complex to respect primary key when the same resource is modified many times in one transaction
        $query  = $this->pdo->prepare("
            INSERT INTO resources (transaction_id, resource_id, acdh_id) 
            SELECT *
            FROM (SELECT ? AS a, ? AS b, ? AS c) AS t
            WHERE NOT EXISTS (SELECT 1 FROM resources WHERE transaction_id = t.a AND resource_id = t.b)
        ");
        try {
            $acdhId    = null;
            $tombstone = preg_match('|/fcr:tombstone$|', $this->proxyUrl);
            if ($this->method === 'DELETE' && !$tombstone) {
                try {
                    $res        = $this->fedora->getResourceByUri($this->proxyUrl);
                    $acdhId     = $res->getId();
                    $resourceId = $this->extractResourceId($this->proxyUrl);

                    $updateQuery = $this->pdo->prepare("UPDATE resources SET acdh_id = ? WHERE transaction_id = ? AND resource_id = ?");
                    $updateQuery->execute(array($acdhId, $this->transactionId, $resourceId));
                } catch (NoAcdhId $e) {
                    if (!$this->isOntologyPart($this->proxyUrl)) {
                        throw $e;
                    }
                }
            }

            $response = $this->proxy->proxy($this->proxyUrl);
            if ($this->method === 'POST' || $response->getStatusCode() == 201) {
                $resourceId = $this->extractResourceId($this->parseLocations($response));
                $query->execute(array($this->transactionId, $resourceId, null));

                foreach ($this->postCreateHandlers as $i) {
                    try {
                        $res = $this->fedora->getResourceByUri($resourceId);
                        $i($res, $this);
                    } catch (LogicException $e) {
                        $errors[] = $e;
                    }
                }
            } else if (!$tombstone) {
                $resourceId = $this->extractResourceId($this->resourceId);
                $this->log($this->resourceId . ' # ' . $resourceId);
                $query->execute(array($this->transactionId, $resourceId, $acdhId));

                foreach ($this->postEditHandlers as $i) {
                    try {
                        $res = $this->fedora->getResourceByUri($resourceId);
                        $i($res, $this);
                    } catch (LogicException $e) {
                        $errors[] = $e;
                    }
                }
            }
        } catch (Exception $e) {
            // this means resource creation/modification went wrong in Fedora and should be reported
            $errors[] = $e;
        }
        $this->reportErrors($errors, false);
    }

    private function handleTransactionEnd() {
        $errors = array();

        if ($this->resourceId === 'fcr:tx/fcr:commit') {
            // COMMIT - check resources integrity
            $query = $this->pdo->prepare("SELECT resource_id, acdh_id FROM resources WHERE transaction_id = ?");
            $query->execute(array($this->transactionId));

            $resources    = array();
            $deletedUris  = array();
            $uuids        = array();
            $deletedUuids = array();

            while ($i = $query->fetch(PDO::FETCH_OBJ)) {
                try {
                    $res                  = $this->fedora->getResourceByUri($i->resource_id);
                    $resources[]          = $res;
                    $uuids[$res->getId()] = $res->getUri(true);
                } catch (Deleted $e) {
                    $deletedUris[]  = $this->fedora->standardizeUri($i->resource_id);
                    $deletedUuids[] = $i->acdh_id;
                } catch (NotFound $e) {
                    $deletedUris[]  = $this->fedora->standardizeUri($i->resource_id);
                    $deletedUuids[] = $i->acdh_id;
                } catch (NoAcdhId $e) {
                    // nothing to be done by the doorkeeper - it's handlers responsibility
                }
            }
            for ($i = 0; $i < count($deletedUris); $i++) {
                if (isset($uuids[$deletedUuids[$i]])) {
                    $this->log('    removing ' . $deletedUris[$i] . ' from deleted resources list because it was succeeded by ' . $uuids[$deletedUuids[$i]]);
                    unset($deletedUris[$i]);
                }
            }
            foreach ($this->preCommitHandlers as $i) {
                try {
                    $i($resources, $deletedUris, $this);
                } catch (Exception $e) {
                    $errors[] = $e;
                }
            }
        }


        // COMIT / ROLLBACK
        $query = $this->pdo->prepare("DELETE FROM resources WHERE transaction_id = ?");
        //$query->execute(array($this->transactionId));
        $query = $this->pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
        //$query->execute(array($this->transactionId));

        if (count($errors) == 0) {
            try {
                $this->proxy->proxy($this->proxyUrl);

                if ($this->resourceId === 'fcr:tx/fcr:commit') {
                    $time = ceil(RC::get('doorkeeperSleepPerResource') * count($resources));
                    $this->log('Sleeping ' . $time . ' s after commiting transaction involving ' . count($resources) . ' resources.');
                    sleep($time);
                }
                
                foreach ($this->postCommitHandlers as $i) {
                    try {
                        $i($resources, $deletedUris, $this);
                    } catch (Exception $e) {
                        $errors[] = $e;
                    }
                }
            } catch (Exception $e) {
                $errors[] = $e;
            }
        }

        $this->reportErrors($errors, true);
    }

    private function handleTransactionBegin() {
        // pass request, check if it was successfull and if so, save the transaction id in the database
        try {
            $response = $this->proxy->proxy($this->proxyUrl);

            $location      = $this->parseLocations($response);
            $transactionId = preg_replace('|^.*/([^/]+)|', '\\1', $location) . '/';

            if ($response->getStatusCode() === 201 && $transactionId !== $location . '/') {
                $query = $this->pdo->prepare("INSERT INTO transactions VALUES (?, current_timestamp)");
                $query->execute(array($transactionId));
            }
        } catch (RequestException $e) {
            
        } catch (\Throwable $e) {
            
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

        if (!in_array($this->method, array('GET', 'OPTIONS', 'HEAD', 'POST', 'PUT',
                'PATCH', 'DELETE'))) {
            header('HTTP/1.1 405 Method Not Supported by the doorkeeper');
            return false;
        }

        return true;
    }

    private function isTransactionEnd(): bool {
        $urlMatch    = preg_match('#^fcr:tx/fcr:(commit|rollback)$#', $this->resourceId);
        $methodMatch = $this->method === 'POST';
        return $urlMatch && $methodMatch;
    }

    private function isMethodReadOnly(): bool {
        return in_array($this->method, array('GET', 'OPTIONS', 'HEAD'));
    }

    private function parseLocations(Response $response) {
        $locations = $response->getHeader('Location');
        $location  = count($locations) > 0 ? $locations[0] : '';
        return $location;
    }

    public function e($str) {
        $f = fopen('php://stdout', 'w');
        fwrite($f, $str);
        fclose($f);
    }

    private function sendRequest($method, $url, $headers = array(), $body = null): Response {
        $headers['Authorization'] = self::getAuthHeader();
        $request                  = new Request('POST', $url, $headers, $body);
        $client                   = new Client();
        return $client->send($request);
    }

    /**
     * Reports errors to the user (if they were found).
     * 
     * Optionally rolls back the transaction.
     * 
     * @param array $errors an array of errors (empty array if no errors occurred)
     * @param bool $rollback should transaction be rolled back upon errors
     */
    private function reportErrors(array $errors, bool $rollback) {
        if (count($errors) == 0) {
            return;
        }

        if ($rollback) {
            $rollbackUrl = $this->proxyBaseUrl . 'rest/' . $this->transactionId . 'fcr:tx/fcr:rollback';
            try {
                $this->sendRequest('POST', $rollbackUrl);
            } catch (Exception $e) {
                
            }
        }

        header('HTTP/1.1 400 Bad Request - doorkeeper checks failed');
        foreach ($errors as $i) {
            echo $i->getMessage() . "\n\n";
            $this->log('    ' . ($i instanceof LogicException ? $i->getMessage() : $i));
        }
    }

    /**
     * Writes a message to the doorkeeper log.
     * 
     * @param string $msg message to write
     */
    public function log($msg) {
        fwrite($this->logFile, $msg . "\n");
    }

}
