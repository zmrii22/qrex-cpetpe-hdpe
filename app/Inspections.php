<?php
declare(strict_types=1);

final class Inspections {
    private const FACTORY_PLANTS = ['M26A' => '3726', 'M26B' => '6726', 'F05B' => '3705', 'M26' => '3726'];
    private const PRODUCT_FACTORY_PLANTS = ['hdpe' => ['M26' => '6726']];
    private const QAPQC_LOOKUP_PLANTS = ['3726', '6726'];
    private static array $parameterCache = [];

    private static function type(string $product): string {
        demand(in_array($product, ['hdpe','cpetpe'], true), 'Choose a valid inspection type.');
        return $product === 'cpetpe' ? 'CPETPE' : 'HDPE';
    }

    private static function product(string $type): string {
        return $type === 'CPETPE' ? 'cpetpe' : 'hdpe';
    }

    private static function productFromSource(array $source): string {
        $description=strtoupper(trim((string)($source['gtype_desc'] ?? $source['source']['gtype_desc'] ?? $source['product_type'] ?? '')));
        if(str_contains($description,'HDPE')) return 'hdpe';
        if(str_contains($description,'CPE') || str_contains($description,'TPE')) return 'cpetpe';
        $text = strtoupper(implode(' ', [
            (string)($source['product_type'] ?? ''),
            (string)($source['gtype'] ?? ''),
            (string)($source['item_code'] ?? ''),
            (string)($source['lot_number'] ?? ''),
        ]));
        return str_contains($text, 'HDPE') || str_starts_with(strtoupper((string)($source['gtype'] ?? '')), 'HD') ? 'hdpe' : 'cpetpe';
    }

    private static function itemTable(string $type): string {
        return $type === 'CPETPE' ? 'T_item_CPETPE' : 'T_item_HDPE';
    }

    private static function recordTable(string $type): string {
        return $type === 'CPETPE' ? 'T_record_CPETPE' : 'T_record_HDPE';
    }

    private static function factoryCode(string $factory): string {
        $factory = strtoupper(trim($factory));
        if (isset(self::FACTORY_PLANTS[$factory])) return $factory;
        if ($factory === '3705') return 'F05B';
        if ($factory === '3726') return 'M26A';
        if (in_array($factory, ['6725','6726'], true)) return 'M26B';
        $code = array_search($factory, self::FACTORY_PLANTS, true);
        demand($code !== false, 'Choose a valid factory / plant.');
        return (string)$code;
    }

    private static function plantNumber(string $factory): string {
        return self::FACTORY_PLANTS[self::factoryCode($factory)];
    }

    private static function lookupPlantNumber(string $product, string $factory): string {
        $factory = self::factoryCode($factory);
        $product = strtolower($product);
        return self::PRODUCT_FACTORY_PLANTS[$product][$factory] ?? self::FACTORY_PLANTS[$factory];
    }

    private static function now(): string {
        return date('Y-m-d H:i:s');
    }

    private static function ordinalInspection(int $n): string {
        $mod100 = $n % 100;
        $suffix = ($mod100 >= 11 && $mod100 <= 13) ? 'th' : match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
        return $n.$suffix.' Inspection';
    }

    private static function normalizeState(array $row): string {
        if ($row['inspection_status'] === 'IN_PROGRESS') return 'draft';
        if ($row['inspection_status'] === 'COMPLETED') return 'submitted';
        $verifications = json_decode($row['verification_json'] ?: '[]', true) ?: [];
        $last = $verifications[0]['decision'] ?? '';
        return in_array($last, ['held','rejected','released'], true) ? $last : 'released';
    }

    private static function normalizeResult(?string $value): string {
        return match (strtoupper((string)$value)) {
            'PASS', 'RELEASED' => 'pass',
            'FAIL', 'REJECTED' => 'fail',
            'HOLD', 'HELD', 'UNASSESSED' => 'hold',
            default => 'pending',
        };
    }

    private static function disposition(string $result): string {
        return match ($result) {
            'pass' => 'PASS',
            'fail' => 'FAIL',
            'hold', 'unassessed' => 'HOLD',
            default => 'PENDING',
        };
    }

    public static function auditItem(string $action, string $id, array $details = []): void {
        foreach (['CPETPE','HDPE'] as $type) {
            $table = self::itemTable($type);
            $row = query("SELECT audit_json FROM $table WHERE id=?", [(int)$id])->fetch();
            if (!$row) continue;
            $audit = json_decode($row['audit_json'] ?: '[]', true) ?: [];
            array_unshift($audit, [
                'actor_id' => $_SESSION['uid'] ?? null,
                'action' => $action,
                'details' => $details,
                'created_at' => self::now(),
            ]);
            query("UPDATE $table SET audit_json=? WHERE id=?", [json_encode(array_slice($audit, 0, 250), JSON_THROW_ON_ERROR), (int)$id]);
            return;
        }
    }

    public static function specification(?string $id): ?array {
        if (!$id) return null;
        $rows = query('SELECT * FROM M_masterlist_specifications WHERE spec_id=? AND active=1 ORDER BY sequence_no', [$id])->fetchAll();
        return self::specificationFromRows($rows);
    }

    private static function specificationFromRows(array $rows): ?array {
        if (!$rows) return null;
        $first = $rows[0];
        $product = self::product($first['inspection_type']);
        $spec = [
            'id' => $first['spec_id'],
            'product' => $product,
            'reference' => $first['reference_name'],
            'reference_name' => $first['reference_name'],
            'product_type' => $first['product_type'],
            'glove_type' => $first['glove_type'],
            'size' => $first['size'],
            'revision' => $first['revision'],
            'source' => $first['source'],
            'active' => (int)$first['active'],
            'limits' => [],
        ];
        foreach ($rows as $row) {
            $spec['limits'][strtolower($row['parameter_code'])] = [
                'min' => $row['min_value'] === null ? null : (float)$row['min_value'],
                'max' => $row['max_value'] === null ? null : (float)$row['max_value'],
                'nominal' => $row['nominal_value'] === null ? null : (float)$row['nominal_value'],
                'na' => (bool)$row['not_applicable'],
                'source' => $row['source'],
            ];
        }
        return self::withoutThicknessLimits($spec);
    }

    private static function withoutThicknessLimits(?array $spec): ?array {
        if ($spec === null) return null;
        $spec['limits']['thickness'] = ['min'=>null,'max'=>null,'nominal'=>null,'na'=>false,'record_only'=>true];
        return $spec;
    }

    private static function specFor(string $product, string $glove, string $size, ?array &$candidateCache=null): ?array {
        $type = self::type($product);
        $exactKey='exact|'.$type.'|'.$glove.'|'.$size;
        if(!array_key_exists($exactKey,$candidateCache??[])) {
            $exact=query('SELECT * FROM M_masterlist_specifications WHERE spec_id=(SELECT spec_id FROM M_masterlist_specifications WHERE inspection_type=? AND size=? AND glove_type=? AND active=1 ORDER BY spec_id LIMIT 1) AND active=1 ORDER BY sequence_no',[$type,$size,$glove])->fetchAll();
            $candidateCache[$exactKey]=self::specificationFromRows($exact) ?: false;
        }
        if($candidateCache[$exactKey]!==false) return $candidateCache[$exactKey];
        $key=$type.'|'.$size;
        $rows=$candidateCache[$key]??=query('SELECT spec_id,glove_type FROM M_masterlist_specifications WHERE inspection_type=? AND size=? AND active=1 GROUP BY spec_id,glove_type ORDER BY spec_id',[$type,$size])->fetchAll();
        $id=null;
        foreach($rows as $row) {
            if((string)$row['glove_type']===$glove) { $id=$row['spec_id'];break; }
        }
        if (!$id) {
            $wanted = self::matchKey($glove);
            foreach ($rows as $row) {
                if (self::matchKey((string)$row['glove_type']) === $wanted) {
                    $id = $row['spec_id'];
                    break;
                }
            }
        }
        return $id ? self::specification((string)$id) : null;
    }

    private static function specForSource(string $product, array $source, ?array &$candidateCache=null, ?array $catalog=null): ?array {
        $catalogOnly=$catalog!==null;
        if($catalog===null) {
            $direct=self::specFor($product,(string)($source['gtype']??''),(string)($source['size']??''),$candidateCache);
            if($direct) return $direct;
            $catalogKey='catalog|'.$product;
            $catalog=$candidateCache[$catalogKey]??=query('SELECT DISTINCT spec_id,glove_type,size FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 ORDER BY spec_id',[self::type($product)])->fetchAll();
        }
        $gloveCandidates = array_values(array_unique(array_filter([
            (string)($source['gtype'] ?? ''),
            self::derivedGloveType($product, $source, $catalog),
        ])));
        $sizeCandidates = array_values(array_unique(array_filter([
            (string)($source['size'] ?? ''),
            self::derivedSize($product, $source, $catalog),
        ])));
        foreach ($gloveCandidates as $glove) {
            foreach ($sizeCandidates as $size) {
                if($catalog!==null) {
                    foreach($catalog as $entry) {
                        if((string)$entry['size']===$size&&self::matchKey((string)$entry['glove_type'])===self::matchKey($glove)) return $catalogOnly ? ['id'=>$entry['spec_id']] : self::specification((string)$entry['spec_id']);
                    }
                    continue;
                }
                $spec = self::specFor($product, $glove, $size, $candidateCache);
                if ($spec) return $spec;
            }
        }
        return null;
    }

