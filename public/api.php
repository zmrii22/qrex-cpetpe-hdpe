<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
session_name('qualis_session');
ini_set('session.use_strict_mode','1');
$sessionDir=ROOT.'/storage/sessions';
if(!is_dir($sessionDir)) @mkdir($sessionDir,0775,true);
if(!is_dir($sessionDir)||!is_writable($sessionDir)) {
    $sessionDir=rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'qrex-sessions-'.substr(hash('sha256', ROOT),0,12);
    if(!is_dir($sessionDir)) @mkdir($sessionDir,0775,true);
}
if(is_dir($sessionDir)&&is_writable($sessionDir)) session_save_path($sessionDir);
$scriptDir=str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
$cookiePath=($scriptDir===''||$scriptDir==='.'||$scriptDir==='/')?'/':rtrim($scriptDir,'/').'/';
$secureCookie=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))==='https';
session_set_cookie_params(['httponly'=>true,'secure'=>$secureCookie,'samesite'=>'Lax','path'=>$cookiePath]);
if(!RequestTiming::measure('session',fn()=>@session_start())) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'Session storage is not writable on this server. Give the quality/storage folder write permission, then try again.']);
    exit;
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function table_for_product(string $product): string { return $product === 'cpetpe' ? 'T_item_CPETPE' : 'T_item_HDPE'; }
function item_type_for_product(string $product): string { return $product === 'cpetpe' ? 'CPETPE' : 'HDPE'; }
function app_state(array $r): string {
    if ($r['inspection_status'] === 'IN_PROGRESS') return 'draft';
    if ($r['inspection_status'] === 'COMPLETED') return 'submitted';
    $v=json_decode($r['verification_json'] ?? '[]',true) ?: [];
    return $v[0]['decision'] ?? 'released';
}
function app_result(?string $v): string { return match(strtoupper((string)$v)){ 'PASS'=>'pass','FAIL'=>'fail','HOLD'=>'hold', default=>'pending' }; }
function item_row(int $id): array {
    foreach(['cpetpe'=>'T_item_CPETPE','hdpe'=>'T_item_HDPE'] as $product=>$table) {
        $row=query("SELECT *, '$product' AS product FROM $table WHERE id=?",[$id])->fetch();
        if($row) return $row;
    }
    demand(false,'Inspection not found.',404);
}
function all_items(): array {
    $rows=[];
    foreach(['cpetpe'=>'T_item_CPETPE','hdpe'=>'T_item_HDPE'] as $product=>$table) {
        foreach(query("SELECT i.*, '$product' AS product,u.full_name AS inspector FROM $table i JOIN M_user u ON u.id=i.created_by")->fetchAll() as $r) {
            $common=json_decode($r['common_json'],true) ?: [];
            $r['state']=app_state($r); $r['result']=app_result($r['final_disposition']);
            $r['glove_type']=$r['glove_type_code']; $r['so_number']=$r['sales_order_no'];
            $r['unit_label']='Pallet '.str_pad((string)$r['pallet_no'],2,'0',STR_PAD_LEFT);
            $r['customer']=$common['customer'] ?? ''; $r['common']=$common;
            $r['size_desc']=$common['size_desc'] ?? $r['size'];
            $rows[]=$r;
        }
    }
    usort($rows, fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return $rows;
}
function records_first_decision_sql(): string {
    return match (db_driver()) {
        'mysql' => "JSON_UNQUOTE(JSON_EXTRACT(i.verification_json,'$[0].decision'))",
        'sqlsrv' => "JSON_VALUE(i.verification_json,'$[0].decision')",
        default => "json_extract(i.verification_json,'$[0].decision')",
    };
}
function records_state_sql(): string {
    $decision=records_first_decision_sql();
    return "CASE WHEN i.inspection_status='IN_PROGRESS' THEN 'draft' WHEN i.inspection_status='COMPLETED' THEN 'submitted' ELSE COALESCE(NULLIF($decision,''),'released') END";
}
function records_result_sql(): string {
    return "CASE UPPER(COALESCE(i.final_disposition,'')) WHEN 'PASS' THEN 'pass' WHEN 'FAIL' THEN 'fail' WHEN 'HOLD' THEN 'hold' ELSE 'pending' END";
}
function records_like(string $value): string { return '%'.strtolower($value).'%'; }
function records_where(array $get): array {
    $where=[]; $params=[];
    $stateSql=records_state_sql(); $resultSql=records_result_sql();
    $commonItem=json_value_sql('i.common_json','item_no');
    $commonCustomer=json_value_sql('i.common_json','customer');
    $commonSizeDesc=json_value_sql('i.common_json','size_desc');
    foreach([
        'state'=>[$stateSql,'='],
        'result'=>[$resultSql,'='],
    ] as $key=>$spec) if(($get[$key]??'')!=='') {$where[]=$spec[0].' '.$spec[1].' ?';$params[]=safe_text($get[$key],100);}
    if(($get['size']??'')!=='') {
        $where[]='(i.size=? OR COALESCE('.$commonSizeDesc.',i.size)=?)';
        $size=safe_text($get['size'],100);
        array_push($params,$size,$size);
    }
    if(($get['so']??'')!=='') {
        $where[]='i.sales_order_no=?';
        $params[]=safe_text($get['so'],100);
    }
    foreach([
        'glove'=>'i.glove_type_code',
        'item'=>"COALESCE($commonItem,'')",
        'brand'=>"COALESCE(i.brand,'')",
        'customer'=>"COALESCE($commonCustomer,i.customer,'')",
    ] as $key=>$expr) if(($get[$key]??'')!=='') {$where[]="LOWER($expr) LIKE ?";$params[]=records_like(safe_text($get[$key],100));}
    if(($get['q']??'')!=='') {
        $q=records_like(safe_text($get['q'],100));
        $where[]="(LOWER(i.sales_order_no) LIKE ? OR LOWER(COALESCE(i.glove_type_code,'')) LIKE ? OR LOWER(COALESCE($commonCustomer,i.customer,'')) LIKE ? OR LOWER(COALESCE($commonSizeDesc,i.size,'')) LIKE ? OR CAST(i.pallet_no AS TEXT) LIKE ?)";
        array_push($params,$q,$q,$q,$q,$q);
    }
    if(($get['from']??'')!=='') {$where[]='i.updated_at>=?';$params[]=safe_text($get['from'],10).' 00:00:00';}
    if(($get['to']??'')!=='') {$where[]='i.updated_at<=?';$params[]=safe_text($get['to'],10).' 23:59:59';}
    return [$where?' WHERE '.implode(' AND ',$where):'', $params];
}
function records_prepare_row(array $r): array {
    $common=json_decode($r['common_json'],true) ?: [];
    $r['state']=$r['state'] ?? app_state($r);
    $r['result']=$r['result'] ?? app_result($r['final_disposition']);
    $r['glove_type']=$r['glove_type_code'];
    $r['so_number']=$r['sales_order_no'];
    $r['unit_label']='Pallet '.str_pad((string)$r['pallet_no'],2,'0',STR_PAD_LEFT);
    $r['customer']=$common['customer'] ?? ($r['customer'] ?? '');
    $r['common']=$common;
    $r['size_desc']=$common['size_desc'] ?? $r['size'];
    return $r;
}
function records_page(bool $export=false): array {
    $product=safe_text($_GET['product']??'',20);
    if($product!=='' && trim((string)($_GET['so']??''))==='') return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'requires_so'=>true];
    $products=$product!==''?[$product=>table_for_product($product)]:['cpetpe'=>'T_item_CPETPE','hdpe'=>'T_item_HDPE'];
    $page=max(1,(int)($_GET['page']??1));
    $showWholeSo=$product!=='' && trim((string)($_GET['so']??''))!=='';
    $pageSize=$export?10000:($showWholeSo?500:20);
    $offset=$export?0:($page-1)*$pageSize;
    $rows=[]; $total=0; $statements=[];
    foreach($products as $p=>$table) {
        if(!in_array($p,['cpetpe','hdpe'],true)) continue;
        [$where,$params]=records_where($_GET);
        $statements[]=['sql'=>"SELECT COUNT(*) AS total FROM $table i$where",'params'=>$params];
        $stateSql=records_state_sql(); $resultSql=records_result_sql();
        $limit=$product!==''?$pageSize:$offset+$pageSize;
        $tableOffset=$product!==''?$offset:0;
        $sql="SELECT i.*, '$p' AS product, u.full_name AS inspector, $stateSql AS state, $resultSql AS result FROM $table i JOIN M_user u ON u.id=i.created_by$where ORDER BY i.updated_at DESC, i.id DESC LIMIT ? OFFSET ?";
        $statements[]=['sql'=>$sql,'params'=>array_merge($params,[$limit,$tableOffset])];
    }
    foreach(query_all_batch($statements) as $index=>$result) {
        if($index%2===0) $total+=(int)($result[0]['total'] ?? 0);
        else foreach($result as $row) $rows[]=records_prepare_row($row);
    }
    usort($rows, fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']) ?: ((int)$b['id'] <=> (int)$a['id']));
    if($product==='') $rows=array_slice($rows,$offset,$pageSize);
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$pageSize))];
}
function spec_rows(?array $rows=null): array {
    $rows ??= query(spec_rows_sql())->fetchAll();
    foreach($rows as &$r) if($r['product']==='cpetpe') $r['product']='cpetpe'; else $r['product']='hdpe';
    return $rows;
}
function spec_rows_sql(): string {
    return "SELECT spec_id AS id,lower(inspection_type) AS product,reference_name,product_type,glove_type,size,revision,source,active FROM M_masterlist_specifications WHERE active=1 GROUP BY spec_id ORDER BY inspection_type,glove_type,size,reference_name";
}
function workspace_metadata_sql(): string {
    return "SELECT 'specifications' AS kind,COUNT(*) AS count,COALESCE(MAX(updated_at),'') AS updated_at,COALESCE(MAX(id),0) AS max_id FROM M_masterlist_specifications
        UNION ALL SELECT 'equipment',COUNT(*),COALESCE(MAX(updated_at),''),COALESCE(MAX(id),0) FROM M_equipment";
}
function workspace_metadata_version(array $rows): string {
    $meta=[];
    foreach($rows as $row) $meta[$row['kind']]=['count'=>(int)$row['count'],'updated_at'=>$row['updated_at'],'max_id'=>(int)$row['max_id']];
    $masterPath=ROOT.'/resources/master.json';
    return sha1(json_encode(['metadata'=>$meta,'master_file'=>is_file($masterPath)?filemtime($masterPath):0,'sampling'=>Sampling::master(),'app'=>'qrex-master-v5'],JSON_THROW_ON_ERROR));
}
function update_item(int $id, array $sets, array $params=[]): void {
    $row=item_row($id); $table=table_for_product($row['product']);
    $sql=[]; foreach($sets as $k=>$v){$sql[]="$k=?";$params[]=$v;}
    $params[]=$id; query("UPDATE $table SET ".implode(',',$sql)." WHERE id=?",$params);
}
function qrex_ordinal_inspection(int $n): string {
    $mod100=$n%100;
    $suffix=($mod100>=11&&$mod100<=13)?'th':match($n%10){1=>'st',2=>'nd',3=>'rd',default=>'th'};
    return $n.$suffix.' Inspection';
}
function qrex_cpetpe_uid(array $row, int $inspectionNo): string {
    return implode('-',[
        (string)$row['sales_order_no'],
        (string)$row['so_item_no'],
        (string)$row['size'],
        (string)$row['pallet_no'],
        (string)$inspectionNo,
    ]);
}
function qrex_defect_category(string $category): string {
    return match($category){'CRITICAL'=>'Critical','HOLES'=>'Holes','MAJOR_VISUAL'=>'Major','MINOR_VISUAL'=>'Minor','PACKAGING'=>'Packaging',default=>$category};
}
function qrex_record_values(string $product, int $id, string $parameterCode, string $field='value_text'): array {
    $type=item_type_for_product($product);
    $code=strtoupper($parameterCode);
    $allowed=$field==='value_num'?'value_num':'value_text';
    return query("SELECT r.$allowed AS value FROM T_record_$type r JOIN M_masterlist_specifications m ON m.id=r.parameter_id WHERE r.item_id=? AND m.parameter_code=? ORDER BY r.sample_no,r.reading_no,r.id",[$id,$code])->fetchAll();
}
function qrex_test_result(string $product, int $id, string $key): string {
    $rows=qrex_record_values($product,$id,$key,'value_text');
    if(!$rows) return 'PENDING';
    $values=array_map(fn($r)=>strtolower((string)$r['value']),$rows);
    if(in_array('fail',$values,true)) return 'FAIL';
    if(in_array('pass',$values,true)) return 'PASS';
    return 'PENDING';
}
function qrex_first_reading(string $product, int $id, string $key): string {
    $rows=qrex_record_values($product,$id,$key,'value_num');
    if(!$rows || $rows[0]['value']==='') return 'Not recorded';
    return (string)(0 + (float)$rows[0]['value']);
}
function qrex_cached_test_result(array $r, string $key): string {
    $details=json_decode($r['details_json'] ?? '{}',true) ?: [];
    if (($details['section_flags']['tests'] ?? '')==='pass') return 'PASS';
    if(!array_key_exists('_records',$r)) return qrex_test_result($r['product'],(int)$r['id'],$key);
    $records=$r['_records'][strtoupper($key)] ?? [];
    if(!$records) return 'PENDING';
    $values=array_map(fn($row)=>strtolower((string)($row['value_text'] ?? '')),$records);
    if(in_array('fail',$values,true)) return 'FAIL';
    if(in_array('pass',$values,true)) return 'PASS';
    return 'PENDING';
}
function qrex_cached_first_reading(array $r, string $key): string {
    if(!array_key_exists('_records',$r)) return qrex_first_reading($r['product'],(int)$r['id'],$key);
    $records=$r['_records'][strtoupper($key)] ?? [];
    if(!$records || ($records[0]['value_num'] ?? '')==='') return 'Not recorded';
    return (string)(0 + (float)$records[0]['value_num']);
}
function enrich_template_summaries(array $rows): array {
    $groups=[]; $statements=[]; $maps=[];
    foreach($rows as $index=>$row) $groups[$row['product']][]=['index'=>$index,'id'=>(int)$row['id']];
    foreach($groups as $product=>$items) {
        $ids=array_values(array_unique(array_map(fn($i)=>$i['id'],$items)));
        if(!$ids) continue;
        $type=item_type_for_product($product);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $indexById=[]; foreach($items as $item) $indexById[$item['id']][]=$item['index'];
        $maps[]=$indexById;
        $statements[]=['sql'=>"SELECT item_id,defect_category,defect_name,quantity FROM T_defect WHERE item_type=? AND item_id IN ($placeholders) ORDER BY defect_category,defect_name",'params'=>array_merge([$type],$ids)];
        $statements[]=['sql'=>"WITH summary AS (
            SELECT r.item_id,m.parameter_code,r.value_num,
                COUNT(*) OVER (PARTITION BY r.item_id) AS reading_count,
                ROW_NUMBER() OVER (PARTITION BY r.item_id,m.parameter_code ORDER BY r.sample_no,r.reading_no,r.id) AS reading_rank,
                MAX(CASE LOWER(r.value_text) WHEN 'fail' THEN 2 WHEN 'pass' THEN 1 ELSE 0 END)
                    OVER (PARTITION BY r.item_id,m.parameter_code) AS test_score
            FROM T_record_$type r JOIN M_masterlist_specifications m ON m.id=r.parameter_id WHERE r.item_id IN ($placeholders)
        ) SELECT item_id,parameter_code,value_num,reading_count,
            CASE test_score WHEN 2 THEN 'fail' WHEN 1 THEN 'pass' ELSE '' END AS value_text
            FROM summary WHERE reading_rank=1",'params'=>$ids];
    }
    $results=$statements?query_all_batch($statements):[];
    foreach($maps as $group=>$indexById) {
        foreach($results[$group*2] as $d) foreach($indexById[(int)$d['item_id']] ?? [] as $index) {
            $rows[$index]['_defects'][]=$d;
        }
        foreach($results[$group*2+1] as $record) foreach($indexById[(int)$record['item_id']] ?? [] as $index) {
            $code=strtoupper((string)$record['parameter_code']);
            $rows[$index]['_records'][$code][]=$record;
            $rows[$index]['_reading_count']=(int)$record['reading_count'];
        }
    }
    foreach($rows as &$row) {
        $row['_defects'] ??= [];
        $row['_records'] ??= [];
        $row['_reading_count'] ??= 0;
    }
    unset($row);
    return $rows;
}
function template_summary(array $r): array {
    $common=$r['common'] ?? [];
    $details=json_decode($r['details_json'] ?? '{}',true) ?: [];
    $fields=$details['fields'] ?? [];
    // Display overrides affect dispositions only, never the recorded values.
    $dispositions=[
        'defects'=>['critical_defect','holes','major_visual','minor_visual','packaging_defect'],
        'tests'=>['air_pump','fstm','header_sealing','cuff_sealing'],
        'measurements'=>['glove_length','length_a','length_b','header_length','header_width','gap_length','palm_width','glove_width','middle_finger_length','thickness'],
        'packing'=>['counting_pcs','counting_inner','counting_polybag'],
    ];
    foreach($dispositions as $section=>$columns) {
        if(($details['section_flags'][$section] ?? '')!=='pass') continue;
        foreach($columns as $column) $r[$column.'_disposition']='PASS';
    }
    $defects=$r['_defects'] ?? query('SELECT defect_category, defect_name, quantity FROM T_defect WHERE item_type=? AND item_id=? ORDER BY defect_category, defect_name',[item_type_for_product($r['product']),(int)$r['id']])->fetchAll();
    $defectByCategory=[];
    foreach($defects as $d) {
        $category=qrex_defect_category((string)$d['defect_category']);
        $defectByCategory[$category]??=['names'=>[],'total'=>0];
        $defectByCategory[$category]['names'][]=$d['defect_name'].' ('.$d['quantity'].')';
        $defectByCategory[$category]['total']+=(int)$d['quantity'];
    }
    $cat=function(string $category, string $part) use ($defectByCategory,$r): string {
        if($part==='Defects Name') return isset($defectByCategory[$category]) ? implode(', ',$defectByCategory[$category]['names']) : 'None';
        if($part==='Total') return (string)($defectByCategory[$category]['total'] ?? 0);
        $map=['Critical'=>'critical_defect_disposition','Holes'=>'holes_disposition','Major'=>'major_visual_disposition','Minor'=>'minor_visual_disposition','Packaging'=>'packaging_defect_disposition'];
        return (string)($r[$map[$category] ?? ''] ?? 'PASS');
    };
    $defectTotal=array_reduce($defects,fn($n,$d)=>$n+(int)$d['quantity'],0);
    $defectNames=implode(', ',array_map(fn($d)=>$d['defect_name'].' ('.$d['quantity'].')',$defects));
    $readingCount=(int)($r['_reading_count'] ?? query('SELECT COUNT(*) FROM T_record_'.item_type_for_product($r['product']).' WHERE item_id=?',[(int)$r['id']])->fetchColumn());
    $inspectionStatus=($fields['inspection_status'] ?? '') ?: ($r['template_inspection_status'] ?: ($r['product']==='cpetpe'?qrex_ordinal_inspection((int)($r['inspection_no'] ?? 1)):'1st Inspection'));
    $counting=implode(' / ',array_values(array_filter([
        $r['counting_pcs_disposition'] ? 'PCS '.$r['counting_pcs_disposition'] : '',
        $r['counting_inner_disposition'] ? 'Inner '.$r['counting_inner_disposition'] : '',
        $r['counting_polybag_disposition'] ? 'Polybag '.$r['counting_polybag_disposition'] : '',
    ])));
    $pf=strtoupper((string)($r['final_disposition'] ?: 'PENDING'));
    return [
        'Fac'=>$r['fac'] ?: ($common['factory'] ?? ''),
        'SO No.'=>$r['sales_order_no'] ?? '',
        'Item No.'=>$common['item_no'] ?? '',
        'Lot No.'=>$common['lot_no'] ?? '',
        'Customer'=>$r['customer'] ?: ($common['customer'] ?? ''),
        'Country'=>$r['country'] ?: ($common['country'] ?? ''),
        'Product Type'=>$r['product_type'] ?: ($common['product_type'] ?? ''),
        'Glove Type / Code'=>$r['glove_type_code'] ?? '',
        'Pallet No.'=>str_pad((string)$r['pallet_no'],2,'0',STR_PAD_LEFT),
        'Size'=>$r['size_desc'] ?? ($common['size_desc'] ?? ($r['size'] ?? '')),
        'Color'=>$r['color'] ?: ($common['colour'] ?? ''),
        'Brand'=>$r['brand'] ?: ($common['brand'] ?? ''),
        'Sample Qty (Ctn x pcs)'=>$r['sample_qty'] ?: ($fields['sample_qty'] ?? ''),
        'Sample Size'=>$fields['sample_size'] ?? '',
        'Inspection Plan'=>array_column(Sampling::master()['plans'],'label','value')[$fields['inspection_plan']??''] ?? 'Existing sampling rule',
        'Inspection Count'=>$inspectionStatus,
        'Qty of Ctn per Pallet'=>$r['qty_ctn_per_pallet'] ?: ($fields['cartons_per_pallet'] ?? ''),
        'Inspection Date'=>$r['inspection_date'] ?: ($fields['inspection_date'] ?? ''),
        'Critical defect · Defects Name'=>$cat('Critical','Defects Name'),
        'Critical defect · Total'=>$cat('Critical','Total'),
        'Critical defect · Disposition'=>$cat('Critical','Disposition'),
        'Holes · Defects Name'=>$cat('Holes','Defects Name'),
        'Holes · Total'=>$cat('Holes','Total'),
        'Holes · Disposition'=>$cat('Holes','Disposition'),
        'Major Viusal · Defects Name'=>$cat('Major','Defects Name'),
        'Major Viusal · Total'=>$cat('Major','Total'),
        'Major Viusal · Disposition'=>$cat('Major','Disposition'),
        'Minor Visual · Defects Name'=>$cat('Minor','Defects Name'),
        'Minor Visual · Total'=>$cat('Minor','Total'),
        'Minor Visual · Disposition'=>$cat('Minor','Disposition'),
        'Packaging Defects · Disposition'=>(string)($r['packaging_defect_disposition'] ?? 'PASS'),
        'Air Pump Test · Disposition'=>(string)($r['air_pump_disposition'] ?? 'PENDING'),
        'FSTM · Disposition'=>(string)($r['fstm_disposition'] ?? 'PENDING'),
        'Header Sealing · Disposition'=>(string)($r['header_sealing_disposition'] ?? qrex_cached_test_result($r,'header_seal')),
        'Cuff Sealing Test · Disposition'=>(string)($r['cuff_sealing_disposition'] ?? qrex_cached_test_result($r,'cuff_seal')),
        'Donning & Tearng · P/F'=>qrex_cached_test_result($r,'donning'),
        'Glove Weight (g) · Reading'=>qrex_cached_first_reading($r,'weight'),
        'Glove Length (mm) · Disposition'=>(string)($r['glove_length_disposition'] ?? 'PENDING'),
        'Length A (mm) · Disposition'=>(string)($r['length_a_disposition'] ?? 'PENDING'),
        'Length B (mm) · Disposition'=>(string)($r['length_b_disposition'] ?? 'PENDING'),
        'Header Length (mm) · Disposition'=>(string)($r['header_length_disposition'] ?? 'PENDING'),
        'Header Width (mm) · Disposition'=>(string)($r['header_width_disposition'] ?? 'PENDING'),
        'Gap Length (mm) · Disposition'=>(string)($r['gap_length_disposition'] ?? 'PENDING'),
        'Palm Width (mm) · Disposition'=>(string)($r['palm_width_disposition'] ?? 'PENDING'),
        'Glove Width (mm) · Disposition'=>(string)($r['glove_width_disposition'] ?? 'PENDING'),
        'Middle Finger Length (mm) · Disposition'=>(string)($r['middle_finger_length_disposition'] ?? 'PENDING'),
        'Counting - Pcs · Disposition'=>(string)($r['counting_pcs_disposition'] ?: 'PENDING'),
        'Counting - Inner · Disposition'=>(string)($r['counting_inner_disposition'] ?: 'PENDING'),
        'Counting - Polybag · Disposition'=>(string)($r['counting_polybag_disposition'] ?: 'PENDING'),
        'Critical defect'=>($r['critical_defect_total'] ?? 0).' / '.$r['critical_defect_disposition'],
        'Holes'=>($r['holes_total'] ?? 0).' / '.$r['holes_disposition'],
        'Major Visual'=>($r['major_visual_total'] ?? 0).' / '.$r['major_visual_disposition'],
        'Minor Visual'=>($r['minor_visual_total'] ?? 0).' / '.$r['minor_visual_disposition'],
        'Packaging Defects'=>($r['packaging_defect_total'] ?? 0).' / '.$r['packaging_defect_disposition'],
        'Counting'=>$counting ?: 'PENDING',
        'Packer ID'=>$r['packer_id'] ?: ($fields['packer_id'] ?? ''),
        'Inspector ID'=>$r['inspector_id'] ?: ($fields['inspector_id'] ?? ''),
        'Verify ID'=>$r['verify_id'] ?? '',
        'Verify Date'=>$r['verify_date'] ?? '',
        'Final Disposition'=>$r['final_disposition'] ?? '',
        'Defects Name'=>$defectNames ?: 'None',
        'Total'=>$defectTotal,
        'Disposition'=>$r['final_disposition'] ?? '',
        'P/F'=>$pf,
        'Reading'=>$readingCount.' saved',
    ];
}
function ensure_auth_storage(): void {
    static $done=false;
    if($done) return;
    try {
        query('SELECT id FROM M_user LIMIT 1')->fetch();
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        if(!str_contains($message, 'no such table') && !str_contains($message, 'does not exist')) throw $e;
        Database::install(db(), false);
        Database::seed(db(), master());
    }
    $done=true;
}

