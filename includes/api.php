<?php
namespace LST_SEO;
if (!defined('ABSPATH')) { exit; }
final class API {
    const INDEX_SCOPE='https://www.googleapis.com/auth/indexing';
    const GSC_SCOPE='https://www.googleapis.com/auth/webmasters.readonly';
    const GA_SCOPE='https://www.googleapis.com/auth/analytics.readonly';
    public static function b64(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
    public static function status_hint(int $code): string {
        return match($code) { 400=>'Invalid request or configuration.',401=>'Authentication rejected.',403=>'Permission denied or service access restricted.',404=>'Resource or endpoint not found.',408=>'Request timed out.',429=>'Rate limit or quota exceeded.',402=>'Billing or payment required.',default=>$code>=500?'Remote service unavailable or failed.':'' };
    }
    public static function transport_hint(\WP_Error $error): string {
        $message=strtolower($error->get_error_message());
        if (preg_match('/timed? out|timeout|curl error 28/',$message)) { return 'Request timed out.'; }
        if (preg_match('/ssl|tls|certificate|curl error (35|60)/',$message)) { return 'TLS or certificate verification failed.'; }
        if (preg_match('/could not resolve|dns|curl error 6/',$message)) { return 'DNS resolution failed.'; }
        if (preg_match('/blocked|not allowed|not a valid url/',$message)) { return 'WordPress HTTP request blocked or URL rejected.'; }
        if (preg_match('/connection refused|failed to connect|curl error 7/',$message)) { return 'Connection to remote host failed.'; }
        return 'WordPress HTTP transport failed.';
    }
    private static function response_hint(int $code,$data): string {
        $hint=self::status_hint($code);
        if ($code<400 || !is_array($data)) { return $hint; }
        $error=$data['error']??null; $reason=is_array($error)?($error['status']??''):$error;
        $known=['invalid_grant'=>'OAuth assertion rejected; check service account key, account status and server clock.','invalid_client'=>'OAuth client authentication rejected.','invalid_scope'=>'Requested OAuth scope is invalid.','unauthorized_client'=>'OAuth client is not authorized.','access_denied'=>'Service denied access.','UNAUTHENTICATED'=>'Authentication rejected.','PERMISSION_DENIED'=>'Check property access and enabled APIs.','RESOURCE_EXHAUSTED'=>'Quota exhausted.','INVALID_ARGUMENT'=>'Invalid request arguments.','NOT_FOUND'=>'Requested resource not found.','FAILED_PRECONDITION'=>'Service prerequisites not satisfied.','SERVICE_DISABLED'=>'Enable the requested API in Google Cloud.','API_KEY_INVALID'=>'API key rejected.'];
        if (is_string($reason) && isset($known[$reason])) { return $reason.': '.$known[$reason]; }
        foreach (is_array($error) && is_array($error['details']??null)?$error['details']:[] as $detail) { $reason=is_array($detail)?($detail['reason']??''):''; if (is_string($reason) && isset($known[$reason])) { return $reason.': '.$known[$reason]; } }
        return $hint;
    }
    public static function request(string $method,string $url,array $headers=[], $body=null): array {
        $args=['method'=>$method,'headers'=>$headers,'timeout'=>12,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576];
        if ($body!==null) { $args['body']=$body; }
        $response=wp_safe_remote_request($url,$args);
        if (is_wp_error($response)) { return ['code'=>0,'data'=>null,'message'=>'Transport failure; delivery unconfirmed. '.self::transport_hint($response),'retry'=>true,'ambiguous'=>true]; }
        $code=(int)wp_remote_retrieve_response_code($response); $raw=wp_remote_retrieve_body($response); $data=json_decode($raw,true);
        // Do not return raw upstream errors: they can contain credentials or private data.
        $retry_after=wp_remote_retrieve_header($response,'retry-after'); $delay=is_numeric($retry_after)?(int)$retry_after:0;
        if (!$delay && is_string($retry_after) && $retry_after!=='') { $when=strtotime($retry_after); $delay=$when?max(0,$when-time()):0; }
        return ['code'=>$code,'data'=>$data,'message'=>'HTTP '.$code.($code>=400?' '.self::response_hint($code,$data):''),'retry'=>$code===429 || $code===408 || $code>=500,'delay'=>min(86400,max(0,$delay))];
    }
    public static function token(string $scope) {
        $raw=Config::secret('google_json'); if ($raw==='') { return new \WP_Error('credential','Google service account JSON is missing or cannot be decrypted. Save credentials again if WordPress salts changed.'); } $v=Config::google_json($raw); if (is_wp_error($v)) { return $v; }
        
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
    public static function metadata(string $url): array { return self::google(self::INDEX_SCOPE,'GET',add_query_arg(array_map('rawurlencode',['url'=>$url]),'https://indexing.googleapis.com/v3/urlNotifications/metadata')); }
    public static function inspect(string $url): array { $p=Config::get()['gsc_property']; if (!Config::property_ok($p)) { return self::error('Configure a verified Search Console property.'); } return self::google(self::GSC_SCOPE,'POST','https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',['inspectionUrl'=>$url,'siteUrl'=>$p]); }
    public static function error(string $message): array { return ['code'=>0,'data'=>null,'message'=>$message,'retry'=>false]; }
    public static function key_url(): string { $s=Config::get(); return home_url('/'.($s['key_path']?:$s['index_key'].'.txt')); }
    public static function test_indexnow() {
        $s=Config::get();
        if (!$s['indexnow']) { return new \WP_Error('indexnow_disabled','IndexNow is disabled. Enable and save it first.'); }
        if (!preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$s['index_key'])) { return new \WP_Error('indexnow_key','IndexNow key is missing or invalid. Save settings to generate a key.'); }
        $response=wp_safe_remote_get(self::key_url(),['timeout'=>10,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>256]);
        if (is_wp_error($response)) { return new \WP_Error('indexnow_transport','IndexNow public key test: '.self::transport_hint($response)); }
        $code=(int)wp_remote_retrieve_response_code($response);
        if ($code!==200) { return new \WP_Error('indexnow_http','IndexNow public key test: HTTP '.$code.'. '.($code>=300 && $code<400?'Key URL redirected; configure the final public URL without redirects.':self::status_hint($code))); }
        if (trim(wp_remote_retrieve_body($response))!==$s['index_key']) { return new \WP_Error('indexnow_mismatch','IndexNow public key test: HTTP 200, but the file content does not match the saved key. Check the .txt route or static file.'); }
        return true;
    }
    public static function indexnow(array $urls): array {
        $s=Config::get(); if (!$s['indexnow'] || !preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$s['index_key'])) { return self::error('IndexNow not configured.'); }
        $r=self::request('POST','https://api.indexnow.org/indexnow',['Content-Type'=>'application/json; charset=utf-8'],wp_json_encode(['host'=>wp_parse_url(home_url('/'),PHP_URL_HOST),'key'=>$s['index_key'],'keyLocation'=>self::key_url(),'urlList'=>array_values($urls)])); return $r;
    }
    public static function bing(array $urls): array {
        $k=Config::secret('bing_key'); if (!$k) { return self::error('Bing API key missing.'); }
        $r=self::request('POST',add_query_arg(array_map('rawurlencode',['apikey'=>$k]),'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlBatch'),['Content-Type'=>'application/json; charset=utf-8'],wp_json_encode(['siteUrl'=>trailingslashit(home_url('/')),'urlList'=>array_values($urls)]));
        if ($r['code']>=200 && $r['code']<300 && ($r['code']!==200 || !is_array($r['data']) || !array_key_exists('d',$r['data']) || $r['data']['d']!==null)) { $r['code']=502; $r['message']='Unexpected Bing response; acceptance not confirmed.'; $r['retry']=false; } return $r;
    }
    public static function bing_quota(): array { $k=Config::secret('bing_key'); if (!$k) { return self::error('Bing API key missing.'); } return self::request('GET',add_query_arg(array_map('rawurlencode',['apikey'=>$k,'siteUrl'=>trailingslashit(home_url('/'))]),'https://ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota')); }
}
