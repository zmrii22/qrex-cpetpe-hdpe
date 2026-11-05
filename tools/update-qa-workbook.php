<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
try {
    $path=$argv[1]??'';
    demand($path!=='' && $path!=='--apply','Usage: php -d extension=zip quality/tools/update-qa-workbook.php workbook.xlsx [--apply]');
    $data=ManpowerSync::parseWorkbook($path);
    demand(isset($data['sampling']),'Sampling Acceptance sheet is required.');
    demand($data['sampling']==Sampling::master(),'Workbook sampling data differs from the deployed sampling master. Import the reviewed master first.');
    echo json_encode(ManpowerSync::updateMissingUsersAndEquipment($data,in_array('--apply',$argv,true)),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $error) { fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1); }
