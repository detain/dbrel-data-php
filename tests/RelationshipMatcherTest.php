<?php
/**
 * @package DbRel\Data\Tests
 */

namespace DbRel\Data\Tests;

use DbRel\Data\RelationshipMatcher;
use PHPUnit\Framework\TestCase;

class RelationshipMatcherTest extends TestCase
{
    /** @var RelationshipMatcher */
    private $matcher;

    protected function setUp(): void
    {
        $this->matcher = new RelationshipMatcher();
    }

    // ------------------------------------------------------------------
    // Direct relationships
    // ------------------------------------------------------------------

    public function testDirectMatchFindsExactIdMatches(): void
    {
        $tables = [
            'my.vps' => [
                'rows' => [
                    ['vps_id' => 1, 'vps_custid' => 100],
                    ['vps_id' => 2, 'vps_custid' => 100],
                    ['vps_id' => 3, 'vps_custid' => 200],
                ],
                'columns' => ['vps_id', 'vps_custid'],
                'total' => 3,
            ],
            'my.accounts' => [
                'rows' => [
                    ['account_id' => 100, 'email' => 'a@b.com'],
                    ['account_id' => 200, 'email' => 'c@d.com'],
                ],
                'columns' => ['account_id', 'email'],
                'total' => 2,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'vps', 'source_field' => 'vps_custid',
            'target_db' => 'my', 'target_table' => 'accounts', 'target_field' => 'account_id',
            'type' => 'direct', 'cardinality' => 'N:1', 'label' => 'VPS to Account',
        ]];
        $out = $this->matcher->compute($tables, $rules);

        $this->assertCount(1, $out, 'one active relationship expected');
        $rel = $out[0];
        $this->assertSame('my.vps', $rel['source']);
        $this->assertSame('my.accounts', $rel['target']);
        $this->assertSame('direct', $rel['type']);
        $this->assertCount(3, $rel['matches'], '3 source rows had matching targets');
        $this->assertSame([0, [0]], $rel['matches'][0]); // row 0 → account idx 0
        $this->assertSame([1, [0]], $rel['matches'][1]);
        $this->assertSame([2, [1]], $rel['matches'][2]);
    }

