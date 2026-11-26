<?php
namespace Leadstart\SEO;
if (!defined('ABSPATH')) { exit; }
final class Reports {
    public static function range(string $period,string $start='',string $end=''): array {
        $today=new \DateTimeImmutable('today',new \DateTimeZone('UTC')); $end=$period==='custom'?$end:$today->format('Y-m-d');
        $days=['daily'=>0,'weekly'=>6,'monthly'=>29,'quarterly'=>89,'yearly'=>364];
        $start=$period==='custom'?$start:($period==='all'?Config::get()['report_start']:$today->modify('-'.($days[$period]??29).' days')->format('Y-m-d'));
        if (!Config::date_ok($start) || !Config::date_ok($end) || $start>$end || $end>$today->format('Y-m-d')) { return []; } return [$start,$end];
    }
    public static function ga_metrics(): array { return ['activeUsers','totalUsers','sessions','screenPageViews','engagementRate','userEngagementDuration','keyEvents']; }
    public static function get(string $provider,string $dimension,array $dates,bool $refresh=false): array {
        if (count($dates)!==2) { return API::error('Invalid report dates.'); }
        $s=Config::get(); $cache=Config::cache_key('report',[$provider,$dimension,$dates,$s['gsc_property'],$s['ga_property'],hash('sha256',Config::secret('google_json'))]);
        if (!$refresh && ($cached=get_transient($cache))!==false) { return $cached; }
        if ($provider==='gsc') {
            if (!Config::property_ok($s['gsc_property'])) { return API::error('Configure a Search Console property.'); }
            $base='https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($s['gsc_property']);
            if ($dimension==='sitemaps') { $r=API::google(API::GSC_SCOPE,'GET',$base.'/sitemaps'); }
            else {
                if (!in_array($dimension,['total','date','query','page','country','device'],true)) { return API::error('Invalid Search Console dimension.'); }
                $floor=(new \DateTimeImmutable('today',new \DateTimeZone('UTC')))->modify('-16 months')->format('Y-m-d'); $start=max($dates[0],$floor);
                if ($start>$dates[1]) { return API::error('Selected period is outside Search Console retention.'); }
                $r=API::google(API::GSC_SCOPE,'POST',$base.'/searchAnalytics/query',['startDate'=>$start,'endDate'=>$dates[1],'dimensions'=>$dimension==='total'?[]:[$dimension],'rowLimit'=>50,'type'=>'web','dataState'=>'final']); $r['range']=[$start,$dates[1]];
            }
        } elseif ($provider==='ga') {
            if (!preg_match('/^[0-9]{1,20}$/D',$s['ga_property'])) { return API::error('Configure a numeric GA4 property ID.'); }
            if (!in_array($dimension,['total','date','sessionDefaultChannelGroup','landingPage','country','city','deviceCategory','organic'],true)) { return API::error('Invalid GA4 dimension.'); }
            $body=['dateRanges'=>[['startDate'=>$dates[0],'endDate'=>$dates[1]]],'metrics'=>array_map(static fn($m)=>['name'=>$m],self::ga_metrics()),'limit'=>50];
            if ($dimension==='organic') { $body['dimensionFilter']=['filter'=>['fieldName'=>'sessionDefaultChannelGroup','stringFilter'=>['matchType'=>'EXACT','value'=>'Organic Search']]]; }
            elseif ($dimension!=='total') { $body['dimensions']=[['name'=>$dimension]]; }
            $r=API::google(API::GA_SCOPE,'POST','https://analyticsdata.googleapis.com/v1beta/properties/'.$s['ga_property'].':runReport',$body); $r['range']=$dates;
        } else { return API::error('Invalid report provider.'); }
        if ($r['code']===200 && is_array($r['data'])) { set_transient($cache,$r,15*MINUTE_IN_SECONDS); } return $r;
    }
    public static function table(string $provider,string $dimension,array $r): array {
        $data=$r['data']??[]; $out=[];
        if ($provider==='gsc' && $dimension==='sitemaps') { foreach ($data['sitemap']??[] as $row) { $out[]=[$row['path']??'', $row['lastSubmitted']??'', $row['lastDownloaded']??'', $row['errors']??'', $row['warnings']??'']; } return [['Sitemap','Submitted','Downloaded','Errors','Warnings'],$out]; }
        if ($provider==='gsc') { foreach ($data['rows']??[] as $row) { $out[]=[implode(' / ',$row['keys']??['Total']),$row['clicks']??0,$row['impressions']??0,round(100*($row['ctr']??0),2).'%',round($row['position']??0,2)]; } return [[$dimension,'Clicks','Impressions','CTR','Position'],$out]; }
        $headers=array_merge([$dimension],self::ga_metrics(),['Engagement sec / active user (est.)']); foreach ($data['rows']??[] as $row) { $values=array_column($row['metricValues']??[],'value'); $label=implode(' / ',array_column($row['dimensionValues']??[],'value'))?:'Total'; $average=isset($values[0],$values[5]) && (float)$values[0]>0?round((float)$values[5]/(float)$values[0],2):0; $out[]=array_merge([$label],$values,[$average]); } return [$headers,$out];
    }
}
