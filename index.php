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

require_once __DIR__ . '/vendor/autoload.php';

use zozlak\util\ClassLoader;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\doorkeeper\Auth;
use acdhOeaw\doorkeeper\Route;
use acdhOeaw\util\RepoConfig as RC;
use EasyRdf\RdfNamespace;

$cl = new ClassLoader();
RdfNamespace::set('dct', 'http://purl.org/dc/terms/');

RC::init('config.ini');

$dbFile = 'db.sqlite';
$initDb = !file_exists($dbFile);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($initDb) {
    Doorkeeper::initDb($pdo);
    Auth::initDb($pdo);
}

$doorkeeper = new Doorkeeper($pdo);

$doorkeeper->registerCommitHandler('\acdhOeaw\doorkeeper\handler\Handler::checkTransaction');
$doorkeeper->registerPostCreateHandler('\acdhOeaw\doorkeeper\handler\Handler::checkCreate');
$doorkeeper->registerPostEditHandler('\acdhOeaw\doorkeeper\handler\Handler::checkEdit');

$doorkeeper->registerRoute(new Route('/blazegraph', 'http://blazegraph:9999/blazegraph', array('resolver'), false));

$doorkeeper->registerRoute(new Route('/browser', 'http://drupal/browser', array(), false));

$doorkeeper->registerRoute(new Route('/oai', 'http://oai', array(), false));

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');
$doorkeeper->handleRequest();
