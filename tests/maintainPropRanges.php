<?php

require_once 'init.php';

use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\ResourceFactory as RF;

$fedora = new Fedora();
RF::init($fedora);

echo "automatic property values casting\n";
$fedora->begin();
try {
    $res = RF::create([
            'https://vocabs.acdh.oeaw.ac.at/schema#hasUpdatedDate' => '2017',
            'https://vocabs.acdh.oeaw.ac.at/schema#hasBinarySize'  => '300.54'
    ]);

    $date = $res->getMetadata()->getLiteral('https://vocabs.acdh.oeaw.ac.at/schema#hasUpdatedDate');
    assert($date->getDatatype() === 'xsd:dateTime', 'date was not cast to xsd:date');
    assert(substr((string) $date, 0, 10) === '2017-01-01', 'date was wrongly casted to xsd:date');

    $int = $res->getMetadata()->getLiteral('https://vocabs.acdh.oeaw.ac.at/schema#hasBinarySize');
    assert($int->getDatatype() === 'xsd:integer', 'int was not cast to xsd:integer');
    assert($int->getValue() === 300, 'int was wrongly casted to xsd:integer');
} finally {
    $fedora->rollback();
}
