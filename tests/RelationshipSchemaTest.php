<?php
/**
 * @package DbRel\Data\Tests
 */

namespace DbRel\Data\Tests;

use DbRel\Data\RelationshipSchema;
use PHPUnit\Framework\TestCase;

class RelationshipSchemaTest extends TestCase
{
    /** @var string Path to the fixture schema JSON */
    private $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = __DIR__ . '/fixtures/sample_schema.json';
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    public function testConstructFromFilePath(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $this->assertNotEmpty($schema->getRaw(), 'raw schema should be populated from JSON file');
    }

    public function testConstructFromArrayBypassesFilesystem(): void
    {
        $data = ['relationships' => []];
        $schema = new RelationshipSchema($data);
        $this->assertSame($data, $schema->getRaw());
    }

    public function testConstructThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Schema file not found/');
        new RelationshipSchema('/tmp/this_file_does_not_exist_' . uniqid() . '.json');
    }

    public function testConstructThrowsOnInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dbrel_bad_');
        file_put_contents($tmp, '{ not valid json ');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Invalid JSON/');
            new RelationshipSchema($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testConstructThrowsOnInvalidInputType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument - intentional */
        new RelationshipSchema(12345);
    }

    // ------------------------------------------------------------------
    // Rule normalization
    // ------------------------------------------------------------------

    public function testGetRulesReturnsNormalizedRules(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $rules = $schema->getRules();
        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules, 'fixture should yield at least one rule');
    }

    public function testNormalizationSkipsPolymorphicTargets(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        foreach ($schema->getRules() as $rule) {
            $this->assertStringNotContainsString('(', $rule['target_table'],
                'polymorphic target_table should be skipped during normalization');
        }
    }

    public function testNormalizationCollapsesUnknownTypeToDirect(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        // The fixture has a rule of type 'code_join' — must be normalized to 'direct'.
        $found = null;
        foreach ($schema->getRules() as $rule) {
            if ($rule['source_table'] === 'vps' && $rule['source_field'] === 'vps_server') {
                $found = $rule;
                break;
            }
        }
        $this->assertNotNull($found, 'vps->servers rule should be present in normalized rules');
        $this->assertSame('direct', $found['type'], "'code_join' should be collapsed to 'direct'");
    }

    public function testNormalizationPreservesFindInSet(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $found = null;
        foreach ($schema->getRules() as $rule) {
            if ($rule['type'] === 'find_in_set') { $found = $rule; break; }
        }
        $this->assertNotNull($found, 'find_in_set rule should be preserved');
        $this->assertSame('server_tags', $found['source_field']);
    }

    public function testNormalizationPreservesCrossDb(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $found = null;
        foreach ($schema->getRules() as $rule) {
            if ($rule['type'] === 'cross_db') { $found = $rule; break; }
        }
        $this->assertNotNull($found, 'cross_db rule should be preserved');
        $this->assertSame('kayako_v4', $found['target_db']);
    }

    public function testNormalizationDefaultsCardinalityAndLabelWhenMissing(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        // The "minimal" fixture entry omits cardinality and label
        $found = null;
        foreach ($schema->getRules() as $rule) {
            if ($rule['source_table'] === 'minimal') { $found = $rule; break; }
        }
        $this->assertNotNull($found);
        $this->assertSame('1:N', $found['cardinality'], 'cardinality should default to "1:N"');
        $this->assertSame('', $found['label'], 'label should default to empty string');
    }

    public function testRulesContainAllRequiredFields(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $required = ['source_db', 'source_table', 'source_field',
                     'target_db', 'target_table', 'target_field',
                     'type', 'cardinality', 'label'];
        foreach ($schema->getRules() as $i => $rule) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $rule, "rule $i missing $key");
            }
        }
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function testGetModules(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $modules = $schema->getModules();
        $this->assertArrayHasKey('accounts', $modules);
        $this->assertSame('account_', $modules['accounts']['prefix']);
    }

    public function testGetTableToModule(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $map = $schema->getTableToModule();
        $this->assertSame('vps', $map['vps']);
    }

    public function testGetVirtualTables(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $vt = $schema->getVirtualTables();
        $this->assertArrayHasKey('accounts_ext', $vt);
    }

    public function testGetMetadata(): void
    {
        $schema = new RelationshipSchema($this->fixturePath);
        $md = $schema->getMetadata();
        $this->assertSame('1.0.0', $md['version']);
        $this->assertArrayHasKey('databases', $md);
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testEmptyRelationshipsArrayProducesEmptyRules(): void
    {
        $schema = new RelationshipSchema(['relationships' => []]);
        $this->assertSame([], $schema->getRules());
    }

    public function testMissingRelationshipsKeyProducesEmptyRules(): void
    {
        $schema = new RelationshipSchema([]);
        $this->assertSame([], $schema->getRules());
    }

    public function testMissingOptionalAccessorsReturnEmptyArrays(): void
    {
        $schema = new RelationshipSchema([]);
        $this->assertSame([], $schema->getModules());
        $this->assertSame([], $schema->getTableToModule());
        $this->assertSame([], $schema->getVirtualTables());
        $this->assertSame([], $schema->getMetadata());
    }

    public function testRuleWithMissingTargetTableIsSkipped(): void
    {
        // target_table absent -> skipped
        $schema = new RelationshipSchema([
            'relationships' => [
                [
                    'source_db' => 'my', 'source_table' => 'a', 'source_field' => 'id',
                    'target_db' => 'my', 'target_field' => 'id',
                ],
            ],
        ]);
        $this->assertSame([], $schema->getRules());
    }
}
