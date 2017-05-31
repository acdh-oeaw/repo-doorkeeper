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

use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\doorkeeper\Doorkeeper;
use acdhOeaw\doorkeeper\Auth;
use zozlak\util\ClassLoader;

$cl = new ClassLoader(__DIR__ . '/src');
RC::init(__DIR__ . '/config.ini');

$dbFile = __DIR__ . '/db.sqlite';
$initDb = !file_exists($dbFile);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($initDb) {
    Doorkeeper::initDb($pdo);
    Auth::initDb($pdo);
}

// clean up the transactions log
$pdo->query("DELETE FROM resources");
$pdo->query("DELETE FROM transactions");

// create an admin user entry
$user = RC::get('fedoraUser');
$query = $pdo->prepare("DELETE FROM users_roles WHERE user_id = ?");
$query->execute(array($user));
$query = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
$query->execute(array($user));
$query = $pdo->prepare("INSERT INTO users (user_id, password, admin) VALUES (?, ?, 1)");
$query->execute(array($user, password_hash(RC::get('fedoraPswd'), PASSWORD_DEFAULT)));

echo "  Doorkeeper database cleanup successful\n";
