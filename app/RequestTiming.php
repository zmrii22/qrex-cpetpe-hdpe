<?php
declare(strict_types=1);

final class RequestTiming {
    private static float $started;
    private static array $metrics=[];
    private static int $calls=0;
    private static int $queries=0;
    private static float $slowest=0;

    public static function start(): void {
        self::$started=microtime(true);
        self::$metrics=[];
        self::$calls=0;
        self::$queries=0;
        self::$slowest=0;
    }

    public static function measure(string $name, callable $work, int $queries=0): mixed {
        $start=microtime(true);
        try { return $work(); }
        finally {
            $ms=(microtime(true)-$start)*1000;
            self::$metrics[$name]=(self::$metrics[$name]??0)+$ms;
            if($name==='db') {
                self::$calls++;
                self::$queries+=$queries;
                self::$slowest=max(self::$slowest,$ms);
            }
        }
    }

    public static function snapshot(): array {
        return ['api'=>(microtime(true)-self::$started)*1000,'db'=>self::$metrics['db']??0,
            'session'=>self::$metrics['session']??0,'db_calls'=>self::$calls,
            'qapqc'=>self::$metrics['qapqc']??0,
            'db_queries'=>self::$queries,'db_slowest'=>self::$slowest,
            'qapqc_queries'=>self::$metrics['qapqc_queries']??0,'source_cache_hits'=>self::$metrics['source_cache_hits']??0];
    }

    public static function count(string $name): void { self::$metrics[$name]=(self::$metrics[$name]??0)+1; }

    public static function headerValue(): string {
        $parts=[];
        foreach(self::snapshot() as $name=>$value) {
            $parts[] = in_array($name,['db_calls','db_queries','qapqc_queries','source_cache_hits'],true)
                ? $name.';desc="'.$value.'"' : $name.';dur='.number_format($value,2,'.','');
        }
        return implode(', ',$parts);
    }

    public static function emit(int $status): void {
        header('Server-Timing: '.self::headerValue());
        $timing=self::snapshot();
        if($timing['api']>=1000) {
            $action=preg_replace('/[^a-z_]/','',substr((string)($_GET['action']??''),0,50));
            error_log('QREX slow request '.json_encode(['action'=>$action,'status'=>$status,'timing'=>$timing]));
        }
    }
}
