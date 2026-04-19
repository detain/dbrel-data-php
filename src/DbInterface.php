<?php
/**
 * Database abstraction interface.
 *
 * Implement this to plug any database layer into the DataProvider.
 * The MyAdmin project's \MyDb\Mysqli\Db already matches this shape.
 *
 * @package DbRel\Data
 */

namespace DbRel\Data;

interface DbInterface
{
    /**
     * Execute a query.
     * @param string $sql The SQL query
     * @param int $line Line number for error context
     * @param string $file File path for error context
     * @return mixed
     */
    public function query($sql, $line = 0, $file = '');

    /**
     * Advance to the next record.
     * @param int $mode Fetch mode (MYSQL_ASSOC = 1)
     * @return bool
     */
    public function next_record($mode = 1);

    /**
     * Number of rows in the current result.
     * @return int
     */
    public function num_rows();

    /**
     * Escape a value for use in SQL.
     * @param string $value
     * @return string
     */
    public function real_escape($value);

    /**
     * Get the last inserted ID.
     * @param string $table
     * @param string $column
     * @return int
     */
    public function getLastInsertId($table = '', $column = '');
}
