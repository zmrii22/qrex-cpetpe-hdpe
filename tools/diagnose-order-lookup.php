<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
$product=$argv[1]??'cpetpe';$factory=$argv[2]??($product==='hdpe'?'M26B':'M26A');$so=$argv[3]??'';
try {
    $result=Inspections::orderLookup($product,$factory,$so);
    echo json_encode(['sizes'=>count($result['sizes']),'fingerprint'=>hash('sha256',json_encode($result,JSON_THROW_ON_ERROR)),'timing'=>RequestTiming::snapshot()],JSON_THROW_ON_ERROR)."\n";
    if(in_array('--compare',$argv,true)) {
        $reflection=new ReflectionClass(Inspections::class);
        $source=$reflection->getMethod('qapqcRows');$source->setAccessible(true);
        $spec=$reflection->getMethod('specForSource');$spec->setAccessible(true);
        $existing=$reflection->getMethod('existingItems');$existing->setAccessible(true);
        foreach($source->invoke(null,$product,$factory,$so) as $n=>$row) {
            $size=$result['sizes'][$n];
            $state=$existing->invoke(null,strtoupper($product),$row['sales_order_no'],$row['so_item_no'],$row['size'],$row['factory']);
            if($size['has_spec']!==(bool)$spec->invoke(null,$product,$row)||$size['state']!==$state['state']||$size['existing_id']!==$state['id']) {
                throw new RuntimeException('Optimized lookup differs from individual queries.');
            }
        }
        echo "All specification and existing-inspection results match the individual queries.\n";
    }
} catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
