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

$composer = require_once __DIR__ . '/vendor/autoload.php';

use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\doorkeeper\Auth;
use zozlak\util\ClassLoader;

$composer->addPsr4('acdhOeaw\\', __DIR__ . '/src/acdhOeaw');
RC::init(__DIR__ . '/config.ini');

$dbFile = __DIR__ . '/db.sqlite';
$initDb = !file_exists($dbFile);
$pdo    = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($initDb) {
    Doorkeeper::initDb($pdo);
    Auth::initDb($pdo);
}

// clean up the transactions log
$pdo->query("DELETE FROM resources");
$pdo->query("DELETE FROM transactions");

// create admin user entries
$users                        = [];
$users[RC::get('fedoraUser')] = RC::get('fedoraPswd');
$users['resolver']            = RC::get('resolverPswd');
$users['oai']                 = RC::get('oaiPswd');
foreach ($users as $user => $pswd) {
    $query = $pdo->prepare("DELETE FROM users_roles WHERE user_id = ?");
    $query->execute([$user]);
    $query = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $query->execute([$user]);
    $query = $pdo->prepare("INSERT INTO users (user_id, password, admin) VALUES (?, ?, 1)");
    $query->execute([$user, password_hash($pswd, PASSWORD_DEFAULT)]);
}
// create normal user entries
try {
    $file = RC::get('doorkeeperUsersDbFile');
    $file = __DIR__ . '/' . $file;
    if (file_exists($file)) {
        $users = json_decode(file_get_contents($file));
        foreach ($users as $i) {
            if (substr($i->pswd, 0, 1) !== '$') {
                $i->pswd = password_hash($i->pswd, PASSWORD_DEFAULT);
            }
            if (!isset($i->admin)) {
                $i->admin = false;
            }
            $query = $pdo->prepare("DELETE FROM users_roles WHERE user_id = ?");
            $query->execute([$i->user]);
            $query = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $query->execute([$i->user]);
            $query = $pdo->prepare("INSERT INTO users (user_id, password, admin) VALUES (?, ?, ?)");
            $query->execute([$i->user, $i->pswd, (int) $i->admin]);
            $query = $pdo->prepare("INSERT INTO users_roles (user_id, role) VALUES (?, ?)");
            foreach ($i->roles as $j) {
                $query->execute([$i->user, $j]);
            }
        }
        file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
    }
} catch (InvalidArgumentException $ex) {
    
}

echo "  Doorkeeper database cleanup successful\n";
