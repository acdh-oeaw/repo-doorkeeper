<?php

/*
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

namespace acdhOeaw\doorkeeper;

use PDO;
use Exception;
use Throwable;
use RuntimeException;
use LogicException;
use EasyRdf\Graph;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\doorkeeper\Proxy;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\exceptions\NoAcdhId;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;

/**
 * Description of Doorkeeper
 *
 * @author zozlak
 */
class Doorkeeper {

    static private $exclResources = [
        'fcr:backup',
        'fcr:restore'
    ];

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
                parents varchar(8000),
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

    private $id;
    private $baseUrl;
    private $proxyBaseUrl;
    private $transactionId;
    private $resourceId;
    private $method;
    private $pdo;
    private $fedora;
    private $proxy;
    private $pass               = false;
    private $preCommitHandlers  = [];
    private $postCommitHandlers = [];
    private $postCreateHandlers = [];
    private $postEditHandlers   = [];
    private $logFile;
    private $routes             = [];
    private $readOnlyMode       = false;

    public function __construct(PDO $pdo) {
        Auth::init($pdo);

        $this->id     = rand();
        $this->method = strtoupper(filter_input(INPUT_SERVER, 'REQUEST_METHOD'));
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

    public function setReadOnlyMode(bool $mode) {
        $this->readOnlyMode = $mode;
    }

    public function getDeletedResourceId(string $uri): string {
        $resourceId = $this->extractResourceId($this->fedora->sanitizeUri($uri));

        $query = "SELECT acdh_id FROM resources WHERE transaction_id = ? AND resource_id = ?";
        $query = $this->pdo->prepare($query);
        $query->execute([$this->transactionId, $resourceId]);
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
        } else if (empty($this->transactionId) && $this->resourceId === 'fcr:tx' && $this->method === 'POST') {
            $this->handleTransactionBegin();
        } else {
            if (!$this->isTransactionValid()) {
                return;
            }

            if ($this->isTransactionEnd()) {
                $this->handleTransactionEnd();
            } else if ($this->resourceId === 'fcr:tx' && $this->method === 'POST') {
                $this->handleTransactionExtend();
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
        $errors = [];
        // so complex to respect primary key when the same resource is modified many times in one transaction
        $query  = $this->pdo->prepare("
            INSERT INTO resources (transaction_id, resource_id, acdh_id) 
            SELECT *
            FROM (SELECT ? AS a, ? AS b, ? AS c) AS t
            WHERE NOT EXISTS (SELECT 1 FROM resources WHERE transaction_id = t.a AND resource_id = t.b)
        ");
        try {
            $acdhId    = null;
            $meta      = null;
            $parents   = [];
            $tombstone = preg_match('|/fcr:tombstone$|', $this->proxyUrl);
            if ($this->method === 'DELETE' && !$tombstone) {
                try {
                    $res        = $this->fedora->getResourceByUri($this->proxyUrl);
                    $acdhId     = $res->getId();
                    $resourceId = $this->extractResourceId($this->proxyUrl);
                    $meta       = $res->getMetadata();

                    $updateQuery = $this->pdo->prepare("
                        UPDATE resources 
                        SET acdh_id = ?
                        WHERE transaction_id = ? AND resource_id = ?
                    ");
                    $param       = [$acdhId, $this->transactionId, $resourceId];
                    $updateQuery->execute($param);

                    foreach ($meta->allResources(RC::relProp()) as $i) {
                        $parents[] = $i->getUri();
                    }
                } catch (NoAcdhId $e) {
                    if (!$this->isOntologyPart($this->proxyUrl)) {
                        throw $e;
                    }
                }
            }

            $response = $this->proxy->proxy($this->proxyUrl);
            if ((int) ($response->getStatusCode() / 100) !== 2) {
                throw new RuntimeException($response->getStatusCode() . " " . $response->getReasonPhrase(), $response->getStatusCode());
            }

            if ($this->method === 'POST' || $response->getStatusCode() == 201 && $this->method !== 'MOVE') {
                $resourceId = $this->extractResourceId($this->parseLocations($response));
                $query->execute([$this->transactionId, $resourceId, null]);

                $res  = $this->fedora->getResourceByUri($resourceId);
                $meta = $res->getMetadata();
                foreach ($this->postCreateHandlers as $i) {
                    try {
                        $i($res, $this);
                    } catch (LogicException $e) {
                        $errors[] = $e;
                    }
                }
            } else if (!$tombstone) {
                if ($this->method === 'MOVE') {
                    $this->log('    Moved to: ' . $this->parseLocations($response));
                    $this->resourceId = $this->extractResourceId($this->parseLocations($response));
                }
                $resourceId = $this->extractResourceId($this->resourceId);
                $query->execute([$this->transactionId, $resourceId, $acdhId]);

                $res  = $this->fedora->getResourceByUri($resourceId);
                $meta = $res->getMetadata();
                foreach ($this->postEditHandlers as $i) {
                    try {
                        $i($res, $this);
                    } catch (LogicException $e) {
                        $errors[] = $e;
                    }
                }
            }

            // save information on parents
            if ($this->method !== 'DELETE') {
                foreach ($res->getMetadata()->allResources(RC::relProp()) as $i) {
                    $parents[] = $i->getUri();
                }
            }
            $parentsQuery = $this->pdo->prepare("
                UPDATE resources 
                SET parents = ?
                WHERE transaction_id = ? AND resource_id = ?
            ");
            $param        = [json_encode($parents), $this->transactionId, $resourceId];
            $parentsQuery->execute($param);
        } catch (Throwable $e) {
            // this means resource creation/modification went wrong in Fedora and should be reported
            $errors[] = $e;
        }
        $this->reportErrors($errors);
        $this->log('  Resource edit ended');
    }

    private function handleTransactionEnd() {
        $errors = [];
        if ($this->resourceId !== 'fcr:tx/fcr:commit') {
            try {
                $this->proxy->proxy($this->proxyUrl);
            } catch (Exception $e) {
                $errors[] = $e;
            }
        } else {
            // check the transaction
            list($resources, $deletedUris, $parents) = $this->checkTransactionResources();
            foreach ($this->preCommitHandlers as $i) {
                try {
                    $i($resources, $deletedUris, $this);
                } catch (Throwable $e) {
                    $errors[] = $e;
                }
            }

            // try to end the transaction
            if (count($errors) == 0) {
                try {
                    $this->proxy->proxy($this->proxyUrl);
                    $this->fedora->setTransactionId(''); // the transaction doesn't exist anymore at this point

                    $this->log('Waiting for the triplestore sync (' . count($resources) . ' resources)...');
                    $time = $this->waitForTriplesSync(1);
                    $this->log('  ...done (' . $time . ' s)');
                } catch (Exception $e) {
                    $errors[] = $e;
                }
            }

            // run post transaction handlers
            if (count($errors) == 0) {
                foreach ($this->postCommitHandlers as $i) {
                    try {
                        $i($resources, $deletedUris, $parents, $this);
                    } catch (Throwable $e) {
                        $errors[] = $e;
                    }
                }
                $this->log('Waiting for the triplestore sync...');
                $time = $this->waitForTriplesSync(1);
                $this->log('  ...done (' . $time . ' s)');
            }
        }

        if (count($errors) > 0) {
            $rollbackUrl = $this->proxyBaseUrl . 'rest/' . $this->transactionId . 'fcr:tx/fcr:rollback';
            try {
                $this->sendRequest('POST', $rollbackUrl);
            } catch (Throwable $e) {

            }
            $this->removeTransactionFromDb();
        }
        $this->reportErrors($errors);
        $this->log('  Transaction ended');
    }

    private function handleTransactionExtend() {
        try {
            $response = $this->proxy->proxy($this->proxyUrl);
        } catch (Throwable $e) {
            
        }
        
        if (isset($response) && $response->getStatusCode() === 410) {
            $this->removeTransactionFromDb();
        }
        
        $this->log('    ' . ($response ? $response->getStatusCode() : 'no response'));
    }

    private function handleTransactionBegin() {
        if ($this->readOnlyMode) {
            header('HTTP/1.1 503 Repository is in the read-only mode');
            $this->log('    ' . json_encode(['DOORKEEPER_ERROR', 503, 'Repository is in the read-only mode']));
        }
        // pass request, check if it was successfull and if so, save the transaction id in the database
        try {
            $response = $this->proxy->proxy($this->proxyUrl);

            $location      = $this->parseLocations($response);
            $transactionId = preg_replace('|^.*/([^/]+)|', '\\1', $location) . '/';

            if ($response->getStatusCode() === 201 && $transactionId !== $location . '/') {
                $query = $this->pdo->prepare("INSERT INTO transactions VALUES (?, current_timestamp)");
                $query->execute([$transactionId]);
            }
            
            $this->log('    ' . $response->getStatusCode() . ' ' . $transactionId);
        } catch (RequestException $e) {
            
        } catch (\Throwable $e) {
            
        }
    }

    private function isTransactionValid(): bool {
        if (!$this->transactionId) {
            header('HTTP/1.1 400 Bad Request - begin transaction first');
            $this->log('    ' . json_encode(['DOORKEEPER_ERROR', 400, 'Bad Request - begin transaction first']));
            return false;
        }

        $query = $this->pdo->prepare("SELECT count(*) FROM transactions WHERE transaction_id = ?");
        $query->execute([$this->transactionId]);
        if ((int) $query->fetchColumn() !== 1) {
            header('HTTP/1.1 400 Bad Request - unknown transaction id ' . $this->transactionId);
            $this->log('    ' . json_encode(['DOORKEEPER_ERROR', 400, 'Bad Request - unknown transaction id ' . $this->transactionId]));
            return false;
        }

        $allowed = ['GET', 'OPTIONS', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'MOVE'];
        if (!in_array($this->method, $allowed)) {
            header('HTTP/1.1 405 Method Not Supported by the doorkeeper');
            $this->log('    ' . json_encode(['DOORKEEPER_ERROR', 400, 'Method Not Supported by the doorkeeper']));
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
        return in_array($this->method, ['GET', 'OPTIONS', 'HEAD']);
    }

    private function parseLocations(Response $response) {
        $locations = $response->getHeader('Location');
        $location  = count($locations) > 0 ? $locations[0] : '';
        return $location;
    }

    private function sendRequest($method, $url, $headers = [], $body = null): Response {
        $headers['Authorization'] = self::getAuthHeader();
        $request                  = new Request($method, $url, $headers, $body);
        $client                   = new Client();
        return $client->send($request);
    }

    /**
     * Reports errors to the user (if they were found).
     * 
     * @param array $errors an array of errors (empty array if no errors occurred)
     */
    private function reportErrors(array $errors) {
        if (count($errors) == 0) {
            return;
        }

        header('HTTP/1.1 400 Bad Request - doorkeeper checks failed');
        foreach ($errors as $i) {
            echo $i->getMessage() . "\n\n";
            $this->log('    [handler error] ' . ($i instanceof LogicException ? $i->getMessage() : $i));
        }
    }

    private function removeTransactionFromDb() {
        $query = $this->pdo->prepare("DELETE FROM resources WHERE transaction_id = ?");
        $query->execute([$this->transactionId]);
        $query = $this->pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?");
        $query->execute([$this->transactionId]);
    }

    /**
     * Writes a message to the doorkeeper log.
     * 
     * @param string $msg message to write
     */
    public function log($msg) {
        fwrite($this->logFile, date('Y-m-d_H:i:s') . "\t" . $this->id . "\t" . $msg . "\n");
    }

    /**
     * Assures that the triplestore is synchronized after the transaction commit.
     * 
     * It is done by updating a known resource and then periodicaly checking the
     * triplestore until the change is populated.
     * 
     * Returns number of seconds elapsed.
     * @param int $interval time to wait before subsequent checks
     * @return int
     */
    private function waitForTriplesSync(int $interval = 1): int {
        $t        = time();
        $syncProp = RC::get('doorkeeperSyncProp');

        try {
            $res = $this->fedora->getResourceByUri(RC::get('doorkeeperSyncRes'));
        } catch (NotFound $e) {
            $meta = (new Graph())->resource('.');
            $meta->addLiteral(RC::titleProp(), 'Technical resource used by the doorkeeper');
            $res  = $this->fedora->createResource($meta, '', RC::get('doorkeeperSyncRes'), 'PUT');
        }
        $meta  = $res->getMetadata();
        $value = $meta->getLiteral($syncProp);
        $value = ($value === null ? 0 : $value->getValue()) + 1;
        $meta->delete($syncProp);
        $meta->addLiteral($syncProp, $value);
        $res->setMetadata($meta);
        $res->updateMetadata();

        $param = [$res->getUri(true), $syncProp];
        $query = new SimpleQuery("SELECT ?val WHERE { ?@ ?@ ?val .}", $param);
        while (true) {
            $results = $this->fedora->runQuery($query);
            if (count($results) > 0 && $results[0]->val->getValue() >= $value) {
                break;
            }
            sleep($interval);
        }
        return time() - $t;
    }

    /**
     * Checks resources modified during the transaction:
     * - if they still exist
     * - if deleted ones were not replaced by the newly created/modified ones
     * - what is their latest modification date
     * 
     * Includes also a list of all parents of resources affected by the 
     * transaction (created/modified/deleted)
     * @return array
     */
    private function checkTransactionResources(): array {
        $query = $this->pdo->prepare("SELECT resource_id, acdh_id, parents FROM resources WHERE transaction_id = ?");
        $query->execute([$this->transactionId]);

        $resources    = [];
        $deletedUris  = [];
        $uuids        = [];
        $deletedUuids = [];
        $parents      = [];

        while ($i = $query->fetch(PDO::FETCH_OBJ)) {
            $parents = array_merge($parents, json_decode($i->parents));
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
        for ($i = 0; $i < count($deletedUuids); $i++) {
            if (isset($uuids[$deletedUuids[$i]])) {
                $this->log('    removing ' . $deletedUris[$i] . ' from deleted resources list because it was succeeded by ' . $uuids[$deletedUuids[$i]]);
                unset($deletedUris[$i]);
            }
        }

        $parents = array_values(array_unique($parents));
        return [$resources, $deletedUris, $parents];
    }
}