    public function testDirectMatchCoercesMixedNumericAndStringIds(): void
    {
        $tables = [
            'my.a' => [
                'rows' => [['x' => 42], ['x' => '42']],
                'columns' => ['x'], 'total' => 2,
            ],
            'my.b' => [
                'rows' => [['y' => '42']],
                'columns' => ['y'], 'total' => 1,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => '',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertCount(1, $out);
        $this->assertCount(2, $out[0]['matches'], 'both int 42 and string "42" should match string "42"');
    }

    public function testDirectMatchSkipsNullEmptyAndZeroSourceValues(): void
    {
        $tables = [
            'my.a' => [
                'rows' => [
                    ['x' => null],
                    ['x' => ''],
                    ['x' => '0'],
                    ['x' => 5],
                ],
                'columns' => ['x'], 'total' => 4,
            ],
            'my.b' => [
                'rows' => [['y' => 5], ['y' => null], ['y' => '0']],
                'columns' => ['y'], 'total' => 3,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => '',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertCount(1, $out);
        $this->assertCount(1, $out[0]['matches'], 'only the row with x=5 should produce a match');
        $this->assertSame(3, $out[0]['matches'][0][0], 'source row index should be 3');
        $this->assertSame([0], $out[0]['matches'][0][1], 'target index should be 0 (y=5)');
    }

    public function testDirectMatchOmitsRelationshipEntryWhenNoRowsMatch(): void
    {
        $tables = [
            'my.a' => ['rows' => [['x' => 1]], 'columns' => ['x'], 'total' => 1],
            'my.b' => ['rows' => [['y' => 2]], 'columns' => ['y'], 'total' => 1],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => '',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertSame([], $out);
    }

    public function testRuleSkippedWhenSourceTableMissing(): void
    {
        $tables = [
            'my.b' => ['rows' => [['y' => 1]], 'columns' => ['y'], 'total' => 1],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => '',
        ]];
        $this->assertSame([], $this->matcher->compute($tables, $rules));
    }

    public function testRuleSkippedWhenTargetTableMissing(): void
    {
        $tables = [
            'my.a' => ['rows' => [['x' => 1]], 'columns' => ['x'], 'total' => 1],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => '',
        ]];
        $this->assertSame([], $this->matcher->compute($tables, $rules));
    }

    // ------------------------------------------------------------------
    // find_in_set relationships
    // ------------------------------------------------------------------

    public function testFindInSetExpandsCsvToMultipleTargets(): void
    {
        $tables = [
            'my.servers' => [
                'rows' => [['server_id' => 1, 'server_tags' => '10,20,30']],
                'columns' => ['server_id', 'server_tags'], 'total' => 1,
            ],
            'my.tags' => [
                'rows' => [
                    ['tag_id' => 10], ['tag_id' => 20], ['tag_id' => 30], ['tag_id' => 99],
                ],
                'columns' => ['tag_id'], 'total' => 4,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'servers', 'source_field' => 'server_tags',
            'target_db' => 'my', 'target_table' => 'tags', 'target_field' => 'tag_id',
            'type' => 'find_in_set', 'cardinality' => 'N:M', 'label' => 'Tags',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertCount(1, $out);
        $this->assertSame('find_in_set', $out[0]['type']);
        $this->assertSame([[0, [0, 1, 2]]], $out[0]['matches']);
    }

    public function testFindInSetTrimsWhitespaceAroundCsvValues(): void
    {
        $tables = [
            'my.servers' => [
                'rows' => [['server_id' => 1, 'server_tags' => ' 10 , 20 ']],
                'columns' => ['server_id', 'server_tags'], 'total' => 1,
            ],
            'my.tags' => [
                'rows' => [['tag_id' => 10], ['tag_id' => 20]],
                'columns' => ['tag_id'], 'total' => 2,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'servers', 'source_field' => 'server_tags',
            'target_db' => 'my', 'target_table' => 'tags', 'target_field' => 'tag_id',
            'type' => 'find_in_set', 'cardinality' => 'N:M', 'label' => '',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertSame([[0, [0, 1]]], $out[0]['matches']);
    }

    public function testFindInSetSkipsEmptyAndNullSource(): void
    {
        $tables = [
            'my.servers' => [
                'rows' => [
                    ['tags' => null],
                    ['tags' => ''],
                    ['tags' => '10'],
                ],
                'columns' => ['tags'], 'total' => 3,
            ],
            'my.tags' => [
                'rows' => [['tag_id' => 10]],
                'columns' => ['tag_id'], 'total' => 1,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'servers', 'source_field' => 'tags',
            'target_db' => 'my', 'target_table' => 'tags', 'target_field' => 'tag_id',
            'type' => 'find_in_set', 'cardinality' => 'N:M', 'label' => '',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertSame([[2, [0]]], $out[0]['matches'], 'only non-empty row should match');
    }

    public function testFindInSetEmittsNoEntryIfNoMatches(): void
    {
        $tables = [
            'my.s' => ['rows' => [['tags' => '1,2']], 'columns' => ['tags'], 'total' => 1],
            'my.t' => ['rows' => [['tag_id' => 99]], 'columns' => ['tag_id'], 'total' => 1],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 's', 'source_field' => 'tags',
            'target_db' => 'my', 'target_table' => 't', 'target_field' => 'tag_id',
            'type' => 'find_in_set', 'cardinality' => 'N:M', 'label' => '',
        ]];
        $this->assertSame([], $this->matcher->compute($tables, $rules));
    }

    // ------------------------------------------------------------------
    // cross_db relationships (use direct path internally)
    // ------------------------------------------------------------------

    public function testCrossDbMatchesBetweenDatabases(): void
    {
        $tables = [
            'my.accounts' => [
                'rows' => [['account_id' => 1, 'account_email' => 'test@example.com']],
                'columns' => ['account_id', 'account_email'], 'total' => 1,
            ],
            'kayako_v4.swusers' => [
                'rows' => [
                    ['userid' => 5, 'email' => 'test@example.com'],
                    ['userid' => 6, 'email' => 'other@example.com'],
                ],
                'columns' => ['userid', 'email'], 'total' => 2,
            ],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'accounts', 'source_field' => 'account_email',
            'target_db' => 'kayako_v4', 'target_table' => 'swusers', 'target_field' => 'email',
            'type' => 'cross_db', 'cardinality' => '1:N', 'label' => 'Kayako link',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $this->assertCount(1, $out);
        $this->assertSame('cross_db', $out[0]['type']);
        $this->assertSame([[0, [0]]], $out[0]['matches']);
        $this->assertSame('kayako_v4.swusers', $out[0]['target']);
    }

    // ------------------------------------------------------------------
    // Output structure
    // ------------------------------------------------------------------

    public function testOutputRelationshipContainsAllExpectedKeys(): void
    {
        $tables = [
            'my.a' => ['rows' => [['x' => 1]], 'columns' => ['x'], 'total' => 1],
            'my.b' => ['rows' => [['y' => 1]], 'columns' => ['y'], 'total' => 1],
        ];
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => 'my-label',
        ]];
        $out = $this->matcher->compute($tables, $rules);
        $keys = ['source', 'source_field', 'target', 'target_field',
                 'type', 'cardinality', 'label', 'matches'];
        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $out[0], "missing key: $k");
        }
        $this->assertSame('my-label', $out[0]['label']);
    }

    public function testEmptyRulesProducesEmptyOutput(): void
    {
        $tables = ['my.a' => ['rows' => [['x' => 1]], 'columns' => ['x'], 'total' => 1]];
        $this->assertSame([], $this->matcher->compute($tables, []));
    }

    public function testEmptyTablesProducesEmptyOutput(): void
    {
        $rules = [[
            'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'x',
            'target_db' => 'my', 'target_table' => 'b', 'target_field' => 'y',
            'type' => 'direct', 'cardinality' => '1:1', 'label' => '',
        ]];
        $this->assertSame([], $this->matcher->compute([], $rules));
    }
}