try {
    $action=$_GET['action']??'bootstrap'; $method=$_SERVER['REQUEST_METHOD'];
    if(isset($_SESSION['last_seen'])&&time()-$_SESSION['last_seen']>28800) {$_SESSION=[];session_regenerate_id(true);}
    $_SESSION['last_seen']=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $input=[];
    if($method==='POST') {
        demand(hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''),'Your session changed. Refresh the page and try again.',419);
        demand((int)($_SERVER['CONTENT_LENGTH']??0)<=250000,'The request is too large.',413);
        $input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR); demand(is_array($input),'Invalid request.');
    } else demand($method==='GET','Method not allowed.',405);
    $write=['login','register','forgot_password','logout','create','save','submit','verify','reopen','retest','rejection','user','delete_user','password','specification','delete_spec','apply_spec','order','delete_draft','report_execute'];
    if(in_array($action,$write,true)) demand($method==='POST','Use POST for this action.',405);
    // Normal requests only read the session; release its lock before remote I/O.
    if(!in_array($action,['session','login','register','logout'],true)) session_write_close();
    if($action==='session') {
        ensure_auth_storage();
        $user=null; if(isset($_SESSION['uid'])) { try{$user=actor();}catch(DomainException $e){unset($_SESSION['uid']);} }
        json_out(['user'=>$user,'csrf'=>$_SESSION['csrf']]);
    }
    if($action==='login') {
        ensure_auth_storage();
        $email=strtolower(safe_text($input['email']??'',190));
        $u=query('SELECT * FROM M_user WHERE email=? OR username=?',[$email,$email])->fetch();
        $valid=password_verify((string)($input['password']??''),$u['password_hash']??'$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        demand($u&&$u['active']&&$valid,'Email or password is incorrect.',401);
        session_regenerate_id(true); $_SESSION['uid']=$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
        json_out(['user'=>actor(),'csrf'=>$_SESSION['csrf']]);
    }
    if($action==='register') {
        ensure_auth_storage();
        $name=safe_text($input['name']??'',120);
        $email=strtolower(safe_text($input['email']??'',190));
        $employee=safe_text($input['employee_id']??'',80);
        $factory=safe_text($input['factory']??'',40);
        $password=(string)($input['password']??'');
        $confirm=(string)($input['confirm_password']??'');
        demand($name!==''&&filter_var($email,FILTER_VALIDATE_EMAIL),'Enter your name and a valid email.');
        demand(strlen($password)>=6&&strlen($password)<=200,'Use a password of 6 to 200 characters.');
        demand(hash_equals($password,$confirm),'Password confirmation does not match.');
        demand(!query('SELECT id FROM M_user WHERE email=? OR username=?',[$email,$email])->fetch(),'This email already has an account. Use Forgot password instead.');
        $now=date('Y-m-d H:i:s');
        $firstUser=(int)query('SELECT COUNT(*) FROM M_user')->fetchColumn()===0;
        $role=$firstUser?'admin':'inspector';
        query('INSERT INTO M_user (username,email,password_hash,full_name,employee_id,factory,role,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$email,$email,password_hash($password,PASSWORD_DEFAULT),$name,$employee,$factory,$role,1,$now,$now]);
        session_regenerate_id(true); $_SESSION['uid']=last_insert_id(); $_SESSION['csrf']=bin2hex(random_bytes(32));
        json_out(['user'=>actor(),'csrf'=>$_SESSION['csrf'],'message'=>$firstUser?'Administrator account created.':'Account created.']);
    }
    if($action==='forgot_password') {
        ensure_auth_storage();
        $email=strtolower(safe_text($input['email']??'',190));
        $password=(string)($input['password']??'');
        $confirm=(string)($input['confirm_password']??'');
        demand($email!=='','Enter your username or email.');
        demand(strlen($password)>=6&&strlen($password)<=200,'Use a password of 6 to 200 characters.');
        demand(hash_equals($password,$confirm),'Password confirmation does not match.');
        $u=query('SELECT id,employee_id FROM M_user WHERE email=? OR username=?',[$email,$email])->fetch();
        demand((bool)$u,'Account not found. Create a new account instead.',404);
        $employee=safe_text($input['employee_id']??'',80);
        if(($u['employee_id']??'')!=='') demand(hash_equals((string)$u['employee_id'],$employee),'Employee ID does not match this account.',403);
        query('UPDATE M_user SET password_hash=?,updated_at=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),date('Y-m-d H:i:s'),$u['id']]);
        json_out(['ok'=>true,'message'=>'Password updated. You can sign in now.']);
    }
    $user=actor();
    $GLOBALS['qrexRequestActor']=$user;
    if($action==='logout') { unset($_SESSION['uid']); session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); json_out(['ok'=>true,'csrf'=>$_SESSION['csrf']]); }
    if($action==='bootstrap') {
        $meta=master()['products'];
        [$specs,$equipment,$metadata]=query_all_batch([
            ['sql'=>spec_rows_sql()],
            ['sql'=>"SELECT product,equipment_type,equipment_id FROM M_equipment WHERE active=1 ORDER BY product,equipment_type,sequence_no,equipment_id"],
            ['sql'=>workspace_metadata_sql()],
        ]);
        json_out(['user'=>$user,'products'=>$meta,'sampling'=>Sampling::master(),'specifications'=>spec_rows($specs),'defects'=>master()['defects'],'equipment'=>ManpowerSync::equipmentOptions($equipment),'version'=>workspace_metadata_version($metadata),'csrf'=>$_SESSION['csrf']]);
    }
    if($action==='bootstrap_version') {
        [$metadata]=query_all_batch([['sql'=>workspace_metadata_sql()]]);
        $version=workspace_metadata_version($metadata);
        json_out(['version'=>$version,'csrf'=>$_SESSION['csrf']]);
    }
    if($action==='spec') { $s=Inspections::specification(safe_text($_GET['id']??'',80)); demand((bool)$s,'Specification not found.',404); json_out($s); }
    if($action==='order_lookup') json_out(Inspections::orderLookup((string)($_GET['product']??''),(string)($_GET['factory']??''),(string)($_GET['so']??'')));
    if($action==='inspection') { $i=Inspections::get((int)($_GET['id']??0)); $i['editable']=$i['state']==='draft'&&($user['role']==='admin'||$user['role']==='supervisor'||($user['role']==='inspector'&&(int)$i['created_by']===(int)$user['id'])); json_out($i); }
    if($action==='create') json_out(['id'=>Inspections::create($input)],201);
    if($action==='delete_draft') json_out(Inspections::deleteDraft((int)($input['id']??0)));
    if($action==='order') {
        role(['inspector','supervisor','admin']);
        $id=(int)($input['set_id']??0); $i=Inspections::get($id); Inspections::editable($i);
        demand(hash_equals($i['common_token'],(string)($input['token']??'')),'Order details changed. Reload before editing.',409);
        $common=$i['common']; foreach(['factory','item_no','lot_no','customer','country','colour','brand','product_type','order_notes'] as $key)$common[$key]=safe_text($input[$key]??$common[$key]??'',500);
        $gloveType=safe_text($input['glove_type_code']??($i['glove_type']??''),80);
        if($gloveType!=='') $common['spec_glove_type']=$gloveType;
        $row=item_row($id); $table=table_for_product($row['product']);
        demand((int)query("SELECT COUNT(*) FROM $table WHERE sales_order_no=? AND so_item_no=? AND inspection_status<>'IN_PROGRESS'",[$row['sales_order_no'],$row['so_item_no']])->fetchColumn()===0,'Shared order details are locked after any unit is completed. Reopen all units before correcting the order.',409);
        $units=query("SELECT id,details_json FROM $table WHERE sales_order_no=? AND so_item_no=?",[$row['sales_order_no'],$row['so_item_no']])->fetchAll();
        foreach($units as $unit) {
            $details=json_decode($unit['details_json'] ?: '{}',true) ?: [];
            $details['fields']??=[];
            if($gloveType!=='') $details['fields']['glove_type_code']=$gloveType;
            query("UPDATE $table SET common_json=?,glove_type_code=?,details_json=?,version=version+1,updated_by=?,updated_at=? WHERE id=?",[json_encode($common,JSON_THROW_ON_ERROR),$gloveType ?: $row['glove_type_code'],json_encode($details,JSON_THROW_ON_ERROR),$user['id'],date('Y-m-d H:i:s'),$unit['id']]);
            audit('order_corrected','inspection',(string)$unit['id'],['after'=>$common,'glove_type_code'=>$gloveType]);
        }
        json_out(['ok'=>true]);
    }
    if($action==='evaluate') json_out(Inspections::evaluatePayload((int)($input['id']??0),$input));
    if($action==='save'||$action==='submit') {
        $saved=Inspections::save((int)($input['id']??0),$input,$action==='submit');
        if($action==='submit'&&!empty($input['next_pallet'])) $saved['next_inspection_id']=Inspections::nextUnfinished($saved,$user);
        json_out($saved);
    }
    if($action==='records'||$action==='export') {
        $pageData=records_page($action==='export');
        $total=$pageData['total']; $page=$pageData['page']; $pages=$pageData['pages']; $slice=enrich_template_summaries($pageData['rows']);
        $out=array_map(fn($r)=>['id'=>(int)$r['id'],'set_id'=>(int)$r['sales_order_id'],'unit_label'=>$r['unit_label'],'state'=>$r['state'],'result'=>$r['result'],'version'=>(int)$r['version'],'created_by'=>(int)$r['created_by'],'updated_at'=>$r['updated_at'],'product'=>$r['product'],'so_number'=>$r['sales_order_no'],'glove_type'=>$r['glove_type_code'],'size'=>$r['size'],'size_desc'=>$r['size_desc'] ?? $r['size'],'inspector'=>$r['inspector'],'customer'=>$r['customer'],'template'=>template_summary($r)],$slice);
        if($action==='export') $out=array_map(function($r){$r['template_json']=json_encode($r['template'],JSON_UNESCAPED_UNICODE);unset($r['template']);return $r;},$out);
        if($action==='export') { header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="inspection-records.csv"');$f=fopen('php://output','w'); if($out){fputcsv($f,array_keys($out[0]));foreach($out as $r)fputcsv($f,$r);}fclose($f);exit; }
        json_out(['rows'=>$out,'total'=>$total,'page'=>$page,'pages'=>$pages]);
    }
    if($action==='recent_so') {
        $product=safe_text($_GET['product']??'',20);
        demand(in_array($product,['hdpe','cpetpe'],true),'Choose HDPE or CPE / TPE.');
        $table=table_for_product($product);
        $row=query("SELECT sales_order_no FROM $table ORDER BY updated_at DESC, id DESC LIMIT 1")->fetch();
        demand((bool)$row,'No recent inspections found.',404);
        $so=(string)$row['sales_order_no'];
        $total=(int)query("SELECT COUNT(*) FROM $table WHERE sales_order_no=?",[$so])->fetchColumn();
        json_out(['so'=>$so,'total'=>$total]);
    }
    if($action==='dashboard') json_out(Reports::overview());
    if($action==='reports') {
        $date=safe_text($_GET['date']??($_GET['from']??date('Y-m-d')),10);
        $saved=Reports::load($date,isset($_GET['version'])?(int)$_GET['version']:null);
        json_out(['snapshot'=>$saved,'saved'=>Reports::listSaved()]);
    }
    if($action==='report_execute') json_out(Reports::execute(safe_text($input['date']??'',10),$user),201);
    if($action==='saved_reports') json_out(Reports::listSaved(null,isset($_GET['before'])?(int)$_GET['before']:null));
    if($action==='audit') {
        $i=Inspections::get((int)($_GET['id']??0)); $audit=json_decode(item_row($i['id'])['audit_json'] ?: '[]', true) ?: [];
        $rows=[]; foreach($audit as $n=>$a){$actor=$a['actor_id']?query('SELECT full_name FROM M_user WHERE id=?',[$a['actor_id']])->fetchColumn():null;$rows[]=['id'=>$n+1,'action'=>$a['action'],'actor'=>$actor ?: 'System','details'=>$a['details'] ?? [],'created_at'=>$a['created_at']];}
        json_out(['rows'=>$rows]);
    }
    if($action==='verify'||$action==='reopen'||$action==='retest'||$action==='apply_spec') {
        role(['supervisor','admin']); $id=(int)($input['id']??0); $i=Inspections::get($id); $row=item_row($id); $table=table_for_product($row['product']); $notes=safe_text($input['notes']??'',4000); demand(strlen($notes)>=5,'Record a reason of at least 5 characters.'); $version=(int)($input['version']??0);
        demand((int)$row['version']===$version,'The record changed. Reload before continuing.',409);
        $auditDetails=['notes'=>$notes];
        if($action==='verify') {
            demand($i['state']==='submitted','Only submitted inspections can be verified.',409); demand((int)$i['updated_by']!==(int)$user['id'],'A different supervisor must verify the submitted inspection.',403);
            $decision=$input['decision']??''; demand(in_array($decision,['released','held','rejected'],true),'Choose a verification decision.');
            demand($decision!=='released'||($i['evaluation']['result']==='pass'&&$i['evaluation']['can_submit']),'Only complete PASS inspections can be released. Reopen and resolve failures or missing specifications first.');
            $ver=json_decode($row['verification_json'] ?: '[]',true) ?: []; array_unshift($ver,['reviewer'=>$user['name'],'reviewer_id'=>$user['id'],'decision'=>$decision,'notes'=>$notes,'inspection_version'=>$version,'created_at'=>date('Y-m-d H:i:s')]);
            $disp=$decision==='released'?'PASS':($decision==='rejected'?'FAIL':'HOLD');
            query("UPDATE $table SET inspection_status='VERIFIED',final_disposition=?,verification_json=?,verify_id=?,verify_date=?,version=version+1,updated_by=?,updated_at=? WHERE id=?",[$disp,json_encode($ver,JSON_THROW_ON_ERROR),(string)$user['id'],date('Y-m-d'),$user['id'],date('Y-m-d H:i:s'),$id]);
        } elseif($action==='reopen') {
            demand($i['state']!=='draft','This record is already a draft.',409);
            query("UPDATE $table SET inspection_status='IN_PROGRESS',version=version+1,updated_by=?,updated_at=? WHERE id=?",[$user['id'],date('Y-m-d H:i:s'),$id]);
        } elseif($action==='retest') {
            demand($row['product']==='cpetpe','Re-test is only available for CPE / TPE inspections.',409);
            demand($i['state']!=='draft','Submit this inspection before creating the next re-test.',409);
            $nextInspectionNo=max(2,(int)($row['inspection_no'] ?? 1)+1);
            demand($nextInspectionNo<=4,'Maximum CPE / TPE re-test is 4th inspection.',409);
            $newUid=qrex_cpetpe_uid($row,$nextInspectionNo);
            demand((int)query('SELECT COUNT(*) FROM T_item_CPETPE WHERE inspection_uid=? AND id<>?',[$newUid,$id])->fetchColumn()===0,'The next CPETPE inspection ID already exists. Open that inspection instead.',409);
            $details=json_decode($row['details_json'] ?: '{}',true) ?: [];
            $details['fields']??=[];
            $details['fields']['inspection_status']=qrex_ordinal_inspection($nextInspectionNo);
            query("UPDATE $table SET inspection_status='IN_PROGRESS',inspection_no=?,inspection_uid=?,template_inspection_status=?,details_json=?,version=version+1,updated_by=?,updated_at=? WHERE id=?",[$nextInspectionNo,$newUid,qrex_ordinal_inspection($nextInspectionNo),json_encode($details,JSON_THROW_ON_ERROR),$user['id'],date('Y-m-d H:i:s'),$id]);
            $auditDetails+=['from_inspection_no'=>(int)($row['inspection_no'] ?? 1),'to_inspection_no'=>$nextInspectionNo,'from_uid'=>$row['inspection_uid'] ?? null,'to_uid'=>$newUid];
        } else {
            demand($i['state']==='draft','Reopen this record before assigning a specification.');
            $spec=Inspections::specification($input['spec_id']??null); demand($spec&&$spec['active']&&$spec['product']===$i['product']&&$spec['size']===$i['size']&&strcasecmp($spec['glove_type'],$i['glove_type'])===0,'Choose an exact matching specification.');
            query("UPDATE $table SET spec_snapshot_json=?,version=version+1,updated_by=?,updated_at=? WHERE id=?",[json_encode($spec,JSON_THROW_ON_ERROR),$user['id'],date('Y-m-d H:i:s'),$id]);
        }
        audit($action,'inspection',(string)$id,$auditDetails); json_out(Inspections::get($id));
    }
    if($action==='rejections') {
        $rows=[]; foreach(all_items() as $r){if($r['result']==='fail'||$r['rejection_status']){$status=$r['rejection_status'] ?: 'open'; if(($_GET['status']??'')!==''&&$_GET['status']!==$status)continue; $owner=$r['rejection_owner_id']?query('SELECT full_name FROM M_user WHERE id=?',[$r['rejection_owner_id']])->fetchColumn():null; $rows[]=['inspection_id'=>$r['id'],'status'=>$status,'notes'=>$r['rejection_notes'] ?? '','updated_at'=>$r['rejection_updated_at'] ?: $r['updated_at'],'unit_label'=>$r['unit_label'],'result'=>$r['result'],'state'=>$r['state'],'so_number'=>$r['sales_order_no'],'product'=>$r['product'],'glove_type'=>$r['glove_type_code'],'size'=>$r['size'],'size_desc'=>$r['size_desc'] ?? $r['size'],'owner'=>$owner];}}
        json_out(['rows'=>$rows]);
    }
    if($action==='rejection') {
        role(['supervisor','admin']); $id=(int)($input['id']??0); $row=item_row($id); $table=table_for_product($row['product']); $status=$input['status']??''; demand(in_array($status,['open','investigating','contained','closed'],true),'Choose a valid investigation status.'); $notes=safe_text($input['notes']??'',4000); demand(strlen($notes)>=5,'Describe the investigation or follow-up action.');
        query("UPDATE $table SET rejection_status=?,rejection_notes=?,rejection_owner_id=?,rejection_updated_at=? WHERE id=?",[$status,$notes,$user['id'],date('Y-m-d H:i:s'),$id]); audit('investigation_updated','inspection',(string)$id,['status'=>$status,'notes'=>$notes]); json_out(['ok'=>true]);
    }
    if($action==='users') { role(['admin']); json_out(['rows'=>query('SELECT id,full_name AS name,email,role,active,created_at FROM M_user ORDER BY full_name')->fetchAll()]); }
    if($action==='user') {
        role(['admin']); $id=(int)($input['id']??0); $name=safe_text($input['name']??'',120); $email=strtolower(safe_text($input['email']??'',190)); $r=$input['role']??''; $active=($input['active']??true)?1:0; $now=date('Y-m-d H:i:s');
        demand($name!==''&&filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a name and valid email.'); demand(in_array($r,['inspector','supervisor','admin','viewer'],true),'Choose a valid role.'); demand($id!==(int)$user['id']||($r==='admin'&&$active===1),'You cannot disable or demote your own administrator account.');
        $password=$input['password']??''; demand(is_string($password),'Enter a valid password.'); if(!$id||$password!=='') demand(strlen($password)>=6&&strlen($password)<=200,'Use a password of 6 to 200 characters.');
        demand(!query('SELECT id FROM M_user WHERE email=? AND id<>?',[$email,$id])->fetch(),'This email is already registered.');
        if($id){demand((bool)query('SELECT id FROM M_user WHERE id=?',[$id])->fetch(),'User not found.',404); query('UPDATE M_user SET full_name=?,email=?,username=?,role=?,active=?,updated_at=? WHERE id=?',[$name,$email,$email,$r,$active,$now,$id]); if($password!=='')query('UPDATE M_user SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);}
        else {query('INSERT INTO M_user (username,email,password_hash,full_name,role,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$email,$email,password_hash($password,PASSWORD_DEFAULT),$name,$r,$active,$now,$now]);$id=last_insert_id();}
        json_out(['ok'=>true]);
    }
    if($action==='delete_user') {
        role(['admin']); $id=(int)($input['id']??0);
        demand($id>0,'Choose a user to delete.');
        demand($id!==(int)$user['id'],'You cannot delete your own signed-in account.');
        $target=query('SELECT id,role,full_name FROM M_user WHERE id=?',[$id])->fetch();
        demand((bool)$target,'User not found.',404);
        if(($target['role']??'')==='admin') demand((int)query('SELECT COUNT(*) FROM M_user WHERE role=? AND active=1 AND id<>?',['admin',$id])->fetchColumn()>0,'Keep at least one active administrator account.');
        $usage=0;
        foreach(['T_item_CPETPE','T_item_HDPE'] as $table) {
            $usage+=(int)query("SELECT COUNT(*) FROM $table WHERE created_by=? OR updated_by=? OR rejection_owner_id=?",[$id,$id,$id])->fetchColumn();
        }
        demand($usage===0,'This user has inspection history. Disable the account instead so past records still show the correct staff identity.');
        query('DELETE FROM M_user WHERE id=?',[$id]);
        json_out(['ok'=>true]);
    }
    if($action==='password') {
        $hash=query('SELECT password_hash FROM M_user WHERE id=?',[$user['id']])->fetchColumn(); demand(password_verify((string)($input['current']??''),$hash),'Current password is incorrect.'); $new=(string)($input['password']??''); demand(strlen($new)>=6&&strlen($new)<=200,'Use a password of 6 to 200 characters.'); query('UPDATE M_user SET password_hash=?,updated_at=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),date('Y-m-d H:i:s'),$user['id']]); session_regenerate_id(true); json_out(['ok'=>true]);
    }
    if($action==='specification') {
        role(['admin']); $product=$input['product']??''; demand(isset(master()['products'][$product]),'Choose a product.'); $inspectionType=item_type_for_product($product); $reference=safe_text($input['reference']??'',150); $glove=safe_text($input['glove_type']??'',80); $size=safe_text($input['size']??'',10); $source=safe_text($input['source']??'',2000); $type=safe_text($input['product_type']??'',80); $now=date('Y-m-d H:i:s'); $specId=safe_text($input['id']??'',80);
        demand($reference!==''&&$source!==''&&$type!=='','Reference, product type and approval source are required.'); demand(in_array($glove,master()['products'][$product]['references']['Glove Type'],true)&&in_array($size,master()['products'][$product]['references']['Size'],true),'Choose a valid glove type and size.');
        if($specId!=='') { $existing=Inspections::specification($specId); demand($existing&&$existing['product']===$product,'Specification not found for this product.',404); }
        else $specId=$product.'-'.bin2hex(random_bytes(8));
        $revision=date('Ymd-His'); db()->beginTransaction();
        try{
            foreach(master()['products'][$product]['metrics'] as $n=>$metric){
                $l=$metric['key']==='thickness'?['min'=>null,'max'=>null,'nominal'=>null,'na'=>false]:($input['limits'][$metric['key']]??[]); foreach(['min','max','nominal'] as $k) if(($l[$k]??'')!=='') demand(is_numeric($l[$k]),'Use valid specification limits.');
                $params=[$inspectionType,$specId,$reference,$type,$glove,$size,strtoupper($metric['key']),$metric['label'],$metric['section'],$metric['unit']??null,($l['min']??'')!==''?($l['min']??null):null,($l['max']??'')!==''?($l['max']??null):null,($l['nominal']??'')!==''?($l['nominal']??null):null,!empty($l['na'])?1:0,$n+1,$source,$revision,$now,$now];
                if(isset($existing)) query('UPDATE M_masterlist_specifications SET reference_name=?,product_type=?,glove_type=?,size=?,parameter_name=?,measurement_group=?,unit=?,min_value=?,max_value=?,nominal_value=?,not_applicable=?,sequence_no=?,source=?,revision=?,active=1,updated_at=? WHERE spec_id=? AND parameter_code=?',[$reference,$type,$glove,$size,$metric['label'],$metric['section'],$metric['unit']??null,$params[10],$params[11],$params[12],$params[13],$n+1,$source,$revision,$now,$specId,strtoupper($metric['key'])]);
                else query('INSERT INTO M_masterlist_specifications (inspection_type,spec_id,reference_name,product_type,glove_type,size,parameter_code,parameter_name,measurement_group,unit,min_value,max_value,nominal_value,not_applicable,sequence_no,source,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',$params);
            }
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        json_out(['id'=>$specId],isset($existing)?200:201);
    }
    if($action==='delete_spec') {
        role(['admin']); $id=safe_text($input['id']??'',80); demand($id!=='','Specification is required.'); demand((bool)Inspections::specification($id),'Specification not found.',404);
        query('UPDATE M_masterlist_specifications SET active=0,updated_at=? WHERE spec_id=?',[date('Y-m-d H:i:s'),$id]);
        json_out(['ok'=>true]);
    }
    throw new DomainException('Page not found.',404);
} catch(DomainException $e) { json_out(['error'=>$e->getMessage()],$e->getCode()?:422); }
catch(JsonException $e) { json_out(['error'=>'The request could not be read. Please refresh and try again.'],400); }
catch(Throwable $e) {
    error_log((string)$e);
    if(str_contains($e->getMessage(),'TURSO_AUTH_TOKEN')||str_contains($e->getMessage(),'Turso auth token')) {
        json_out(['error'=>'Turso database is not configured. Set TURSO_AUTH_TOKEN on the PHP/Apache server, run the installer, then try again.'],503);
    }
    if(str_contains($e->getMessage(),'cURL extension')||str_contains($e->getMessage(),'curl_init')) {
        json_out(['error'=>'Server PHP is missing the cURL extension. Enable extension=curl in php.ini, restart Apache, then try again.'],503);
    }
    if(str_contains($e->getMessage(),'could not find driver')) {
        json_out(['error'=>'Server PHP is missing a required database driver. Enable pdo_sqlite for local SQLite or pdo_sqlsrv/sqlsrv for QAPQC lookup, then restart Apache.'],503);
    }
    if(str_contains($e->getMessage(),'Turso request failed')||str_contains($e->getMessage(),'Turso HTTP')||str_contains($e->getMessage(),'Turso returned')) {
        json_out(['error'=>'Turso database connection failed: '.$e->getMessage()],503);
    }
    json_out(['error'=>'We could not complete that action. Your unsaved work remains on this device. Try again or contact your administrator.'],500);
}
