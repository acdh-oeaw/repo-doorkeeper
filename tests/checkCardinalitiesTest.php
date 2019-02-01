<?php

require_once 'init.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\ResourceFactory as RF;

$fedora = new Fedora();
RF::init($fedora);

echo "acdh:Publication is properly created\n";
$fedora->begin();
try {
    $res = RF::create([
            'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'        => 'https://vocabs.acdh.oeaw.ac.at/schema#Publication',
            'https://vocabs.acdh.oeaw.ac.at/schema#hasAvailableDate' => '2017-01-01',
    ]);
} finally {
    $fedora->rollback();
}

echo "Min acdh:hasAvailableDate count on acdh:Publication is enforced\n";
$fedora->begin();
try {
    try {
        $res = RF::create([
            'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'        => 'https://vocabs.acdh.oeaw.ac.at/schema#Publication',
        ]);
        assert(false, 'Cardinality check was not successful (1)');
    } catch (Exception $ex) {
        assert(preg_match('/Min property count .* is 1 but resource has 0/', $ex->getMessage()), 'Cardinality check was not successful (2)');
    }
} finally {
    $fedora->rollback();
}
