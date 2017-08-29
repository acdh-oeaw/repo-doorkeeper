<?php

/*
 * The MIT License
 *
 * Copyright 2017 zozlak.
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
 * Description of Route
 *
 * @author zozlak
 */
class Route {

    private $route;
    private $proxyUrl;
    private $roles;
    private $admin;

    public function __construct(string $route, string $proxyUrl,
                                array $roles = array(), bool $admin = false) {
        $this->route    = $route;
        $this->proxyUrl = $proxyUrl;
        $this->roles    = $roles;
        $this->admin    = $admin;
    }

    public function getRoute(): string {
        return $this->route;
    }

    public function matches(string $reqUri): bool {
        return preg_match('|^' . $this->route . '|', $reqUri);
    }

    public function authenticate(): string {
        $authData = Auth::authenticate();

        $adminFlag = $this->admin && !$authData->admin;
        $roleFlag  = count($this->roles) > 0 && count(array_intersect($this->roles, $authData->roles)) == 0;
        if ($adminFlag || $roleFlag) {
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
        
        $reqUri = filter_input(INPUT_SERVER, 'REQUEST_URI');
        $reqUri = preg_replace('|^' . $this->route . '|', $this->proxyUrl, $reqUri);
        return $reqUri;
    }
}
