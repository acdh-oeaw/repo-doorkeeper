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

/**
 * Description of Proxy
 *
 * @author zozlak
 */
class Proxy {

    private $host;

    public function __construct(string $host) {
        $this->host = $host;
    }

    public function proxy($url): Response {
        $options = array();
        $output = fopen('php://output', 'w');
        $options['sink'] = $output;
        $options['on_headers'] = function(Response $r) {
            $this->handleHeaders($r);
        };
        $client = new Client($options);

        $authHeader = null;
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $authHeader = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        $headers = array(
            'Authorization' => $authHeader,
            'Accept' => filter_input(INPUT_SERVER, 'HTTP_ACCEPT'),
            'Content-Type' => filter_input(INPUT_SERVER, 'HTTP_CONTENT_TYPE'),
            'Host' => $this->host
        );

        $method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        $input = fopen('php://input', 'r');
        $request = new Request($method, $url, $headers, $input);
        $response = $client->send($request);

        fclose($input);
        fclose($output);

        return $response;
    }

    public function handleHeaders(Response $response) {
        //@TODO for sure this list should be longer!
        static $skipHeaders = array('transfer-encoding', 'host');

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
