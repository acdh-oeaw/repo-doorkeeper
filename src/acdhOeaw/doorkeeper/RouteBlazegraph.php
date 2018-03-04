<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace acdhOeaw\doorkeeper;

use RuntimeException;

/**
 * Implements specific access rules for the Blazegraph SPARQL endpoint.
 *
 * @author zozlak
 */
class RouteBlazegraph extends Route {

    /**
     * All GET requests and some POST requests can be sonsidered safe.
     * @return string
     * @throws RuntimeException
     */
    public function authenticate(): string {
        $authData = Auth::authenticate();
        if (!$authData->admin) {
            $method = filter_input(\INPUT_SERVER, 'REQUEST_METHOD');
            $deny   = false;
            $deny   |= !in_array($method, ['GET', 'POST']);
            if ($method === 'POST') {
                $allowedCT = ['multipart/form-data', 'application/x-www-form-urlencoded'];
                $cT = explode(';', filter_input(\INPUT_SERVER, 'CONTENT_TYPE') ?? '')[0];
                $deny      |= !in_array($cT, $allowedCT);
                $deny      |= filter_input(\INPUT_POST, 'query') === null && filter_input(\INPUT_POST, 'mapgraph') === null;
                $deny      |= filter_input(\INPUT_POST, 'update') !== null;
                $deny      |= filter_input(\INPUT_POST, 'updatePost') !== null;
                $deny      |= filter_input(\INPUT_POST, 'uri') !== null;
            }
            if ($deny) {
                if ($authData->user == Auth::DEFAULT_USER) {
                    $ex = new RuntimeException('Unauthorized', 401);
                    header('HTTP/1.1 401 Unauthorized');
                    header('WWW-Authenticate: Basic realm="repository"');
                } else {
                    $ex = new RuntimeException('Forbidden', 403);
                    header('HTTP/1.1 403 Forbidden');
                }
                throw $ex;
            }
        }
        return parent::authenticate();
    }

}
