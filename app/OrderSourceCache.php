<?php
declare(strict_types=1);

final class OrderSourceCache {
    public static function remember(string $key, callable $load, int $ttl=45, ?string $directory=null): array {
        $directory ??= ROOT.'/storage/cache/orders';
        if($ttl<=0 || (!is_dir($directory) && !@mkdir($directory,0775,true))) return $load();
        $file=$directory.'/'.hash('sha256',$key).'.json';
        $handle=@fopen($file,'c+');
        if($handle===false) return $load();
        // A per-key lock combines simultaneous lookups of the same SO into one source call.
        if(!flock($handle,LOCK_EX)) { fclose($handle); return $load(); }
        try {
            $raw=stream_get_contents($handle,8*1024*1024+1);
            $cached=strlen($raw)<=8*1024*1024 ? json_decode($raw,true) : null;
            if(is_array($cached) && ($cached['expires']??0)>time() && is_array($cached['rows']??null)) {
                RequestTiming::count('source_cache_hits');
                return $cached['rows'];
            }
            $rows=$load();
            if($rows) {
                $json=json_encode(['expires'=>time()+$ttl,'rows'=>$rows],JSON_THROW_ON_ERROR);
                if(strlen($json)<=8*1024*1024) {
                    rewind($handle); ftruncate($handle,0);
                    fwrite($handle,$json); fflush($handle);
                }
            }
            return $rows;
        } finally {
            flock($handle,LOCK_UN); fclose($handle);
            self::prune($directory);
        }
    }

    private static function prune(string $directory): void {
        $files=glob($directory.'/*.json')?:[];
        if(count($files)<=128) return;
        usort($files,fn($a,$b)=>(filemtime($a)?:0)<=>(filemtime($b)?:0));
        foreach(array_slice($files,0,count($files)-128) as $file) {
            $handle=@fopen($file,'r+');
            if($handle===false) continue;
            if(flock($handle,LOCK_EX|LOCK_NB)) {
                // Windows needs the handle closed before deleting the old entry.
                flock($handle,LOCK_UN); fclose($handle); @unlink($file);
            } else fclose($handle);
        }
    }
}
