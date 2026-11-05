<?php
declare(strict_types=1);

final class Sampling {
    public static function master(): array {
        static $data;
        return $data ??= json_decode(file_get_contents(__DIR__.'/../resources/sampling.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function usesPlan(array $fields): bool {
        return ($fields['inspection_plan']??'')!=='';
    }

    public static function limit(array $fields, string $category): ?array {
        // Packaging is absent from the workbook; retain the existing zero-defect rule.
        if($category==='Packaging' || !self::usesPlan($fields)) return ['aql'=>null,'acc'=>0,'rej'=>1,'source'=>'existing'];
        return self::master()['limits'][(string)($fields['sample_size']??'')][(string)($fields['inspection_plan']??'')][$category] ?? null;
    }

    public static function missing(array $fields): array {
        if(!self::usesPlan($fields)) return [];
        $missing=[];
        if(!in_array((string)($fields['inspection_plan']??''),array_column(self::master()['plans'],'value'),true)) $missing[]=['section'=>'information','key'=>'inspection_plan','message'=>'Choose an Inspection Plan.'];
        if(!in_array((string)($fields['sample_size']??''),array_map('strval',self::master()['sample_sizes']),true)) $missing[]=['section'=>'information','key'=>'sample_size','message'=>'Choose Sample Size 125, 200 or 315.'];
        return $missing;
    }

    public static function check(array $fields, string $category, int $total): array {
        $limit=self::limit($fields,$category);
        $result=$limit===null?'pending':($total<=$limit['acc']?'pass':'fail');
        return ['label'=>$category,'section'=>'defects','result'=>$result,'total'=>$total,'sampling'=>$limit];
    }

    public static function failure(array $check): string {
        $limit=$check['sampling'];
        return $check['label'].': '.$check['total'].' defect(s); Acc '.$limit['acc'].' / Rej '.$limit['rej'].($limit['aql']!==null?' at AQL '.$limit['aql']:' (existing rule)').'.';
    }
}
