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

    public function proxy($url, $skipResponse = false): Response {
        $options = array();
        if (!$skipResponse) {
            $output = fopen('php://output', 'w');
            $options['sink'] = $output;
            $options['on_headers'] = function(Response $r) {
                $this->handleHeaders($r);
            };
        }
        $client = new Client($options);

        $authHeader = null;
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $authHeader = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        $headers = array(
            'Authorization' => $authHeader,
            'Accept' => filter_input(INPUT_SERVER, 'HTTP_ACCEPT'),
            'Content-Type' => filter_input(INPUT_SERVER, 'HTTP_CONTENT_TYPE')
        );

        $method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        $input = fopen('php://input', 'r');
        $request = new Request($method, $url, $headers, $input);
        $response = $client->send($request);

        fclose($input);
        if (isset($output)) {
            fclose($output);
        }

        return $response;
    }

    public function handleHeaders(Response $response) {
        header('HTTP/1.1 ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }
    }

}
