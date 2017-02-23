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
        $options['verify'] = false;
        $client = new Client($options);

        $authHeader = null;
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $authHeader = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        $contentType = filter_input(INPUT_SERVER, 'HTTP_CONTENT_TYPE');
        $contentType = $contentType ? $contentType : filter_input(INPUT_SERVER, 'CONTENT_TYPE');
        $headers = array(
            'Authorization' => $authHeader,
            'Accept' => filter_input(INPUT_SERVER, 'HTTP_ACCEPT'),
            'Content-Type' => $contentType,
            'Host' => $this->host
        );

        $method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        $input = fopen('php://input', 'r');
        //print_r([$method, $url, $headers, $input]);
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
