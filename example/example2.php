<?php 

use CodeWisdoms\Eams\Eams;
require __DIR__ . '/../vendor/autoload.php';

$eams = new Eams([
    'firstName' => 'iam',
    'lastName' => 'admin',
    'email' => 'iam@adminiam.com',
], false);

echo json_encode($eams->findByName('J', 'A', ['basic']), JSON_PRETTY_PRINT);
exit;