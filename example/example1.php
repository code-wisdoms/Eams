<?php 

use CodeWisdoms\Eams\Eams;
require __DIR__ . '/../vendor/autoload.php';

$eams = new Eams([
    'firstName' => 'iam',
    'lastName' => 'admin',
    'email' => 'iam@adminiam.com',
    'session_id' => '00002dxvKWbjt4Chx2qb1lYQRK5:-1'
], false);

echo json_encode($eams->findByAdj('ADJ1546485', []), JSON_PRETTY_PRINT);
exit;