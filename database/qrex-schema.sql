PRAGMA foreign_keys = ON;

CREATE TABLE M_user (
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

CREATE TABLE M_masterlist_specifications (
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

CREATE TABLE T_sales_order (
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

CREATE TABLE T_item_CPETPE (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sales_order_id INTEGER NOT NULL,
    sales_order_no TEXT NOT NULL,
    so_item_no TEXT NOT NULL,
    size TEXT NOT NULL,
    pallet_no INTEGER NOT NULL DEFAULT 1,
    inspection_no INTEGER NOT NULL DEFAULT 1,
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
    UNIQUE(sales_order_no, so_item_no, size, pallet_no, inspection_no)
);

CREATE TABLE T_item_HDPE (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sales_order_id INTEGER NOT NULL,
    sales_order_no TEXT NOT NULL,
    so_item_no TEXT NOT NULL,
    size TEXT NOT NULL,
    pallet_no INTEGER NOT NULL DEFAULT 1,
    
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
    UNIQUE(sales_order_no, so_item_no, size, pallet_no)
);

CREATE TABLE T_record_CPETPE (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    parameter_id INTEGER NOT NULL,
    sample_no INTEGER,
    measurement_point TEXT,
    reading_no INTEGER NOT NULL DEFAULT 1,
    value_num REAL,
    value_text TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY(item_id) REFERENCES T_item_CPETPE(id) ON DELETE CASCADE,
    FOREIGN KEY(parameter_id) REFERENCES M_masterlist_specifications(id)
);

CREATE TABLE T_record_HDPE (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    parameter_id INTEGER NOT NULL,
    sample_no INTEGER,
    measurement_point TEXT,
    reading_no INTEGER NOT NULL DEFAULT 1,
    value_num REAL,
    value_text TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY(item_id) REFERENCES T_item_HDPE(id) ON DELETE CASCADE,
    FOREIGN KEY(parameter_id) REFERENCES M_masterlist_specifications(id)
);

CREATE TABLE T_defect (
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

CREATE INDEX idx_M_master_specs_lookup ON M_masterlist_specifications(inspection_type,glove_type,size,active);

CREATE INDEX idx_T_defect_item ON T_defect(item_type,item_id);

CREATE INDEX idx_T_item_CPETPE_so ON T_item_CPETPE(sales_order_no);

CREATE INDEX idx_T_item_CPETPE_so_item ON T_item_CPETPE(so_item_no);

CREATE INDEX idx_T_item_CPETPE_status ON T_item_CPETPE(inspection_status);

CREATE INDEX idx_T_item_CPETPE_uid ON T_item_CPETPE(inspection_uid);

CREATE INDEX idx_T_item_HDPE_so ON T_item_HDPE(sales_order_no);

CREATE INDEX idx_T_item_HDPE_so_item ON T_item_HDPE(so_item_no);

CREATE INDEX idx_T_item_HDPE_status ON T_item_HDPE(inspection_status);

CREATE INDEX idx_T_item_HDPE_uid ON T_item_HDPE(inspection_uid);

CREATE INDEX idx_T_record_CPETPE_item ON T_record_CPETPE(item_id);

CREATE INDEX idx_T_record_CPETPE_parameter ON T_record_CPETPE(parameter_id);

CREATE INDEX idx_T_record_HDPE_item ON T_record_HDPE(item_id);

CREATE INDEX idx_T_record_HDPE_parameter ON T_record_HDPE(parameter_id);

CREATE INDEX idx_T_sales_order_identity ON T_sales_order(sales_order_no, so_item_no);

CREATE INDEX idx_T_sales_order_so ON T_sales_order(sales_order_no);

