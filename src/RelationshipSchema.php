<?php
/**
 * Loads and normalizes the relationship schema from a JSON file.
 *
 * The JSON file defines:
 *  - modules: service module metadata (table, prefix, title)
 *  - relationships: array of relationships between tables
 *  - table_to_module: lookup map
 *  - virtual_tables: tables synthesized from pivoted data (e.g. accounts_ext)
 *
 * @package DbRel\Data
 */

namespace DbRel\Data;

class RelationshipSchema
{
    /** @var array */
    private $data;

    /** @var array normalized relationship rules */
    private $rules = [];

    /**
     * @param string|array $jsonPathOrArray Either a path to the JSON file, or a pre-parsed array.
     * @throws \InvalidArgumentException If the file cannot be read or parsed.
     */
    public function __construct($jsonPathOrArray)
    {
        if (is_array($jsonPathOrArray)) {
            $this->data = $jsonPathOrArray;
        } elseif (is_string($jsonPathOrArray)) {
            if (!is_file($jsonPathOrArray)) {
                throw new \InvalidArgumentException("Schema file not found: $jsonPathOrArray");
            }
            $raw = file_get_contents($jsonPathOrArray);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException("Invalid JSON in schema: $jsonPathOrArray");
            }
            $this->data = $decoded;
        } else {
            throw new \InvalidArgumentException('Expected string path or array');
        }
        $this->normalizeRules();
    }

    /**
     * Normalize the relationship list for the matcher:
     *  - skip polymorphic rules with abstract targets like "(table1|table2)"
     *  - collapse type variants (code_join, fk_constraint, etc.) to 'direct'
     *  - keep find_in_set and cross_db as-is
     */
    private function normalizeRules()
    {
        $rels = isset($this->data['relationships']) ? $this->data['relationships'] : [];
        foreach ($rels as $r) {
            if (!isset($r['target_table']) || strpos($r['target_table'], '(') === 0) {
                continue; // skip polymorphic placeholders
            }
            $type = isset($r['type']) ? $r['type'] : 'direct';
            if ($type !== 'find_in_set' && $type !== 'cross_db') {
                $type = 'direct';
            }
            $this->rules[] = [
                'source_db' => $r['source_db'],
                'source_table' => $r['source_table'],
                'source_field' => $r['source_field'],
                'target_db' => $r['target_db'],
                'target_table' => $r['target_table'],
                'target_field' => $r['target_field'],
                'type' => $type,
                'cardinality' => isset($r['cardinality']) ? $r['cardinality'] : '1:N',
                'label' => isset($r['label']) ? $r['label'] : '',
            ];
        }
    }

    /** @return array Normalized relationship rules */
    public function getRules()
    {
        return $this->rules;
    }

    /** @return array Module definitions keyed by module name */
    public function getModules()
    {
        return isset($this->data['modules']) ? $this->data['modules'] : [];
    }

    /** @return array Table-to-module lookup */
    public function getTableToModule()
    {
        return isset($this->data['table_to_module']) ? $this->data['table_to_module'] : [];
    }

    /** @return array Virtual table definitions (e.g. accounts_ext pivot) */
    public function getVirtualTables()
    {
        return isset($this->data['virtual_tables']) ? $this->data['virtual_tables'] : [];
    }

    /** @return array Metadata block from the JSON */
    public function getMetadata()
    {
        return isset($this->data['_metadata']) ? $this->data['_metadata'] : [];
    }

    /** @return array Raw schema data */
    public function getRaw()
    {
        return $this->data;
    }
}
