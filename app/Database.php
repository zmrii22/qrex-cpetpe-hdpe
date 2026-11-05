<?php
declare(strict_types=1);

final class Database {
    public static function connect(array $config): PDO|TursoConnection {
        if (str_starts_with((string)$config['dsn'], 'libsql://') || str_starts_with((string)$config['dsn'], 'turso://')) {
            return new TursoConnection((string)$config['dsn'], (string)($config['auth_token'] ?? ''));
        }
        $db = new PDO($config['dsn'], $config['user'] ?? '', $config['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec('PRAGMA foreign_keys = ON');
            $db->exec('PRAGMA busy_timeout = 5000');
            $db->exec('PRAGMA journal_mode = WAL');
        }
        return $db;
    }

    public static function install(PDO|TursoConnection $db, bool $reset = true): void {
        if (!in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlite', 'turso'], true)) {
            throw new RuntimeException('QREX runtime storage must use SQLite/libSQL for this build.');
        }
        $db->exec('PRAGMA foreign_keys = ON');
        if ($reset) {
            foreach ([
                'T_inspection_creation','T_saved_report','T_report_revision',
                'T_defect','T_record_HDPE','T_record_CPETPE','T_item_HDPE','T_item_CPETPE',
                'T_sales_order','M_masterlist_specifications','M_equipment','M_user'
            ] as $table) {
                $db->exec("DROP TABLE IF EXISTS $table");
            }
        }
        $createTable = $reset ? 'CREATE TABLE' : 'CREATE TABLE IF NOT EXISTS';
        $db->exec(<<<SQL
$createTable M_user (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL,
    employee_id TEXT,
    factory TEXT,
    role TEXT NOT NULL CHECK(role IN ('inspector','supervisor','admin','viewer')),
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);
        $db->exec(<<<SQL
$createTable M_equipment (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product TEXT NOT NULL CHECK(product IN ('cpetpe','hdpe')),
    equipment_type TEXT NOT NULL CHECK(equipment_type IN ('scale','ruler','gauge')),
    equipment_id TEXT NOT NULL,
    sequence_no INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(product,equipment_type,equipment_id)
);
SQL);
        $db->exec(<<<SQL
$createTable M_masterlist_specifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inspection_type TEXT NOT NULL CHECK(inspection_type IN ('CPETPE','HDPE')),
    spec_id TEXT NOT NULL,
    reference_name TEXT NOT NULL,
    product_type TEXT NOT NULL,
    glove_type TEXT NOT NULL,
    size TEXT NOT NULL,
    parameter_code TEXT NOT NULL,
    parameter_name TEXT NOT NULL,
    measurement_group TEXT NOT NULL,
    unit TEXT,
    min_value REAL,
    max_value REAL,
    nominal_value REAL,
    not_applicable INTEGER NOT NULL DEFAULT 0,
    sequence_no INTEGER NOT NULL,
    source TEXT,
    revision TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(spec_id, parameter_code)
);
SQL);
        $db->exec(<<<SQL
$createTable T_sales_order (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sales_order_no TEXT NOT NULL,
    so_item_no TEXT NOT NULL,
    gtype TEXT,
    item_code TEXT,
    factory TEXT NOT NULL,
    customer TEXT,
    country TEXT,
    colour TEXT,
    brand TEXT,
    product_type TEXT,
    size TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    lot_number TEXT,
    batch_no TEXT,
    source_system TEXT NOT NULL,
    source_lookup_at TEXT NOT NULL,
    source_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(sales_order_no, so_item_no, factory, size)
);
SQL);
        foreach (['CPETPE','HDPE'] as $type) {
            $table = "T_item_$type";
            $extra = $type === 'CPETPE'
                ? "inspection_no INTEGER NOT NULL DEFAULT 1,"
                : "";
            $unique = $type === 'CPETPE'
                ? 'UNIQUE(sales_order_no, so_item_no, size, pallet_no, inspection_no)'
                : 'UNIQUE(sales_order_no, so_item_no, size, pallet_no)';
            $db->exec(<<<SQL
$createTable $table (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sales_order_id INTEGER NOT NULL,
    sales_order_no TEXT NOT NULL,
    so_item_no TEXT NOT NULL,
    size TEXT NOT NULL,
    pallet_no INTEGER NOT NULL DEFAULT 1,
    $extra
    inspection_uid TEXT NOT NULL UNIQUE,
    inspection_status TEXT NOT NULL CHECK(inspection_status IN ('IN_PROGRESS','COMPLETED','VERIFIED')) DEFAULT 'IN_PROGRESS',
    final_disposition TEXT NOT NULL DEFAULT 'PENDING',
    fac TEXT,
    customer TEXT,
    country TEXT,
    product_type TEXT,
    glove_type_code TEXT,
    color TEXT,
    brand TEXT,
    sample_qty TEXT,
    template_inspection_status TEXT,
    qty_ctn_per_pallet TEXT,
    inspection_date TEXT,
    packer_id TEXT,
    inspector_id TEXT,
    verify_id TEXT,
    verify_date TEXT,
    critical_defect_total INTEGER NOT NULL DEFAULT 0,
    critical_defect_disposition TEXT NOT NULL DEFAULT 'PASS',
    holes_total INTEGER NOT NULL DEFAULT 0,
    holes_disposition TEXT NOT NULL DEFAULT 'PASS',
    major_visual_total INTEGER NOT NULL DEFAULT 0,
    major_visual_disposition TEXT NOT NULL DEFAULT 'PASS',
    minor_visual_total INTEGER NOT NULL DEFAULT 0,
    minor_visual_disposition TEXT NOT NULL DEFAULT 'PASS',
    packaging_defect_total INTEGER NOT NULL DEFAULT 0,
    packaging_defect_disposition TEXT NOT NULL DEFAULT 'PASS',
    air_pump_disposition TEXT,
    fstm_disposition TEXT,
    header_sealing_disposition TEXT,
    cuff_sealing_disposition TEXT,
    glove_length_disposition TEXT,
    length_a_disposition TEXT,
    length_b_disposition TEXT,
    header_length_disposition TEXT,
    header_width_disposition TEXT,
    gap_length_disposition TEXT,
    palm_width_disposition TEXT,
    glove_width_disposition TEXT,
    middle_finger_length_disposition TEXT,
    thickness_disposition TEXT,
    counting_pcs_disposition TEXT,
    counting_inner_disposition TEXT,
    counting_polybag_disposition TEXT,
    details_json TEXT NOT NULL,
    common_json TEXT NOT NULL,
    spec_snapshot_json TEXT NOT NULL,
    verification_json TEXT NOT NULL DEFAULT '[]',
    audit_json TEXT NOT NULL DEFAULT '[]',
    rejection_status TEXT,
    rejection_notes TEXT,
    rejection_owner_id INTEGER,
    rejection_updated_at TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL,
    updated_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    submitted_at TEXT,
    FOREIGN KEY(sales_order_id) REFERENCES T_sales_order(id),
    FOREIGN KEY(created_by) REFERENCES M_user(id),
    FOREIGN KEY(updated_by) REFERENCES M_user(id),
    $unique
);
SQL);
        }
        foreach (['CPETPE','HDPE'] as $type) {
            $db->exec(<<<SQL
$createTable T_record_$type (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    parameter_id INTEGER NOT NULL,
    sample_no INTEGER,
    measurement_point TEXT,
    reading_no INTEGER NOT NULL DEFAULT 1,
    value_num REAL,
    value_text TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY(item_id) REFERENCES T_item_$type(id) ON DELETE CASCADE,
    FOREIGN KEY(parameter_id) REFERENCES M_masterlist_specifications(id)
);
SQL);
        }
        $db->exec(<<<SQL
$createTable T_defect (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_type TEXT NOT NULL CHECK(item_type IN ('CPETPE','HDPE')),
    item_id INTEGER NOT NULL,
    cpetpe_item_id INTEGER,
    hdpe_item_id INTEGER,
    defect_id TEXT,
    defect_category TEXT NOT NULL,
    defect_name TEXT NOT NULL,
    defect_code TEXT,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    created_at TEXT NOT NULL,
    FOREIGN KEY(cpetpe_item_id) REFERENCES T_item_CPETPE(id) ON DELETE CASCADE,
    FOREIGN KEY(hdpe_item_id) REFERENCES T_item_HDPE(id) ON DELETE CASCADE,
    CHECK((item_type='CPETPE' AND cpetpe_item_id IS NOT NULL AND hdpe_item_id IS NULL) OR (item_type='HDPE' AND hdpe_item_id IS NOT NULL AND cpetpe_item_id IS NULL))
);
SQL);
        foreach ([
            'idx_T_sales_order_so' => 'T_sales_order(sales_order_no)',
            'idx_T_sales_order_identity' => 'T_sales_order(sales_order_no, so_item_no)',
            'idx_T_item_CPETPE_so' => 'T_item_CPETPE(sales_order_no)',
            'idx_T_item_CPETPE_so_item' => 'T_item_CPETPE(so_item_no)',
            'idx_T_item_CPETPE_uid' => 'T_item_CPETPE(inspection_uid)',
            'idx_T_item_CPETPE_status' => 'T_item_CPETPE(inspection_status)',
            'idx_T_item_CPETPE_updated' => 'T_item_CPETPE(updated_at DESC, id DESC)',
            'idx_T_item_CPETPE_result_updated' => 'T_item_CPETPE(final_disposition, updated_at DESC)',
            'idx_T_item_CPETPE_size_updated' => 'T_item_CPETPE(size, updated_at DESC)',
            'idx_T_item_CPETPE_glove_updated' => 'T_item_CPETPE(glove_type_code, updated_at DESC)',
            'idx_T_item_HDPE_so' => 'T_item_HDPE(sales_order_no)',
            'idx_T_item_HDPE_so_item' => 'T_item_HDPE(so_item_no)',
            'idx_T_item_HDPE_uid' => 'T_item_HDPE(inspection_uid)',
            'idx_T_item_HDPE_status' => 'T_item_HDPE(inspection_status)',
            'idx_T_item_HDPE_updated' => 'T_item_HDPE(updated_at DESC, id DESC)',
            'idx_T_item_HDPE_result_updated' => 'T_item_HDPE(final_disposition, updated_at DESC)',
            'idx_T_item_HDPE_size_updated' => 'T_item_HDPE(size, updated_at DESC)',
            'idx_T_item_HDPE_glove_updated' => 'T_item_HDPE(glove_type_code, updated_at DESC)',
            'idx_T_record_CPETPE_item' => 'T_record_CPETPE(item_id)',
            'idx_T_record_CPETPE_parameter' => 'T_record_CPETPE(parameter_id)',
            'idx_T_record_HDPE_item' => 'T_record_HDPE(item_id)',
            'idx_T_record_HDPE_parameter' => 'T_record_HDPE(parameter_id)',
            'idx_T_defect_item' => 'T_defect(item_type,item_id)',
            'idx_M_master_specs_lookup' => 'M_masterlist_specifications(inspection_type,glove_type,size,active)',
        ] as $name => $target) {
            $db->exec("CREATE INDEX IF NOT EXISTS $name ON $target");
        }
        $db->exec("INSERT OR IGNORE INTO sqlite_sequence(name,seq) VALUES ('T_item_HDPE',100000)");
        self::optimizeIndexes($db);
        self::upgradeWorkspace($db);
    }

    public static function upgradeWorkspace(PDO|TursoConnection $db): void {
        require_once __DIR__.'/Reports.php';
        $statements=[['sql'=>'CREATE TABLE IF NOT EXISTS T_inspection_creation (
            actor_id INTEGER NOT NULL, creation_key TEXT NOT NULL, payload_hash TEXT NOT NULL,
            first_item_id INTEGER, created_at TEXT NOT NULL,
            PRIMARY KEY(actor_id,creation_key))'],
            ['sql'=>"CREATE TABLE IF NOT EXISTS M_equipment (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product TEXT NOT NULL CHECK(product IN ('cpetpe','hdpe')),
                equipment_type TEXT NOT NULL CHECK(equipment_type IN ('scale','ruler','gauge')),
                equipment_id TEXT NOT NULL,
                sequence_no INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(product,equipment_type,equipment_id))"],
            ['sql'=>'CREATE INDEX IF NOT EXISTS idx_M_equipment_lookup ON M_equipment(product,equipment_type,active,sequence_no)']];
        $statements=array_merge($statements,Reports::schemaStatements());
        if($db instanceof TursoConnection) $db->executeTransaction($statements);
        else {
            $db->beginTransaction();
            try { foreach($statements as $statement) $db->exec($statement['sql']); $db->commit(); }
            catch(Throwable $e) { $db->rollBack(); throw $e; }
        }
    }

    public static function performanceIndexes(): array {
        return [
            'idx_CPETPE_so_units'=>'T_item_CPETPE(sales_order_no,so_item_no,size,pallet_no,inspection_no,id)',
            'idx_HDPE_so_units'=>'T_item_HDPE(sales_order_no,so_item_no,size,pallet_no,id)',
            'idx_CPETPE_so_updated'=>'T_item_CPETPE(sales_order_no,updated_at DESC,id DESC)',
            'idx_HDPE_so_updated'=>'T_item_HDPE(sales_order_no,updated_at DESC,id DESC)',
            'idx_CPETPE_report_day'=>'T_item_CPETPE(inspection_no,submitted_at)',
            'idx_HDPE_report_day'=>'T_item_HDPE(submitted_at)',
            'idx_specs_active_sequence'=>'M_masterlist_specifications(spec_id,active,sequence_no)',
            'idx_specs_size_candidates'=>'M_masterlist_specifications(inspection_type,size,active,spec_id,glove_type)',
            'idx_specs_exact_match'=>'M_masterlist_specifications(inspection_type,size,glove_type,active,spec_id)',
            'idx_CPETPE_record_summary'=>'T_record_CPETPE(item_id,parameter_id,sample_no,reading_no,id)',
            'idx_HDPE_record_summary'=>'T_record_HDPE(item_id,parameter_id,sample_no,reading_no,id)',
            'idx_CPETPE_reading_order'=>'T_record_CPETPE(item_id,sample_no,reading_no,id)',
            'idx_HDPE_reading_order'=>'T_record_HDPE(item_id,sample_no,reading_no,id)',
        ];
    }

    public static function optimizeIndexes(PDO|TursoConnection $db): void {
        $statements=[];
        foreach(self::performanceIndexes() as $name=>$target) $statements[]=['sql'=>"CREATE INDEX IF NOT EXISTS $name ON $target"];
        if($db instanceof TursoConnection) $db->executeBatch($statements);
        else foreach($statements as $statement) $db->exec($statement['sql']);
    }

    public static function seed(PDO|TursoConnection $db, array $master): void {
        if ((int)$db->query('SELECT COUNT(*) FROM M_masterlist_specifications')->fetchColumn() > 0) return;
        $now = date('Y-m-d H:i:s');
        $transactional = $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'turso';
        if ($transactional) $db->beginTransaction();
        try {
            foreach ($master['specifications'] as $s) {
                $type = strtoupper($s['product']) === 'CPETPE' ? 'CPETPE' : 'HDPE';
                $metrics = $master['products'][$s['product']]['metrics'];
                foreach ($metrics as $sequence => $metric) {
                    $limit = $s['limits'][$metric['key']] ?? ['min'=>null,'max'=>null,'nominal'=>null,'na'=>true,'source'=>$s['source']];
                    $db->prepare('INSERT INTO M_masterlist_specifications (inspection_type,spec_id,reference_name,product_type,glove_type,size,parameter_code,parameter_name,measurement_group,unit,min_value,max_value,nominal_value,not_applicable,sequence_no,source,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$type,$s['id'],$s['reference'],$s['product_type'],$s['glove_type'],$s['size'],strtoupper($metric['key']),$metric['label'],$metric['section'],$metric['unit'] ?? null,$limit['min'],$limit['max'],is_numeric($limit['nominal'] ?? null)?$limit['nominal']:null,(int)($limit['na'] ?? false),$sequence + 1,$limit['source'] ?? $s['source'],$s['revision'],$now,$now]);
                }
            }
            foreach ($master['products'] as $product => $data) {
                $type = $product === 'cpetpe' ? 'CPETPE' : 'HDPE';
                foreach ($data['tests'] as $sequence => $test) {
                    $db->prepare('INSERT OR IGNORE INTO M_masterlist_specifications (inspection_type,spec_id,reference_name,product_type,glove_type,size,parameter_code,parameter_name,measurement_group,unit,sequence_no,source,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$type,'__'.$product,'Runtime '.$type.' tests',$type,'*','*',strtoupper($test['key']),$test['label'],'tests',null,1000 + $sequence,'QREX runtime test definition','runtime',$now,$now]);
                }
            }
            if ($transactional) $db->commit();
        } catch (Throwable $e) {
            if ($transactional) $db->rollBack();
            throw $e;
        }
    }

    public static function seedDemoOrders(PDO|TursoConnection $db): void {
        // QAPQC02.QREX_MMSO is the source of sales-order lookup. Demo rows are
        // intentionally not mirrored into SQLite; the QAPQC connector supplies a
        // development fallback when the factory SQL Server is unavailable.
    }
}