    private static function specForInspection(array $inspection, array $data, ?array $catalog=null): ?array {
        $candidateCache=[];
        $chosenSpecId = safe_text($data['fields']['spec_id'] ?? '', 80);
        if ($chosenSpecId !== '') {
            $chosen = self::specification($chosenSpecId);
            if ($chosen && $chosen['product'] === (string)$inspection['product'] && $chosen['active']) return $chosen;
        }
        $common = $inspection['common'] ?? [];
        $gloveCandidates = array_values(array_unique(array_filter([
            (string)($data['fields']['glove_type_code'] ?? ''),
            (string)($common['spec_glove_type'] ?? ''),
            (string)($inspection['glove_type'] ?? ''),
        ])));
        $sizeCandidates = array_values(array_unique(array_filter([
            (string)($common['spec_size'] ?? ''),
            (string)($inspection['size'] ?? ''),
        ])));
        foreach ($gloveCandidates as $glove) {
            foreach ($sizeCandidates as $size) {
                if($catalog!==null) {
                    $exact=null; $normalized=null;
                    foreach($catalog as $entry) {
                        if((string)$entry['size']!==$size) continue;
                        if((string)$entry['glove_type']===$glove) { $exact=$entry['spec_id']; break; }
                        if($normalized===null && self::matchKey((string)$entry['glove_type'])===self::matchKey($glove)) $normalized=$entry['spec_id'];
                    }
                    $id=$exact??$normalized;
                    if($id!==null) return self::specification((string)$id);
                    continue;
                }
                $spec = self::specFor((string)$inspection['product'], $glove, $size, $candidateCache);
                if ($spec) return $spec;
            }
        }
        $derived=self::specForSource((string)$inspection['product'], [
            'gtype' => (string)($data['fields']['glove_type_code'] ?? $inspection['glove_type'] ?? ''),
            'size' => (string)($inspection['size'] ?? ''),
            'item_code' => (string)($common['item_no'] ?? ''),
            'lot_number' => (string)($common['lot_no'] ?? ''),
            'product_type' => (string)($common['product_type'] ?? ''),
        ], $candidateCache, $catalog);
        return $derived && $catalog!==null ? self::specification((string)$derived['id']) : $derived;
    }

