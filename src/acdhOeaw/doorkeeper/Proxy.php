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

    public function proxy(string $url, ProxyOptions $opts = null): Response {
        if ($opts === null) {
            $opts = new ProxyOptions();
        }

        $method = strtoupper(filter_input(INPUT_SERVER, 'REQUEST_METHOD'));
        $input  = $method !== 'HEAD' ? fopen('php://input', 'r') : null;

        $options               = array();
        $output                = fopen('php://output', 'w');
        $options['sink']       = $output;
        $options['on_headers'] = function(Response $r) {
            $this->handleHeaders($r);
        };
        $options['verify']          = false;
        $options['allow_redirects'] = $opts->allowRedirects;
        $client                     = new Client($options);

        $contentType = filter_input(INPUT_SERVER, 'HTTP_CONTENT_TYPE');
        $contentType = $contentType ? $contentType : filter_input(INPUT_SERVER, 'CONTENT_TYPE');

        $contentDisposition = filter_input(INPUT_SERVER, 'HTTP_CONTENT_DISPOSITION');
        $contentDisposition = $contentDisposition ? $contentDisposition : filter_input(INPUT_SERVER, 'CONTENT_DISPOSITION');

        $headers = array(
            'Accept'              => filter_input(INPUT_SERVER, 'HTTP_ACCEPT'),
            'Content-Type'        => $contentType,
            'Content-Disposition' => $contentDisposition
        );
        if ($opts->authHeaders) {
            $authData   = Auth::authenticate();
            $cfgPrefix  = $authData->admin ? '' : 'Guest';
            $authHeader = Auth::getHttpBasicHeader(RC::get('fedora' . $cfgPrefix . 'User'), RC::get('fedora' . $cfgPrefix . 'Pswd'));

            $headers['Authorization']              = $authHeader;
            $headers[RC::get('fedoraRolesHeader')] = implode(',', $authData->roles);
        }
        if ($opts->proxyHeaders) {
            $headers['X-FORWARDED-FOR']    = self::getHeader('FOR');
            $headers['X-FORWARDED-PROTO']  = self::getHeader('PROTO');
            $headers['X-FORWARDED-HOST']   = self::getHeader('HOST');
            $headers['X-FORWARDED-PORT']   = self::getHeader('PORT');
            $headers['X-FORWARDED-SERVER'] = self::getHeader('SERVER');
        }
        if ($opts->preserveHost) {
            $headers['Host'] = self::getHeader('HOST');
        }
        if ($opts->cookies) {
            $headers['Cookie'] = array();
            foreach ($_COOKIE as $k => $v) {
                $headers['Cookie'][] = $k . '=' . $v;
            }
            $headers['Cookie'] = implode('; ', $headers['Cookie']);
        }

        //print_r([$method, $url, $headers, $input]);
        $request = new Request($method, $url, $headers, $input);
        try {
            $response = $client->send($request);
        } catch (RequestException $e) {
            if (!$e->hasResponse()) {
                throw $e; // if there is no response we can't properly return from function
            }
            $response = $e->getResponse();

            // place debugging code here
            if (preg_match('/browser/', $url)) {
                echo $response->getStatusCode() . " ###\n";
                print_r($response->getHeaders());
            }
        } finally {
            if ($input) {
                fclose($input);
            }
            fclose($output);
        }

        return $response;
    }

    public function handleHeaders(Response $response) {
        //@TODO for sure this list should be longer!
        static $skipHeaders = array('transfer-encoding', 'host');

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
                if (in_array(strtolower($name), $skipHeaders)) {
                    continue;
                }
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }
    }

}
