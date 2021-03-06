<?php

/**
 * The MIT License
 *
 * Copyright 2018 Austrian Centre for Digital Humanities at the Austrian Academy of Sciences
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


$composer = require_once __DIR__ . '/../vendor/autoload.php';
$composer->addPsr4('acdhOeaw\\', __DIR__ . '/../src/acdhOeaw');

use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\ResourceFactory as RF;
use acdhOeaw\fedora\exceptions\NotFound;
use zozlak\util\ClassLoader;

ini_set('assert.exception', 1);
if (ini_get('zend.assertions') !== '1') {
    throw new Exception('Enable assertions by setting zend.assertions = 1 in your php.ini');
}

RC::init(__DIR__ . '/config.ini');
$fedora = new Fedora();
RF::init($fedora);

$fedora->begin();
$uri = RC::get('fedoraAclUri');
try {
    $aclRes = $fedora->getResourceByUri($uri);
} catch (NotFound $e) {
    RF::create([], $uri, 'PUT');
}
$id = RC::get('fedoraHostingPropDefault');
try {
    $aclRes = $fedora->getResourceById($id);
} catch (NotFound $e) {
    RF::create(['id' => $id]);
}
$fedora->commit();

