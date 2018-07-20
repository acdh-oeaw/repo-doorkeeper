<?php

/**
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
