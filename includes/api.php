<?php
namespace LST_SEO;
if (!defined('ABSPATH')) { exit; }
final class API {
    const INDEX_SCOPE='https://www.googleapis.com/auth/indexing';
    const GSC_SCOPE='https://www.googleapis.com/auth/webmasters.readonly';
    const GA_SCOPE='https://www.googleapis.com/auth/analytics.readonly';
    public static function b64(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
    public static function request(string $method,string $url,array $headers=[], $body=null): array {
        $args=['method'=>$method,'headers'=>$headers,'timeout'=>12,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576];
        if ($body!==null) { $args['body']=$body; }
        $response=wp_safe_remote_request($url,$args);
        if (is_wp_error($response)) { return ['code'=>0,'data'=>null,'message'=>'Transport failure; delivery unconfirmed.','retry'=>true,'ambiguous'=>true]; }
        $code=(int)wp_remote_retrieve_response_code($response); $raw=wp_remote_retrieve_body($response); $data=json_decode($raw,true);
        // Do not return raw upstream errors: they can contain credentials or private data.
        $retry_after=wp_remote_retrieve_header($response,'retry-after'); $delay=is_numeric($retry_after)?(int)$retry_after:0;
        if (!$delay && is_string($retry_after) && $retry_after!=='') { $when=strtotime($retry_after); $delay=$when?max(0,$when-time()):0; }
        return ['code'=>$code,'data'=>$data,'message'=>'HTTP '.$code,'retry'=>$code===429 || $code===408 || $code>=500,'delay'=>min(86400,max(0,$delay))];
    }
    public static function token(string $scope) {
        $raw=Config::secret('google_json'); $v=Config::google_json($raw); if (is_wp_error($v)) { return $v; }
        
        $now=time(); $jwt=self::b64(wp_json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.self::b64(wp_json_encode(['iss'=>$v['client_email'],'scope'=>$scope,'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
        $sig=''; if (!openssl_sign($jwt,$sig,$v['private_key'],OPENSSL_ALGO_SHA256)) { return new \WP_Error('sign','Google assertion signing failed.'); }
        $r=self::request('POST','https://oauth2.googleapis.com/token',['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt.'.'.self::b64($sig)],'','&'));
        $t=$r['data']['access_token']??null; if ($r['code']!==200 || !is_string($t) || $t==='') { return new \WP_Error('oauth','Google authentication failed ('.$r['message'].').',['retry'=>$r['retry'],'code'=>$r['code']]); }
        // Token remains in memory only. No raw bearer token in options/transients.
        return $t;
    }
    public static function google(string $scope,string $method,string $url,?array $body=null): array {
        static $tokens=[]; $key=hash('sha256',$scope.Config::secret('google_json')); if (!isset($tokens[$key])) { $tokens[$key]=self::token($scope); }
        $t=$tokens[$key]; if (is_wp_error($t)) { $d=$t->get_error_data(); return ['code'=>(int)($d['code']??0),'data'=>null,'message'=>$t->get_error_message(),'retry'=>(bool)($d['retry']??false)]; }
        $r=self::request($method,$url,['Authorization'=>'Bearer '.$t,'Content-Type'=>'application/json'], $body===null?null:wp_json_encode($body)); if ($r['code']===401) { unset($tokens[$key]); } return $r;
    }
    public static function publish(string $url,string $action): array { if (!in_array($action,['update','delete'],true)) { return self::error('Invalid Google notification action.'); } $r=self::google(self::INDEX_SCOPE,'POST','https://indexing.googleapis.com/v3/urlNotifications:publish',['url'=>$url,'type'=>$action==='delete'?'URL_DELETED':'URL_UPDATED']); if ($r['code']>=200 && $r['code']<300 && ($r['code']!==200 || !is_array($r['data']['urlNotificationMetadata']??null))) { $r['code']=502; $r['message']='Unexpected Google response; acceptance not confirmed.'; $r['retry']=false; } return $r; }
    public static function metadata(string $url): array { return self::google(self::INDEX_SCOPE,'GET','https://indexing.googleapis.com/v3/urlNotifications/metadata?'.http_build_query(['url'=>$url],'','&',PHP_QUERY_RFC3986)); }
    public static function inspect(string $url): array { $p=Config::get()['gsc_property']; if (!Config::property_ok($p)) { return self::error('Configure a verified Search Console property.'); } return self::google(self::GSC_SCOPE,'POST','https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',['inspectionUrl'=>$url,'siteUrl'=>$p]); }
    public static function error(string $message): array { return ['code'=>0,'data'=>null,'message'=>$message,'retry'=>false]; }
    public static function key_url(): string { $s=Config::get(); return home_url('/'.($s['key_path']?:$s['index_key'].'.txt')); }
    public static function indexnow(array $urls): array {
        $s=Config::get(); if (!$s['indexnow'] || !preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$s['index_key'])) { return self::error('IndexNow not configured.'); }
        $r=self::request('POST','https://api.indexnow.org/indexnow',['Content-Type'=>'application/json; charset=utf-8'],wp_json_encode(['host'=>wp_parse_url(home_url('/'),PHP_URL_HOST),'key'=>$s['index_key'],'keyLocation'=>self::key_url(),'urlList'=>array_values($urls)])); return $r;
    }
    public static function bing(array $urls): array {
        $k=Config::secret('bing_key'); if (!$k) { return self::error('Bing API key missing.'); }
        $r=self::request('POST','https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlBatch?'.http_build_query(['apikey'=>$k],'','&',PHP_QUERY_RFC3986),['Content-Type'=>'application/json; charset=utf-8'],wp_json_encode(['siteUrl'=>trailingslashit(home_url('/')),'urlList'=>array_values($urls)]));
        if ($r['code']>=200 && $r['code']<300 && ($r['code']!==200 || !is_array($r['data']) || !array_key_exists('d',$r['data']) || $r['data']['d']!==null)) { $r['code']=502; $r['message']='Unexpected Bing response; acceptance not confirmed.'; $r['retry']=false; } return $r;
    }
    public static function bing_quota(): array { $k=Config::secret('bing_key'); if (!$k) { return self::error('Bing API key missing.'); } return self::request('GET','https://ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota?'.http_build_query(['apikey'=>$k,'siteUrl'=>trailingslashit(home_url('/'))],'','&',PHP_QUERY_RFC3986)); }
}
