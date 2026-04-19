<?php
/**
 * Minimal example: use dbrel-data-php to build a visualization payload.
 *
 * This example uses PDO directly. In production, wrap your DB layer
 * with an implementation of DbRel\Data\DbInterface.
 */

require_once __DIR__ . '/../src/DbInterface.php';
require_once __DIR__ . '/../src/RelationshipSchema.php';
require_once __DIR__ . '/../src/RelationshipMatcher.php';
require_once __DIR__ . '/../src/DataCollector.php';
require_once __DIR__ . '/../src/DataProvider.php';

use DbRel\Data\RelationshipSchema;
use DbRel\Data\DataCollector;
use DbRel\Data\DataProvider;

// 1. Load your relationship schema
$schema = new RelationshipSchema(__DIR__ . '/sample_schema.json');

// 2. Collect data. For this example, we skip DB queries and addTable() directly.
$collector = new DataCollector();
$collector->addTable('my', 'accounts', [
    ['account_id' => 123, 'account_email' => 'user@example.com'],
], ['account_id', 'account_email']);

$collector->addTable('my', 'vps', [
    ['vps_id' => 1, 'vps_custid' => 123, 'vps_hostname' => 'server1.example.com'],
    ['vps_id' => 2, 'vps_custid' => 123, 'vps_hostname' => 'server2.example.com'],
], ['vps_id', 'vps_custid', 'vps_hostname']);

// 3. Build the response payload
$provider = new DataProvider($schema);
$response = $provider->build($collector, [
    'custid' => 123,
    'primaryKeys' => [
        'accounts' => 'account_id',
        'vps' => 'vps_id',
    ],
    'prefixes' => [
        'accounts' => 'account_',
        'vps' => 'vps_',
    ],
    'hiddenFields' => ['password'],
]);

// 4. Return as JSON
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
