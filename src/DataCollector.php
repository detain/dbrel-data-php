<?php
/**
 * Collects rows from database tables into a normalized structure.
 *
 * Thin wrapper around a DbInterface that accumulates query results keyed by "db.table"
 * in the format expected by the visualization.
 *
 * @package DbRel\Data
 */

namespace DbRel\Data;

class DataCollector
{
    /** @var array [ 'db.table' => ['rows' => [...], 'total' => N, 'columns' => [...], 'truncated' => bool] ] */
    private $tables = [];

    /**
     * Collect rows from a query into $tables.
     *
     * @param DbInterface $db The database handle to execute the query on
     * @param string $dbName The logical database name (e.g. 'my', 'kayako_v4', 'pdns')
     * @param string $table Table name
     * @param string $sql The SELECT query
     * @param int $limit Max rows to include in output
     */
    public function collect(DbInterface $db, $dbName, $table, $sql, $limit = 50)
    {
        $key = $dbName . '.' . $table;
        $db->query($sql, __LINE__, __FILE__);
        $total = $db->num_rows();
        if ($total == 0) {
            return;
        }
        $rows = [];
        $columns = [];
        $count = 0;
        while ($db->next_record(1)) {
            $record = property_exists($db, 'Record') ? $db->Record : null;
            if ($record === null && method_exists($db, 'getRecord')) {
                $record = $db->getRecord();
            }
            if (!is_array($record)) continue;
            if (empty($columns)) {
                $columns = array_keys($record);
            }
            if ($count < $limit) {
                $rows[] = $record;
            }
            $count++;
        }
        $this->tables[$key] = [
            'rows' => $rows,
            'total' => $total,
            'columns' => $columns,
            'truncated' => $total > $limit,
        ];
    }

    /**
     * Manually add a table entry (useful for pivot tables like accounts_ext).
     *
     * @param string $dbName
     * @param string $table
     * @param array $rows
     * @param array $columns
     * @param int|null $total
     */
    public function addTable($dbName, $table, array $rows, array $columns, $total = null)
    {
        $key = $dbName . '.' . $table;
        $this->tables[$key] = [
            'rows' => $rows,
            'total' => $total !== null ? $total : count($rows),
            'columns' => $columns,
            'truncated' => false,
        ];
    }

    /**
     * Append rows to an existing table (or create it if missing).
     *
     * @param string $dbName
     * @param string $table
     * @param array $rows
     */
    public function appendRows($dbName, $table, array $rows)
    {
        $key = $dbName . '.' . $table;
        if (!isset($this->tables[$key])) {
            $columns = !empty($rows) ? array_keys($rows[0]) : [];
            $this->addTable($dbName, $table, $rows, $columns);
        } else {
            foreach ($rows as $row) {
                $this->tables[$key]['rows'][] = $row;
                $this->tables[$key]['total']++;
            }
        }
    }

    /** @return array All collected tables */
    public function getTables()
    {
        return $this->tables;
    }

    /**
     * Check if a given "db.table" key has been collected.
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return isset($this->tables[$key]);
    }

    /**
     * Get rows for a collected table.
     * @param string $key "db.table" key
     * @return array
     */
    public function getRows($key)
    {
        return isset($this->tables[$key]) ? $this->tables[$key]['rows'] : [];
    }

    /** Total row count across all tables */
    public function getTotalRows()
    {
        $n = 0;
        foreach ($this->tables as $t) {
            $n += $t['total'];
        }
        return $n;
    }
}
