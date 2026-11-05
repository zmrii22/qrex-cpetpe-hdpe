<?php
declare(strict_types=1);

final class Reports {
    /** Installer-owned migration; report reads never create schema or save data. */
    public static function schemaStatements(): array {
        $statements=[
            ['sql'=>'CREATE TABLE IF NOT EXISTS T_report_revision (
                id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL DEFAULT 0 CHECK(revision>=0))'],
            ['sql'=>'INSERT OR IGNORE INTO T_report_revision(id,revision) VALUES (1,0)'],
            ['sql'=>'CREATE TABLE IF NOT EXISTS T_saved_report (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_date TEXT NOT NULL,
                version INTEGER NOT NULL CHECK(version>0),
                executed_at TEXT NOT NULL,
                executed_by INTEGER NOT NULL,
                executed_by_name TEXT NOT NULL,
                source_revision INTEGER NOT NULL,
                snapshot_json TEXT NOT NULL,
                UNIQUE(report_date,version))'],
            ['sql'=>'CREATE INDEX IF NOT EXISTS idx_saved_report_date_id ON T_saved_report(report_date,id DESC)'],
        ];
        // Daily snapshots contain global stats, so even out-of-day retests invalidate them.
        foreach(['T_item_CPETPE','T_item_HDPE','T_record_CPETPE','T_record_HDPE','T_defect'] as $table) {
            foreach(['INSERT','UPDATE','DELETE'] as $event) {
                $name='trg_report_revision_'.$table.'_'.strtolower($event);
                $statements[]=['sql'=>"CREATE TRIGGER IF NOT EXISTS $name AFTER $event ON $table
                    BEGIN UPDATE T_report_revision SET revision=revision+1 WHERE id=1; END"];
            }
        }
        foreach(['UPDATE','DELETE'] as $event) {
            $name='trg_saved_report_immutable_'.strtolower($event);
            $statements[]=['sql'=>"CREATE TRIGGER IF NOT EXISTS $name BEFORE $event ON T_saved_report
                BEGIN SELECT RAISE(ABORT,'Saved reports are immutable.'); END"];
        }
        return $statements;
    }

    public static function overview(): array {
        $statements=[];
        $today=date('Y-m-d').' 00:00:00';
        foreach(['CPETPE','HDPE'] as $type) {
            $statements[]=['sql'=>"SELECT
                SUM(CASE WHEN inspection_status='IN_PROGRESS' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN inspection_status='COMPLETED' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN final_disposition='FAIL' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN created_at>=? THEN 1 ELSE 0 END) AS today FROM T_item_$type",'params'=>[$today]];
        }
        $stats=array_fill_keys(['drafts','pending','failed','today'],0);
        foreach(query_all_batch($statements) as $rows) {
            foreach($stats as $key=>$value) $stats[$key]+=(int)($rows[0][$key]??0);
        }
        return ['stats'=>$stats];
    }

    /** Explicit execution always appends a version; no read path calls this method. */
    public static function execute(string $date, array $actor): array {
        self::validateDate($date);
        $actorId=filter_var($actor['id']??null,FILTER_VALIDATE_INT);
        demand($actorId!==false&&$actorId>0,'A report executor is required.');
        $actorName=safe_text($actor['name']??$actor['full_name']??'',200);
        demand($actorName!=='','A report executor name is required.');
        for($attempt=0;$attempt<3;$attempt++) {
            $before=self::revision();
            $snapshot=self::daily($date);
            if(self::revision()!==$before) continue;
            // One SQLite/libSQL write allocates the version and checks the revision under its write lock.
            // This closes the race between the final read and INSERT without a connection-spanning transaction.
            $row=query('INSERT INTO T_saved_report
                (report_date,version,executed_at,executed_by,executed_by_name,source_revision,snapshot_json)
                SELECT ?,COALESCE((SELECT MAX(version) FROM T_saved_report WHERE report_date=?),0)+1,?,?,?,?,?
                FROM T_report_revision WHERE id=1 AND revision=?
                RETURNING *,source_revision AS current_revision',
                [$date,$date,gmdate('Y-m-d\TH:i:s\Z'),$actorId,$actorName,$before,json_encode($snapshot,JSON_THROW_ON_ERROR),$before])->fetch();
            if($row) return self::snapshot($row);
        }
        throw new DomainException('Inspection data changed while the report was running. Execute the report again.',409);
    }

    public static function load(string $date, ?int $version=null): ?array {
        self::validateDate($date);
        demand($version===null||$version>0,'Choose a valid report version.');
        $params=[$date];
        $where='s.report_date=?';
        if($version!==null) { $where.=' AND s.version=?';$params[]=$version; }
        $row=query("SELECT s.*,r.revision AS current_revision FROM T_saved_report s
            CROSS JOIN T_report_revision r WHERE r.id=1 AND $where ORDER BY s.version DESC LIMIT 1",$params)->fetch();
        return $row?self::snapshot($row):null;
    }

    /** Newest executions first; the exclusive ID cursor remains stable as new reports arrive. */
    public static function listSaved(?string $date=null, ?int $beforeId=null, int $limit=50): array {
        if($date!==null) self::validateDate($date);
        demand($beforeId===null||$beforeId>0,'Choose a valid report cursor.');
        demand($limit>0,'Choose a valid report page size.');
        $limit=min($limit,50);
        $where=['r.id=1'];$params=[];
        if($date!==null) { $where[]='s.report_date=?';$params[]=$date; }
        if($beforeId!==null) { $where[]='s.id<?';$params[]=$beforeId; }
        $rows=query('SELECT s.id,s.report_date,s.version,s.executed_at,s.executed_by,s.executed_by_name,
            s.source_revision,s.snapshot_json,r.revision AS current_revision FROM T_saved_report s CROSS JOIN T_report_revision r
            WHERE '.implode(' AND ',$where).' ORDER BY s.id DESC LIMIT '.($limit+1),$params)->fetchAll();
        $more=count($rows)>$limit;
        $reports=array_map([self::class,'metadata'],array_slice($rows,0,$limit));
        return ['reports'=>$reports,'next_before_id'=>$more?$reports[count($reports)-1]['id']:null];
    }

    private static function validateDate(string $date): void {
        $day=preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        demand($day!==false&&$day->format('Y-m-d')===$date,'Choose a valid report date.');
    }

    private static function revision(): int {
        $revision=query('SELECT revision FROM T_report_revision WHERE id=1')->fetchColumn();
        if($revision===false) throw new RuntimeException('Report revision tracking is not installed.');
        return (int)$revision;
    }

    private static function metadata(array $row): array {
        $products=[];
        $snapshot=null;
        if(array_key_exists('snapshot_json',$row)) {
            try {
                $snapshot=json_decode($row['snapshot_json'] ?: '{}',true,512,JSON_THROW_ON_ERROR);
                $products=is_array($snapshot['products'] ?? null) ? $snapshot['products'] : [];
            } catch(Throwable $e) {
                $products=[];
                $snapshot=null;
            }
        }
        return ['id'=>(int)$row['id'],'date'=>$row['report_date'],'version'=>(int)$row['version'],
            'executed_at'=>$row['executed_at'],'executed_by'=>(int)$row['executed_by'],'executed_by_name'=>$row['executed_by_name'],
            'source_revision'=>(int)$row['source_revision'],'current_revision'=>(int)$row['current_revision'],
            'has_newer_changes'=>(int)$row['current_revision']!==(int)$row['source_revision'],'products'=>$products,
            'snapshot'=>$snapshot];
    }

    private static function snapshot(array $row): array {
        $snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $snapshot['report']=self::metadata($row);
        return $snapshot;
    }

    public static function daily(string $date): array {
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        demand($day!==false&&$day->format('Y-m-d')===$date,'Choose a valid report date.');
        $from=$date.' 00:00:00';
        $until=$day->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $today=date('Y-m-d').' 00:00:00';
        $statements=[];
        foreach(['cpetpe'=>'CPETPE','hdpe'=>'HDPE'] as $product=>$type) {
            $table='T_item_'.$type;
            $first=$type==='CPETPE'?' AND i.inspection_no=1':'';
            $statements[]=['sql'=>"SELECT COUNT(*) AS total,
                SUM(CASE WHEN inspection_status='IN_PROGRESS' THEN 1 ELSE 0 END) AS drafts,
                SUM(CASE WHEN inspection_status='COMPLETED' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN final_disposition='FAIL' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN final_disposition='PASS' AND inspection_status<>'IN_PROGRESS' THEN 1 ELSE 0 END) AS passed,
                SUM(CASE WHEN created_at>=? THEN 1 ELSE 0 END) AS today FROM $table",'params'=>[$today]];
            $statements[]=['sql'=>"SELECT '$product' AS product,COUNT(*) AS total,
                SUM(CASE WHEN i.final_disposition='PASS' THEN 1 ELSE 0 END) AS passed,
                SUM(CASE WHEN i.final_disposition='FAIL' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN i.final_disposition='HOLD' THEN 1 ELSE 0 END) AS held
                FROM $table i WHERE i.submitted_at>=? AND i.submitted_at<?$first",'params'=>[$from,$until]];
            $statements[]=['sql'=>"SELECT d.defect_name AS name,d.defect_category AS category,SUM(d.quantity) AS quantity
                FROM $table i JOIN T_defect d ON d.item_id=i.id AND d.item_type=?
                WHERE i.submitted_at>=? AND i.submitted_at<?$first GROUP BY d.defect_name,d.defect_category",
                'params'=>[$type,$from,$until]];
        }
        $rows=query_all_batch($statements);
        $stats=array_fill_keys(['total','drafts','pending','failed','passed','today'],0);
        $products=[];$defects=[];
        $trend=['day'=>$date,'total'=>0,'passed'=>0,'failed'=>0];
        foreach([0,3] as $offset) {
            foreach($stats as $key=>$value) $stats[$key]+=(int)($rows[$offset][0][$key]??0);
            $product=$rows[$offset+1][0];
            foreach(['total','passed','failed','held'] as $key) $product[$key]=(int)$product[$key];
            if($product['total']) $products[]=$product;
            foreach(['total','passed','failed'] as $key) $trend[$key]+=$product[$key];
            foreach($rows[$offset+2] as $defect) {
                $key=$defect['category'].'|'.$defect['name'];
                $defects[$key]??=['name'=>$defect['name'],'category'=>$defect['category'],'quantity'=>0];
                $defects[$key]['quantity']+=(int)$defect['quantity'];
            }
        }
        $defects=array_values($defects);
        usort($defects,fn($a,$b)=>$b['quantity']<=>$a['quantity']);
        return ['stats'=>$stats,'trend'=>$trend['total']?[$trend]:[],'defects'=>array_slice($defects,0,12),
            'products'=>$products,'period'=>['date'=>$date,'from'=>$date,'to'=>$date]];
    }
}