    private static function derivedGloveType(string $product, array $source, ?array $catalog=null): string {
        $type = self::type($product);
        $text = strtoupper(implode(' ', [
            (string)($source['gtype'] ?? ''),
            (string)($source['item_code'] ?? ''),
            (string)($source['lot_number'] ?? ''),
            (string)($source['product_type'] ?? ''),
        ]));
        $seen = [];
        $gloves=$catalog??query('SELECT glove_type FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 GROUP BY glove_type ORDER BY LENGTH(glove_type) DESC, glove_type', [$type])->fetchAll();
        if($catalog!==null) usort($gloves,fn($a,$b)=>strlen((string)$b['glove_type'])<=>strlen((string)$a['glove_type']) ?: strcmp((string)$a['glove_type'],(string)$b['glove_type']));
        $exists=function(string $candidate) use ($product,$catalog): bool {
            if($catalog===null) return self::referenceValueExists($product,'Glove Type',$candidate);
            foreach($catalog as $entry) if(self::matchKey((string)$entry['glove_type'])===self::matchKey($candidate)) return true;
            return false;
        };
        foreach ($gloves as $row) {
            $label = (string)$row['glove_type'];
            $key = self::matchKey($label);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            if (preg_match('/(?<![A-Z0-9])'.preg_quote($key, '/').'(?![A-Z0-9])/', self::matchKey($text))) return $label;
        }
        if ($product === 'cpetpe' && preg_match('/(?:AJ|CF|UE|KD|GP)\s*0?(\d{2})/i', $text, $m)) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Z]+/', '', $m[0]), 0, 2));
            $candidate = $prefix.$m[1];
            if ($exists($candidate)) return $candidate;
        }
        if ($product === 'cpetpe' && preg_match('/(?:FCE|CPE|CPETCP|CP|CE)\D*0?(\d{2})/', $text, $m)) {
            $candidate = 'AJ'.$m[1];
            if ($exists($candidate)) return $candidate;
        }
        return '';
    }

    private static function derivedSize(string $product, array $source, ?array $catalog=null): string {
        $type = self::type($product);
        $valid = [];
        $sizes=$catalog??query('SELECT size FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 GROUP BY size', [$type])->fetchAll();
        if($catalog!==null) usort($sizes,fn($a,$b)=>strcmp((string)$a['size'],(string)$b['size']));
        foreach ($sizes as $row) {
            $size = strtoupper(trim((string)$row['size']));
            if ($size !== '') $valid[$size] = true;
        }
        foreach ([(string)($source['size'] ?? ''), (string)($source['lot_number'] ?? ''), (string)($source['item_code'] ?? '')] as $value) {
            if ($value === '') continue;
            $candidate = strtoupper(trim($value));
            if (isset($valid[$candidate])) return $candidate;
            foreach (array_keys($valid) as $size) {
                if (preg_match('/(?<![A-Z0-9])'.preg_quote($size, '/').'(?![A-Z0-9])/', strtoupper($value))) return $size;
            }
        }
        return '';
    }

    private static function matchKey(string $value): string {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper($value)) ?? '';
    }

    private static function referenceValueExists(string $product, string $reference, string $value): bool {
        if ($reference !== 'Glove Type') return false;
        $type = self::type($product);
        $wanted = self::matchKey($value);
        foreach (query('SELECT glove_type FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 GROUP BY glove_type', [$type])->fetchAll() as $row) {
            if (self::matchKey((string)$row['glove_type']) === $wanted) return true;
        }
        return false;
    }

    private static function parameterId(string $type, ?string $specId, string $code): int {
        $key = $type.'|'.($specId ?: '').'|'.$code;
        if (isset(self::$parameterCache[$key])) return self::$parameterCache[$key];
        $code = strtoupper($code);
        $row = null;
        if ($specId) $row = query('SELECT id FROM M_masterlist_specifications WHERE inspection_type=? AND spec_id=? AND parameter_code=? LIMIT 1', [$type, $specId, $code])->fetch();
        if (!$row) $row = query("SELECT id FROM M_masterlist_specifications WHERE inspection_type=? AND parameter_code=? ORDER BY CASE WHEN spec_id LIKE '__%' THEN 0 ELSE 1 END, id LIMIT 1", [$type, $code])->fetch();
        demand((bool)$row, 'Measurement parameter is not configured: '.$code);
        return self::$parameterCache[$key] = (int)$row['id'];
    }

    private static function parameterMap(string $type, ?string $specId): array {
        $cacheKey = $type.'|'.($specId ?: '*');
        if (isset(self::$parameterCache[$cacheKey]) && is_array(self::$parameterCache[$cacheKey])) return self::$parameterCache[$cacheKey];
        $params = $specId ? [$type, $specId] : [$type];
        $sql = $specId
            ? "SELECT id,parameter_code FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 ORDER BY CASE WHEN spec_id=? THEN 0 WHEN spec_id LIKE '__%' THEN 1 ELSE 2 END, id"
            : "SELECT id,parameter_code FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 ORDER BY CASE WHEN spec_id LIKE '__%' THEN 0 ELSE 1 END, id";
        $map = [];
        foreach (query($sql, $params)->fetchAll() as $row) {
            $code = strtoupper((string)$row['parameter_code']);
            if (!isset($map[$code])) $map[$code] = (int)$row['id'];
        }
        return self::$parameterCache[$cacheKey] = $map;
    }

    public static function get(int $id): array {
        $types=['CPETPE','HDPE'];
        $statements=array_map(function($type) use ($id) {
            $table = self::itemTable($type);
            return ['sql'=>"SELECT i.*,u.full_name AS inspector_name FROM $table i JOIN M_user u ON u.id=i.created_by WHERE i.id=?",'params'=>[$id]];
        },$types);
        foreach($types as $type) {
            $table=self::itemTable($type);
            $statements[]=['sql'=>"SELECT DISTINCT spec_id,glove_type,size FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 AND EXISTS(SELECT 1 FROM $table WHERE id=? AND COALESCE(spec_snapshot_json,'null') IN ('null','','{}','[]')) ORDER BY spec_id",'params'=>[$type,$id]];
        }
        $rows=query_all_batch($statements);
        foreach($types as $index=>$type) {
            $row=$rows[$index][0]??null;
            if ($row) return self::inflate($type, $row, $rows[$index+2]?:null);
        }
        demand(false, 'Inspection not found.', 404);
    }

    private static function evaluationBase(int $id): array {
        foreach (['CPETPE','HDPE'] as $type) {
            $table = self::itemTable($type);
            $row = query("SELECT * FROM $table WHERE id=?", [$id])->fetch();
            if (!$row) continue;
            $product = self::product($type);
            $details = json_decode($row['details_json'], true) ?: [];
            $details['fields'] ??= [];
            $details['measurements'] ??= [];
            $details['tests'] ??= [];
            $details['defects'] ??= [];
            $details['checked_categories'] ??= [];
            $details['defect_checks'] ??= [];
	            $details['measurement_flags'] ??= [];
	            $details['section_flags'] ??= [];
            $common = json_decode($row['common_json'], true) ?: [];
            $spec = json_decode($row['spec_snapshot_json'], true);
            if (!$spec && ($common['spec_id'] ?? '')) $spec = self::specification($common['spec_id']);
            if (!$spec) $spec = self::specForInspection(['product'=>$product,'glove_type'=>$row['glove_type_code'],'size'=>$row['size'],'common'=>$common], $details);
            return [
                'id' => (int)$row['id'],
                'product' => $product,
                'so_number' => $row['sales_order_no'],
                'so_item_no' => $row['so_item_no'],
                'size' => $row['size'],
                'size_desc' => $common['size_desc'] ?? $row['size'],
                'glove_type' => $row['glove_type_code'],
                'inspection_no' => $type === 'CPETPE' ? (int)($row['inspection_no'] ?? 1) : null,
                'state' => self::normalizeState($row),
                'result' => self::normalizeResult($row['final_disposition']),
                'data' => $details,
                'common' => $common,
                'spec' => self::withoutThicknessLimits($spec),
                'created_by' => (int)$row['created_by'],
            ];
        }
        demand(false, 'Inspection not found.', 404);
    }

    private static function inflate(string $type, array $row, ?array $catalog=null): array {
        $product = self::product($type);
        $details = json_decode($row['details_json'], true) ?: [];
        $details['fields'] ??= [];
        $details['measurements'] = [];
        $details['tests'] = [];
        $details['defects'] = [];
        $details['checked_categories'] ??= [];
        $details['defect_checks'] ??= [];
	        $details['measurement_flags'] ??= [];
	        $details['section_flags'] ??= [];
        $common = json_decode($row['common_json'], true) ?: [];
        $spec = json_decode($row['spec_snapshot_json'], true);
        if (!$spec && ($common['spec_id'] ?? '')) $spec = self::specification($common['spec_id']);
        if (!$spec) $spec = self::specForInspection(['product'=>$product,'glove_type'=>$row['glove_type_code'],'size'=>$row['size'],'common'=>$common], $details, $catalog);
        if ($spec && self::matchKey((string)($details['fields']['glove_type_code']??''))!==self::matchKey((string)$spec['glove_type']) && !self::referenceValueExists($product, 'Glove Type', (string)($details['fields']['glove_type_code'] ?? ''))) {
            $details['fields']['glove_type_code'] = (string)($spec['glove_type'] ?? $details['fields']['glove_type_code'] ?? '');
        }
        $recordTable = self::recordTable($type);
        [$readings,$defects,$unitRows]=query_all_batch([
            ['sql'=>"SELECT r.*,m.parameter_code,m.measurement_group FROM $recordTable r JOIN M_masterlist_specifications m ON m.id=r.parameter_id WHERE r.item_id=? ORDER BY r.sample_no,r.reading_no,r.id",'params'=>[$row['id']]],
            ['sql'=>'SELECT * FROM T_defect WHERE item_type=? AND item_id=? ORDER BY id','params'=>[$type,$row['id']]],
            self::unitsQuery($type,$row['sales_order_no']),
        ]);
        foreach ($readings as $r) {
            $key = strtolower($r['parameter_code']);
            $index = max(0, (int)($r['sample_no'] ?? 1) - 1);
            if ($r['measurement_group'] === 'tests' && $r['value_text'] !== null) $details['tests'][$key][$index] = $r['value_text'];
            elseif ($r['value_num'] !== null) $details['measurements'][$key][$index] = (float)$r['value_num'];
        }
        foreach ($defects as $d) {
            $details['defects'][] = [
                'defect_id' => $d['defect_id'],
                'category' => self::categoryToUi($d['defect_category']),
                'name' => $d['defect_name'],
                'code' => $d['defect_code'],
                'quantity' => (int)$d['quantity'],
            ];
        }
        $inspection = [
            'id' => (int)$row['id'],
            'set_id' => (int)$row['id'],
            'sales_order_id' => (int)$row['sales_order_id'],
            'product' => $product,
            'so_number' => $row['sales_order_no'],
            'so_item_no' => $row['so_item_no'],
            'size' => $row['size'],
            'size_desc' => $common['size_desc'] ?? $row['size'],
            'glove_type' => $row['glove_type_code'],
            'inspection_no' => $type === 'CPETPE' ? (int)($row['inspection_no'] ?? 1) : null,
            'unit_type' => 'pallet',
            'unit_number' => (int)$row['pallet_no'],
            'unit_label' => 'Pallet '.str_pad((string)$row['pallet_no'], 2, '0', STR_PAD_LEFT),
            'inspection_uid' => $row['inspection_uid'],
            'state' => self::normalizeState($row),
            'result' => self::normalizeResult($row['final_disposition']),
            'version' => (int)$row['version'],
            'data' => $details,
            'common' => $common,
            'common_token' => hash('sha256', $row['common_json']),
            'spec' => self::withoutThicknessLimits($spec),
            'created_by' => (int)$row['created_by'],
            'updated_by' => (int)$row['updated_by'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'submitted_at' => $row['submitted_at'],
            'inspector_name' => $row['inspector_name'],
            'verifications' => json_decode($row['verification_json'] ?: '[]', true) ?: [],
        ];
        $inspection['evaluation'] = Rules::evaluate($inspection);
        $inspection['units'] = self::mapUnits($unitRows);
        return $inspection;
    }

    private static function units(string $type, string $so): array {
        $statement=self::unitsQuery($type,$so);
        return self::mapUnits(query($statement['sql'],$statement['params'])->fetchAll());
    }

    private static function unitsQuery(string $type, string $so): array {
        $table = self::itemTable($type);
        $select = $type === 'CPETPE'
            ? 'id,so_item_no,size,common_json,glove_type_code,pallet_no,inspection_no,inspection_status,final_disposition,verification_json'
            : 'id,so_item_no,size,common_json,glove_type_code,pallet_no,NULL AS inspection_no,inspection_status,final_disposition,verification_json';
        $order = $type === 'CPETPE' ? 'so_item_no,size,pallet_no,inspection_no' : 'so_item_no,size,pallet_no';
        return ['sql'=>"SELECT $select,created_by FROM $table WHERE sales_order_no=? ORDER BY $order,id",'params'=>[$so]];
    }

    private static function mapUnits(array $rows): array {
        return array_map(function($r) {
            $common = json_decode($r['common_json'] ?: '{}', true) ?: [];
            return [
                'id' => (int)$r['id'],
                'created_by' => (int)$r['created_by'],
                'so_item_no' => $r['so_item_no'],
                'size' => $r['size'],
                'size_desc' => $common['size_desc'] ?? $r['size'],
                'glove_type' => $r['glove_type_code'],
                'unit_number' => (int)$r['pallet_no'],
                'unit_label' => 'Pallet '.str_pad((string)$r['pallet_no'], 2, '0', STR_PAD_LEFT),
                'inspection_no' => isset($r['inspection_no']) ? (int)$r['inspection_no'] : null,
                'state' => self::normalizeState($r + ['verification_json'=>'[]']),
                'result' => self::normalizeResult($r['final_disposition']),
            ];
        }, $rows);
    }

    public static function nextUnfinished(array $inspection, array $user): ?int {
        if(!in_array($user['role'],['admin','supervisor','inspector'],true)) return null;
        $units=$inspection['units'];
        $position=array_search($inspection['id'],array_column($units,'id'),true);
        $offset=$position===false?0:$position+1;
        foreach(array_merge(array_slice($units,$offset),array_slice($units,0,$offset)) as $unit) {
            if($unit['id']===$inspection['id']||$unit['state']!=='draft') continue;
            if($user['role']==='inspector'&&(int)$unit['created_by']!==(int)$user['id']) continue;
            return (int)$unit['id'];
        }
        return null;
    }

    public static function editable(array $i): void {
        $user = actor();
        demand($i['state'] === 'draft', 'Submitted records are locked. Reopen before editing.', 409);
        demand($user['role'] === 'admin' || $user['role'] === 'supervisor' || ((int)$i['created_by'] === (int)$user['id'] && $user['role'] === 'inspector'), 'You cannot edit this inspection.', 403);
    }

    public static function orderLookup(string $product, string $factory, string $so): array {
        $product = strtolower(safe_text($product, 20));
        if (!in_array($product, ['hdpe','cpetpe'], true)) $product = '';
        $factory = self::factoryCode(safe_text($factory, 80));
        $so = safe_text($so, 80);
        demand($factory !== '' && $so !== '', 'Enter factory and Sales Order number.');
        $rows = RequestTiming::measure('qapqc',fn()=>self::qapqcRows($product, $factory, $so));
        demand($rows !== [], 'Sales Order was not found in QAPQC02.QREX_MMSO.', 404);
        $first = $rows[0];
        $firstProduct = $product ?: self::productFromSource($first);
        $sizes = [];
        $products=array_values(array_unique(array_map(fn($row)=>$product?:self::productFromSource($row),$rows)));
        $statements=[];
        foreach($products as $rowProduct) {
            $type=self::type($rowProduct);$table=self::itemTable($type);
            $statements[]=['sql'=>"SELECT id,so_item_no,size,fac,inspection_status FROM $table WHERE sales_order_no=? ORDER BY id",'params'=>[$so]];
            $statements[]=['sql'=>'SELECT DISTINCT spec_id,glove_type,size FROM M_masterlist_specifications WHERE inspection_type=? AND active=1 ORDER BY spec_id','params'=>[$type]];
        }
        $results=query_all_batch($statements);
        $existingByProduct=[];$catalogs=[];
        foreach($products as $n=>$rowProduct) {
            foreach($results[$n*2] as $item) {
                $key=json_encode([$item['fac'],$item['so_item_no'],$item['size']]);
                $existingByProduct[$rowProduct][$key][]=$item;
                if ($item['fac'] === 'M26') {
                    $newFactory = $rowProduct === 'hdpe' ? 'M26B' : 'M26A';
                    $newKey = json_encode([$newFactory,$item['so_item_no'],$item['size']]);
                    $existingByProduct[$rowProduct][$newKey][]=$item;
                }
            }
            $catalogs[$rowProduct]=$results[$n*2+1];
        }
        $candidateCache=[];
        foreach ($rows as $row) {
            $rowProduct = $product ?: self::productFromSource($row);
            $key=json_encode([self::factoryCode($row['factory']),$row['so_item_no'],$row['size']]);
            $existing = self::existingItemState($existingByProduct[$rowProduct][$key]??[]);
            $quantity = max(0, (int)$row['quantity']);
            $soQty = max(0, (int)($row['so_qty'] ?? 0));
            $sizes[] = [
                'key' => self::orderItemKey($row),
                'product' => $rowProduct,
                'so_item_no' => $row['so_item_no'],
                'size' => $row['size'],
                'size_desc' => $row['size_desc'] ?? $row['size'],
	                'lot_number' => $row['lot_number'] ?? '',
	                'batch_no' => $row['batch_no'] ?? '',
                'quantity' => $quantity,
                'so_qty' => $soQty,
                'uom' => $row['uom'] ?? '',
                'cartons_per_pallet' => '',
                'carton_count' => $quantity,
                'pallet_count' => 0,
                'form_count' => 0,
                'has_spec' => (bool)self::specForSource($rowProduct, $row, $candidateCache, $catalogs[$rowProduct]),
                'state' => $existing['state'],
                'existing_id' => $existing['id'],
                'preview' => [],
            ];
        }
        return [
            'order' => [
                'so_number' => $first['sales_order_no'],
                'factory' => $first['factory'],
                'plant' => $first['plant'],
                'customer' => $first['customer'],
                'country' => $first['country'],
                'brand' => $first['brand'],
                'product_type' => $first['product_type'],
                'product' => $firstProduct,
                'glove_type' => $first['gtype'],
                'colour' => $first['colour'],
                'lot_number' => $first['lot_number'],
                'item_number' => $first['item_code'],
                'total_quantity' => array_sum(array_column($rows, 'quantity')),
            ],
            'sizes' => $sizes,
        ];
    }

    private static function existingItems(string $type, string $so, string $item, string $size, ?string $factory = null): array {
        $table = self::itemTable($type);
        $params = [$so,$item,$size];
        $factoryWhere = '';
        if ($factory !== null) {
            $factoryWhere = ' AND fac=?';
            $params[] = self::factoryCode($factory);
        }
        $rows = query("SELECT id,inspection_status FROM $table WHERE sales_order_no=? AND so_item_no=? AND size=?$factoryWhere ORDER BY id", $params)->fetchAll();
        return self::existingItemState($rows);
    }

    private static function existingItemState(array $rows): array {
        if (!$rows) return ['state'=>'NOT_STARTED','id'=>null];
        $open = array_values(array_filter($rows, fn($r) => $r['inspection_status'] === 'IN_PROGRESS'));
        if ($open) return ['state'=>'IN_PROGRESS','id'=>(int)$open[0]['id']];
        $verified = array_values(array_filter($rows, fn($r) => $r['inspection_status'] === 'VERIFIED'));
        if ($verified && count($verified) === count($rows)) return ['state'=>'VERIFIED','id'=>(int)$verified[0]['id']];
        return ['state'=>'COMPLETED','id'=>(int)$rows[0]['id']];
    }

    private static function qapqcRows(string $product, string $factory, string $so): array {
        $factory=self::factoryCode($factory);
        $plants=in_array($factory,['M26A','M26B'],true)
            ? [self::lookupPlantNumber($product,$factory)]
            : array_values(array_unique([self::lookupPlantNumber($product,$factory),self::plantNumber($factory),...self::QAPQC_LOOKUP_PLANTS]));
        if(str_starts_with(strtoupper($so),'DEMO-')) {
            $rows=array_merge(self::demoRows('hdpe',$factory,$so),self::demoRows('cpetpe',$factory,$so));
            return self::filterRowsByProduct($rows,$product);
        }
        global $settings;
        if(empty($settings['qapqc']['enabled'])) return [];
        if(!in_array('sqlsrv',PDO::getAvailableDrivers(),true)) {
            throw new DomainException('QAPQC lookup needs the PHP SQL Server driver. Enable pdo_sqlsrv/sqlsrv for XAMPP PHP, then restart Apache.',503);
        }
        $load=function() use ($settings,$product,$plants,$so): array {
            $dsn=$settings['qapqc']['dsn'];
            if(stripos($dsn,'LoginTimeout=')===false) $dsn.=';LoginTimeout=5';
            $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
            if(defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) $options[constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')]=10;
            try {
                $pdo=new PDO($dsn,$settings['qapqc']['user'],$settings['qapqc']['password'],$options);
                foreach([false,true] as $fallback) {
                    [$sql,$params]=self::qapqcQuery($product,$plants,$so,$fallback);
                    RequestTiming::count('qapqc_queries');
                    $stmt=$pdo->prepare($sql); $stmt->execute($params);
                    $rows=array_map(fn($row)=>self::mapQapqcRow($row),$stmt->fetchAll());
                    if($rows) return self::filterRowsByProduct($rows,$product);
                }
                return [];
            } catch(Throwable $error) {
                error_log('QAPQC lookup failed: '.$error->getMessage());
                throw new DomainException('QAPQC02.QREX_MMSO could not be reached. Please try Find order again.',503);
            }
        };
        $key=json_encode(['v'=>2,'dsn'=>$settings['qapqc']['dsn'],'user'=>$settings['qapqc']['user'],'product'=>$product,'plants'=>$plants,'so'=>$so],JSON_THROW_ON_ERROR);
        return OrderSourceCache::remember($key,$load);
    }

    private static function qapqcQuery(string $product, array $plants, string $so, bool $fallback=false): array {
        $placeholders=implode(',',array_fill(0,count($plants),'?'));
        $plantOrder=implode(' ',array_map(fn($n)=>"WHEN ? THEN $n",array_keys($plants)));
        $soColumn=$fallback ? 'LTRIM(RTRIM(CAST(so_num AS varchar(80))))' : 'so_num';
        $productSql=$product==='hdpe' ? ' AND gtype_desc LIKE ?'
            : ($product==='cpetpe' ? ' AND (gtype_desc LIKE ? OR gtype_desc LIKE ?)' : '');
        $params=array_merge([$so],$plants);
        if($product==='hdpe') $params[]='%HDPE%';
        elseif($product==='cpetpe') array_push($params,'%TPE%','%CPE%');
        array_push($params,...$plants);
        return ["SELECT so_num,so_item,plant,name1_cust,route_country,brand,total_qty,so_qty,uom,gtype,gtype_desc,size,size_desc,colour,colour_desc,material,material_desc,batch,last_update FROM QREX_MMSO WHERE $soColumn=CAST(? AS varchar(80)) AND plant IN ($placeholders)$productSql ORDER BY CASE plant $plantOrder END,so_item,size",$params];
    }

    private static function filterRowsByProduct(array $rows, string $product): array {
        if ($product === '') return $rows;
        $filtered = array_values(array_filter($rows, fn($row) => self::rowMatchesProduct($row, $product)));
        return $filtered;
    }

    private static function orderItemKey(array $row): string {
        return (string)$row['so_item_no'].'|'.(string)$row['size'];
    }

    private static function mapQapqcRow(array $r, ?string $factoryCode = null): array {
        $plant = trim((string)$r['plant']);
        $factory = $factoryCode ?: self::factoryCode($plant);
        return [
            'sales_order_no' => trim((string)$r['so_num']),
            'so_item_no' => trim((string)$r['so_item']),
            'factory' => $factory,
            'plant' => $plant,
            'customer' => trim((string)$r['name1_cust']),
            'country' => trim((string)$r['route_country']),
            'brand' => trim((string)$r['brand']),
            'quantity' => (int)($r['total_qty'] ?: $r['so_qty'] ?: 0),
            'so_qty' => (int)($r['so_qty'] ?: 0),
            'uom' => trim((string)($r['uom'] ?? '')),
            'gtype' => trim((string)$r['gtype']),
            'gtype_desc' => trim((string)($r['gtype_desc'] ?? '')),
            'product_type' => trim((string)($r['gtype_desc'] ?: $r['material_desc'] ?: $r['gtype'])),
            'size' => trim((string)$r['size']),
            'size_desc' => trim((string)($r['size_desc'] ?? $r['size'])),
            'colour' => trim((string)($r['colour_desc'] ?: $r['colour'])),
            'item_code' => trim((string)$r['material']),
            'lot_number' => trim((string)($r['batch'] ?? '')),
            'batch_no' => trim((string)$r['batch']),
            'source' => $r,
        ];
    }

    private static function rowMatchesProduct(array $row, string $product): bool {
        $description=strtoupper(trim((string)($row['gtype_desc'] ?? $row['source']['gtype_desc'] ?? $row['product_type'] ?? '')));
        if($description!=='') {
            return $product==='hdpe' ? str_contains($description,'HDPE')
                : (!str_contains($description,'HDPE') && (str_contains($description,'CPE') || str_contains($description,'TPE')));
        }
        $gtype = strtoupper(trim((string)($row['gtype'] ?? '')));
        $type = strtoupper(trim((string)($row['product_type'] ?? '')));
        $text = $type.' '.$gtype.' '.strtoupper(trim((string)($row['item_code'] ?? ''))).' '.strtoupper(trim((string)($row['lot_number'] ?? '')));
        $isHdpe = str_contains($text, 'HDPE') || str_starts_with($gtype, 'HD');
        if ($product === 'hdpe') return $isHdpe;
        $cpetpeTypes = ['CPE', 'TPE', 'CPEPT', 'CPES', 'CPEDE', 'TPES'];
        $isCpetpe = !$isHdpe && (bool)array_filter($cpetpeTypes, fn($needle) => str_contains($text, $needle));
        if (!$isCpetpe) {
            $refs = master()['products']['cpetpe']['references']['Glove Type'] ?? [];
            $isCpetpe = in_array($row['gtype'] ?? '', $refs, true);
        }
        return $isCpetpe;
    }

    private static function demoRows(string $product, string $factory, string $so): array {
        $factory = self::factoryCode($factory);
        $plant = self::lookupPlantNumber($product, $factory);
        if ($product === 'hdpe' && in_array($factory, ['F05B','M26','M26B'], true) && $so === 'DEMO-5700001') {
            return [
                ['sales_order_no'=>$so,'so_item_no'=>'000010','factory'=>$factory,'plant'=>$plant,'customer'=>'MCKESSON','country'=>'Malaysia','brand'=>'TOP GLOVE','quantity'=>36000,'so_qty'=>90,'uom'=>'KAR','gtype'=>'HD120','product_type'=>'HDPE','size'=>'FS','size_desc'=>'Free Size','colour'=>'Blue','item_code'=>'ITM-HD-120A','lot_number'=>'LOT-HD-001','batch_no'=>'','source'=>['demo'=>true,'plant'=>$plant]],
                ['sales_order_no'=>$so,'so_item_no'=>'000020','factory'=>$factory,'plant'=>$plant,'customer'=>'MCKESSON','country'=>'Malaysia','brand'=>'TOP GLOVE','quantity'=>12000,'so_qty'=>30,'uom'=>'KAR','gtype'=>'HD120','product_type'=>'HDPE','size'=>'XS','size_desc'=>'Extra Small','colour'=>'Blue','item_code'=>'ITM-HD-120B','lot_number'=>'LOT-HD-002','batch_no'=>'','source'=>['demo'=>true,'plant'=>$plant]],
            ];
        }
        if ($product === 'cpetpe' && in_array($factory, ['F05B','M26','M26A'], true) && $so === 'DEMO-5700002') {
            return [
                ['sales_order_no'=>$so,'so_item_no'=>'000010','factory'=>$factory,'plant'=>$plant,'customer'=>'GLOBAL SUPPLIES INC.','country'=>'Malaysia','brand'=>'TOP GLOVE','quantity'=>18000,'so_qty'=>45,'uom'=>'KAR','gtype'=>'AJ14','product_type'=>'CPEPT / CPES','size'=>'S','size_desc'=>'Small','colour'=>'Clear','item_code'=>'ITM-CPE-AJ14','lot_number'=>'LOT-CPE-014','batch_no'=>'','source'=>['demo'=>true,'plant'=>$plant]],
                ['sales_order_no'=>$so,'so_item_no'=>'000020','factory'=>$factory,'plant'=>$plant,'customer'=>'GLOBAL SUPPLIES INC.','country'=>'Malaysia','brand'=>'TOP GLOVE','quantity'=>24000,'so_qty'=>60,'uom'=>'KAR','gtype'=>'AJ14','product_type'=>'CPEPT / CPES','size'=>'M','size_desc'=>'Medium','colour'=>'Clear','item_code'=>'ITM-CPE-AJ14','lot_number'=>'LOT-CPE-014','batch_no'=>'','source'=>['demo'=>true,'plant'=>$plant]],
                ['sales_order_no'=>$so,'so_item_no'=>'000030','factory'=>$factory,'plant'=>$plant,'customer'=>'GLOBAL SUPPLIES INC.','country'=>'Malaysia','brand'=>'TOP GLOVE','quantity'=>10000,'so_qty'=>25,'uom'=>'KAR','gtype'=>'AJ14','product_type'=>'CPEPT / CPES','size'=>'L','size_desc'=>'Large','colour'=>'Clear','item_code'=>'ITM-CPE-AJ14','lot_number'=>'LOT-CPE-014','batch_no'=>'','source'=>['demo'=>true,'plant'=>$plant]],
            ];
        }
        return [];
    }

    private static function packingPreview(int $forms, int $cartons, int $cartonsPerPallet): array {
        $preview = [];
        $carton = 1;
        for ($p=1; $p <= min($forms, 3); $p++) {
            $list = [];
            for ($n=0; $n < $cartonsPerPallet && $carton <= $cartons; $n++, $carton++) $list[] = 'Carton '.str_pad((string)$carton, 2, '0', STR_PAD_LEFT);
            $preview[] = ['pallet_no' => 'Pallet '.str_pad((string)$p, 2, '0', STR_PAD_LEFT), 'cartons' => $list];
        }
        return $preview;
    }

    private static function calculatedPalletCount(int $quantity, int $cartonsPerPallet): int {
        return max(1, (int)ceil(max(1, $quantity) / max(1, $cartonsPerPallet)));
    }

    private static function palletBasisQuantity(array $source): int {
        $soQty = (int)($source['so_qty'] ?? 0);
        return $soQty > 0 ? $soQty : max(1, (int)($source['quantity'] ?? 0));
    }

    private static function cartonPerPalletInput(mixed $value): int {
        if (is_float($value)) {
            demand(false, 'Carton per pallet/lot/batch must be a whole number.');
        }
        $text = trim((string)$value);
        demand($text !== '' && preg_match('/^\d+$/', $text) === 1, 'Carton per pallet/lot/batch must be a whole number.');
        return (int)$text;
    }

    public static function create(array $input): int {
        $user = role(['inspector','supervisor','admin']);
        $key = isset($input['creation_key']) ? safe_text($input['creation_key'], 200) : bin2hex(random_bytes(24));
        demand($key !== '', 'Creation request key is required.');
        unset($input['creation_key']);
        $hash = hash('sha256', json_encode(self::canonicalCreationInput($input), JSON_THROW_ON_ERROR));
        $actorId = (int)$user['id'];
        $replay = self::creationReplay($actorId, $key, $hash);
        if ($replay !== null) return $replay;

        $now = self::now();
        $groups = isset($input['so_number'], $input['factory'], $input['sizes'])
            ? self::createFromOrder($input) : self::createManual($input);
        if(array_key_exists('inspection_plan',$input)) {
            $plan=safe_text($input['inspection_plan'],40);
            demand(in_array($plan,array_column(Sampling::master()['plans'],'value'),true),'Choose an Inspection Plan.');
            $sample=safe_text($input['sample_size']??'',10);
            demand($sample==='' || in_array($sample,['125','200','315'],true),'Choose Sample Size 125, 200 or 315.');
            foreach($groups as &$group) $group['sampling_fields']=['inspection_plan'=>$plan,'sample_size'=>$sample];
            unset($group);
        }
        $statements = [['sql' => 'INSERT INTO T_inspection_creation (actor_id,creation_key,payload_hash,created_at) VALUES (?,?,?,?)',
            'params' => [$actorId,$key,$hash,$now]]];
        $first = true;
        foreach ($groups as $group) {
            $statements[] = self::snapshotSalesOrderStatement($group['source'], $now);
            for ($offset = 0; $offset < $group['count']; $offset++) {
                $statements[] = self::insertItemStatement($group, $user, $now, $offset);
                if ($first) {
                    $statements[] = ['sql' => 'UPDATE T_inspection_creation SET first_item_id=last_insert_rowid() WHERE actor_id=? AND creation_key=?',
                        'params' => [$actorId,$key]];
                    $first = false;
                }
            }
        }
        $statements[] = ['sql' => 'SELECT first_item_id FROM T_inspection_creation WHERE actor_id=? AND creation_key=?', 'params' => [$actorId,$key]];
        try {
            return self::executeCreation($statements);
        } catch (Throwable $e) {
            // A competing request or lost HTTP response may already have committed this key.
            try { $replay = self::creationReplay($actorId, $key, $hash); }
            catch (DomainException $conflict) { throw $conflict; }
            catch (Throwable $unavailable) { throw $e; }
            if ($replay !== null) return $replay;
            if (str_contains($e->getMessage(), 'UNIQUE')) demand(false, 'This inspection unit already exists. Resume or view the existing unit instead.', 409);
            throw $e;
        }
    }

    private static function canonicalCreationInput(array $input): array {
        if ($input!==[] && array_keys($input)!==range(0,count($input)-1)) ksort($input, SORT_STRING);
        foreach ($input as &$value) if (is_array($value)) $value = self::canonicalCreationInput($value);
        return $input;
    }

    private static function creationReplay(int $actorId, string $key, string $hash): ?int {
        $row = query('SELECT payload_hash,first_item_id FROM T_inspection_creation WHERE actor_id=? AND creation_key=?', [$actorId,$key])->fetch();
        if (!$row) return null;
        demand(hash_equals((string)$row['payload_hash'], $hash), 'This creation key was already used for different inspection data.', 409);
        demand((int)$row['first_item_id'] > 0, 'This creation request is incomplete. Retry with the same request key.', 409);
        return (int)$row['first_item_id'];
    }

    private static function executeCreation(array $statements): int {
        $connection = db();
        if ($connection instanceof TursoConnection) {
            $results = $connection->executeTransaction($statements);
            $rows = TursoStatement::decodeRows(end($results));
            return (int)$rows[0]['first_item_id'];
        }
        $connection->exec('BEGIN IMMEDIATE');
        try {
            foreach ($statements as $statement) $last = query($statement['sql'], $statement['params'] ?? []);
            $first = (int)$last->fetchColumn();
            $connection->exec('COMMIT');
            return $first;
        } catch (Throwable $e) {
            try { $connection->exec('ROLLBACK'); } catch (Throwable $rollbackError) { error_log((string)$rollbackError); }
            throw $e;
        }
    }

    private static function createFromOrder(array $input): array {
        $product = strtolower(safe_text($input['product'] ?? '', 20));
        if (!in_array($product, ['hdpe','cpetpe'], true)) $product = '';
        $factory = self::factoryCode(safe_text($input['factory'] ?? '', 80));
        $so = safe_text($input['so_number'] ?? '', 80);
        $selected = $input['sizes'] ?? [];
        demand(is_array($selected) && $selected !== [], 'Choose at least one Sales Order item.');
        $cartonMap = is_array($input['cartons_per_pallet'] ?? null) ? $input['cartons_per_pallet'] : [];
	        $lotMap = is_array($input['lot_numbers'] ?? null) ? $input['lot_numbers'] : [];
        $byItem = [];
        foreach (self::qapqcRows($product, $factory, $so) as $row) {
            $byItem[self::orderItemKey($row)] = $row;
            $byItem[$row['so_item_no']] ??= $row;
        }
        $groups = [];
        $candidateCache = [];
            foreach ($selected as $itemKey) {
                $itemKey = safe_text($itemKey, 80);
                $source = $byItem[$itemKey] ?? null;
                if (!$source) foreach ($byItem as $candidate) if ($candidate['size'] === $itemKey) { $source = $candidate; break; }
                demand((bool)$source, 'Selected Sales Order item is no longer available.');
	                $source = self::applyOrderPreviewOverrides($source, $input['order_overrides'] ?? []);
	                $lotOverride = safe_text($lotMap[$itemKey] ?? $lotMap[$source['size']] ?? '', 180);
	                if ($lotOverride !== '') $source['lot_number'] = $lotOverride;
                $sourceProduct = $product ?: self::productFromSource($source);
                $type = self::type($sourceProduct);
                $cartonsPerPallet = self::cartonPerPalletInput($cartonMap[$itemKey] ?? $cartonMap[$source['size']] ?? null);
                demand($cartonsPerPallet >= 1 && $cartonsPerPallet <= 100000, 'Enter a valid Carton per pallet/lot/batch value.');
                $basisQuantity = self::palletBasisQuantity($source);
                demand($cartonsPerPallet <= $basisQuantity, 'Carton per pallet/lot/batch cannot be more than SO quantity.');
                $count = self::calculatedPalletCount($basisQuantity, $cartonsPerPallet);
                demand($count >= 1 && $count <= 50, 'The calculated pallet count must be between 1 and 50. Increase Carton per pallet/lot/batch and try again.');
                $spec = self::specForSource($sourceProduct, $source, $candidateCache);
                $common = self::commonFromSource($source, $spec['id'] ?? null, $spec);
                $groups[] = compact('type','source','count','spec','common','cartonsPerPallet') + ['manual'=>false];
            }
        return $groups;
    }

    private static function applyOrderPreviewOverrides(array $source, mixed $overrides): array {
        if (!is_array($overrides)) return $source;
        if (array_key_exists('factory_display', $overrides)) {
            $display = safe_text($overrides['factory_display'], 80);
            if ($display !== '') {
                $parts = preg_split('/\s*\/\s*/', $display);
                $factory = safe_text($parts[0] ?? '', 20);
                $plant = safe_text($parts[1] ?? '', 20);
                if ($factory !== '') $source['factory'] = $factory;
                if ($plant !== '') $source['plant'] = $plant;
            }
        }
        foreach ([
            'item_code' => 120,
            'lot_number' => 180,
            'colour' => 80,
            'brand' => 120,
            'country' => 80,
        ] as $key => $limit) {
            if (array_key_exists($key, $overrides)) $source[$key] = safe_text($overrides[$key], $limit);
        }
        $gloveType = safe_text($overrides['glove_type_code'] ?? ($overrides['gtype'] ?? ''), 80);
        if ($gloveType !== '') $source['gtype'] = $gloveType;
        $source['source'] = array_merge((array)($source['source'] ?? []), ['qrex_preview_overrides' => $overrides]);
        return $source;
    }

    private static function snapshotSalesOrderStatement(array $source, string $now): array {
        return ['sql' => 'INSERT INTO T_sales_order (sales_order_no,so_item_no,gtype,item_code,factory,customer,country,colour,brand,product_type,size,quantity,lot_number,batch_no,source_system,source_lookup_at,source_json,created_at) SELECT ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM T_sales_order WHERE sales_order_no=? AND so_item_no=? AND factory=? AND size=?)', 'params' => [
            $source['sales_order_no'],$source['so_item_no'],$source['gtype'],$source['item_code'],$source['factory'],$source['customer'],$source['country'],$source['colour'],$source['brand'],$source['product_type'],$source['size'],$source['quantity'],$source['lot_number'],$source['batch_no'],'QAPQC02.QREX_MMSO',$now,json_encode($source['source'], JSON_THROW_ON_ERROR),$now,
            $source['sales_order_no'],$source['so_item_no'],$source['factory'],$source['size'],
        ]];
    }

    private static function commonFromSource(array $source, ?string $specId, ?array $spec = null): array {
        return [
            'factory' => $source['factory'],
            'plant' => $source['plant'] ?? self::plantNumber($source['factory']),
            'item_no' => $source['item_code'],
            'lot_no' => $source['lot_number'],
            'customer' => $source['customer'],
            'country' => $source['country'],
            'colour' => $source['colour'],
            'brand' => $source['brand'],
            'product_type' => $source['product_type'],
            'size_desc' => $source['size_desc'] ?? $source['size'],
            'so_item_no' => $source['so_item_no'],
            'batch_no' => $source['batch_no'],
            'spec_id' => $specId,
            'spec_glove_type' => $spec['glove_type'] ?? self::derivedGloveType(self::productFromSource($source), $source),
            'spec_size' => $spec['size'] ?? self::derivedSize(self::productFromSource($source), $source),
        ];
    }

    private static function cartonNoLabel(int $palletOffset, int $cartonsPerPallet, int $quantity): string {
        if ($cartonsPerPallet <= 0) return '';
        $start = ($palletOffset * $cartonsPerPallet) + 1;
        $end = min(max(1, $quantity), $start + $cartonsPerPallet - 1);
        if ($start > $end) return '';
        return $start === $end ? (string)$start : $start.'-'.$end;
    }

    private static function insertItemStatement(array $group, array $user, string $now, int $offset): array {
        ['type'=>$type,'source'=>$source,'common'=>$common,'spec'=>$spec] = $group;
        $userId = (int)$user['id'];
        $table = self::itemTable($type);
        $itemGloveType = (string)($spec['glove_type'] ?? $source['gtype']);
        $cartonsPerPalletValue = isset($group['cartonsPerPallet']) ? (string)$group['cartonsPerPallet'] : '';
        $inspectorId = (string)($user['employee_id'] ?? '');
        if ($inspectorId === '') $inspectorId = (string)$userId;
        $fields = [
            'batch_no' => '',
            'lot_no' => (string)($source['lot_number'] ?? ''),
            'pallet_no' => '',
            'carton_no' => '',
            'glove_type_code' => $itemGloveType,
            'inspection_date' => date('Y-m-d'),
            'sample_size' => '',
            'sample_qty' => '',
            'cartons_per_pallet' => $cartonsPerPalletValue,
            'inspector_id' => $inspectorId,
        ];
        $fields=array_merge($fields,$group['sampling_fields']??[]);
        $details = ['fields'=>$fields,'checked_categories'=>[],'defect_checks'=>[],'measurement_flags'=>[],'section_flags'=>[]];
        $audit = [['actor_id'=>$userId,'action'=>'created','details'=>[
            'source'=>$group['manual'] ? 'manual' : 'QAPQC02.QREX_MMSO','inspection_uid'=>'',
        ],'created_at'=>$now]];
        $palletSql = $group['manual'] ? 'SELECT ? AS pallet_no'
            : "SELECT MAX(1,COALESCE(MAX(pallet_no),0)+1) AS pallet_no FROM $table WHERE sales_order_no=? AND fac=?";
        $params = $group['manual'] ? [$offset + 1] : [$source['sales_order_no'],self::factoryCode($source['factory'])];
        if (!$group['manual'] && in_array($source['factory'], ['M26A','M26B'], true)) {
            $palletSql = "SELECT MAX(1,COALESCE(MAX(pallet_no),0)+1) AS pallet_no FROM $table WHERE sales_order_no=? AND fac IN (?,?)";
            $params[] = 'M26';
        }
        array_push($params, $source['sales_order_no'].'-'.$source['so_item_no'].'-'.$source['size'].'-', $type === 'CPETPE' ? '-1' : '');
        $cols = 'sales_order_id,sales_order_no,so_item_no,size,pallet_no,'.($type === 'CPETPE' ? 'inspection_no,' : '')
            .'inspection_uid,fac,customer,country,product_type,glove_type_code,color,brand,inspection_date,details_json,common_json,spec_snapshot_json,audit_json,created_by,updated_by,created_at,updated_at';
        $select = '(SELECT id FROM T_sales_order WHERE sales_order_no=? AND so_item_no=? AND factory=? AND size=?),?,?,?,pallet_no,'
            .($type === 'CPETPE' ? '1,' : '')."inspection_uid,?,?,?,?,?,?,?,?,json_set(?,'$.fields.pallet_no',printf('Pallet %02d',pallet_no)),?,?,json_set(?,'$[0].details.inspection_uid',inspection_uid),?,?,?,?";
        array_push($params, $source['sales_order_no'],$source['so_item_no'],$source['factory'],$source['size'],
            $source['sales_order_no'],$source['so_item_no'],$source['size'],
            $source['factory'],$source['customer'],$source['country'],$source['product_type'],$itemGloveType,$source['colour'],$source['brand'],date('Y-m-d'),
            json_encode($details,JSON_THROW_ON_ERROR),json_encode($common,JSON_THROW_ON_ERROR),json_encode($spec,JSON_THROW_ON_ERROR),json_encode($audit,JSON_THROW_ON_ERROR),$userId,$userId,$now,$now);
        return ['sql'=>"WITH next_pallet AS ($palletSql), creation_unit AS (SELECT pallet_no,? || pallet_no || ? AS inspection_uid FROM next_pallet) INSERT INTO $table ($cols) SELECT $select FROM creation_unit",'params'=>$params];
    }

    private static function createManual(array $input): array {
        $product = strtolower(safe_text($input['product'] ?? '', 20));
        $type = self::type($product);
        $glove = safe_text($input['glove_type'] ?? '', 80);
        $size = safe_text($input['size'] ?? '', 20);
        $so = safe_text($input['so_number'] ?? ('MANUAL-'.date('YmdHis')), 80);
        $count = (int)($input['count'] ?? 1);
        demand($count >= 1 && $count <= 50, 'Enter between 1 and 50 units.');
        $factory = self::factoryCode(safe_text($input['factory'] ?? ($product === 'hdpe' ? 'M26B' : 'M26A'), 80));
        $source = ['sales_order_no'=>$so,'so_item_no'=>'000010','factory'=>$factory,'plant'=>self::plantNumber($factory),'customer'=>safe_text($input['customer'] ?? 'Manual inspection', 150),'country'=>safe_text($input['country'] ?? '', 80),'brand'=>safe_text($input['brand'] ?? '', 120),'quantity'=>0,'gtype'=>$glove,'product_type'=>strtoupper($product),'size'=>$size,'colour'=>safe_text($input['colour'] ?? '', 80),'item_code'=>safe_text($input['item_no'] ?? '', 80),'lot_number'=>safe_text($input['lot_no'] ?? '', 80),'batch_no'=>'','source'=>['manual'=>true,'plant'=>self::plantNumber($factory)]];
        $spec = self::specForSource($product, $source) ?: self::specification($input['spec_id'] ?? null);
        $common = self::commonFromSource($source, $spec['id'] ?? null, $spec);
        return [compact('type','source','count','spec','common') + ['manual'=>true]];
    }

    public static function deleteDraft(int $id): array {
        $i = self::get($id); self::editable($i);
        $type = self::type($i['product']); $table = self::itemTable($type);
        query("DELETE FROM $table WHERE id=? AND inspection_status='IN_PROGRESS'", [$id]);
        return ['ok'=>true,'remaining'=>count(self::units($type, $i['so_number']))];
    }

    public static function validate(array $input, string $product): array {
	        foreach(['fields','measurements','tests','defects','checked_categories','defect_checks','measurement_flags','section_flags'] as $key) demand(!isset($input[$key])||is_array($input[$key]),'Invalid inspection data.');
	        $definition=master()['products'][$product]; $out=['fields'=>[],'measurements'=>[],'tests'=>[],'defects'=>[],'checked_categories'=>[],'defect_checks'=>[],'measurement_flags'=>[],'section_flags'=>[]];
        $fields=['batch_no','lot_no','pallet_no','carton_no','glove_type_code','embossment','sample_size','sample_qty','inspection_status','cartons_per_pallet','inspection_date','machine_no','stamping_date','inspector_id','verify_id','spec_id','scale_id','ruler_id','gauge_id','packer_id','remarks','defect_remarks','length_a_defect','glove_length_defect','gap_length_defect','header_seal_defect','layering_defect','counting_mode','count_pcs','count_inner','count_polybag','count_pcs_result','count_inner_result','count_polybag_result'];
        foreach($fields as $key) {
            $value=safe_text($input['fields'][$key]??'',str_contains($key,'remarks')?4000:200);
            if($key==='sample_size' && $value!=='') demand(in_array($value,['125','200','315'],true),'Choose Sample Size 125, 200 or 315.');
            if($value!=='' && in_array($key,['sample_size','cartons_per_pallet','count_pcs','count_inner','count_polybag'],true)) demand(ctype_digit($value)&&(int)$value<=10000000&&($key!=='sample_size'||(int)$value>0),'Enter a valid whole-number quantity for '.str_replace('_',' ',$key).'.');
            if($value!==''&&str_ends_with($key,'date')) { $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value); demand($date&&$date->format('Y-m-d')===$value,'Enter a valid date.'); }
            if($value!==''&&str_ends_with($key,'_result')) demand(in_array($value,['PASS','Less Count','Extra Count'],true),'Choose a valid count disposition.');
            $reference=match($key){'inspection_status'=>'Inspection Status','machine_no'=>$product==='hdpe'?'HD No.':'STM No.','glove_type_code'=>'Glove Type','embossment'=>'Embossment',default=>null};
            if($value!==''&&$reference&&isset($definition['references'][$reference])) demand(in_array($value,$definition['references'][$reference],true)||self::referenceValueExists($product,$reference,$value),'Choose a valid '.str_replace('_',' ',$key).'.');
            if($key==='spec_id'&&$value!=='') { $spec=self::specification($value); demand($spec&&$spec['active']&&$spec['product']===$product,'Choose a valid specification reference.'); }
            $out['fields'][$key]=$value;
        }
        $plan=safe_text($input['fields']['inspection_plan']??'',40);
        demand($plan==='' || in_array($plan,array_column(Sampling::master()['plans'],'value'),true),'Choose a valid Inspection Plan.');
        $out['fields']['inspection_plan']=$plan;
        foreach(['length_a_defect'=>['Short Length','Long Length'],'glove_length_defect'=>['Short Length','Long Length'],'gap_length_defect'=>['Gap Length out of spec','No Gap Length'],'header_seal_defect'=>['PPH','HHM'],'layering_defect'=>['Poor','Outside header']] as $key=>$values) demand($out['fields'][$key]===''||in_array($out['fields'][$key],$values,true),'Choose a valid failure reason.');
        foreach($definition['metrics'] as $metric) {
            $values=$input['measurements'][$metric['key']]??[]; demand(is_array($values)&&count($values)<=$metric['samples'],'Invalid number of measurement samples.');
            for($n=0;$n<$metric['samples'];$n++) { $v=$values[$n]??null; if($v===null||$v==='') {$out['measurements'][$metric['key']][]=null;continue;} demand(is_numeric($v)&&is_finite((float)$v)&&(float)$v>=0&&(float)$v<=1000000,'Enter a non-negative numeric reading.'); $out['measurements'][$metric['key']][]=round((float)$v,6); }
        }
        foreach($definition['metrics'] as $metric) {
            $flag = ($input['measurement_flags'][$metric['key']] ?? '') === 'blank_pass';
            if ($flag) $out['measurement_flags'][$metric['key']] = 'blank_pass';
        }
	        foreach(['information','defects','tests','measurements','packing'] as $section) {
	            if(($input['section_flags'][$section] ?? '') === 'pass') $out['section_flags'][$section] = 'pass';
	        }
        foreach($definition['tests'] as $test) {
            $values=$input['tests'][$test['key']]??[]; demand(is_array($values)&&count($values)<=$test['samples'],'Invalid test samples.');
            for($n=0;$n<$test['samples'];$n++) {$v=$values[$n]??'na';if($v==='')$v='na';demand(in_array($v,['na','pass','fail'],true),'Choose N/A, PASS or FAIL.');$out['tests'][$test['key']][]=$v;}
        }
        $masterDefects=array_values(array_filter(master()['defects'],fn($d)=>$d['product']===$product));
        $byId=[]; foreach($masterDefects as $m)$byId[$m['id']]=$m;
        $seen=[]; demand(count($input['defects']??[])<=100,'Too many defects.'); $failQuantities=[];
        foreach($input['defects']??[] as $d) {
            $id=safe_text($d['defect_id']??'',100); demand(isset($byId[$id]),'Choose a defect from the master list.'); demand(!isset($seen[$id]),'Combine duplicate defects into one quantity.');$seen[$id]=true;
            $qty=filter_var($d['quantity']??null,FILTER_VALIDATE_INT); demand($qty!==false&&$qty>0&&$qty<=1000000,'Defect quantity must be a positive whole number.');
            $m=$byId[$id]; $out['defects'][]=['defect_id'=>$id,'quantity'=>$qty,'category'=>$m['category'],'name'=>$m['name'],'code'=>$m['code']]; $failQuantities[$id]=$qty;
        }
        foreach($masterDefects as $m) {
            $raw=$input['defect_checks'][$m['id']]??(isset($failQuantities[$m['id']])?'fail':'na');
            demand(in_array($raw,['na','pass','fail'],true),'Choose N/A, PASS or FAIL for each defect.');
            $out['defect_checks'][$m['id']]=$raw;
            if($raw==='fail'&&!isset($failQuantities[$m['id']])) {$out['defects'][]=['defect_id'=>$m['id'],'quantity'=>1,'category'=>$m['category'],'name'=>$m['name'],'code'=>$m['code']];$failQuantities[$m['id']]=1;}
            if($raw!=='fail'&&isset($failQuantities[$m['id']])) unset($failQuantities[$m['id']]);
        }
        $out['defects']=array_values(array_filter($out['defects'],fn($d)=>isset($failQuantities[$d['defect_id']])));
        $out['checked_categories']=Rules::CATEGORIES;
        return $out;
    }

    private static function validateInspectionData(array $inspection, array $input): array {
        $plan=$inspection['data']['fields']['inspection_plan']??'';
        $submitted=$input['fields']['inspection_plan']??$plan;
        demand($submitted===$plan,'Inspection Plan is fixed when the inspection set is created.');
        $input['fields']['inspection_plan']=$plan;
        return self::validate($input,$inspection['product']);
    }

    public static function evaluatePayload(int $id, array $payload): array {
        $i = self::evaluationBase($id);
        self::editable($i);
        $data = self::validateInspectionData($i, $payload['data'] ?? []);
        $i['data'] = $data;
        $i['spec'] = self::specForInspection($i, $data);
        $evaluation = Rules::evaluate($i);
        return [
            'id' => $id,
            'result' => $evaluation['result'],
            'evaluation' => $evaluation,
            'spec' => $i['spec'],
        ];
    }

    public static function save(int $id, array $payload, bool $submit=false): array {
        $i=self::get($id); self::editable($i); $user=actor(); $type=self::type($i['product']); $table=self::itemTable($type); $recordTable=self::recordTable($type);
        $data=self::validateInspectionData($i,$payload['data']??[]); if($type==='CPETPE') $data['fields']['inspection_status']=self::ordinalInspection((int)($i['inspection_no']??1)); $updated=$i; $updated['data']=$data; $updated['spec']=self::specForInspection($i, $data); $evaluation=Rules::evaluate($updated);
        if($submit) demand($evaluation['can_submit'],'Complete the missing items in Review before submitting.');
        $version=filter_var($payload['version']??null,FILTER_VALIDATE_INT); demand($version!==false,'Record version is required.');
        $result=$evaluation['result']; $now=self::now(); $status=$submit?'COMPLETED':'IN_PROGRESS'; $disposition=self::disposition($result);
        $specId=$updated['spec']['id']??null;
        $parameterMap=self::parameterMap($type,$specId);
        $parameterId=function(string $key) use ($parameterMap,$type,$specId): int {
            $code=strtoupper($key);
            if(isset($parameterMap[$code])) return $parameterMap[$code];
            return self::parameterId($type,$specId,$code);
        };
        $auditJson=query("SELECT audit_json FROM $table WHERE id=?",[$id])->fetchColumn();
        $audit=json_decode($auditJson ?: '[]',true) ?: [];
        array_unshift($audit,['actor_id'=>$user['id'],'action'=>$submit?'completed':'draft_saved','details'=>['from_version'=>$version,'to_version'=>$version+1,'result'=>$result],'created_at'=>$now]);
        $auditJson=json_encode(array_slice($audit,0,250),JSON_THROW_ON_ERROR);
        $transactional = db_driver() !== 'turso';
        if($transactional) db()->beginTransaction();
        try {
            $summary=self::summaryColumns($type,$evaluation,$data);
            $set=[];$params=[];
            foreach($summary as $col=>$value){$set[]="$col=?";$params[]=$value;}
            array_push($params,json_encode(['fields'=>$data['fields'],'checked_categories'=>$data['checked_categories'],'defect_checks'=>$data['defect_checks'],'measurement_flags'=>$data['measurement_flags'],'section_flags'=>$data['section_flags']],JSON_THROW_ON_ERROR),json_encode($updated['spec'],JSON_THROW_ON_ERROR),$auditJson,$status,$disposition,$user['id'],$now,$submit?$now:null,$id,$version,'IN_PROGRESS');
            $updateSql="UPDATE $table SET ".implode(',',$set).",details_json=?,spec_snapshot_json=?,audit_json=?,inspection_status=?,final_disposition=?,version=version+1,updated_by=?,updated_at=?,submitted_at=? WHERE id=? AND version=? AND inspection_status=?";
            if(db_driver()==='turso') {
                $guard=[$id,$version+1,$status];
                $exists="EXISTS(SELECT 1 FROM $table WHERE id=? AND version=? AND inspection_status=?)";
                $statements=[['sql'=>$updateSql,'params'=>$params]];
                if($submit && $result==='fail') $statements[]=['sql'=>"UPDATE $table SET rejection_status=COALESCE(rejection_status,'open'),rejection_notes=COALESCE(rejection_notes,''),rejection_updated_at=COALESCE(rejection_updated_at,?) WHERE id=? AND version=? AND inspection_status=?",'params'=>[$now,...$guard]];
                $statements[]=['sql'=>"DELETE FROM $recordTable WHERE item_id=? AND $exists",'params'=>[$id,...$guard]];
                $statements[]=['sql'=>"DELETE FROM T_defect WHERE item_type=? AND item_id=? AND $exists",'params'=>[$type,$id,...$guard]];
                foreach($data['measurements'] as $key=>$values) foreach($values as $n=>$v) if($v!==null) $statements[]=['sql'=>"INSERT INTO $recordTable (item_id,parameter_id,sample_no,reading_no,value_num,created_at) SELECT ?,?,?,?,?,? WHERE $exists",'params'=>[$id,$parameterId($key),$n+1,1,(float)$v,$now,...$guard]];
                foreach($data['tests'] as $key=>$values) foreach($values as $n=>$v) $statements[]=['sql'=>"INSERT INTO $recordTable (item_id,parameter_id,sample_no,reading_no,value_text,created_at) SELECT ?,?,?,?,?,? WHERE $exists",'params'=>[$id,$parameterId($key),$n+1,1,$v,$now,...$guard]];
                foreach($data['defects'] as $d) $statements[]=['sql'=>"INSERT INTO T_defect (item_type,item_id,cpetpe_item_id,hdpe_item_id,defect_id,defect_category,defect_name,defect_code,quantity,created_at) SELECT ?,?,?,?,?,?,?,?,?,? WHERE $exists",'params'=>[$type,$id,$type==='CPETPE'?$id:null,$type==='HDPE'?$id:null,$d['defect_id'],self::categoryToDb($d['category']),$d['name'],$d['code']??'',(int)$d['quantity'],$now,...$guard]];
                $results=db()->executeTransaction($statements);
                demand((int)($results[0]['affected_row_count'] ?? 0)===1,'This inspection changed in another tab. Your work is retained locally. Reload the record to compare before saving.',409);
            } else {
                $stmt=query($updateSql,$params);
                $fresh=query("SELECT version, inspection_status FROM $table WHERE id=?",[$id])->fetch();
                $updatedOk=$fresh && (int)$fresh['version']===$version+1 && (string)$fresh['inspection_status']===$status;
                demand($stmt->rowCount()===1 || $updatedOk,'This inspection changed in another tab. Your work is retained locally. Reload the record to compare before saving.',409);
                if($submit && $result==='fail') query("UPDATE $table SET rejection_status=COALESCE(rejection_status,'open'),rejection_notes=COALESCE(rejection_notes,''),rejection_updated_at=COALESCE(rejection_updated_at,?) WHERE id=?",[$now,$id]);
                $statements=[
                    ['sql'=>"DELETE FROM $recordTable WHERE item_id=?", 'params'=>[$id]],
                    ['sql'=>'DELETE FROM T_defect WHERE item_type=? AND item_id=?', 'params'=>[$type,$id]],
                ];
                foreach($data['measurements'] as $key=>$values) foreach($values as $n=>$v) if($v!==null) $statements[]=['sql'=>"INSERT INTO $recordTable (item_id,parameter_id,sample_no,reading_no,value_num,created_at) VALUES (?,?,?,?,?,?)",'params'=>[$id,$parameterId($key),$n+1,1,(float)$v,$now]];
                foreach($data['tests'] as $key=>$values) foreach($values as $n=>$v) $statements[]=['sql'=>"INSERT INTO $recordTable (item_id,parameter_id,sample_no,reading_no,value_text,created_at) VALUES (?,?,?,?,?,?)",'params'=>[$id,$parameterId($key),$n+1,1,$v,$now]];
                foreach($data['defects'] as $d) $statements[]=['sql'=>'INSERT INTO T_defect (item_type,item_id,cpetpe_item_id,hdpe_item_id,defect_id,defect_category,defect_name,defect_code,quantity,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)','params'=>[$type,$id,$type==='CPETPE'?$id:null,$type==='HDPE'?$id:null,$d['defect_id'],self::categoryToDb($d['category']),$d['name'],$d['code']??'',(int)$d['quantity'],$now]];
                foreach($statements as $statement) query($statement['sql'],$statement['params']);
            }
            if($transactional) db()->commit();
        } catch(Throwable $e) {
            if($transactional) {
                try { db()->rollBack(); } catch(Throwable $rollbackError) { error_log((string)$rollbackError); }
            }
            throw $e;
        }
        return self::get($id);
    }

    private static function summaryColumns(string $type, array $evaluation, array $data): array {
        $checks=$evaluation['checks'];
        $countingMode = ($data['fields']['counting_mode'] ?? 'inner') === 'polybag' ? 'polybag' : 'inner';
        $cols=[
            'critical_defect_total'=>$checks['defect_Critical']['total']??0,
            'critical_defect_disposition'=>strtoupper($checks['defect_Critical']['result']??'PASS'),
            'holes_total'=>$checks['defect_Holes']['total']??0,
            'holes_disposition'=>strtoupper($checks['defect_Holes']['result']??'PASS'),
            'major_visual_total'=>$checks['defect_Major']['total']??0,
            'major_visual_disposition'=>strtoupper($checks['defect_Major']['result']??'PASS'),
            'minor_visual_total'=>$checks['defect_Minor']['total']??0,
            'minor_visual_disposition'=>strtoupper($checks['defect_Minor']['result']??'PASS'),
            'packaging_defect_total'=>$checks['defect_Packaging']['total']??0,
            'packaging_defect_disposition'=>strtoupper($checks['defect_Packaging']['result']??'PASS'),
            'sample_qty'=>$data['fields']['sample_qty']??'',
            'template_inspection_status'=>$data['fields']['inspection_status']??'',
            'qty_ctn_per_pallet'=>$data['fields']['cartons_per_pallet']??'',
            'inspection_date'=>$data['fields']['inspection_date']??'',
            'packer_id'=>$data['fields']['packer_id']??'',
            'inspector_id'=>$data['fields']['inspector_id']??'',
            'verify_id'=>$data['fields']['verify_id']??'',
            'counting_pcs_disposition'=>strtoupper($checks['count_pcs']['result']??'pending'),
            'counting_inner_disposition'=>$countingMode === 'inner' ? strtoupper($checks['count_inner']['result']??'pending') : 'NA',
            'counting_polybag_disposition'=>$countingMode === 'polybag' ? strtoupper($checks['count_polybag']['result']??'pending') : 'NA',
        ];
        foreach(['air_pump','fstm','header_seal','cuff_seal','glove_length','length_a','length_b','header_length','header_width','gap_length','palm_width','glove_width','middle_finger'] as $key) {
            $col=match($key){'header_seal'=>'header_sealing_disposition','cuff_seal'=>'cuff_sealing_disposition','middle_finger'=>'middle_finger_length_disposition',default=>$key.'_disposition'};
            if(isset($checks[$key])) $cols[$col]=strtoupper($checks[$key]['result']);
        }
        return $cols;
    }

    private static function countDisposition(string $value): string {
        return $value === '' ? 'PENDING' : ($value === 'PASS' ? 'PASS' : 'FAIL');
    }

    private static function categoryToDb(string $category): string {
        return match($category){'Major'=>'MAJOR_VISUAL','Minor'=>'MINOR_VISUAL','Critical'=>'CRITICAL','Holes'=>'HOLES','Packaging'=>'PACKAGING',default=>strtoupper($category)};
    }

    private static function categoryToUi(string $category): string {
        return match($category){'MAJOR_VISUAL'=>'Major','MINOR_VISUAL'=>'Minor','CRITICAL'=>'Critical','HOLES'=>'Holes','PACKAGING'=>'Packaging',default=>$category};
    }
}
