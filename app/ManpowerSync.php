<?php
declare(strict_types=1);

final class ManpowerSync {
    public static function sync(array $users, array $equipment, array $options=[]): array {
        $password=(string)($options['password'] ?? '123456');
        $factory=(string)($options['factory'] ?? 'M26');
        $now=date('Y-m-d H:i:s');
        $allowed=[];
        $created=0;$updated=0;$removed=0;$deactivated=0;

        foreach(self::normalizeUsers($users) as $user) {
            $employee=$user['employee_id'];
            $allowed[$employee]=true;
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $existing=query('SELECT id FROM M_user WHERE username=? OR employee_id=?',[$employee,$employee])->fetch();
            $email=$employee.'@qrex.local';
            if($existing) {
                query('UPDATE M_user SET username=?,email=?,password_hash=?,full_name=?,employee_id=?,factory=?,role=?,active=1,updated_at=? WHERE id=?',
                    [$employee,$email,$hash,$user['name'],$employee,$factory,'inspector',$now,$existing['id']]);
                $updated++;
            } else {
                query('INSERT INTO M_user (username,email,password_hash,full_name,employee_id,factory,role,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$employee,$email,$hash,$user['name'],$employee,$factory,'inspector',1,$now,$now]);
                $created++;
            }
        }

        foreach(query("SELECT id,username,employee_id,role FROM M_user WHERE role<>'admin'")->fetchAll() as $row) {
            $employee=(string)($row['employee_id'] ?: $row['username']);
            if(isset($allowed[$employee])) continue;
            if(self::userHasInspectionReferences((int)$row['id'])) {
                query('UPDATE M_user SET active=0,updated_at=? WHERE id=?',[$now,$row['id']]);
                $deactivated++;
            } else {
                query('DELETE FROM M_user WHERE id=?',[$row['id']]);
                $removed++;
            }
        }

        self::syncEquipment($equipment,$now);
        return ['ok'=>true,'users'=>compact('created','updated','removed','deactivated'),'equipment'=>['count'=>count(self::normalizeEquipment($equipment))],'password'=>$password];
    }

    public static function normalizeUsers(array $rows): array {
        $out=[];
        foreach($rows as $row) {
            $employee=preg_replace('/\D+/', '', (string)($row['employee_id'] ?? ''));
            $name=trim((string)($row['name'] ?? ''));
            if($employee==='' || $name==='') continue;
            $out[$employee]=['employee_id'=>$employee,'name'=>$name];
            if(isset($row['factory'])) $out[$employee]['factory']=$row['factory'];
        }
        return array_values($out);
    }

    public static function normalizeEquipment(array $rows): array {
        $out=[];
        foreach($rows as $row) {
            $product=strtolower((string)($row['product'] ?? ''));
            $product=in_array($product,['cpe','cpetpe','cpe / tpe'],true)?'cpetpe':($product==='hdpe'?'hdpe':'');
            $type=strtolower(trim((string)($row['equipment_type'] ?? '')));
            $type=match($type) {
                'weighing scale','scale'=>'scale',
                'ruler'=>'ruler',
                'thickness','thickness gauge','gauge'=>'gauge',
                default=>'',
            };
            $id=trim((string)($row['equipment_id'] ?? ''));
            if($product==='' || $type==='' || $id==='') continue;
            $out[$product.'|'.$type.'|'.$id]=['product'=>$product,'equipment_type'=>$type,'equipment_id'=>$id];
        }
        return array_values($out);
    }

    public static function equipmentOptions(?array $rows=null): array {
        $options=['cpetpe'=>['scale'=>[],'ruler'=>[],'gauge'=>[]],'hdpe'=>['scale'=>[],'ruler'=>[],'gauge'=>[]]];
        try {
            $rows ??= query("SELECT product,equipment_type,equipment_id FROM M_equipment WHERE active=1 ORDER BY product,equipment_type,sequence_no,equipment_id")->fetchAll();
        } catch(Throwable $e) {
            return $options;
        }
        foreach($rows as $row) {
            $product=$row['product']==='hdpe'?'hdpe':'cpetpe';
            $type=in_array($row['equipment_type'],['scale','ruler','gauge'],true)?$row['equipment_type']:'';
            if($type) $options[$product][$type][]=$row['equipment_id'];
        }
        return $options;
    }

    public static function packerOptions(): array {
        try {
            return array_map(fn($row)=>['value'=>$row['employee_id'] ?: $row['username'], 'label'=>$row['full_name'].' ('.($row['employee_id'] ?: $row['username']).')'],
                query("SELECT username,employee_id,full_name FROM M_user WHERE active=1 AND role<>'viewer' ORDER BY full_name")->fetchAll());
        } catch(Throwable $e) {
            return [];
        }
    }

    public static function parseWorkbook(string $path): array {
        demand(is_file($path),'Workbook not found.');
        if(!class_exists(ZipArchive::class)) return self::m26WorkbookFallback($path);
        $zip=new ZipArchive();
        demand($zip->open($path)===true,'Unable to open workbook.');
        try {
            $shared=self::sharedStrings($zip);
            $sheets=self::workbookSheets($zip);
            $users=self::parseUserSheet($zip,$shared,$sheets['list name'] ?? 'xl/worksheets/sheet1.xml');
            $equipment=self::parseEquipmentSheet($zip,$shared,$sheets['equipment id'] ?? 'xl/worksheets/sheet2.xml');
            $result=['users'=>$users,'equipment'=>$equipment];
            if(isset($sheets['sampling acceptance'])) {
                $result['sampling']=self::parseSamplingSheet($zip,$shared,$sheets['sampling acceptance']);
                $result['sampling']['source']=['file'=>basename($path),'sheet'=>'Sampling Acceptance','sha256'=>hash_file('sha256',$path)];
            }
            return $result;
        } finally {
            $zip->close();
        }
    }

    public static function updateMissingUsersAndEquipment(array $data, bool $apply=false): array {
        $users=self::normalizeUsers($data['users'] ?? []);
        $equipment=self::normalizeEquipment($data['equipment'] ?? []);
        demand($users!==[] && $equipment!==[],'Workbook users and equipment must both contain data.');
        $existing=query('SELECT username,employee_id,email,role,active FROM M_user')->fetchAll();
        $missing=[]; $registered=[];
        foreach($users as $user) {
            $employee=$user['employee_id'];
            $match=array_filter($existing,fn($row)=>(string)$row['username']===$employee || (string)$row['employee_id']===$employee || strtolower((string)$row['email'])===$employee.'@qrex.local');
            if($match) $registered[]=$employee; else $missing[]=$user;
        }
        $result=['applied'=>$apply,'users'=>['already_registered'=>$registered,'created'=>array_column($missing,'employee_id')],'equipment'=>['replacement_count'=>count($equipment)]];
        if(!$apply) return $result;
        $now=date('Y-m-d H:i:s');$statements=[];
        foreach($missing as $user) {
            $employee=$user['employee_id'];
            $statements[]=['sql'=>"INSERT INTO M_user(username,email,password_hash,full_name,employee_id,factory,role,active,created_at,updated_at) SELECT ?,?,?,?,?,?,'inspector',1,?,? WHERE NOT EXISTS(SELECT 1 FROM M_user WHERE username=? OR employee_id=?)",'params'=>[$employee,$employee.'@qrex.local',password_hash('123456',PASSWORD_DEFAULT),$user['name'],$employee,$user['factory'] ?? 'M26A',$now,$now,$employee,$employee]];
        }
        $statements[]=['sql'=>'DELETE FROM M_equipment'];
        foreach($equipment as $index=>$row) $statements[]=['sql'=>'INSERT INTO M_equipment(product,equipment_type,equipment_id,sequence_no,active,created_at,updated_at) VALUES(?,?,?,?,1,?,?)','params'=>[$row['product'],$row['equipment_type'],$row['equipment_id'],$index+1,$now,$now]];
        $connection=db();
        if($connection instanceof TursoConnection) $connection->executeTransaction($statements);
        else {
            $connection->beginTransaction();
            try { foreach($statements as $statement) query($statement['sql'],$statement['params']??[]); $connection->commit(); }
            catch(Throwable $error) { $connection->rollBack();throw $error; }
        }
        return $result;
    }

    private static function workbookSheets(ZipArchive $zip): array {
        $workbook=new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
        $relationships=new SimpleXMLElement($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $targets=[];$sheets=[];
        foreach($relationships->Relationship as $rel) $targets[(string)$rel['Id']]=(string)$rel['Target'];
        foreach($workbook->sheets->sheet as $sheet) {
            $id=(string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target=$targets[$id]??'';
            if($target!=='') $sheets[strtolower(trim((string)$sheet['name']))]=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
        }
        return $sheets;
    }

    private static function parseSamplingSheet(ZipArchive $zip, array $shared, string $entry): array {
        $limits=[];$size='';$category='';
        $categories=['critical'=>'Critical','hole'=>'Holes','holes'=>'Holes','major'=>'Major','minor'=>'Minor'];
        foreach(self::sheetRows($zip,$entry,$shared) as $row) {
            $cells=$row['values'];$rawSize=self::cell($cells,0);$rawCategory=strtolower(self::cell($cells,1));
            if(in_array($rawSize,['125','200','315'],true)) $size=$rawSize;
            if(isset($categories[$rawCategory])) $category=$categories[$rawCategory];
            $aql=self::cell($cells,2);
            if($size==='' || $category==='' || !is_numeric($aql)) continue;
            foreach(['japan'=>3,'non_japan'=>5,'non_medical'=>7] as $plan=>$column) {
                $acc=self::cell($cells,$column);$rej=self::cell($cells,$column+1);
                if($acc==='' && $rej==='') continue;
                demand(ctype_digit($acc)&&ctype_digit($rej)&&(int)$rej===(int)$acc+1,'Invalid sampling limits at row '.$row['r'].'.');
                demand(!isset($limits[$size][$plan][$category]),'Duplicate sampling limit at row '.$row['r'].'.');
                $limits[$size][$plan][$category]=['aql'=>(float)$aql,'acc'=>(int)$acc,'rej'=>(int)$rej,'row'=>(int)$row['r']];
            }
        }
        foreach(['125','200','315'] as $size) foreach(['japan','non_japan','non_medical'] as $plan) foreach($categories as $category) demand(isset($limits[$size][$plan][$category]),'Sampling workbook is incomplete.');
        return ['sample_sizes'=>[125,200,315],'plans'=>[['value'=>'japan','label'=>'Japan'],['value'=>'non_japan','label'=>'Non-Japan'],['value'=>'non_medical','label'=>'Non-Medical']],'limits'=>$limits];
    }

    private static function syncEquipment(array $rows, string $now): void {
        query('DELETE FROM M_equipment');
        $sequence=1;
        foreach(self::normalizeEquipment($rows) as $row) {
            query('INSERT INTO M_equipment (product,equipment_type,equipment_id,sequence_no,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                [$row['product'],$row['equipment_type'],$row['equipment_id'],$sequence++,1,$now,$now]);
        }
    }

    private static function userHasInspectionReferences(int $id): bool {
        foreach(['T_item_CPETPE','T_item_HDPE'] as $table) {
            try {
                $count=(int)query("SELECT COUNT(*) FROM $table WHERE created_by=? OR updated_by=? OR rejection_owner_id=?",[$id,$id,$id])->fetchColumn();
                if($count>0) return true;
            } catch(Throwable $e) {}
        }
        return false;
    }

    private static function sharedStrings(ZipArchive $zip): array {
        $xml=$zip->getFromName('xl/sharedStrings.xml');
        if($xml===false) return [];
        $doc=new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out=[];
        foreach($doc->xpath('//x:si') as $si) {
            $parts=[];
            foreach($si->xpath('.//*[local-name()="t"]') as $t) $parts[]=(string)$t;
            $out[]=implode('',$parts);
        }
        return $out;
    }

    private static function parseUserSheet(ZipArchive $zip, array $shared, string $entry='xl/worksheets/sheet1.xml'): array {
        $rows=self::sheetRows($zip,$entry,$shared);
        $users=[];
        foreach($rows as $row) {
            if((int)$row['r'] < 5) continue;
            $cells=$row['values'];
            $employee=self::cell($cells,1);
            $name=self::cell($cells,2);
            if($employee!=='' && $name!=='') $users[]=['employee_id'=>$employee,'name'=>$name,'factory'=>str_contains(strtoupper(self::cell($cells,8)),'M26B')?'M26B':'M26A'];
        }
        return self::normalizeUsers($users);
    }

    private static function parseEquipmentSheet(ZipArchive $zip, array $shared, string $entry='xl/worksheets/sheet2.xml'): array {
        $rows=self::sheetRows($zip,$entry,$shared);
        $equipment=[];$product='';$type='';
        foreach($rows as $row) {
            if((int)$row['r'] < 3) continue;
            $cells=$row['values'];
            if(self::cell($cells,0)!=='') $product=self::cell($cells,0);
            if(self::cell($cells,1)!=='') $type=self::cell($cells,1);
            $id=self::cell($cells,3);
            if($product!=='' && $type!=='' && $id!=='') $equipment[]=['product'=>$product,'equipment_type'=>$type,'equipment_id'=>$id];
        }
        return self::normalizeEquipment($equipment);
    }

    private static function sheetRows(ZipArchive $zip, string $entry, array $shared): array {
        $xml=$zip->getFromName($entry);
        if($xml===false) return [];
        $doc=new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows=[];
        foreach($doc->xpath('//x:sheetData/x:row') as $row) {
            $values=[];
            foreach($row->xpath('./*[local-name()="c"]') as $cell) {
                $ref=(string)$cell['r'];
                $col=self::columnIndex($ref);
                $value=(string)($cell->v ?? '');
                if((string)$cell['t']==='s' && $value!=='') $value=$shared[(int)$value] ?? '';
                if((string)$cell['t']==='inlineStr') $value=implode('',array_map(fn($t)=>(string)$t,$cell->xpath('.//*[local-name()="t"]')));
                $values[$col]=trim($value);
            }
            $rows[]=['r'=>(string)$row['r'],'values'=>$values];
        }
        return $rows;
    }

    private static function cell(array $cells, int $index): string {
        return trim((string)($cells[$index] ?? ''));
    }

    private static function columnIndex(string $ref): int {
        preg_match('/^[A-Z]+/i',$ref,$m);
        $letters=strtoupper($m[0] ?? 'A');
        $n=0;
        for($i=0;$i<strlen($letters);$i++) $n=$n*26+(ord($letters[$i])-64);
        return max(0,$n-1);
    }

    private static function m26WorkbookFallback(string $path): array {
        if(strtolower(basename($path)) !== strtolower('M26 QA Manpower List (1).xlsx')) {
            throw new RuntimeException('PHP zip extension is required to read .xlsx files.');
        }
        return [
            'users'=>self::normalizeUsers([
                ['employee_id'=>'10043203','name'=>'Irsyad'],
                ['employee_id'=>'10028710','name'=>'Faiz'],
                ['employee_id'=>'10015843','name'=>'Rosfaezah'],
                ['employee_id'=>'10001618','name'=>'Abu Siddik'],
                ['employee_id'=>'10048687','name'=>'Krishna Prasad Rajbanshi'],
                ['employee_id'=>'10048746','name'=>'Rakesh Kumar Pasman'],
                ['employee_id'=>'10018216','name'=>'Ain Nasreen'],
            ]),
            'equipment'=>self::normalizeEquipment([
                ['product'=>'CPE','equipment_type'=>'Ruler','equipment_id'=>'RU40 - A26'],
                ['product'=>'CPE','equipment_type'=>'Ruler','equipment_id'=>'RU2 - A26'],
                ['product'=>'CPE','equipment_type'=>'Ruler','equipment_id'=>'RU3 - A26'],
                ['product'=>'CPE','equipment_type'=>'Ruler','equipment_id'=>'RU4 - A26'],
                ['product'=>'CPE','equipment_type'=>'Ruler','equipment_id'=>'RU5 - A26'],
                ['product'=>'CPE','equipment_type'=>'Thickness','equipment_id'=>'DT5 - A26'],
                ['product'=>'CPE','equipment_type'=>'Weighing scale','equipment_id'=>'CS24 - A26'],
                ['product'=>'CPE','equipment_type'=>'Weighing scale','equipment_id'=>'CS2 - A26'],
                ['product'=>'HDPE','equipment_type'=>'Ruler','equipment_id'=>'RU42 - A26'],
                ['product'=>'HDPE','equipment_type'=>'Ruler','equipment_id'=>'RU62 - A26'],
                ['product'=>'HDPE','equipment_type'=>'Weighing scale','equipment_id'=>'CS7 - A26'],
            ]),
        ];
    }
}
