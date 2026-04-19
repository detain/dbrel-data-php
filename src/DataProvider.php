<?php
/**
 * Main entry point for building the visualization payload.
 *
 * Given a configured RelationshipSchema, a DataCollector with collected rows,
 * and a set of table prefixes / primary keys / hidden fields, produces the
 * JSON response consumed by the @detain/dbrel-viz JS library.
 *
 * ## Example usage
 *
 * ```php
 * use DbRel\Data\RelationshipSchema;
 * use DbRel\Data\DataCollector;
 * use DbRel\Data\DataProvider;
 *
 * $schema = new RelationshipSchema(__DIR__ . '/config/db_relationships.json');
 * $collector = new DataCollector();
 *
 * // Collect rows - use the $db handle your project provides.
 * $collector->collect($db, 'my', 'accounts',
 *     "SELECT * FROM accounts WHERE account_id = {$custid}", 1);
 * $collector->collect($db, 'my', 'vps',
 *     "SELECT * FROM vps WHERE vps_custid = {$custid}", 50);
 * // ... more collect() calls as needed ...
 *
 * $provider = new DataProvider($schema);
 * $response = $provider->build($collector, [
 *     'custid' => $custid,
 *     'primaryKeys' => [ 'accounts' => 'account_id', 'vps' => 'vps_id', ... ],
 *     'prefixes'    => [ 'accounts' => 'account_', 'vps' => 'vps_', ... ],
 *     'hiddenFields'=> ['location_coords', 'password'],
 *     'pivotTable'  => '', // optional
 *     'pivotId'     => 0,
 * ]);
 * header('Content-Type: application/json');
 * echo json_encode($response);
 * ```
 *
 * @package DbRel\Data
 */

namespace DbRel\Data;

class DataProvider
{
    /** @var RelationshipSchema */
    private $schema;

    /** @var RelationshipMatcher */
    private $matcher;

    /** @var float Start time for perf metric */
    private $startTime;

    public function __construct(RelationshipSchema $schema, ?RelationshipMatcher $matcher = null)
    {
        $this->schema = $schema;
        $this->matcher = $matcher ?: new RelationshipMatcher();
        $this->startTime = microtime(true);
    }

    /**
     * Build the response payload.
     *
     * @param DataCollector $collector Collected table data
     * @param array $options {
     *   @var int    $custid       The customer ID (for metadata)
     *   @var array  $primaryKeys  Map of table name => PK column name
     *   @var array  $prefixes     Map of table name => column prefix (for display)
     *   @var array  $hiddenFields Columns to hide from display
     *   @var string $pivotTable   Optional: pivot table name (filters to connected tables)
     *   @var int    $pivotId      Optional: pivot entity ID
     * }
     * @return array
     */
    public function build(DataCollector $collector, array $options = [])
    {
        $custid = isset($options['custid']) ? intval($options['custid']) : 0;
        $primaryKeys = isset($options['primaryKeys']) ? $options['primaryKeys'] : [];
        $prefixes = isset($options['prefixes']) ? $options['prefixes'] : [];
        $hiddenFields = isset($options['hiddenFields']) ? $options['hiddenFields'] : [];
        $pivotTable = isset($options['pivotTable']) ? trim($options['pivotTable']) : '';
        $pivotId = isset($options['pivotId']) ? intval($options['pivotId']) : 0;

        $tables = $collector->getTables();
        $rules = $this->schema->getRules();

        // Optional pivot filtering: keep only tables within 2 hops of the pivot table
        if (!empty($pivotTable) && $pivotId > 0) {
            $pivotKey = 'my.' . $pivotTable;
            if (isset($tables[$pivotKey])) {
                $tables = $this->filterByPivot($tables, $rules, $pivotKey);
            }
        }

        // Compute relationship matches
        $relationships = $this->matcher->compute($tables, $rules);

        return [
            'custid' => $custid,
            'tables' => $tables,
            'relationships' => $relationships,
            'metadata' => [
                'databases' => array_keys($this->schema->getMetadata()['databases'] ?? ['my' => '', 'kayako_v4' => '', 'pdns' => '']),
                'table_count' => count($tables),
                'total_rows' => $collector->getTotalRows(),
                'relationship_count' => count($relationships),
                'query_time_ms' => round((microtime(true) - $this->startTime) * 1000, 2),
                'custid' => $custid,
                'pivot_table' => $pivotTable ?: null,
                'pivot_id' => $pivotId ?: null,
            ],
            'prefixes' => $prefixes,
            'primaryKeys' => $primaryKeys,
            'hiddenFields' => $hiddenFields,
        ];
    }

    /**
     * Filter tables to only include those within 2 hops of the pivot table.
     * Always keeps 'my.accounts' for context.
     */
    private function filterByPivot(array $tables, array $rules, $pivotKey)
    {
        $connectedTables = [$pivotKey => true];

        // Find direct connections (1 hop)
        foreach ($rules as $rule) {
            $srcKey = $rule['source_db'] . '.' . $rule['source_table'];
            $tgtKey = $rule['target_db'] . '.' . $rule['target_table'];
            if ($srcKey === $pivotKey) $connectedTables[$tgtKey] = true;
            if ($tgtKey === $pivotKey) $connectedTables[$srcKey] = true;
        }

        // Second hop
        $secondHop = [];
        foreach (array_keys($connectedTables) as $ctk) {
            foreach ($rules as $rule) {
                $srcKey = $rule['source_db'] . '.' . $rule['source_table'];
                $tgtKey = $rule['target_db'] . '.' . $rule['target_table'];
                if ($srcKey === $ctk && !isset($connectedTables[$tgtKey])) $secondHop[$tgtKey] = true;
                if ($tgtKey === $ctk && !isset($connectedTables[$srcKey])) $secondHop[$srcKey] = true;
            }
        }
        $connectedTables = array_merge($connectedTables, $secondHop);

        // Filter tables
        $filtered = [];
        foreach ($tables as $key => $td) {
            if (isset($connectedTables[$key])) {
                $filtered[$key] = $td;
            }
        }
        // Always keep accounts for context
        if (isset($tables['my.accounts'])) {
            $filtered['my.accounts'] = $tables['my.accounts'];
        }
        return $filtered;
    }

    /** @return RelationshipSchema */
    public function getSchema()
    {
        return $this->schema;
    }

    /** @return RelationshipMatcher */
    public function getMatcher()
    {
        return $this->matcher;
    }
}
