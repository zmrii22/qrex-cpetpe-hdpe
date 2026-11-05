<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
$apply=in_array('--apply',$argv,true);
$changed=0;
$after=0;
do {
    $rows=query('SELECT id,sales_order_no,so_item_no,size,pallet_no,inspection_no,inspection_uid,template_inspection_status,details_json FROM T_item_CPETPE WHERE id>? ORDER BY id LIMIT 250',[$after])->fetchAll();
    foreach($rows as $row) {
        $after=(int)$row['id'];
        $n=max(1,(int)$row['inspection_no']);
        $uid=implode('-',[$row['sales_order_no'],$row['so_item_no'],$row['size'],$row['pallet_no'],$n]);
        $suffix=($n%100>=11&&$n%100<=13)?'th':match($n%10){1=>'st',2=>'nd',3=>'rd',default=>'th'};
        $status=$n.$suffix.' Inspection';
        $details=json_decode($row['details_json'] ?: '{}',true,512,JSON_THROW_ON_ERROR);
        if($row['inspection_uid']===$uid && $row['template_inspection_status']===$status && ($details['fields']['inspection_status']??'')===$status) continue;
        if(query('SELECT id FROM T_item_CPETPE WHERE inspection_uid=? AND id<>?',[$uid,$after])->fetchColumn()) {
            echo "Skipped conflicting identity for inspection $after.\n";
            continue;
        }
        $changed++;
        if($apply) {
            $details['fields']['inspection_status']=$status;
            query('UPDATE T_item_CPETPE SET inspection_uid=?,template_inspection_status=?,details_json=?,version=version+1 WHERE id=?',[$uid,$status,json_encode($details,JSON_THROW_ON_ERROR),$after]);
        }
    }
} while(count($rows)===250);
echo ($apply?'Repaired ':'Would repair ').$changed." inspection identities.\n";
if(!$apply) echo "Run with --apply during maintenance to write changes.\n";
