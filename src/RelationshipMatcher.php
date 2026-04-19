<?php
/**
 * Computes which rows in which tables match the relationship rules.
 *
 * Given collected table data and a set of relationship rules, outputs
 * active relationships with row-level match arrays for the visualization.
 *
 * @package DbRel\Data
 */

namespace DbRel\Data;

class RelationshipMatcher
{
    /**
     * Compute active relationships from collected data.
     *
     * @param array $tablesData  [ 'db.table' => ['rows' => [...], 'total' => N, 'columns' => [...]] ]
     * @param array $rules       Relationship rules from RelationshipSchema::getRules()
     * @return array Active relationships: each has source, target, source_field, target_field, type, cardinality, label, matches
     *               matches: [ [sourceRowIdx, [targetRowIdx, ...]], ... ]
     */
    public function compute(array $tablesData, array $rules)
    {
        $active = [];

        foreach ($rules as $rule) {
            $sourceKey = $rule['source_db'] . '.' . $rule['source_table'];
            $targetKey = $rule['target_db'] . '.' . $rule['target_table'];

            if (!isset($tablesData[$sourceKey]) || !isset($tablesData[$targetKey])) {
                continue;
            }
            $sourceRows = $tablesData[$sourceKey]['rows'];
            $targetRows = $tablesData[$targetKey]['rows'];

            $matches = [];

            if ($rule['type'] === 'find_in_set') {
                // Source field contains CSV of target IDs
                foreach ($sourceRows as $si => $srow) {
                    $csv = isset($srow[$rule['source_field']]) ? $srow[$rule['source_field']] : '';
                    if ($csv === null || $csv === '') continue;
                    $ids = array_map('trim', explode(',', (string)$csv));
                    $matchedTargets = [];
                    foreach ($targetRows as $ti => $trow) {
                        $tval = isset($trow[$rule['target_field']]) ? (string)$trow[$rule['target_field']] : '';
                        if ($tval !== '' && in_array($tval, $ids, true)) {
                            $matchedTargets[] = $ti;
                        }
                    }
                    if (!empty($matchedTargets)) {
                        $matches[] = [$si, $matchedTargets];
                    }
                }
            } else {
                // direct and cross_db: exact field match
                foreach ($sourceRows as $si => $srow) {
                    $sval = isset($srow[$rule['source_field']]) ? $srow[$rule['source_field']] : null;
                    if ($sval === null || $sval === '' || $sval === '0') continue;
                    $matchedTargets = [];
                    foreach ($targetRows as $ti => $trow) {
                        $tval = isset($trow[$rule['target_field']]) ? $trow[$rule['target_field']] : null;
                        if ((string)$sval === (string)$tval) {
                            $matchedTargets[] = $ti;
                        }
                    }
                    if (!empty($matchedTargets)) {
                        $matches[] = [$si, $matchedTargets];
                    }
                }
            }

            if (!empty($matches)) {
                $active[] = [
                    'source' => $sourceKey,
                    'source_field' => $rule['source_field'],
                    'target' => $targetKey,
                    'target_field' => $rule['target_field'],
                    'type' => $rule['type'],
                    'cardinality' => $rule['cardinality'],
                    'label' => $rule['label'],
                    'matches' => $matches,
                ];
            }
        }

        return $active;
    }
}
