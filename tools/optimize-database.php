<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
if(in_array('--apply',$argv,true)) {
    Database::optimizeIndexes(db());
    echo "Performance indexes installed; inspection data unchanged.\n";
}
foreach(['CPETPE','HDPE'] as $type) {
    $first=$type==='CPETPE'?'inspection_no=1 AND ':'';
    $queries=[
        'SO search'=>["SELECT id FROM T_item_$type WHERE sales_order_no=? ORDER BY updated_at DESC,id DESC",['PLAN-CHECK']],
        'Daily report'=>["SELECT final_disposition FROM T_item_$type WHERE {$first}submitted_at>=? AND submitted_at<?",['2026-01-01 00:00:00','2026-01-02 00:00:00']],
        'Readings'=>["SELECT * FROM T_record_$type WHERE item_id=? ORDER BY sample_no,reading_no,id",[0]],
    ];
    foreach($queries as $label=>[$sql,$params]) {
        $plan=query('EXPLAIN QUERY PLAN '.$sql,$params)->fetchAll();
        echo "$type $label: ".implode(' | ',array_column($plan,'detail'))."\n";
    }
}
if(in_array('--timings',$argv,true)) {
    foreach(['CPETPE','HDPE'] as $type) {
        $id=query("SELECT id FROM T_item_$type ORDER BY updated_at DESC,id DESC LIMIT 1")->fetchColumn();
        if(!$id) continue;
        RequestTiming::start();
        Inspections::get((int)$id);
        echo "$type inspection timing: ".RequestTiming::headerValue()."\n";
    }
    RequestTiming::start();
    Reports::daily(date('Y-m-d'));
    echo 'Daily report timing: '.RequestTiming::headerValue()."\n";
}
