<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;

final class InspectionHistoryReset {
    public const HISTORY=['T_inspection_creation','T_saved_report','T_defect','T_record_CPETPE','T_record_HDPE','T_item_CPETPE','T_item_HDPE','T_sales_order'];
    public const PRESERVED=['M_user','M_masterlist_specifications','M_equipment'];

    public static function preview(PDO|TursoConnection $db): array {
        $counts=[];
        foreach([...self::HISTORY,...self::PRESERVED] as $table) $counts[$table]=(int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        return $counts;
    }

    public static function apply(PDO|TursoConnection $db,string $backupPath): array {
        $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['sqlite','turso'],true)) throw new RuntimeException('Only the QREX SQLite/Turso database is supported.');
        $directory=realpath(dirname($backupPath));
        $workspace=realpath(ROOT.'/..');
        if(!$directory || str_starts_with(strtolower($directory.DIRECTORY_SEPARATOR),strtolower($workspace.DIRECTORY_SEPARATOR))) throw new RuntimeException('Backup must be outside the web workspace in an existing private directory.');
        if(file_exists($backupPath)) throw new RuntimeException('Backup already exists; refusing to overwrite it.');
        if($db instanceof TursoConnection) $db->execute('BEGIN IMMEDIATE',[],false);
        else $db->exec('BEGIN IMMEDIATE');
        try {
            $schema=$db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type,name")->fetchAll();
            $history=[];$preserved=[];
            foreach(self::HISTORY as $table) {
                $order=$table==='T_inspection_creation'?'actor_id,creation_key':'id';
                $history[$table]=$db->query("SELECT * FROM $table ORDER BY $order")->fetchAll();
            }
            foreach(self::PRESERVED as $table) $preserved[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll();
            $revision=$db->query('SELECT * FROM T_report_revision ORDER BY id')->fetchAll();
            if(count($revision)!==1 || (int)$revision[0]['id']!==1) throw new RuntimeException('Report revision tracking is missing.');
            $fingerprint=hash('sha256',json_encode($preserved,JSON_THROW_ON_ERROR));
            $backup=['format'=>'qrex-inspection-history-v1','created_at'=>date(DATE_ATOM),'driver'=>$driver,'schema'=>$schema,'tables'=>$history,'report_revision'=>$revision,'preserved_sha256'=>$fingerprint,'preserved_counts'=>array_map('count',$preserved)];
            $json=json_encode($backup,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
            $handle=fopen($backupPath,'xb');
            if(!$handle) throw new RuntimeException('Cannot create the private backup.');
            try {
                if(fwrite($handle,$json)!==strlen($json) || !fflush($handle) || (function_exists('fsync')&&!fsync($handle))) throw new RuntimeException('Cannot finish the backup; no history was deleted.');
            } finally {fclose($handle);}
            if(hash_file('sha256',$backupPath)!==hash('sha256',$json)) throw new RuntimeException('Backup verification failed.');
            // Temporarily suspend only the deletion guard, under the same write lock.
            $guard=array_values(array_filter($schema,fn($row)=>$row['type']==='trigger'&&$row['name']==='trg_saved_report_immutable_delete'));
            if(count($guard)!==1) throw new RuntimeException('Saved-report deletion guard is missing.');
            $db->exec('DROP TRIGGER trg_saved_report_immutable_delete');
            foreach(self::HISTORY as $table) $db->exec("DELETE FROM $table");
            $db->exec($guard[0]['sql']);
            $after=[];
            foreach(self::PRESERVED as $table) $after[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll();
            if(hash('sha256',json_encode($after,JSON_THROW_ON_ERROR))!==$fingerprint) throw new RuntimeException('Preserved data changed; reset rolled back.');
            $counts=self::preview($db);
            foreach(self::HISTORY as $table) if($counts[$table]!==0) throw new RuntimeException('History was not fully cleared.');
            if($db->query('PRAGMA foreign_key_check')->fetchAll()) throw new RuntimeException('Foreign key verification failed.');
            // Keep revision tracking and ID sequences: old links must not identify new pallets.
            $db->exec('UPDATE T_report_revision SET revision=revision+1 WHERE id=1');
            if($db instanceof TursoConnection) $db->commit();
            else $db->exec('COMMIT');
            return ['backup'=>$backupPath,'backup_sha256'=>hash('sha256',$json),'deleted'=>array_map('count',$history),'remaining'=>$counts,'preserved_verified'=>true];
        } catch(Throwable $error) {
            try {if($db instanceof TursoConnection)$db->rollBack();else $db->exec('ROLLBACK');}
            catch(Throwable $rollbackError) {error_log((string)$rollbackError);}
            throw $error;
        }
    }
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    require __DIR__.'/../app/bootstrap.php';
    try {
        if(in_array('--apply',$argv,true)) {
            if(!in_array('--turso',$argv,true) || db_driver()!=='turso') throw new RuntimeException('Live reset requires --turso and a Turso connection.');
            $path='';foreach($argv as $arg)if(str_starts_with($arg,'--backup='))$path=substr($arg,9);
            if($path==='') throw new RuntimeException('Supply --backup=absolute-private-file.json outside the web workspace.');
            $result=InspectionHistoryReset::apply(db(),$path);
        } else $result=['dry_run'=>true,'driver'=>db_driver(),'counts'=>InspectionHistoryReset::preview(db()),'preserve'=>InspectionHistoryReset::PRESERVED];
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
    } catch(Throwable $error) {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
}
