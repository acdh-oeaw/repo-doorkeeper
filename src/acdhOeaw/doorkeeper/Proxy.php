<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace acdhOeaw\doorkeeper;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of Proxy
 *
 * @author zozlak
 */
class Proxy {

    /**
     * Response headers not to be forwarded to the client.
     * @var array
     */
    static $skipResponseHeaders = array('transfer-encoding', 'host');

    /**
     * Gets an original (before being proxied) HTTP header value.
     * @param string $header
     * @return string
     */
    static public function getHeader(string $header): string {
        $tmp = filter_input(\INPUT_SERVER, 'HTTP_X_FORWARDED_' . $header);
        if (empty($tmp)) {
            $tmp = filter_input(\INPUT_SERVER, 'HTTP_' . $header);
            if (empty($tmp)) {
                $tmp = '';
            }
        }
        return $tmp;
    }

    /**
     *
     * @var \acdhOeaw\doorkeeper\Doorkeeper
     */
    private $doorkeeper;

    /**
     * Headers not to be automatically included in the proxied request.
     * 
     * Typically they have a separate logic driving their values.
     * @var arrray
     * @see getForwardHeaders()
     */
    private $skipForwardHeaders = array(
        'authorization',
        'x-forwarded-for',
        'x-forwarded-proto',
        'x-forwarded-host',
        'x-forwarded-port',
        'x-forwarded-server',
        'host'
    );

    /**
     * 
     * @param \acdhOeaw\doorkeeper\Doorkeeper $d
     */
    public function __construct(Doorkeeper $d) {
        $this->doorkeeper           = $d;
        $this->skipForwardHeaders[] = strtolower(RC::get('fedoraRolesHeader'));
    }

    /**
     * 
     * @param string $url
     * @param \acdhOeaw\doorkeeper\ProxyOptions $opts
     * @return Response
     * @throws RequestException
     */
    public function proxy(string $url, ProxyOptions $opts = null): Response {
        if ($opts === null) {
            $opts = new ProxyOptions();
        }

        if ($opts->onlyRedirect) {
            header('HTTP/1.1 302 Found');
            header('Location: ' . $url);
            return;
        }

        $headers = $this->getForwardHeaders($opts);

        $method = strtoupper(filter_input(INPUT_SERVER, 'REQUEST_METHOD'));
        $input  = null;
        if ($method !== 'TRACE' && (isset($headers['content-type']) || isset($headers['content-length']))) {
            $input = fopen('php://input', 'r');
        }

        $options               = array();
        $output                = fopen('php://output', 'w');
        $options['sink']       = $output;
        $options['on_headers'] = function(Response $r) {
            $this->handleResponseHeaders($r);
        };
        $options['verify']          = false;
        $options['allow_redirects'] = $opts->allowRedirects;
        $client                     = new Client($options);

        //$this->doorkeeper->log(json_encode($headers));
        $request = new Request($method, $url, $headers, $input);
        try {
            $response = $client->send($request);
        } catch (RequestException $e) {
            if (!$e->hasResponse()) {
                throw $e; // if there is no response we can't properly return from function
            }
            $response = $e->getResponse();

            if ($input) {
                fclose($input);
                $input = null;
            }
            $request = $e->getRequest();
            $uri     = $request->getUri();
            $this->doorkeeper->log('    ' . json_encode(array(
                    'PROXY ERROR',
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $request->getMethod(),
                    $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath(),
                    $request->getHeaders(),
                    $request->getBody()
            )));
        } finally {
            if ($input) {
                fclose($input);
            }
            fclose($output);
        }

        return $response;
    }

    /**
     * 
     * @param Response $response
     */
    public function handleResponseHeaders(Response $response) {

        $status = $response->getStatusCode();
        if (in_array($status, array(401, 403))) {
            // if credentials were not provided in the original request inform user, they are required
            $authData = Auth::authenticate();
            if ($authData->user == Auth::DEFAULT_USER) {
                header('HTTP/1.1 401 Unauthorized');
                header('WWW-Authenticate: Basic realm="repository"');
            }
        } else {
            header('HTTP/1.1 ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
            foreach ($response->getHeaders() as $name => $values) {
                if (in_array(strtolower($name), self::$skipResponseHeaders)) {
                    continue;
                }
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }
    }

    /**
     * 
     * @param \acdhOeaw\doorkeeper\ProxyOptions $opts
     * @return array
     */
    private function getForwardHeaders(ProxyOptions $opts): array {
        $headers = array();
        foreach ($_SERVER as $k => $v) {
            if (substr($k, 0, 5) !== 'HTTP_') {
                continue;
            }
            $k = str_replace('_', '-', strtolower(substr($k, 5)));
            if (!in_array($k, $this->skipForwardHeaders)) {
                $headers[$k] = $v;
            }
        }

        $contentType = filter_input(\INPUT_SERVER, 'CONTENT_TYPE');
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        $contentLength = filter_input(\INPUT_SERVER, 'CONTENT_LENGTH');
        if ($contentLength !== null) {
            $headers['content-length'] = $contentLength;
        }

        if ($opts->authHeaders) {
            $authData   = Auth::authenticate();
            $cfgPrefix  = $authData->admin ? '' : 'Guest';
            $authHeader = Auth::getHttpBasicHeader(RC::get('fedora' . $cfgPrefix . 'User'), RC::get('fedora' . $cfgPrefix . 'Pswd'));

            $headers['Authorization']              = $authHeader;
            $headers[RC::get('fedoraRolesHeader')] = implode(',', $authData->roles);
        }
        if ($opts->proxyHeaders) {
            $headers['x-forwarded-for']    = self::getHeader('FOR');
            $headers['x-forwarded-proto']  = self::getHeader('PROTO');
            $headers['x-forwarded-host']   = self::getHeader('HOST');
            $headers['x-forwarded-port']   = self::getHeader('PORT');
            $headers['x-forwarded-server'] = self::getHeader('SERVER');
        }
        if ($opts->preserveHost) {
            $tmp             = explode(', ', self::getHeader('HOST'));
            $headers['host'] = trim($tmp[0]);
        }
        if ($opts->cookies) {
            $cookies = array();
            foreach ($_COOKIE as $k => $v) {
                $cookies[] = $k . '=' . $v;
            }
            if (count($cookies) > 0) {
                $headers['cookie'] = implode('; ', $cookies);
            }
        }

        return $headers;
    }

}
