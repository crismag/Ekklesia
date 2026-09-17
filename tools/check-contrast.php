<?php
/** Phase 0 CI gate: every theme preset must pass WCAG AA for its token matrix.
 *  Usage: php tools/check-contrast.php   (exit 1 on any failure) */
declare(strict_types=1);
function lum(string $h): float {
    $h=ltrim($h,'#'); if(strlen($h)===3){$h=$h[0].$h[0].$h[1].$h[1].$h[2].$h[2];}
    $c=[]; foreach([0,2,4] as $i){$v=hexdec(substr($h,$i,2))/255;
        $c[]=$v<=0.03928?$v/12.92:pow(($v+0.055)/1.055,2.4);}
    return 0.2126*$c[0]+0.7152*$c[1]+0.0722*$c[2];
}
function cr(string $a,string $b): float {
    $l1=lum($a);$l2=lum($b); if($l1<$l2){[$l1,$l2]=[$l2,$l1];}
    return round(($l1+0.05)/($l2+0.05),2);
}
$theme=json_decode(file_get_contents(__DIR__.'/../config/theme.json'),true);
$fail=0;$checked=0;
foreach($theme['presets'] as $name=>$p){
    $v=$p['vars']; $paper=$v['--paper']??'#ffffff'; $soft=$v['--soft']??$paper;
    $tests=[
        ['ink on paper',$v['--ink']??null,$paper,4.5],
        ['muted on paper',$v['--muted']??null,$paper,4.5],
        ['muted on soft',$v['--muted']??null,$soft,4.5],
    ];
    // Brand hues are NEVER darkened to force white text — the preset's character
    // is preserved and each fill carries a paired, accessible foreground:
    //   --on-<tok>   readable ON that fill
    //   --<tok>-ink  the hue darkened for use AS text on paper/soft
    foreach(['--teal','--gold','--rose','--blue'] as $t){
        if(!isset($v[$t])) continue;
        $nm=substr($t,2);
        if(isset($v['--on-'.$nm]))  $tests[]=["on-$nm on $nm",$v['--on-'.$nm],$v[$t],4.5];
        else                        $tests[]=["MISSING --on-$nm",'#ffffff',$v[$t],4.5];
        if(isset($v[$t.'-ink'])){
            $tests[]=["$nm-ink on paper",$v[$t.'-ink'],$paper,4.5];
            $tests[]=["$nm-ink on soft",$v[$t.'-ink'],$soft,4.5];
        } else $tests[]=["MISSING $t-ink",$v[$t],$paper,4.5];
    }
    // Record links (a person or family name in an admin table) render in
    // --blue-ink. They sit on paper and on soft, which the loop above already
    // covers, and inside the import screen's tinted badge, which it does not.
    if(isset($v['--blue-ink'])){
        // The badge takes its background from --soft, so the pair the gate
        // already checks is the pair the screen actually renders.
        $tests[]=['blue-ink on merged badge',$v['--blue-ink'],$soft,4.5];
    }

    foreach($tests as [$label,$a,$b,$min]){
        if($a===null||$b===null) continue;
        $checked++; $r=cr($a,$b);
        if($r<$min){ $fail++; printf("  FAIL %-16s %-18s %.2f (need %.1f)\n",$name,$label,$r,$min); }
    }
}
printf("\n%d pairs checked across %d presets — %d failures\n",$checked,count($theme['presets']),$fail);
exit($fail>0?1:0);
