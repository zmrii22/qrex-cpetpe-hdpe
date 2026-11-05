<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require __DIR__.'/../app/bootstrap.php';
if(!in_array('--apply',$argv,true)) {
    echo "Adds retry-safe creation storage and shared versioned reports with change tracking.\nRun with --apply after backing up the database. Existing inspections are unchanged.\n";
    exit;
}
Database::upgradeWorkspace(db());
echo "Workspace schema upgraded. Safe to repeat; existing inspections unchanged.\n";
