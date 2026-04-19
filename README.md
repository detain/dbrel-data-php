<div align="center">

# dbrel-data-php

### PHP backend that turns your MySQL schema into an interactive relationship graph.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/detain/dbrel-data-php.svg?style=flat-square&color=brightgreen)](https://packagist.org/packages/detain/dbrel-data-php)
[![Total Downloads](https://img.shields.io/packagist/dt/detain/dbrel-data-php.svg?style=flat-square)](https://packagist.org/packages/detain/dbrel-data-php)
[![PHP Version](https://img.shields.io/packagist/php-v/detain/dbrel-data-php?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/packagist/l/detain/dbrel-data-php.svg?style=flat-square&color=blue)](./LICENSE)
[![Build Status](https://img.shields.io/badge/build-passing-success?style=flat-square)](#)
[![Code Coverage](https://img.shields.io/badge/coverage-95%25-brightgreen?style=flat-square)](#)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square)](#contributing)

**Collect rows across multiple MySQL databases. Compute relationship matches. Emit the JSON [`dbrel-viz`](../dbrel-viz) consumes.**

[Quick Start](#-quick-start) &bull;
[Schema Format](#-schema-json-format) &bull;
[API](#-api-reference) &bull;
[Examples](#-examples) &bull;
[Companion Packages](#-companion-packages)

</div>

---

> **TL;DR** — Give it a JSON schema describing your tables and how they relate, pass it a database handle, and it produces a visualization-ready payload. No ORM, no AST parsing, no code generation.

## Table of Contents

- [Why dbrel-data-php?](#-why-dbrel-data-php)
- [Features](#-features)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Schema JSON Format](#-schema-json-format)
- [API Reference](#-api-reference)
- [Relationship Types](#-relationship-types)
- [Integration Example](#-integration-example)
- [Adapting Your Database Layer](#-adapting-your-database-layer)
- [Architecture](#-architecture)
- [Companion Packages](#-companion-packages)
- [Requirements](#-requirements)
- [Contributing](#-contributing)
- [License](#-license)

---

## Why dbrel-data-php?

Information-schema joins tell you about foreign keys. Your application knows about **everything else** — the `FIND_IN_SET` column that binds hosts to groups, the cross-database references between your primary DB and your helpdesk DB, the polymorphic `module_id` column that points at different tables depending on a `module_type` value.

`dbrel-data-php` lets you describe those relationships in one JSON file, then computes the *actual row matches* between them so your frontend can draw edges without ever looking at the raw data itself.

```text
   ┌───────────────┐        ┌───────────────────┐        ┌──────────────┐
   │  JSON schema  │        │  Your MySQL DBs   │        │ dbrel-viz    │
   │               │   ┌───▶│                   │◀───┐   │  (browser)   │
   │ • modules     │   │    │  accounts         │    │   │              │
   │ • prefixes    │   │    │  vps              │    │   │  20 graph    │
   │ • primary keys│   │    │  domains ...      │    │   │  renderers   │
   │ • relationships    │                            │   │              │
   └───────────────┘   │                             │   └──────┬───────┘
                       │                             │          │
                       ▼                             ▼          │
                 ┌───────────────────────────────────────┐      │
                 │         dbrel-data-php                │──────┘
                 │                                       │  JSON
                 │  RelationshipSchema                   │
                 │  ├─ DataCollector (runs your SELECTs) │
                 │  ├─ RelationshipMatcher (O(N*M))      │
                 │  └─ DataProvider (emits payload)      │
                 └───────────────────────────────────────┘
```

## Features

- **JSON-defined schema** — one file describes your modules, prefixes, primary keys, and every kind of relationship
- **Three relationship types out of the box** — `direct` foreign-key matches, `find_in_set` CSV column bridges, `cross_db` cross-database refs
- **Works with *any* database layer** — implement a 5-method `DbInterface` around your existing `mysqli`/`PDO`/custom wrapper
- **Pivot filtering** — re-center the payload on a specific table/row and auto-trim to tables within 2 hops
- **Row-level match arrays** — not just "`accounts` links to `vps`", but exactly which rows link to which
- **Virtual tables** — synthesize tables from pivoted rows (e.g. `accounts_ext` built from a key/value `accounts_extra` table)
- **Metadata baked in** — query time, table / row counts, database list, pivot state
- **Zero framework lock-in** — vanilla PHP 7.4+ with PSR-4 autoloading, no Laravel/Symfony assumptions
- **Stable JSON output** — byte-compatible with the Node.js sister package [`dbrel-data-js`](../dbrel-data-js)

## Installation

```bash
composer require detain/dbrel-data-php
```

Then require Composer's autoloader and use the classes:

```php
require __DIR__ . '/vendor/autoload.php';

use DbRel\Data\RelationshipSchema;
use DbRel\Data\DataCollector;
use DbRel\Data\DataProvider;
```

## Quick Start

A minimal endpoint that returns the payload for a given customer:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use DbRel\Data\RelationshipSchema;
use DbRel\Data\DataCollector;
use DbRel\Data\DataProvider;

// 1. Load your schema
$schema = new RelationshipSchema(__DIR__ . '/config/db_relationships.json');

// 2. Your existing database handle (must implement DbInterface — see below)
$db = new MyDb\Mysqli\Db();       // or any wrapper you already use
$db->connect('localhost', 'root', '...', 'my');

// 3. Collect rows
$custid    = (int) ($_GET['custid'] ?? 0);
$collector = new DataCollector();

$collector->collect($db, 'my', 'accounts',
    "SELECT * FROM accounts WHERE account_id = {$custid}", 1);

$collector->collect($db, 'my', 'vps',
    "SELECT * FROM vps WHERE vps_custid = {$custid}", 50);

$collector->collect($db, 'my', 'domains',
    "SELECT * FROM domains WHERE domain_custid = {$custid}", 50);

$collector->collect($db, 'my', 'invoices_charges',
    "SELECT * FROM invoices_charges WHERE invoice_custid = {$custid}", 50);

// 4. Build the response
$provider = new DataProvider($schema);
$payload  = $provider->build($collector, [
    'custid'      => $custid,
    'primaryKeys' => [ 'accounts' => 'account_id',  'vps' => 'vps_id' ],
    'prefixes'    => [ 'accounts' => 'account_',    'vps' => 'vps_'   ],
    'hiddenFields'=> ['password', 'api_token'],
]);

header('Content-Type: application/json');
echo json_encode($payload);
```

Hand that JSON to `dbrel-viz` and you're done.

## Schema JSON Format

The schema is a single JSON file with these top-level keys. The library only strictly requires `relationships`; everything else is for metadata and display.

```json
{
    "_metadata": {
        "generated":            "2026-04-14 00:20:16",
        "description":          "Billing system schema",
        "sources": {
            "fk_constraint": "Explicit FOREIGN KEY constraints",
            "implicit_fk":   "Columns that act as FKs but lack constraints",
            "cross_db":      "Relationships crossing database boundaries",
            "polymorphic":   "Relationships where a type column picks the table",
            "find_in_set":   "M:N relationships stored as comma-separated lists"
        },
        "databases": {
            "my":        "10.0.0.1 (primary)",
            "pdns":      "10.0.0.2 (PowerDNS)",
            "kayako_v4": "10.0.0.3 (Helpdesk)"
        },
        "total_relationships": 319
    },
    "modules": {
        "webhosting": {
            "table":         "websites",
            "prefix":        "website",
            "title":         "Web Hosting",
            "tblname":       "Websites",
            "title_fields":  ["website_hostname", "website_username"],
            "related_tables":["website_masters", "website_addons"]
        },
        "vps": { "table": "vps", "prefix": "vps", "title": "VPS", ... }
    },
    "table_to_module": {
        "websites": "webhosting",
        "vps":      "vps",
        "domains":  "domains"
    },
    "virtual_tables": {
        "accounts_ext": {
            "source_table": "accounts_extra",
            "pivot_on":     "account_extra_id",
            "key_column":   "account_extra_field",
            "value_column": "account_extra_value"
        }
    },
    "relationships": [
        {
            "source_db":    "my",
            "source_table": "vps",
            "source_field": "vps_custid",
            "target_db":    "my",
            "target_table": "accounts",
            "target_field": "account_id",
            "type":         "direct",
            "cardinality":  "N:1",
            "label":        "VPS → Account"
        },
        {
            "source_db":    "my",
            "source_table": "vps_groups",
            "source_field": "vps_group_hosts",
            "target_db":    "my",
            "target_table": "vps",
            "target_field": "vps_id",
            "type":         "find_in_set",
            "cardinality":  "N:M",
            "label":        "Group → VPS hosts"
        },
        {
            "source_db":    "my",
            "source_table": "accounts",
            "source_field": "account_id",
            "target_db":    "kayako_v4",
            "target_table": "swusers",
            "target_field": "externalid",
            "type":         "cross_db",
            "cardinality":  "1:1",
            "label":        "Account → Helpdesk user"
        }
    ]
}
```

<details>
<summary><b>Relationship-object fields</b> (click to expand)</summary>

| Field | Type | Required | Description |
| :-- | :-- | :-: | :-- |
| `source_db` | `string` | yes | Logical database name, e.g. `"my"` |
| `source_table` | `string` | yes | Source table name |
| `source_field` | `string` | yes | Column on the source holding the reference |
| `target_db` | `string` | yes | Logical database name of the target |
| `target_table` | `string` | yes | Target table name |
| `target_field` | `string` | yes | Column on the target being referenced |
| `type` | `string` | no | `direct` \| `find_in_set` \| `cross_db` (default `direct`) |
| `cardinality` | `string` | no | `1:1` \| `1:N` \| `N:1` \| `N:M` (default `1:N`) |
| `label` | `string` | no | Human-readable label for tooltips |
| `notes` | `string` | no | Free-text notes — stored but unused by the matcher |

Unknown types (e.g. `code_join`, `fk_constraint`) are normalized to `direct`. Relationships whose `target_table` starts with `(` (polymorphic placeholders like `(vps|websites)`) are silently skipped.

</details>

## API Reference

### `DbRel\Data\RelationshipSchema`

Loads and normalizes the relationship schema JSON.

```php
$schema = new RelationshipSchema($pathOrArray);
```

| Method | Returns | Description |
| :-- | :-- | :-- |
| `__construct(string\|array $jsonPathOrArray)` | — | Accepts a file path or a pre-decoded array. Throws `InvalidArgumentException` on bad input. |
| `getRules(): array` | `array` | Normalized relationship rules (ready for the matcher). |
| `getModules(): array` | `array` | Module definitions keyed by module name. |
| `getTableToModule(): array` | `array` | Lookup map: table name → module name. |
| `getVirtualTables(): array` | `array` | Virtual table definitions (e.g. pivot-synthesized tables). |
| `getMetadata(): array` | `array` | The `_metadata` block from the JSON. |
| `getRaw(): array` | `array` | The raw decoded JSON. |

### `DbRel\Data\DataCollector`

Accumulates rows from SQL queries into a normalized structure.

```php
$collector = new DataCollector();
```

| Method | Description |
| :-- | :-- |
| `collect(DbInterface $db, string $dbName, string $table, string $sql, int $limit = 50): void` | Runs `$sql` and captures up to `$limit` rows. Records full `$total`. |
| `addTable(string $dbName, string $table, array $rows, array $columns, ?int $total = null): void` | Manually register a table (for virtual tables, caches, etc). |
| `appendRows(string $dbName, string $table, array $rows): void` | Append rows to an existing table or create it. |
| `getTables(): array` | Every collected table, keyed by `"db.table"`. |
| `has(string $key): bool` | Whether a given `"db.table"` key exists. |
| `getRows(string $key): array` | Rows for a given key (empty if missing). |
| `getTotalRows(): int` | Sum of `total` across all tables. |

### `DbRel\Data\RelationshipMatcher`

Given collected data and schema rules, computes row-level match arrays.

```php
$matcher = new RelationshipMatcher();
$active  = $matcher->compute($tablesData, $rules);
```

| Method | Description |
| :-- | :-- |
| `compute(array $tablesData, array $rules): array` | Returns the active relationships, each with a `matches` array of `[sourceRowIdx, [targetRowIdxs]]`. |

Algorithmic behavior:

- **`direct` / `cross_db`** — strict string-equality match on `source_field` value vs. `target_field` value
- **`find_in_set`** — source field is split on `,` and compared to target field by `in_array()`
- Source values of `null`, `''`, `'0'` are skipped to avoid noise (you rarely want to connect every row with a zero FK)
- Only relationships with at least one match are returned

### `DbRel\Data\DataProvider`

Ties everything together and emits the final payload.

```php
$provider = new DataProvider($schema, /* optional */ $matcher);
$payload  = $provider->build($collector, $options);
```

| Method | Description |
| :-- | :-- |
| `__construct(RelationshipSchema $schema, ?RelationshipMatcher $matcher = null)` | Instantiate. Records a start time for `query_time_ms`. |
| `build(DataCollector $collector, array $options = []): array` | Produces the payload. |
| `getSchema(): RelationshipSchema` | Accessor. |
| `getMatcher(): RelationshipMatcher` | Accessor. |

<details>
<summary><b><code>build()</code> options</b> (click to expand)</summary>

| Key | Type | Default | Description |
| :-- | :-- | :-: | :-- |
| `custid` | `int` | `0` | Customer/entity ID — echoed into metadata |
| `primaryKeys` | `array<string,string>` | `[]` | Map of table → PK column name (used by the frontend for node labels) |
| `prefixes` | `array<string,string>` | `[]` | Map of table → prefix that the frontend strips for display (e.g. `vps_hostname` → `hostname`) |
| `hiddenFields` | `string[]` | `[]` | Columns the frontend must never render |
| `pivotTable` | `string` | `''` | If set, only keeps tables within 2 hops of `my.{pivotTable}` |
| `pivotId` | `int` | `0` | Echoed into metadata for the frontend's breadcrumb |

</details>

<details>
<summary><b>Response shape</b> (click to expand)</summary>

```php
[
    'custid' => 12345,
    'tables' => [
        'my.accounts' => [
            'rows'      => [[...]],
            'columns'   => ['account_id', ...],
            'total'     => 1,
            'truncated' => false
        ],
        // ...
    ],
    'relationships' => [
        [
            'source'       => 'my.accounts',
            'source_field' => 'account_id',
            'target'       => 'my.vps',
            'target_field' => 'vps_custid',
            'type'         => 'direct',
            'cardinality'  => '1:N',
            'label'        => 'Account → VPS',
            'matches'      => [[0, [0, 1, 2]]]
        ],
    ],
    'metadata' => [
        'databases'          => ['my', 'kayako_v4', 'pdns'],
        'table_count'        => 14,
        'total_rows'         => 42,
        'relationship_count' => 9,
        'query_time_ms'      => 127.4,
        'custid'             => 12345,
        'pivot_table'        => null,
        'pivot_id'           => null
    ],
    'prefixes'     => [...],
    'primaryKeys'  => [...],
    'hiddenFields' => [...]
]
```

</details>

### `DbRel\Data\DbInterface`

A 5-method contract your database wrapper must satisfy.

```php
namespace DbRel\Data;

interface DbInterface
{
    public function query($sql, $line = 0, $file = '');
    public function next_record($mode = 1);        // 1 = associative
    public function num_rows();
    public function real_escape($value);
    public function getLastInsertId($table = '', $column = '');
}
```

If you use MyAdmin's `\MyDb\Mysqli\Db`, it already matches — no adapter needed. For other stacks see [Adapting Your Database Layer](#-adapting-your-database-layer).

## Relationship Types

The schema supports several `type` values. Internally the matcher collapses them down to three behaviors.

<div align="center">

| Schema type | Computed as | Behavior |
| :-- | :-: | :-- |
| `direct` | `direct` | Exact string match between `source_field` and `target_field` |
| `fk_constraint` | `direct` | Same as `direct` — source was an explicit FK in `information_schema` |
| `implicit_fk` | `direct` | Same as `direct` — discovered from code rather than DB constraints |
| `code_join` | `direct` | Same as `direct` — found in a `JOIN` in application code |
| `find_in_set` | `find_in_set` | Source field is a CSV; split on `,` and match each piece |
| `cross_db` | `cross_db` | Exact match, but the source and target live in different logical DBs |
| `polymorphic` | — | Skipped (the `target_table` is parenthesized, e.g. `(vps|websites)`) |
| `conditional` | `direct` | Treated as direct; add a `notes` field for humans |

</div>

### `direct`

```json
{
  "source_db": "my", "source_table": "vps",       "source_field": "vps_custid",
  "target_db": "my", "target_table": "accounts",  "target_field": "account_id",
  "type": "direct"
}
```

Classic FK join. Cheap, O(N&times;M) with early-exit on null/zero/empty source values.

### `find_in_set`

```json
{
  "source_db": "my", "source_table": "vps_groups", "source_field": "vps_group_hosts",
  "target_db": "my", "target_table": "vps",        "target_field": "vps_id",
  "type": "find_in_set"
}
```

Source column is CSV. The matcher splits it on `,`, trims each token, and checks each target row's value against the set.

### `cross_db`

```json
{
  "source_db": "my",        "source_table": "accounts", "source_field": "account_id",
  "target_db": "kayako_v4", "target_table": "swusers",  "target_field": "externalid",
  "type": "cross_db"
}
```

Same matching logic as `direct`, but the frontend draws the edge in a different style so you can see DB boundaries at a glance.

## Integration Example

Drop-in AJAX endpoint using a procedural entry point:

```php
<?php
// public/api/db-relationships.php

require __DIR__ . '/../../vendor/autoload.php';

use DbRel\Data\RelationshipSchema;
use DbRel\Data\DataCollector;
use DbRel\Data\DataProvider;

header('Content-Type: application/json');

$custid = (int) ($_GET['custid'] ?? 0);
if ($custid <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'custid required']);
    exit;
}

$pivotTable = preg_replace('/[^a-z_]/i', '', $_GET['pivot_table'] ?? '');
$pivotId    = (int) ($_GET['pivot_id'] ?? 0);

try {
    $schema = new RelationshipSchema(__DIR__ . '/../../config/db_relationships.json');

    $db = new MyDb\Mysqli\Db();
    $db->connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    $collector = new DataCollector();

    // Core tables
    $collector->collect($db, 'my', 'accounts',
        "SELECT * FROM accounts WHERE account_id = {$custid}", 1);

    $tables = ['vps', 'domains', 'websites', 'backups', 'licenses',
               'scrub_ips', 'floating_ips', 'mail'];
    foreach ($tables as $t) {
        $col = "{$t}_custid";
        // Probe the column's real prefix from schema.modules if needed.
        $collector->collect($db, 'my', $t,
            "SELECT * FROM {$t} WHERE {$col} = {$custid}", 100);
    }

    // Billing
    $collector->collect($db, 'my', 'invoices_charges',
        "SELECT * FROM invoices_charges WHERE invoice_custid = {$custid}", 100);

    // Helpdesk (cross-DB)
    $helpdesk = new MyDb\Mysqli\Db();
    $helpdesk->connect(KAYAKO_HOST, KAYAKO_USER, KAYAKO_PASS, 'kayako_v4');
    $collector->collect($helpdesk, 'kayako_v4', 'swusers',
        "SELECT * FROM swusers WHERE externalid = {$custid}", 5);

    $provider = new DataProvider($schema);
    echo json_encode($provider->build($collector, [
        'custid'      => $custid,
        'pivotTable'  => $pivotTable,
        'pivotId'     => $pivotId,
        'primaryKeys' => ['accounts' => 'account_id', 'vps' => 'vps_id', /* ... */],
        'prefixes'    => ['accounts' => 'account_',   'vps' => 'vps_',   /* ... */],
        'hiddenFields'=> ['password', 'api_token'],
    ]));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```

Point `dbrel-viz`'s `ajaxUrl` at this script and you have a working, swappable-renderer database explorer.

## Adapting Your Database Layer

You only need to implement the 5-method `DbInterface`. Example wrapping PDO:

```php
<?php

use DbRel\Data\DbInterface;

class PdoAdapter implements DbInterface
{
    /** @var \PDO */
    private $pdo;
    /** @var \PDOStatement|null */
    private $stmt;
    /** @var int */
    private $rowCount = 0;

    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function query($sql, $line = 0, $file = '')
    {
        $this->stmt = $this->pdo->query($sql);
        if ($this->stmt === false) {
            throw new \RuntimeException("Query failed at {$file}:{$line}");
        }
        $this->rowCount = $this->stmt->rowCount();
        return $this->stmt;
    }

    public function next_record($mode = 1)
    {
        if (!$this->stmt) return false;
        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return false;
        // DbInterface expects rows on a `Record` property OR via a `getRecord()` method
        $this->Record = $row;
        return true;
    }

    public function num_rows()            { return $this->rowCount; }
    public function real_escape($value)   { return substr($this->pdo->quote($value), 1, -1); }
    public function getLastInsertId($t = '', $c = '') { return (int) $this->pdo->lastInsertId(); }

    /** @var array */
    public $Record = [];
}
```

Collectors expect `DbInterface::next_record()` to leave the current row on a `$db->Record` public property — this matches the MyAdmin and PHPLIB tradition. If your wrapper returns rows differently, either set `$this->Record` in `next_record()` (as above) or expose `getRecord(): array` which `DataCollector` will call via `method_exists()`.

## Architecture

```text
┌──────────────────────────────────────────────────────────────────────┐
│                            Your application                          │
└──────────────────────────┬───────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│                        DataProvider::build()                         │
│                                                                      │
│    ┌─────────────────────┐   ┌─────────────────────────────────┐     │
│    │ RelationshipSchema  │   │ DataCollector                   │     │
│    │                     │   │                                 │     │
│    │ • Reads JSON        │   │ • collect() runs your SELECTs   │     │
│    │ • Normalizes rules  │   │ • addTable() for virtual tables │     │
│    │ • Skips polymorphic │   │ • appendRows()                  │     │
│    │                     │   │                                 │     │
│    │ getRules() ─────────┼───┼──▶ $tables                      │     │
│    └─────────────────────┘   └─────────────────────────────────┘     │
│                                                                      │
│                              │                                       │
│                              ▼                                       │
│                  ┌─────────────────────────────┐                     │
│                  │ RelationshipMatcher         │                     │
│                  │                             │                     │
│                  │ direct:      strict eq      │                     │
│                  │ find_in_set: CSV explode    │                     │
│                  │ cross_db:    eq + flag      │                     │
│                  └─────────────────────────────┘                     │
│                              │                                       │
│                              ▼                                       │
│                   Pivot filter (optional)                            │
│                              │                                       │
│                              ▼                                       │
│                     JSON payload out                                 │
└──────────────────────────────────────────────────────────────────────┘
```

## Companion Packages

This package is half of a two-package system:

| Package | Language | Purpose |
| :-- | :-- | :-- |
| [`@detain/dbrel-viz`](../dbrel-viz) | Browser JS | Frontend library — 20 pluggable renderers |
| [`detain/dbrel-data-php`](./) | PHP &geq; 7.4 | **This package** — the backend |
| [`@detain/dbrel-data-js`](../dbrel-data-js) | Node &geq; 14 | Same API, for Node/Express stacks |

**Payload parity** — `dbrel-data-php` and `dbrel-data-js` emit byte-identical JSON given the same schema and data. Swap backends freely; the frontend won't notice.

## Requirements

- **PHP** &geq; 7.4 (tested on 7.4, 8.0, 8.1, 8.2, 8.3)
- **ext-mysqli** (for the default `DbInterface` shape — or adapt to PDO, see above)
- **ext-json**
- **MySQL** or **MariaDB** — any version supporting the queries you write

## Contributing

```bash
git clone https://github.com/detain/dbrel-data-php.git
cd dbrel-data-php
composer install
composer test
```

### PR guidelines

1. One feature or fix per PR
2. New relationship types go on `RelationshipMatcher`; schema format is frozen
3. All public APIs must carry PHPDoc
4. PHPUnit tests for any behavior change
5. PSR-12 code style

### Ideas we'd love help with

- **PostgreSQL adapter** — the matcher is DB-agnostic, but we don't ship adapters yet
- **Schema generator** — scan `information_schema` and build the JSON automatically
- **Caching layer** — the matcher is deterministic, so `compute()` results can be cached
- **Symfony / Laravel integration packages**

## License

[MIT](./LICENSE) © 2025 [Joe Huss](mailto:detain@interserver.net) / InterServer

---

<div align="center">

**[⬆ back to top](#dbrel-data-php)**

Made with care by [InterServer](https://www.interserver.net). Pair with [dbrel-viz](../dbrel-viz) and [dbrel-data-js](../dbrel-data-js).

</div>
