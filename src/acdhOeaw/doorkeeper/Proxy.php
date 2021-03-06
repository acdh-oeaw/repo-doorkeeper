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
     * (https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers#hbh plus host header)
     * @var array
     */
    static $skipResponseHeaders = ['connection', 'keep-alive', 'proxy-authenticate',
        'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'host'];

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
    private $skipForwardHeaders = [
        'authorization',
        'x-forwarded-for',
        'x-forwarded-proto',
        'x-forwarded-host',
        'x-forwarded-port',
        'x-forwarded-server',
        'host'
    ];

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
    public function proxy(string $url, ProxyOptions $opts = null) {
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

        $options               = [];
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
            if ($e->hasResponse()) {
                $response = $e->getResponse();
            }
        }
        if ($input) {
            fclose($input);
        }
        fclose($output);

        if (!isset($response) || $response->getStatusCode() >= 400) {
            $uri = $request->getUri();
            $this->doorkeeper->log('    ' . json_encode([
                    'PROXY ERROR',
                    $response ? $response->getStatusCode() : 'no response',
                    $response ? $response->getReasonPhrase() : 'no response',
                    $request->getMethod(),
                    $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath(),
                    $request->getHeaders(),
                    $request->getBody()
            ]));
        }

        if (!isset($response)) {
            throw $e; // if there is no response we can't properly return from function
        }
        return $response;
    }

    /**
     * 
     * @param Response $response
     */
    public function handleResponseHeaders(Response $response) {
        $status = $response->getStatusCode();
        if (in_array($status, [401, 403])) {
            // if credentials were not provided in the original request inform user, they are required
            $authData = Auth::authenticate();
            if ($authData->user == Auth::DEFAULT_USER) {
                header('HTTP/1.1 401 Unauthorized');
                header('WWW-Authenticate: Basic realm="repository"');
                return;
            }
        }

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

    /**
     * 
     * @param \acdhOeaw\doorkeeper\ProxyOptions $opts
     * @return array
     */
    private function getForwardHeaders(ProxyOptions $opts): array {
        $headers = [];
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
            $cookies = [];
            foreach ($_COOKIE as $k => $v) {
                $cookies[] = $k . '=' . $v;
            }
            if (count($cookies) > 0) {
                $headers['cookie'] = implode('; ', $cookies);
            }
        }

        // Fedora compares the accept header to the binary resource's mime type and sends 406 when they don't match
        // It causes `curl -L -H "Accept: application/x-cmdi+xml" http://hdl.handle.net/11022/0000-0007-C094-8` to fail with 406 (cause curl sends the original accept header to all subsequent redirects)
        // An easy and quite nice workaround is to make sure that "*/*" mime type is always set with the lowest weight
        if (!isset($headers['accept'])) {
            $headers['accept'] = '';
        }
        if (strpos($headers['accept'], '*/*') === false) {
            $headers['accept'] .= ($headers['accept'] !== '' ? ',' : '') . '*/*;q=0.1';
        }

        return $headers;
    }

}
