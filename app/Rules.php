<?php
declare(strict_types=1);
final class Rules {
    public const CATEGORIES = ['Critical','Holes','Major','Minor','Packaging'];
    public static function defectResult(int $total, bool $checked=true): string { return $total>0?'fail':'pass'; }
    public static function reading(mixed $value, array $limit): string {
        if($value===null || $value==='') return 'pending';
        if($limit['na']??false) return 'na';
        if(($limit['min']??null)===null && ($limit['max']??null)===null) return 'unassessed';
        if((isset($limit['min']) && (float)$value<(float)$limit['min']-0.0000001)||(isset($limit['max']) && (float)$value>(float)$limit['max']+0.0000001)) return 'fail';
        return 'pass';
    }
    public static function aggregate(array $results): string {
        foreach(['fail','pending','hold','unassessed'] as $state) if(in_array($state,$results,true)) return $state;
        return count(array_filter($results,fn($r)=>$r!=='na'))?'pass':'na';
    }
    public static function evaluate(array $inspection): array {
        $product=$inspection['product']; $definition=master()['products'][$product]; $data=$inspection['data']; $limits=$inspection['spec']['limits']??[];
        $checks=[]; $issues=[]; $missing=[];
        foreach(['batch_no'=>'Batch number','pallet_no'=>'Pallet number','inspection_date'=>'Inspection date','inspection_status'=>'Inspection Count','sample_size'=>'Sample size','sample_qty'=>'Sample quantity','cartons_per_pallet'=>'Carton per pallet/lot/batch'] as $key=>$label) {
            if(trim((string)($data['fields'][$key]??''))==='') $missing[]=['section'=>'information','key'=>$key,'message'=>"Enter $label."];
        }
        if(trim((string)($data['fields']['inspector_id']??''))==='') $missing[]=['section'=>'review','key'=>'inspector_id','message'=>'Enter Inspector ID.'];
        $missing=array_merge($missing,Sampling::missing($data['fields']??[]));
        foreach($definition['metrics'] as $metric) {
            $key=$metric['key']; $limit=$limits[$key]??[]; $values=$data['measurements'][$key]??[]; $results=[];
            if($key==='thickness') {
                $entered=count(array_filter($values,fn($v)=>$v!==null&&$v!==''));
                $checks[$key]=['label'=>$metric['label'],'section'=>$metric['section'],'result'=>'na','entered'=>$entered,'expected'=>$metric['samples'],'readings'=>array_fill(0,$metric['samples'],'na')];
                continue;
            }
            if($limit['na']??false) { $checks[$key]=['label'=>$metric['label'],'section'=>$metric['section'],'result'=>'na','entered'=>0,'expected'=>0,'readings'=>[]]; continue; }
            for($n=0;$n<$metric['samples'];$n++) {
                $v=$values[$n]??null; $r=self::reading($v,$limit); $results[]=$r;
                if($r==='fail') $issues[]=['section'=>$metric['section'],'key'=>$key,'sample'=>$n+1,'message'=>$metric['label'].' sample '.($n+1).': '.$v.' '.$metric['unit'].'; required '.self::range($limit,$metric['unit']).'.'];
            }
            $entered=count(array_filter($values,fn($v)=>$v!==null&&$v!==''));
            $assessed=array_values(array_filter($results,fn($r)=>$r!=='pending'));
            $result=self::aggregate($assessed);
            if(($data['measurement_flags'][$key]??'')==='blank_pass' && $entered===0) $result='pass';
            // HDPE thickness is recorded without limits, as confirmed by the product owner.
            if($key==='thickness'&&$product==='hdpe'&&($limit['min']??null)===null&&($limit['max']??null)===null&&$entered>0) $result='na';
            if($key==='air_pump'&&$product==='cpetpe'&&($limit['min']??null)===null&&($limit['max']??null)===null&&$entered>0) $result='pass';
            if($result==='unassessed'&&$entered>0) $issues[]=['section'=>$metric['section'],'key'=>$key,'message'=>$metric['label'].': specification not available; requires review.'];
            $checks[$key]=['label'=>$metric['label'],'section'=>$metric['section'],'result'=>$result,'entered'=>$entered,'expected'=>$metric['samples'],'readings'=>$results];
        }
        foreach($definition['tests'] as $test) {
            $key=$test['key']; $values=$data['tests'][$key]??[]; $results=[];
            for($n=0;$n<$test['samples'];$n++) $results[]=strtolower(($values[$n]??'')?:'na');
            $assessed=array_values(array_filter($results,fn($r)=>$r!=='pending'));
            $result=self::aggregate($assessed); $checks[$key]=['label'=>$test['label'],'section'=>'tests','result'=>$result,'readings'=>$results];
            if($result==='fail') {
                $issues[]=['section'=>'tests','key'=>$key,'message'=>$test['label'].': '.($test['failure']??($data['fields'][$key.'_defect']??'Failed test')).'.'];
                if(isset($test['defects'])&&empty($data['fields'][$key.'_defect'])) $missing[]=['section'=>'tests','key'=>$key,'message'=>'Select the '.$test['label'].' defect.'];
            }
        }
        foreach(self::CATEGORIES as $category) {
            $total=0; foreach($data['defects'] as $defect) if($defect['category']===$category) $total+=(int)$defect['quantity'];
            $check=Sampling::check($data['fields']??[],$category,$total);
            $checks['defect_'.$category]=$check;
            if($check['result']==='fail') $issues[]=['section'=>'defects','key'=>$category,'message'=>Sampling::failure($check)];
        }
        $countingMode=$data['fields']['counting_mode'] ?? 'inner';
        $requiredCounts=$countingMode==='polybag' ? ['pcs'=>'Pieces','polybag'=>'Polybag'] : ['pcs'=>'Pieces','inner'=>'Inner'];
        foreach(['pcs'=>'Pieces','inner'=>'Inner','polybag'=>'Polybag'] as $key=>$label) {
            $value=$data['fields']['count_'.$key]??''; $disposition=$data['fields']['count_'.$key.'_result']??'';
            if(!array_key_exists($key,$requiredCounts)) {
                $checks['count_'.$key]=['label'=>$label.' count','section'=>'packing','result'=>'na'];
                continue;
            }
            $result=$disposition===''?'pending':($disposition==='PASS'?'pass':'fail');
            $checks['count_'.$key]=['label'=>$label.' count','section'=>'packing','result'=>$result];
            if($value===''||$disposition==='') $missing[]=['section'=>'packing','key'=>'count_'.$key,'message'=>'Complete '.$label.' count and disposition.'];
            if($result==='fail') $issues[]=['section'=>'packing','key'=>'count_'.$key,'message'=>$label.' count: '.$disposition.'.'];
        }
        foreach(['scale_id'=>'Weighing scale ID','ruler_id'=>'Ruler ID','gauge_id'=>'Thickness gauge ID','packer_id'=>'Packer ID'] as $key=>$label) if(empty($data['fields'][$key])) $missing[]=['section'=>'packing','key'=>$key,'message'=>'Enter '.$label.'.'];
        foreach(['length_a'=>'Short Length|Long Length','glove_length'=>'Short Length|Long Length','gap_length'=>'Gap Length out of spec|No Gap Length'] as $key=>$choices) if(($checks[$key]['result']??'')==='fail'&&empty($data['fields'][$key.'_defect'])) $missing[]=['section'=>'measurements','key'=>$key,'message'=>'Select the '.$checks[$key]['label'].' defect.'];
        $sections=[];
        foreach(['information','defects','tests','measurements','packing'] as $section) {
            $states=array_column(array_filter($checks,fn($c)=>$c['section']===$section),'result');
            if(count(array_filter($missing,fn($m)=>$m['section']===$section))) $states[]='pending';
            $sections[$section]=self::aggregate($states?:['pass']);
        }
        $activitySections=['information','defects','tests','measurements','packing'];
        $allSectionsFlagged=count(array_filter($activitySections,fn($section)=>($data['section_flags'][$section]??'')==='pass'))===count($activitySections);
        foreach($activitySections as $section) {
            if(($data['section_flags'][$section]??'')!=='pass') continue;
            foreach($checks as $key=>$check) {
                if(($check['section']??'')===$section) $checks[$key]['result']='pass';
            }
            $sections[$section]='pass';
            $missing=array_values(array_filter($missing,fn($m)=>$m['section']!==$section));
            $issues=array_values(array_filter($issues,fn($issue)=>$issue['section']!==$section));
        }
        if($allSectionsFlagged) $missing=[];
        $overall=self::aggregate(array_values($sections));
        if($overall==='unassessed') $overall='hold';
        return ['result'=>$overall,'checks'=>$checks,'sections'=>$sections,'issues'=>$issues,'missing'=>$missing,'can_submit'=>count($missing)===0,'passed'=>count(array_filter($checks,fn($c)=>$c['result']==='pass')),'failed'=>count(array_filter($checks,fn($c)=>$c['result']==='fail'))];
    }
    public static function range(array $limit,string $unit=''): string {
        if(($limit['min']??null)===null&&($limit['max']??null)===null) return 'No specification';
        return (isset($limit['min'])?(string)$limit['min']:'no minimum').' to '.(isset($limit['max'])?(string)$limit['max']:'no maximum').($unit?' '.$unit:'');
    }
}
