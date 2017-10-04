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

use PDO;
use BadMethodCallException;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Provides an authorization for the Doorkeeper
 *
 * @author zozlak
 */
class Auth {
    const DEFAULT_USER = 'guest';

    /**
     * Database connection
     * @var \PDO
     */
    static private $pdo;
    
    /**
     * Cached Auth object (authentication data do not change during a request)
     * @var acdhOeaw\doorkeeper\Auth
     */
    static private $authData;

    /**
     * 
     * @param PDO $pdo
     */
    static public function init(PDO $pdo) {
        self::$pdo = $pdo;
    }

    /**
     * 
     * @param PDO $pdo
     */
    static public function initDb(PDO $pdo) {
        $pdo->query("
            CREATE TABLE users (
                user_id text not null primary key, 
                password text not null,
                admin bool not null default false
            )
        ");
        $pdo->query("
            CREATE TABLE users_roles (
                user_id text not null references users (user_id) on delete cascade on update cascade, 
                role text not null,
                primary key (user_id, role)
            )
        ");
    }
    
    /**
     * 
     * @param string $user
     * @param string $password
     * @return string
     */
    static public function getHttpBasicHeader(string $user, string $password): string {
        return 'Basic ' . base64_encode($user . ':' . $password);
    }

    /**
     * 
     * @return \acdhOeaw\doorkeeper\Auth
     */
    static public function authenticate(): Auth {
        if (self::$authData) {
            return self::$authData;
        }
        
        $authData = new Auth();
       
        $shibHeader = 'HTTP_' . strtoupper(RC::get('doorkeeperShibUserHeader'));
        $tmpUser = trim(filter_input(\INPUT_SERVER, $shibHeader));
        if (strlen($tmpUser) > 0 && $tmpUser !== '(null)') {
            $authData->user = $tmpUser;
        } elseif (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $tmpUser = $_SERVER['PHP_AUTH_USER'];
            $tmpPswd = $_SERVER['PHP_AUTH_PW'];
            if (self::authUser($tmpUser, $tmpPswd)) {
                $authData->user = $tmpUser;
            }
        }
        
        $authData->roles = self::getRoles($authData->user);
        $authData->roles[] = $authData->user;
        $authData->admin = self::getAdmin($authData->user);
        
        self::$authData = $authData;
        return $authData;
    }
    
    /**
     * 
     * @param string $user
     * @param string $password
     * @return bool
     */
    static public function authUser(string $user, string $password): bool {
        $query = self::$pdo->prepare("SELECT password FROM users WHERE user_id = ?");
        $query->execute(array($user));
        $hash  = $query->fetchColumn();
        if ($hash !== false) {
            return password_verify($password, $hash);
        }
        return false;
    }
    
    /**
     * 
     * @param string $user
     * @return bool
     */
    static public function getAdmin(string $user): bool {
        $query = self::$pdo->prepare("SELECT admin FROM users WHERE user_id = ?");
        $query->execute(array($user));
        return $query->fetchColumn();
    }

    /**
     * 
     * @param string $user
     * @return array
     */
    static public function getRoles(string $user): array {
        $query = self::$pdo->prepare("SELECT role FROM users_roles WHERE user_id = ?");
        $query->execute(array($user));
        $roles = $query->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($roles)) {
            $roles = array();
        }
        return $roles;
    }

    /**
     * 
     * @param string $user
     * @param string $password
     * @param array $roles
     * @throws BadMethodCallException
     */
    static public function addUser(string $user, string $password,
                                   array $roles = array()) {
        if (strlen($password) < 4) {
            throw new BadMethodCallException('password must contain at least 4 characters');
        }
        
        self::$pdo->beginTransaction();
        
        $query = self::$pdo->prepare("DELETE FROM users_roles WHERE user_id = ?");
        $query->execute(array($user));
        
        $query = self::$pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $query->execute(array($user));

        $query = self::$pdo->prepare("INSERT INTO users (user_id, password) VALUES (?, ?)");
        $query->execute(array($user, password_hash($password, \PASSWORD_DEFAULT)));
        
        $query = self::$pdo->prepare("INSERT INTO users_roles (user_id, role) VALUES (?, ?)");
        foreach (array_unique($roles) as $i) {
            $query->execute(array($user, $i));
        }
        
        self::$pdo->commit();
    }

    /**
     * 
     * @param string $user
     * @param string $password
     * @param array $roles
     */
    static public function modifyUser(string $user, string $password,
                                      array $roles = array()) {
        self::addUser($user, $password, $roles);
    }

    /**
     * Is user an admin?
     * @var bool
     */
    public $admin = false;
    
    /**
     * User name
     * @var string
     */
    public $user = self::DEFAULT_USER;
    
    /**
     * All roles user belongs to
     * @var array
     */
    public $roles = array();
}
