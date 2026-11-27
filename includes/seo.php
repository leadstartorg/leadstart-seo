<?php
namespace LST_SEO;
if (!defined('ABSPATH')) { exit; }
final class SEO {
    public static function boot(): void {
        add_action('init',[self::class,'enable_excerpts'],99);
        add_action('registered_post_type',[self::class,'registered_excerpt'],99,2);
        add_action('add_meta_boxes',[self::class,'boxes']); add_action('admin_notices',[self::class,'schema_notice']); add_action('save_post',[self::class,'save'],10,2);
        add_filter('document_title_parts',[self::class,'title']); add_filter('wp_robots',[self::class,'robots']); add_action('wp_head',[self::class,'head'],20);
    }
    public static function competitor(): bool { return (bool)apply_filters('lst_seo_external_seo_active',defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION') || defined('THE_SEO_FRAMEWORK_VERSION') || class_exists('The_SEO_Framework\\Load') || defined('SLIM_SEO_VER') || class_exists('SEOPress\\Core\\Kernel')); }
    public static function external_schema(\WP_Post $p): bool { return (bool)apply_filters('lst_seo_external_schema_active',self::competitor() || ($p->post_type==='product' && class_exists('WooCommerce')) || (str_starts_with($p->post_type,'tribe_') && class_exists('Tribe__Events__Main')) || ($p->post_type==='job_listing' && class_exists('WP_Job_Manager')), $p); }
    public static function public_post($p): bool { return $p instanceof \WP_Post && $p->post_status==='publish' && $p->post_password==='' && is_post_publicly_viewable($p) && (bool)get_option('blog_public') && !in_array($p->post_type,['attachment','revision'],true); }
    /** Recognize Jetpack content gates even if its SEO module is disabled. No content rendering. */
    public static function gated(\WP_Post $p): bool {
        $id=$p->ID;
        $newsletter=get_post_meta($id,'_jetpack_newsletter_access',true);
        return (bool)get_post_meta($id,'_jetpack_memberships_contains_paid_content',true)
            || (bool)get_post_meta($id,'_jetpack_memberships_contains_paywalled_content',true)
            || ($newsletter!=='' && $newsletter!=='everybody')
            || str_contains($p->post_content,'<!-- wp:jetpack/paywall')
            || str_contains($p->post_content,'<!-- wp:premium-content/');
    }
    public static function indexable($p): bool {
        if (!self::public_post($p) || self::gated($p)) { return false; }
        $id=$p->ID; if (get_post_meta($id,'_lst_seo_noindex',true)==='1' || (bool)get_post_meta($id,'jetpack_seo_noindex',true) || get_post_meta($id,'_yoast_wpseo_meta-robots-noindex',true)==='1' || get_post_meta($id,'_seopress_robots_index',true)==='yes' || in_array('noindex',(array)get_post_meta($id,'rank_math_robots',true),true)) { return false; }
        $canonical=(string)get_post_meta($id,'_lst_seo_canonical',true); if ($canonical && untrailingslashit($canonical)!==untrailingslashit(get_permalink($p))) { return false; }
        return (bool)apply_filters('lst_seo_indexable',true,$p);
    }
    public static function google_eligible($p): bool { return self::indexable($p) && get_post_meta($p->ID,'_lst_seo_google_eligible',true)==='1'; }
    public static function excerpt_selected(string $type, $settings): bool {
        return $settings['excerpt_types'] === null || in_array($type, (array) $settings['excerpt_types'], true);
    }
    public static function registered_excerpt(string $type, $object): void {
        if ($type !== 'attachment' && !empty($object->public) && is_post_type_viewable($object) && self::excerpt_selected($type, Config::get()) && !post_type_supports($type,'excerpt')) { add_post_type_support($type,'excerpt'); }
    }
    public static function enable_excerpts(): void {
        foreach (get_post_types(['public'=>true],'objects') as $type=>$object) { self::registered_excerpt($type,$object); }
    }
    public static function plain(string $value): string {
        // Never execute shortcodes, dynamic blocks, or content filters.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = wp_strip_all_tags(strip_shortcodes($value), true);
        return trim(preg_replace('/\s+/u',' ',wp_check_invalid_utf8($value)) ?? '');
    }
    public static function description(\WP_Post $p, bool $allow_body = true): string {
        $manual = self::plain((string)get_post_meta($p->ID,'_lst_seo_description',true));
        $value = $manual;
        if ($value === '' && Config::get()['use_excerpt']) {
            $excerpt = self::plain($p->post_excerpt);
            $value = $excerpt !== '' ? wp_html_excerpt($excerpt,160,'') : ($allow_body ? wp_html_excerpt(self::plain($p->post_content),160,'') : '');
        }
        // Keep GEO strictly saved-only, independent of metadata filters that may expose private text.
        if (!$allow_body) { return $value; }
        $filtered = apply_filters('lst_seo_description',$value,$p);
        return is_string($filtered) ? self::plain($filtered) : $value;
    }
    public static function description_source(\WP_Post $p): string {
        if (self::plain((string)get_post_meta($p->ID,'_lst_seo_description',true)) !== '') { return 'Custom'; }
        if (!Config::get()['use_excerpt']) { return 'None (excerpt fallback disabled)'; }
        if (self::plain($p->post_excerpt) !== '') { return 'Saved excerpt'; }
        return self::description($p) !== '' ? 'Generated from content' : 'None';
    }
    public static function title(array $parts): array { if (Config::get()['meta'] && !self::competitor() && is_singular() && ($p=get_queried_object()) instanceof \WP_Post && self::public_post($p)) { $title=get_post_meta($p->ID,'_lst_seo_title',true); if ($title) { return ['title'=>$title]; } } return $parts; }
    public static function robots(array $robots): array { if (Config::get()['meta'] && !self::competitor() && is_singular() && get_post_meta(get_queried_object_id(),'_lst_seo_noindex',true)==='1') { $robots['noindex']=true; unset($robots['index']); } return $robots; }
    public static function head(): void {
        if (!is_singular() || !(($p=get_queried_object()) instanceof \WP_Post) || !self::public_post($p) || self::gated($p)) { return; }
        $s=Config::get(); if ($s['meta'] && !self::competitor()) {
            $desc=self::description($p); $title=get_post_meta($p->ID,'_lst_seo_title',true)?:get_the_title($p); $canonical=get_post_meta($p->ID,'_lst_seo_canonical',true)?:wp_get_canonical_url($p);
            if ($desc) { echo '<meta name="description" content="'.esc_attr($desc).'">' . "\n"; }
            echo '<meta property="og:title" content="'.esc_attr($title).'">' . "\n";
            if ($desc) { echo '<meta property="og:description" content="'.esc_attr($desc).'">' . "\n"; }
            echo '<meta property="og:url" content="'.esc_url($canonical?:get_permalink($p)).'">' . "\n";
            $img=get_the_post_thumbnail_url($p,'full'); if ($img) { echo '<meta property="og:image" content="'.esc_url($img).'">' . "\n"; }
        }
        if ($s['schema'] && self::indexable($p) && !self::external_schema($p)) {
            $data = Schema::build($p->ID);
            if (!is_wp_error($data)) {
                self::print_schema($data);
            }
        }
    }
    public static function print_schema(array $data): void {
        // Script data must remain valid JSON, not HTML entity encoded. JSON_HEX_TAG
        // prevents injected closing script tags; use the native WP script renderer.
        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        if (false !== $json) {
            wp_print_inline_script_tag($json, ['type' => 'application/ld+json']);
        }
    }
    public static function canonical($url,$p) { if (Config::get()['meta'] && !self::competitor() && $p instanceof \WP_Post) { $v=Config::url((string)get_post_meta($p->ID,'_lst_seo_canonical',true)); if ($v) { return $v; } } return $url; }
    public static function boxes(): void { foreach (get_post_types(['public'=>true],'names') as $type) { if ($type!=='attachment') { add_meta_box('lst-seo','Leadstart SEO',[self::class,'box'],$type,'normal','default'); } } }
    public static function box(\WP_Post $p): void {
        wp_nonce_field('lst_seo_meta','lst_seo_meta_nonce'); $id=$p->ID;
        foreach (['title'=>'SEO title','description'=>'Meta description','keyword'=>'Focus keyword','canonical'=>'Canonical URL (same site)'] as $k=>$label) { echo '<p><label>'.esc_html($label).'<br><input class="widefat" name="'.esc_attr('lst_seo_'.$k).'" value="'.esc_attr((string)get_post_meta($id,'_lst_seo_'.$k,true)).'"></label></p>'; }
        foreach (['noindex'=>'Noindex (requires metadata output enabled)','geo_priority'=>__('Always prioritize in llms.txt (GEO; within document limits)','leadstart-seo'),'geo_exclude'=>'Exclude from llms.txt (GEO)','google_eligible'=>'I verified this URL contains eligible JobPosting or livestream VideoObject/BroadcastEvent structured data and follows Google Indexing API policy'] as $k=>$label) { echo '<p><label><input type="checkbox" name="'.esc_attr('lst_seo_'.$k).'" value="1" '.checked(get_post_meta($id,'_lst_seo_'.$k,true),'1',false).'> '.esc_html($label).'</label></p>'; }
        $cur=get_post_meta($id,'_lst_seo_schema_type',true); echo '<p><label>Schema type <select name="lst_seo_schema_type"><option value="">Use post type setting</option><option value="none" '.selected($cur,'none',false).'>None</option>'; foreach (Schema::types() as $t) { echo '<option '.selected($cur,$t,false).'>'.esc_html($t).'</option>'; } echo '</select></label></p><p><label>Additional factual JSON-LD fields (object, no @context)<textarea class="widefat" rows="5" name="lst_seo_schema_fields">'.esc_textarea((string)get_post_meta($id,'_lst_seo_schema_fields',true)).'</textarea></label></p>';
        $schema=Schema::build($id); if (Schema::type($id)) { echo '<p>'.esc_html(is_wp_error($schema)?$schema->get_error_message():'Schema basic validation passed; verify against search engine guidelines.').'</p>'; }
        echo '<p>Analysis of last saved content (not live editor):</p>'; self::render_analysis(self::analyze($p));
    }
    public static function save(int $id,\WP_Post $p): void {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !current_user_can('edit_post',$id) || !isset($_POST['lst_seo_meta_nonce']) || !is_string($_POST['lst_seo_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lst_seo_meta_nonce'])),'lst_seo_meta')) { return; }
        foreach (['title','description','keyword','canonical','schema_type','schema_fields'] as $k) {
            if (!isset($_POST['lst_seo_'.$k]) || !is_string($_POST['lst_seo_'.$k])) { continue; }
            if ($k==='schema_fields') {
                // JSON must retain its structure and permitted JobPosting HTML.
                // clean_json() decodes it, sanitizes individual values using native WordPress functions, then re-encodes it.
                $v=Schema::clean_json(wp_unslash($_POST['lst_seo_'.$k]),Schema::type($id)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Schema::clean_json() bounds and decodes the JSON, recursively validates and sanitizes properties with native WordPress functions, and re-encodes only cleaned values before saving.
                if (is_wp_error($v)) {
                    set_transient('lst_seo_schema_error_'.get_current_user_id(),$id,120);
                    continue;
                }
            }
            elseif ($k==='canonical') { $url=esc_url_raw(wp_unslash($_POST['lst_seo_'.$k]),['http','https']); $v=Config::url($url); if (!empty($_POST['lst_seo_'.$k]) && !$v) { continue; } }
            elseif ($k==='schema_type') { $candidate=sanitize_text_field(wp_unslash($_POST['lst_seo_'.$k])); if (!in_array($candidate,array_merge(['','none'],Schema::types()),true)) { continue; } $v=$candidate; }
            else { $v=substr(sanitize_text_field(wp_unslash($_POST['lst_seo_'.$k])),0,1000); }
            update_post_meta($id,'_lst_seo_'.$k,wp_slash($v));
        }
        foreach (['noindex','google_eligible','geo_exclude','geo_priority'] as $k) { update_post_meta($id,'_lst_seo_'.$k,isset($_POST['lst_seo_'.$k]) && is_string($_POST['lst_seo_'.$k]) && sanitize_text_field(wp_unslash($_POST['lst_seo_'.$k]))==='1'?'1':'0'); }
    }
    public static function schema_notice(): void {
        $screen=get_current_screen(); if (!$screen || $screen->base!=='post') { return; }
        $key='lst_seo_schema_error_'.get_current_user_id(); $id=absint(get_transient($key));
        if (!$id || !current_user_can('edit_post',$id)) { return; }
        delete_transient($key);
        echo '<div class="notice notice-error"><p>'.esc_html__('Leadstart SEO: Invalid additional Schema fields were not saved. The previous values were kept. Check the JSON, property names, URLs and dates.','leadstart-seo').'</p></div>';
    }
    public static function analyze(\WP_Post $p): array {
        $title=(string)get_post_meta($p->ID,'_lst_seo_title',true)?:get_the_title($p); $d=self::description($p); $content=wp_strip_all_tags(strip_shortcodes($p->post_content)); $words=preg_split('/\s+/u',trim($content),-1,PREG_SPLIT_NO_EMPTY)?:[]; $kw=(string)get_post_meta($p->ID,'_lst_seo_keyword',true);
        $len=static fn($v)=>function_exists('mb_strlen')?mb_strlen($v):strlen($v);
        $links=0;$internal=0;$external=0;$images=0;$missing=0;$headings=[];
        if (class_exists('DOMDocument')) { $dom=new \DOMDocument(); $old=libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">'.$p->post_content,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); libxml_clear_errors(); libxml_use_internal_errors($old);
            foreach ($dom->getElementsByTagName('a') as $a) { $href=$a->getAttribute('href'); if (!$href || str_starts_with($href,'#')) { continue; } $h=wp_parse_url($href,PHP_URL_HOST); if (!$h && !preg_match('/^[a-z][a-z0-9+.-]*:/i',$href) || $h && strtolower($h)===strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))) { $internal++; } elseif ($h) { $external++; } $links++; }
            foreach ($dom->getElementsByTagName('img') as $img) { $images++; if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt'))==='') { $missing++; } }
            for ($i=1;$i<=6;$i++) { $headings[$i]=$dom->getElementsByTagName('h'.$i)->length; }
        }
        $canonical=(string)get_post_meta($p->ID,'_lst_seo_canonical',true); $custom_title=(string)get_post_meta($p->ID,'_lst_seo_title',true); $custom_desc=(string)get_post_meta($p->ID,'_lst_seo_description',true);
        $dup=static function ($key,$value) use ($p) { if ($value==='') { return null; } return !array_diff(array_map('intval',get_posts(['post_type'=>get_post_types(['public'=>true]),'post_status'=>'publish','numberposts'=>2,'fields'=>'ids','meta_key'=>$key,'meta_value'=>$value,'no_found_rows'=>true,'suppress_filters'=>false])),[(int)$p->ID]); }; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact duplicate check of plugin-owned SEO metadata on published posts. ID-only, at most two results, no found rows; meta-value lookup can still be costly on large sites.
        $checks=['Title length (30–60 characters)'=>$len($title)>=30 && $len($title)<=60,'Description length (70–160 characters)'=>$len($d)>=70 && $len($d)<=160,'Focus keyword in title'=>$kw!==''?stripos($title,$kw)!==false:null,'Focus keyword in content'=>$kw!==''?stripos($content,$kw)!==false:null,'Public and locally indexable'=>self::indexable($p),'Content has text'=>count($words)>0,'At least one internal link'=>$headings!==[]?$internal>0:null,'Images with missing/empty alt reviewed'=>$headings!==[]?$missing===0:null,'No extra H1 in post body'=>$headings!==[]?$headings[1]===0:null,'Canonical points to this URL'=>$canonical?untrailingslashit($canonical)===untrailingslashit(get_permalink($p)):null,'Custom SEO title unique among Leadstart metadata'=>$dup('_lst_seo_title',$custom_title),'Custom description unique among Leadstart metadata'=>$dup('_lst_seo_description',$custom_desc)];
        $sentences=max(1,count(preg_split('/[.!?]+/u',$content,-1,PREG_SPLIT_NO_EMPTY)?:[])); $app=array_filter($checks,static fn($v)=>$v!==null);
        return ['score'=>count($app)?round(100*count(array_filter($app))/count($app)):0,'checks'=>$checks,'details'=>['Words'=>count($words),'Words per sentence (estimate)'=>round(count($words)/$sentences,1),'Internal links'=>$internal,'External links'=>$external,'Images'=>$images,'Missing/empty alt'=>$missing,'Headings'=>$headings,'Canonical'=>wp_get_canonical_url($p)?:'Unavailable','Schema type'=>Schema::type($p->ID)?:'None','Robots (local)' =>get_post_meta($p->ID,'_lst_seo_noindex',true)==='1'?'noindex':'No local noindex flag','Native sitemap enabled (not URL inclusion)' => function_exists('wp_sitemaps_get_server') && wp_sitemaps_get_server()->sitemaps_enabled(),'Permalink'=>get_permalink($p),'Open Graph output (configured, not rendered-page test)'=>Config::get()['meta'] && !self::competitor(),'Description source'=>self::description_source($p)]];
    }
    public static function render_analysis(array $r): void { echo '<p><strong>Local heuristic score: '.esc_html((string)$r['score']).'/100</strong> (not a Google ranking score).</p><ul>'; foreach ($r['checks'] as $k=>$v) { echo '<li>'.esc_html($k.': '.($v===null?'Not assessed':($v?'Pass':'Review'))).'</li>'; } echo '</ul><details><summary>Details</summary><pre>'.esc_html(wp_json_encode($r['details'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>'; }
}
add_filter('get_canonical_url',[SEO::class,'canonical'],10,2);
