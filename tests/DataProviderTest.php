<?php
/**
 * @package DbRel\Data\Tests
 */

namespace DbRel\Data\Tests;

use DbRel\Data\DataCollector;
use DbRel\Data\DataProvider;
use DbRel\Data\RelationshipSchema;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    /** @var RelationshipSchema */
    private $schema;

    protected function setUp(): void
    {
        $this->schema = new RelationshipSchema(__DIR__ . '/fixtures/sample_schema.json');
    }

    // ------------------------------------------------------------------
    // build() output shape
    // ------------------------------------------------------------------

    public function testBuildReturnsArrayWithExpectedTopLevelKeys(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 1]], ['account_id']);

        $out = $provider->build($collector, ['custid' => 1]);

        foreach (['custid', 'tables', 'relationships', 'metadata', 'prefixes', 'primaryKeys', 'hiddenFields'] as $k) {
            $this->assertArrayHasKey($k, $out, "missing top-level key: $k");
        }
    }

    public function testBuildPassesThroughCustidAndOptions(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 42]], ['account_id']);

        $primaryKeys = ['accounts' => 'account_id', 'vps' => 'vps_id'];
        $prefixes = ['accounts' => 'account_', 'vps' => 'vps_'];
        $hidden = ['password', 'internal_notes'];

        $out = $provider->build($collector, [
            'custid' => 42,
            'primaryKeys' => $primaryKeys,
            'prefixes' => $prefixes,
            'hiddenFields' => $hidden,
        ]);

        $this->assertSame(42, $out['custid']);
        $this->assertSame($primaryKeys, $out['primaryKeys']);
        $this->assertSame($prefixes, $out['prefixes']);
        $this->assertSame($hidden, $out['hiddenFields']);
    }

    public function testBuildDefaultsWhenOptionsMissing(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 1]], ['account_id']);

        $out = $provider->build($collector);

        $this->assertSame(0, $out['custid']);
        $this->assertSame([], $out['primaryKeys']);
        $this->assertSame([], $out['prefixes']);
        $this->assertSame([], $out['hiddenFields']);
        $this->assertNull($out['metadata']['pivot_table']);
        $this->assertNull($out['metadata']['pivot_id']);
    }

    public function testBuildMetadataCountsTablesRowsAndRelationships(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 10]], ['account_id'], 1);
        $collector->addTable('my', 'vps',
            [['vps_id' => 1, 'vps_custid' => 10], ['vps_id' => 2, 'vps_custid' => 10]],
            ['vps_id', 'vps_custid'], 2);

        $out = $provider->build($collector, ['custid' => 10]);

        $this->assertSame(2, $out['metadata']['table_count']);
        $this->assertSame(3, $out['metadata']['total_rows'], '1 + 2 rows across both tables');
        $this->assertGreaterThanOrEqual(1, $out['metadata']['relationship_count'],
            'vps→accounts rule should match');
        $this->assertIsFloat($out['metadata']['query_time_ms']);
        $this->assertGreaterThanOrEqual(0.0, $out['metadata']['query_time_ms']);
    }

    public function testBuildIncludesRelationshipsComputedFromMatcher(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 10]], ['account_id']);
        $collector->addTable('my', 'vps', [['vps_id' => 1, 'vps_custid' => 10]], ['vps_id', 'vps_custid']);

        $out = $provider->build($collector, ['custid' => 10]);
        $rels = $out['relationships'];
        $this->assertNotEmpty($rels);
        $this->assertSame('my.vps', $rels[0]['source']);
        $this->assertSame('my.accounts', $rels[0]['target']);
        $this->assertNotEmpty($rels[0]['matches']);
    }

    // ------------------------------------------------------------------
    // Pivot filtering
    // ------------------------------------------------------------------

    public function testPivotFilteringKeepsConnectedTables(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 10]], ['account_id']);
        $collector->addTable('my', 'vps', [['vps_id' => 1, 'vps_custid' => 10, 'vps_server' => 5]],
            ['vps_id', 'vps_custid', 'vps_server']);
        $collector->addTable('my', 'servers', [['server_id' => 5]], ['server_id']);
        // Add an unrelated table - should be filtered out when pivoting on vps
        $collector->addTable('my', 'unrelated', [['id' => 1]], ['id']);

        $out = $provider->build($collector, [
            'custid' => 10,
            'pivotTable' => 'vps',
            'pivotId' => 1,
        ]);

        $this->assertArrayHasKey('my.vps', $out['tables'], 'pivot table must be present');
        // accounts is always kept as context
        $this->assertArrayHasKey('my.accounts', $out['tables'], 'accounts kept for context');
        // directly connected table (via vps->servers rule) kept
        $this->assertArrayHasKey('my.servers', $out['tables']);
        // unrelated table should be filtered out
        $this->assertArrayNotHasKey('my.unrelated', $out['tables'],
            'unrelated table should be filtered out when pivoting on vps');
    }

    public function testPivotFilteringIncludesMetadataForPivot(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'vps', [['vps_id' => 1]], ['vps_id']);

        $out = $provider->build($collector, [
            'custid' => 10,
            'pivotTable' => 'vps',
            'pivotId' => 1,
        ]);

        $this->assertSame('vps', $out['metadata']['pivot_table']);
        $this->assertSame(1, $out['metadata']['pivot_id']);
    }

    public function testPivotFilteringIsSkippedWhenPivotTableNotCollected(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 10]], ['account_id']);
        // No 'my.nonexistent' table collected
        $out = $provider->build($collector, [
            'custid' => 10,
            'pivotTable' => 'nonexistent',
            'pivotId' => 5,
        ]);
        // All tables should remain
        $this->assertArrayHasKey('my.accounts', $out['tables']);
    }

    public function testPivotFilteringIsSkippedWhenPivotIdIsZero(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 10]], ['account_id']);
        $collector->addTable('my', 'unrelated', [['id' => 1]], ['id']);
        $out = $provider->build($collector, [
            'custid' => 10,
            'pivotTable' => 'vps',
            'pivotId' => 0,
        ]);
        $this->assertCount(2, $out['tables'], 'no pivot filter applied with pivotId=0');
    }

    public function testPivotFilteringAlwaysKeepsAccounts(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $collector->addTable('my', 'accounts', [['account_id' => 10]], ['account_id']);
        $collector->addTable('my', 'servers', [['server_id' => 5]], ['server_id']);
        $collector->addTable('my', 'isolated_pivot', [['isolated_id' => 1]], ['isolated_id']);

        $out = $provider->build($collector, [
            'custid' => 10,
            'pivotTable' => 'isolated_pivot',
            'pivotId' => 1,
        ]);

        // accounts should survive the filter even though it has no direct rule to isolated_pivot
        $this->assertArrayHasKey('my.accounts', $out['tables'],
            'accounts must always be kept for context');
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function testGetSchemaReturnsTheSchema(): void
    {
        $provider = new DataProvider($this->schema);
        $this->assertSame($this->schema, $provider->getSchema());
    }

    public function testGetMatcherReturnsDefaultMatcherWhenNotInjected(): void
    {
        $provider = new DataProvider($this->schema);
        $this->assertInstanceOf(\DbRel\Data\RelationshipMatcher::class, $provider->getMatcher());
    }

    public function testCustomMatcherCanBeInjected(): void
    {
        $custom = new \DbRel\Data\RelationshipMatcher();
        $provider = new DataProvider($this->schema, $custom);
        $this->assertSame($custom, $provider->getMatcher());
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testBuildWithEmptyCollector(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $out = $provider->build($collector, ['custid' => 0]);

        $this->assertSame([], $out['tables']);
        $this->assertSame([], $out['relationships']);
        $this->assertSame(0, $out['metadata']['table_count']);
        $this->assertSame(0, $out['metadata']['total_rows']);
    }

    public function testBuildReturnsDatabasesFromSchemaMetadata(): void
    {
        $provider = new DataProvider($this->schema);
        $collector = new DataCollector();
        $out = $provider->build($collector, ['custid' => 0]);
        $this->assertIsArray($out['metadata']['databases']);
        // sample schema lists my, kayako_v4, pdns
        $this->assertContains('my', $out['metadata']['databases']);
    }
}
