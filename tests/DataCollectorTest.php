<?php
/**
 * @package DbRel\Data\Tests
 */

namespace DbRel\Data\Tests;

use DbRel\Data\DataCollector;
use PHPUnit\Framework\TestCase;

// Bring in the mock via include so this test suite also works when not run
// under composer autoload (e.g. during early bootstrap).
require_once __DIR__ . '/MockDb.php';

class DataCollectorTest extends TestCase
{
    /** @var DataCollector */
    private $collector;

    /** @var MockDb */
    private $db;

    protected function setUp(): void
    {
        $this->collector = new DataCollector();
        $this->db = new MockDb();
    }

    // ------------------------------------------------------------------
    // collect()
    // ------------------------------------------------------------------

    public function testCollectStoresRowsUnderDbTableKey(): void
    {
        $sql = 'SELECT * FROM accounts';
        $this->db->setResult($sql, [
            ['account_id' => 1, 'email' => 'a@b.com'],
            ['account_id' => 2, 'email' => 'c@d.com'],
        ]);
        $this->collector->collect($this->db, 'my', 'accounts', $sql);

        $tables = $this->collector->getTables();
        $this->assertArrayHasKey('my.accounts', $tables);
        $this->assertCount(2, $tables['my.accounts']['rows']);
        $this->assertSame(2, $tables['my.accounts']['total']);
        $this->assertSame(['account_id', 'email'], $tables['my.accounts']['columns']);
        $this->assertFalse($tables['my.accounts']['truncated']);
    }

    public function testCollectSkipsStoringWhenZeroRows(): void
    {
        $sql = 'SELECT * FROM empty_table';
        $this->db->setResult($sql, []);
        $this->collector->collect($this->db, 'my', 'empty_table', $sql);
        $this->assertArrayNotHasKey('my.empty_table', $this->collector->getTables(),
            'empty result should not add a table entry');
    }

    public function testCollectRespectsLimit(): void
    {
        $sql = 'SELECT * FROM big_table';
        $rows = [];
        for ($i = 1; $i <= 5; $i++) { $rows[] = ['id' => $i]; }
        $this->db->setResult($sql, $rows);
        $this->collector->collect($this->db, 'my', 'big_table', $sql, 3);

        $entry = $this->collector->getTables()['my.big_table'];
        $this->assertCount(3, $entry['rows'], 'should only keep 3 rows');
        $this->assertSame(5, $entry['total'], 'total should reflect all rows');
        $this->assertTrue($entry['truncated']);
    }

    public function testCollectExactLimitIsNotTruncated(): void
    {
        $sql = 'SELECT * FROM exact';
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
        $this->db->setResult($sql, $rows);
        $this->collector->collect($this->db, 'my', 'exact', $sql, 3);
        $this->assertFalse($this->collector->getTables()['my.exact']['truncated']);
    }

    public function testCollectCapturesColumnOrderFromFirstRow(): void
    {
        $sql = 'SELECT * FROM t';
        $this->db->setResult($sql, [
            ['a' => 1, 'b' => 2, 'c' => 3],
            ['a' => 10, 'b' => 20, 'c' => 30],
        ]);
        $this->collector->collect($this->db, 'my', 't', $sql);
        $this->assertSame(['a', 'b', 'c'], $this->collector->getTables()['my.t']['columns']);
    }

    public function testCollectLogsQueryAgainstMockDb(): void
    {
        $sql = 'SELECT id FROM x WHERE y=1';
        $this->db->setResult($sql, [['id' => 1]]);
        $this->collector->collect($this->db, 'my', 'x', $sql);
        $this->assertContains($sql, $this->db->queryLog);
    }

    // ------------------------------------------------------------------
    // addTable()
    // ------------------------------------------------------------------

    public function testAddTableCreatesEntryWithGivenRows(): void
    {
        $rows = [['id' => 1], ['id' => 2]];
        $this->collector->addTable('my', 'v', $rows, ['id']);
        $tables = $this->collector->getTables();
        $this->assertCount(2, $tables['my.v']['rows']);
        $this->assertSame(2, $tables['my.v']['total']);
        $this->assertSame(['id'], $tables['my.v']['columns']);
        $this->assertFalse($tables['my.v']['truncated']);
    }

    public function testAddTableAcceptsExplicitTotal(): void
    {
        $rows = [['id' => 1]];
        $this->collector->addTable('my', 'virt', $rows, ['id'], 99);
        $this->assertSame(99, $this->collector->getTables()['my.virt']['total']);
    }

    public function testAddTableOverwritesExistingEntry(): void
    {
        $this->collector->addTable('my', 't', [['id' => 1]], ['id']);
        $this->collector->addTable('my', 't', [['id' => 5], ['id' => 6]], ['id']);
        $this->assertCount(2, $this->collector->getTables()['my.t']['rows']);
    }

    // ------------------------------------------------------------------
    // appendRows()
    // ------------------------------------------------------------------

    public function testAppendRowsToExistingTableGrowsRowsAndTotal(): void
    {
        $this->collector->addTable('my', 't', [['id' => 1]], ['id']);
        $this->collector->appendRows('my', 't', [['id' => 2], ['id' => 3]]);
        $entry = $this->collector->getTables()['my.t'];
        $this->assertCount(3, $entry['rows']);
        $this->assertSame(3, $entry['total']);
    }

    public function testAppendRowsCreatesNewTableWhenMissing(): void
    {
        $this->collector->appendRows('my', 'new_tbl', [['x' => 1, 'y' => 2]]);
        $tables = $this->collector->getTables();
        $this->assertArrayHasKey('my.new_tbl', $tables);
        $this->assertSame(['x', 'y'], $tables['my.new_tbl']['columns']);
        $this->assertCount(1, $tables['my.new_tbl']['rows']);
    }

    public function testAppendEmptyRowsIsNoOp(): void
    {
        $this->collector->addTable('my', 't', [['id' => 1]], ['id']);
        $this->collector->appendRows('my', 't', []);
        $this->assertCount(1, $this->collector->getTables()['my.t']['rows']);
    }

    // ------------------------------------------------------------------
    // has / getRows / getTotalRows
    // ------------------------------------------------------------------

    public function testHasReturnsTrueForCollectedKey(): void
    {
        $this->collector->addTable('my', 'a', [['id' => 1]], ['id']);
        $this->assertTrue($this->collector->has('my.a'));
        $this->assertFalse($this->collector->has('my.not_there'));
    }

    public function testGetRowsReturnsRowsForKey(): void
    {
        $this->collector->addTable('my', 'a', [['id' => 1]], ['id']);
        $this->assertSame([['id' => 1]], $this->collector->getRows('my.a'));
    }

    public function testGetRowsReturnsEmptyArrayForUnknownKey(): void
    {
        $this->assertSame([], $this->collector->getRows('my.ghost'));
    }

    public function testGetTotalRowsSumsAcrossTables(): void
    {
        $this->collector->addTable('my', 'a', [['id' => 1]], ['id'], 10);
        $this->collector->addTable('my', 'b', [['id' => 2]], ['id'], 25);
        $this->assertSame(35, $this->collector->getTotalRows());
    }

    public function testGetTotalRowsIsZeroWhenNothingCollected(): void
    {
        $this->assertSame(0, $this->collector->getTotalRows());
    }
}
