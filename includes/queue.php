<?php
namespace LST_SEO;
if (!defined('ABSPATH')) { exit; }
final class Queue {
    const TYPE='lst_seo_job';
    const LOCK='lst_seo_worker_lock';
    const LOCK_TIME='lst_seo_worker_lock_time';
    public static function boot(): void {
        add_action('init',[self::class,'register']);
        add_action('init',[self::class,'serve_key'],1);
        add_action('wp_after_insert_post',[self::class,'changed'],20,4);
        add_action('before_delete_post',[self::class,'deleted'],10,2);
        add_action('wp_trash_post',[self::class,'deleted'],10,1);
        add_action('lst_seo_tick',[self::class,'work']); add_action('lst_seo_run',[self::class,'work']);
    }
    public static function register(): void { register_post_type(self::TYPE,['public'=>false,'show_ui'=>false,'show_in_rest'=>false,'can_export'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'rewrite'=>false,'query_var'=>false,'supports'=>[],'map_meta_cap'=>true,'capabilities'=>array_fill_keys(['edit_post','read_post','delete_post','edit_posts','edit_others_posts','delete_posts','publish_posts','read_private_posts','create_posts'],'manage_options')]); }
    public static function serve_key(): void {
        $s=Config::get(); if (!$s['indexnow'] || $s['key_path'] || !preg_match('/^[A-Za-z0-9-]{8,128}$/D',$s['index_key'])) { return; }
        $uri=isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])?esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])):'';
        if (wp_parse_url($uri,PHP_URL_PATH)!==wp_parse_url(API::key_url(),PHP_URL_PATH)) { return; }
        $method=isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])?sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])):'';
        if (!in_array($method,['GET','HEAD'],true)) { status_header(405); exit; }
        status_header(200); header('Content-Type: text/plain; charset=utf-8'); header('X-Content-Type-Options: nosniff'); nocache_headers(); if ($method==='GET') { echo esc_html($s['index_key']); } exit;
    }
    public static function enqueue(string $provider,string $action,string $url,int $post=0,bool $attest=false,bool $manual=false) {
        $url=Config::url($url); if (!$url || !in_array($provider,['google','indexnow','bing'],true) || !in_array($action,['update','delete','metadata','inspect'],true) || ($provider!=='google' && in_array($action,['metadata','inspect'],true))) { return new \WP_Error('input','Invalid URL, provider or action.'); }
        if ($action==='delete' && $provider==='google' && !$attest) { return new \WP_Error('eligibility','Google removal requires eligibility and removal confirmation.'); }
        $p=$post?get_post($post):(($resolved=url_to_postid($url))?get_post($resolved):null);
        if ($action==='update' && $provider==='google' && (!$p || !SEO::google_eligible($p))) { return new \WP_Error('eligibility','Google updates require a public WordPress post marked eligible in its SEO panel.'); }
        if ($action==='update' && $p && !SEO::indexable($p)) { return new \WP_Error('private','Post is not locally indexable.'); }
        if ($action==='update' && !$p && $provider!=='google' && !$attest) { return new \WP_Error('eligibility','Confirm the URL is public and indexable.'); }
        if ($action==='delete' && $manual && !$attest) { return new \WP_Error('eligibility','Confirm the URL has been removed or is noindex.'); }
        $finger=hash('sha256',$provider.'|'.$action.'|'.$url); if (!$manual && get_transient('lst_seo_dedupe_'.$finger)) { return 0; }
        $job=['provider'=>$provider,'action'=>$action,'url'=>$url,'post'=>$p?$p->ID:$post,'attest'=>$attest,'manual'=>$manual,'attempts'=>0,'due'=>time(),'status'=>'pending','result'=>'Queued','code'=>0,'created'=>time(),'finished'=>0];
        $id=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'private','post_title'=>'Indexing request','post_content'=>'','meta_input'=>['_lst_seo_job'=>$job,'_lst_seo_status'=>'pending','_lst_seo_due'=>time()]],true);
        if (!is_wp_error($id) && $id) { if (!$manual) { set_transient('lst_seo_dedupe_'.$finger,1,60); } self::schedule(); }
        return $id ?: new \WP_Error('queue','Could not create queue item.');
    }
    public static function schedule(int $seconds=5): void { if (!wp_next_scheduled('lst_seo_run')) { wp_schedule_single_event(time()+$seconds,'lst_seo_run'); } }
    public static function changed(int $id,\WP_Post $p,bool $update,?\WP_Post $before): void {
        if ($p->post_type===self::TYPE || wp_is_post_revision($id) || wp_is_post_autosave($id)) { return; }
        $s=Config::get();
        if ($before && $before->post_status==='publish' && $before->post_password==='' && !SEO::public_post($p) && $p->post_status!=='trash') { self::removed($before); return; }
        if (!SEO::indexable($p)) { return; }
        if (in_array($p->post_type,$s['google_auto'],true) && SEO::google_eligible($p)) { self::enqueue('google','update',get_permalink($p),$id); }
        if ($s['indexnow'] && in_array($p->post_type,$s['indexnow_auto'],true)) { self::enqueue('indexnow','update',get_permalink($p),$id); }
    }
    public static function deleted(int $id,$p=null): void { $p=$p instanceof \WP_Post?$p:get_post($id); if ($p && $p->post_type!==self::TYPE && $p->post_status==='publish') { self::removed($p); } }
    private static function removed(\WP_Post $p): void {
        if ($p->post_password!=='' || !get_option('blog_public') || !is_post_type_viewable($p->post_type)) { return; }
        $s=Config::get(); $url=get_permalink($p); if (!$url) { return; }
        if (in_array($p->post_type,$s['google_auto'],true) && get_post_meta($p->ID,'_lst_seo_google_eligible',true)==='1') { self::enqueue('google','delete',$url,$p->ID,true); }
        if ($s['indexnow'] && in_array($p->post_type,$s['indexnow_auto'],true)) { self::enqueue('indexnow','delete',$url,$p->ID,true); }
    }
    public static function query(array $args=[]): array {
        $query=array_replace(['numberposts'=>50,'orderby'=>'ID','order'=>'DESC','no_found_rows'=>true],$args,['post_type'=>self::TYPE,'post_status'=>'private','suppress_filters'=>false]);
        // A third-party query filter must never make unrelated posts eligible for queue processing or cleanup.
        return array_values(array_filter(get_posts($query),static fn($p)=>$p instanceof \WP_Post && $p->post_type===self::TYPE && $p->post_status==='private'));
    }
    public static function get(int $id): array { return get_post_type($id)===self::TYPE?(array)get_post_meta($id,'_lst_seo_job',true):[]; }
    private static function save(int $id,array $j): void { update_post_meta($id,'_lst_seo_job',$j); update_post_meta($id,'_lst_seo_status',$j['status']); update_post_meta($id,'_lst_seo_due',$j['due']); update_post_meta($id,'_lst_seo_finished',$j['finished']); }
    public static function retry(int $id): bool { $j=self::get($id); if (!in_array($j['status']??'',['failed','unknown'],true)) { return false; } return !is_wp_error(self::enqueue($j['provider'],$j['action'],$j['url'],(int)$j['post'],(bool)$j['attest'],true)); }
    public static function quota_day(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d'); }
    public static function quota(): array { $q=(array)get_option('lst_seo_quota',[]); return ($q['day']??'')===self::quota_day()?$q:['day'=>self::quota_day(),'used'=>0]; }
    public static function fallback_allowed(int $code): bool { return in_array($code,[403,404,405,410,501],true); }
    private static function dispatch(array $j): array {
        $url=Config::url($j['url']); if (!$url) { return API::error('URL no longer valid for this site.'); }
        $resolved=url_to_postid($url); $p=$resolved?get_post($resolved):($j['post']?get_post($j['post']):null); $provider=$j['provider']; $a=$j['action']; $s=Config::get();
        if (empty($j['manual']) && $a==='update' && (!$p || !in_array($p->post_type,$s[$provider==='google'?'google_auto':'indexnow_auto'],true))) { return API::error('Automatic submission is no longer enabled for this post.'); }
        if ($a==='update' && !$p && !empty($j['post'])) { return API::error('Original post is no longer available.'); }
        if ($a==='update' && $p && (!SEO::indexable($p) || Config::url(get_permalink($p))!==$url)) { return API::error('Post is no longer indexable at this URL.'); }
        if ($a==='update' && $provider==='google' && (!$p || !SEO::google_eligible($p))) { return API::error('Google eligibility no longer confirmed.'); }
        if ($a==='delete' && $p && SEO::indexable($p) && Config::url(get_permalink($p))===$url) { return API::error('Removal blocked: URL is still locally indexable.'); }
        if ($provider==='google') {
            if (in_array($a,['metadata','inspect'],true)) { $r=$a==='metadata'?API::metadata($url):API::inspect($url); if ($r['code']>=200 && $r['code']<300 && ($r['code']!==200 || !is_array($r['data']) || ($a==='inspect' && !is_array($r['data']['inspectionResult']??null)))) { return API::error('Unexpected Google response; result not confirmed.'); } return $r; }
            if ($a==='delete' && !self::removed_url($url)) { return API::error('Removal not verified: URL must return 404/410 or contain a noindex robots meta tag.'); }
            $q=self::quota(); if ($q['used'] >= $s['google_limit']) { return ['code'=>429,'message'=>'Local Google daily budget reached.','retry'=>true,'delay'=>max(60,(new \DateTimeImmutable('tomorrow',new \DateTimeZone('America/Los_Angeles')))->getTimestamp()-time()),'data'=>null,'defer'=>true]; }
            $q['used']++; update_option('lst_seo_quota',$q,false); return API::publish($url,$a);
        }
        if ($provider==='bing') { if (!$s['bing_fallback']) { return API::error('Legacy Bing API not enabled.'); } return API::bing([$url]); }
        $r=API::indexnow([$url]);
        if ($s['bing_fallback'] && self::fallback_allowed($r['code']) && Config::secret('bing_key')) { $b=API::bing([$url]); $b['message']='IndexNow HTTP '.$r['code'].'; Bing fallback: '.$b['message']; $b['fallback']=true; if ($b['retry']) { $b['retry_provider']='bing'; } return $b; } return $r;
    }
    private static function removed_url(string $url): bool {
        $r=wp_safe_remote_get($url,['timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>262144]); if (is_wp_error($r)) { return false; } $code=(int)wp_remote_retrieve_response_code($r); if (in_array($code,[404,410],true)) { return true; } if ($code!==200 || !class_exists('DOMDocument')) { return false; }
        $dom=new \DOMDocument(); $old=libxml_use_internal_errors(true); try { $dom->loadHTML(wp_remote_retrieve_body($r),LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); foreach ($dom->getElementsByTagName('head') as $head) { foreach ($head->getElementsByTagName('meta') as $tag) { if (in_array(strtolower($tag->getAttribute('name')),['robots','googlebot'],true) && preg_match('/(?:^|[,\s])(?:noindex|none)(?:$|[,\s])/i',$tag->getAttribute('content'))) { return true; } } } } finally { libxml_clear_errors(); libxml_use_internal_errors($old); } return false;
    }
    public static function result_status(array $r): string { if ($r['code']===202) { return 'received'; } return $r['code']>=200 && $r['code']<300?'accepted':'failed'; }
    public static function work(): void {
        $now=time();
        // Fixed value prevents concurrent add_option upserts from replacing the lease. Fail closed after interruption.
        if (!add_option(self::LOCK,'locked','',false)) { return; }
        update_option(self::LOCK_TIME,$now,false);
        try {
            $jobs=self::query(['numberposts'=>2,'orderby'=>'ID','order'=>'ASC','meta_query'=>[['key'=>'_lst_seo_status','value'=>'pending'],['key'=>'_lst_seo_due','value'=>$now,'compare'=>'<=','type'=>'NUMERIC']]]); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Find due pending records in the plugin-private queue post type; process at most two per worker run under the worker lock. Meta joins can still incur a cost.
            foreach ($jobs as $p) {
                $j=self::get($p->ID); if (($j['status']??'')!=='pending') { continue; }
                // Reserve before I/O: a killed worker leaves a visible unknown state, not an invisible duplicate.
                $j['status']='unknown'; $j['result']='Delivery unconfirmed (worker interrupted if this persists).'; $j['attempts']++; self::save($p->ID,$j);
                try { $r=self::dispatch($j); } catch (\Throwable $e) { $r=API::error('Unexpected processing error; consult server logs.'); }
                $j['code']=$r['code']; $j['result']=$r['message']; $j['fallback']=!empty($r['fallback']) || !empty($j['fallback']); $j['status']=self::result_status($r);
                if (in_array($j['action'],['update','delete'],true) && $r['code']===0 && !empty($r['ambiguous'])) { $j['status']='unknown'; }
                if ($j['status']==='failed' && !empty($r['retry']) && ($j['attempts']<5 || !empty($r['defer']))) { $j['status']='pending'; if (!empty($r['defer'])) { $j['attempts']--; } $j['due']=time()+max((int)($r['delay']??0),min(3600,60*2**$j['attempts'])); if (!empty($r['retry_provider'])) { $j['provider']=$r['retry_provider']; $j['manual']=true; } }
                if ($j['status']!=='pending') { $j['finished']=time(); if (in_array($j['action'],['metadata','inspect'],true) && $j['status']==='accepted') { $j['details']=self::details($r['data']); } }
                self::save($p->ID,$j);
            }
            // Bounded retention for terminal records. Pending and unknown requests are never pruned automatically.
            foreach (self::query(['numberposts'=>20,'meta_query'=>[['key'=>'_lst_seo_status','value'=>['accepted','received','failed'],'compare'=>'IN'],['key'=>'_lst_seo_finished','value'=>time()-30*DAY_IN_SECONDS,'compare'=>'<','type'=>'NUMERIC']]]) as $p) { wp_delete_post($p->ID,true); } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Retention cleanup is limited to terminal records of the plugin-private queue post type, at most 20 per worker run; pending and unknown records are excluded.
            if (count($jobs)===2) { self::schedule(15); } elseif (self::query(['numberposts'=>1,'meta_key'=>'_lst_seo_status','meta_value'=>'pending'])) { self::schedule(300); } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Check whether the plugin-private queue has any pending record to schedule another worker; at most one result, no found rows.
        } finally { delete_option(self::LOCK_TIME); delete_option(self::LOCK); }
    }
    public static function unlock(): bool { if (get_option(self::LOCK)!=='locked' || (int)get_option(self::LOCK_TIME,0)>time()-900) { return false; } delete_option(self::LOCK_TIME); delete_option(self::LOCK); return true; }
    private static function details($data): array { if (!is_array($data)) { return []; } $d=$data['inspectionResult']['indexStatusResult']??$data; return array_intersect_key($d,array_flip(['verdict','coverageState','robotsTxtState','indexingState','lastCrawlTime','pageFetchState','googleCanonical','userCanonical','url','latestUpdate','latestRemove','latest_update','latest_remove'])); }
}
