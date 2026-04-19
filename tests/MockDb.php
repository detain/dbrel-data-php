<?php
/**
 * In-memory implementation of DbInterface for testing.
 *
 * Seed the mock with per-query result sets via setResult($sql, $rows).
 * When query() is called, it matches the first seeded entry whose SQL matches
 * (exact string compare) and makes those rows available to next_record() /
 * num_rows(). If no entry is seeded, an empty result set is returned.
 *
 * The DbInterface::next_record() contract sets $this->Record to the current row
 * and returns true while rows are available, returning false when exhausted —
 * mirroring the MyDb\Mysqli\Db iteration pattern used by DataCollector.
 *
 * @package DbRel\Data\Tests
 */

namespace DbRel\Data\Tests;

use DbRel\Data\DbInterface;

class MockDb implements DbInterface
{
    /** @var array<string,array<int,array<string,mixed>>> */
    private $results = [];

    /** @var array<int,array<string,mixed>> current row set */
    private $currentRows = [];

    /** @var int current cursor position */
    private $cursor = 0;

    /** @var int last inserted id */
    private $lastInsertId = 0;

    /** @var array<int,string> log of queries run (useful in assertions) */
    public $queryLog = [];

    /**
     * Public record property — mirrors \MyDb\Mysqli\Db::$Record accessed by DataCollector.
     * @var array<string,mixed>|null
     */
    public $Record = null;

    /**
     * Seed a result set for an exact SQL string.
     *
     * @param string $sql
     * @param array  $rows
     */
    public function setResult($sql, array $rows)
    {
        $this->results[$sql] = $rows;
    }

    /**
     * Convenience: seed a result that will be returned no matter which SQL is passed.
     * Useful for tests that don't care about the exact query string.
     */
    public function setDefaultResult(array $rows)
    {
        $this->results['__default__'] = $rows;
    }

    public function query($sql, $line = 0, $file = '')
    {
        $this->queryLog[] = $sql;
        if (array_key_exists($sql, $this->results)) {
            $this->currentRows = $this->results[$sql];
        } elseif (array_key_exists('__default__', $this->results)) {
            $this->currentRows = $this->results['__default__'];
        } else {
            $this->currentRows = [];
        }
        $this->cursor = 0;
        $this->Record = null;
        return true;
    }

    public function next_record($mode = 1)
    {
        if ($this->cursor >= count($this->currentRows)) {
            $this->Record = null;
            return false;
        }
        $this->Record = $this->currentRows[$this->cursor];
        $this->cursor++;
        return true;
    }

    public function num_rows()
    {
        return count($this->currentRows);
    }

    public function real_escape($value)
    {
        // Cheap escape for testing — production should use mysqli_real_escape_string.
        return str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value);
    }

    public function getLastInsertId($table = '', $column = '')
    {
        return $this->lastInsertId;
    }

    /**
     * Test-only helper to set the last insert id.
     */
    public function setLastInsertId($id)
    {
        $this->lastInsertId = (int)$id;
    }
}
