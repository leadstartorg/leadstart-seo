<?php
/** Public, curated llms.txt index. No filesystem writes or external requests. */
namespace Leadstart\SEO;
if (!defined('ABSPATH')) { exit; }
final class GEO {
    const MAX_TOTAL = 1000;
    const CACHE = 'lst_seo_geo_candidates';
    const CACHE_TTL = 900;
    const MAX_SCAN = 4000;
    const MAX_PRIORITY = 200;
    public static function boot(): void {
        add_action('template_redirect', [self::class, 'serve'], 0);
        add_filter('robots_txt', [self::class, 'robots'], 100, 2);
        add_action('clean_post_cache', [self::class, 'post_changed'], 10, 2);
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'meta_changed'], 10, 3);
        }
        foreach (['wp_update_nav_menu','wp_delete_nav_menu','switch_theme','customize_save_after','clean_term_cache','activated_plugin','deactivated_plugin'] as $hook) {
            add_action($hook, [self::class, 'clear_cache'], 10, 0);
        }
        add_action('updated_option', [self::class, 'option_changed'], 10, 1);
        add_action('added_option', [self::class, 'option_changed'], 10, 1);
        add_action('deleted_option', [self::class, 'option_changed'], 10, 1);
    }
    public static function url(): string { return home_url('/llms.txt'); }
    public static function conflict(): bool {
        return (bool) apply_filters('lst_seo_external_llms_active', defined('WEBSITE_LLMS_TXT_VERSION') || ((defined('JETPACK__VERSION') || class_exists('Automattic\\Jetpack\\SEO\\Llms_Txt')) && (bool)get_option('jetpack_seo_llms_txt_enabled',false)));
    }
    public static function restricted_site(): bool {
        // Fail closed on recognized access-control plugins. An integrator may lift
        // this only after supplying an anonymous-access policy via the filters.
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) { $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', []))); }
        $restricted = ['members','restrict-content','restrict-content-pro','ultimate-member','wp-members','woocommerce-memberships','paid-memberships-pro','restrict-user-access','content-control','memberpress','lifterlms','sfwd-lms','tutor','password-protected','wp-maintenance-mode','coming-soon'];
        $found = false;
        foreach ($active as $file) { if (is_string($file) && in_array(strtok($file,'/'),$restricted,true)) { $found = true; break; } }
        return (bool) apply_filters('lst_seo_geo_restricted_site', $found);
    }
    public static function types(): array {
        return array_filter(get_post_types(['public'=>true], 'objects'), static fn($type) => $type->name !== 'attachment' && is_post_type_viewable($type));
    }
    public static function selected(array $settings): array {
        return array_intersect_key(self::types(), array_flip(array_filter((array) $settings['geo_types'], 'is_string')));
    }
    public static function eligible($post): bool {
        if (!SEO::indexable($post) || get_post_meta($post->ID, '_lst_seo_geo_exclude', true) === '1') { return false; }
        // Sites with membership/paywall or additional access rules should use this filter
        // to exclude content that is not freely available to an anonymous reader.
        return (bool) apply_filters('lst_seo_geo_include_post', true, $post);
    }
    public static function markdown(string $value, int $limit): string {
        $value = SEO::plain($value);
        $value = preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $value) ?? '';
        $value = wp_html_excerpt($value, $limit, '');
        // Escape Markdown punctuation, not the entire plain-text response.
        return strtr($value, ['\\'=>'\\\\','`'=>'\\`','*'=>'\\*','_'=>'\\_','{'=>'\\{','}'=>'\\}','['=>'\\[',']'=>'\\]','<'=>'\\<','>'=>'\\>','#'=>'\\#','!'=>'\\!','|'=>'\\|']);
    }
    public static function link(string $url): string {
        $url = Config::url($url);
        return $url === '' ? '' : str_replace(['(',')','<','>'], ['%28','%29','%3C','%3E'], $url);
    }
    /** Store only candidate IDs, never titles, descriptions, URLs or rendered documents. */
    public static function clear_cache(): void {
        delete_transient(self::CACHE);
        do_action('lst_seo_geo_cache_cleared');
    }
    public static function post_changed($id, $post): void {
        if ($post instanceof \WP_Post && (in_array($post->post_type,['wp_navigation','nav_menu_item','wp_template','wp_template_part','wp_block'],true) || isset(self::types()[$post->post_type]))) { self::clear_cache(); }
    }
    public static function meta_changed($meta_id, $post_id, $key): void {
        if (str_starts_with((string)$key, '_lst_seo_') || str_starts_with((string)$key, '_menu_item_') || preg_match('/robots|noindex|canonical|jetpack_memberships|jetpack_newsletter_access/i', (string)$key)) {
            $post = get_post((int)$post_id);
            if ($post) { self::post_changed($post_id, $post); }
        }
    }
    public static function option_changed($name): void {
        if (in_array($name, [Config::OPTION,'blog_public','blogname','blogdescription','home','siteurl','permalink_structure','active_plugins','page_on_front','page_for_posts','show_on_front','WPLANG','jetpack_seo_llms_txt_enabled'], true) || str_starts_with((string)$name, 'theme_mods_')) { self::clear_cache(); }
    }
    /** Optional disallow only; never override existing named groups or add permissive rules. */
    public static function bots(): array {
        return [
            'OAI-SearchBot'=>__('OpenAI search','leadstart-seo'),
            'Claude-SearchBot'=>__('Anthropic search','leadstart-seo'),
            'PerplexityBot'=>__('Perplexity search','leadstart-seo'),
            'GPTBot'=>__('OpenAI training','leadstart-seo'),
            'ClaudeBot'=>__('Anthropic training','leadstart-seo'),
            'Google-Extended'=>__('Google AI uses outside Search (training and grounding)','leadstart-seo'),
        ];
    }
    public static function robots(string $output, $public): string {
        $s = Config::get();
        // robots.txt applies at the host root. Do not change policies for a subdirectory site.
        if (!$public || !get_option('blog_public') || self::restricted_site() || trim((string)wp_parse_url(home_url('/'), PHP_URL_PATH), '/') !== '') { return $output; }
        $selected = array_intersect(array_keys(self::bots()), (array)$s['geo_bots']);
        if (!$selected) { return $output; }
        // Skip bots already governed by a named group (including a possible prefix match).
        preg_match_all('/^\s*user-agent\s*:\s*([^\s#]+)\s*(?:#.*)?$/im', $output, $matches);
        foreach ($selected as $bot) {
            foreach ($matches[1] as $existing) {
                if ($existing !== '*' && (stripos($bot,$existing) === 0 || stripos($existing,$bot) === 0)) { continue 2; }
            }
            $output = rtrim($output)."\n\nUser-agent: ".$bot."\nDisallow: /\n";
        }
        return $output;
    }
    private static function ids(array $values, int $limit): array {
        $ids = [];
        foreach (array_slice($values, 0, $limit) as $value) {
            if ($value instanceof \WP_Post) { $value = $value->ID; }
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int)$value;
                if ($id > 0) { $ids[$id] = $id; }
            }
        }
        return array_values($ids);
    }
    /** Only linked post IDs, not arbitrary custom menu URLs or menu labels. */
    public static function navigation_ids(): array {
        $ids = [];
        $locations = get_nav_menu_locations();
        foreach (array_slice(array_unique(array_filter((array)$locations)),0,10) as $menu_id) {
            $items = wp_get_nav_menu_items((int)$menu_id, ['nopaging'=>false,'numberposts'=>200,'posts_per_page'=>200]);
            foreach (array_slice((array)$items,0,200) as $item) {
                if (($item->type??'') === 'post_type') { $ids[] = (int)$item->object_id; }
                if (count($ids)>=self::MAX_PRIORITY) { break 2; }
            }
        }
        // Include published block navigation records without rendering dynamic blocks.
        if (count($ids)<self::MAX_PRIORITY && function_exists('parse_blocks') && post_type_exists('wp_navigation')) {
            $menus = get_posts(['post_type'=>'wp_navigation','post_status'=>'publish','numberposts'=>10,'offset'=>0,'has_password'=>false,'suppress_filters'=>false,'update_post_term_cache'=>false]);
            $remaining = 300;
            foreach ($menus as $menu) {
                if ($remaining<=0 || count($ids)>=self::MAX_PRIORITY) { break; }
                if (!$menu instanceof \WP_Post || $menu->post_status!=='publish' || $menu->post_password!=='' || strlen($menu->post_content)>131072) { continue; }
                self::block_ids(parse_blocks($menu->post_content), $ids, $remaining, 0);
            }
        }
        return self::ids($ids,self::MAX_PRIORITY);
    }
    private static function block_ids(array $blocks, array &$ids, int &$remaining, int $depth): void {
        if ($depth>12) { return; }
        foreach ($blocks as $block) {
            if ($remaining--<=0 || count($ids)>=self::MAX_PRIORITY) { return; }
            $attrs=$block['attrs']??[];
            if (in_array($block['blockName']??'', ['core/navigation-link','core/navigation-submenu'], true) && ($attrs['kind']??'')==='post-type' && isset($attrs['id']) && is_numeric($attrs['id'])) { $ids[]=(int)$attrs['id']; }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) { self::block_ids($block['innerBlocks'],$ids,$remaining,$depth+1); }
        }
    }
    private static function query_ids(array $args): array {
        return self::ids(get_posts(array_replace(['post_status'=>'publish','has_password'=>false,'fields'=>'ids','offset'=>0,'orderby'=>['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true,'suppress_filters'=>false,'update_post_term_cache'=>false],$args)), (int)$args['numberposts']);
    }
    public static function candidates(array $settings): array {
        $types = array_keys(self::selected($settings));
        $limit = max(1,min(200,(int)$settings['geo_limit']));
        $fingerprint = hash('sha256',wp_json_encode([$types,$limit,(bool)$settings['geo_navigation'],home_url('/'),get_locale()]));
        // Logged-in previews never populate a shared cache with personalized selections.
        $cacheable = !empty($settings['geo_cache']) && !is_user_logged_in();
        $cached = $cacheable ? get_transient(self::CACHE) : false;
        if (is_array($cached) && ($cached['key']??'')===$fingerprint && is_array($cached['ids']??null)) { return $cached['ids']; }
        $nav = !empty($settings['geo_navigation']) ? self::navigation_ids() : [];
        $plan = []; $scanned = 0; $planned=0;
        foreach ($types as $type) {
            if ($scanned>=self::MAX_SCAN || $planned>=self::MAX_SCAN) { break; }
            $pinned=[]; $recent=[];
            $pin_budget = min(self::MAX_PRIORITY,self::MAX_SCAN-$scanned);
            for ($offset=0;$offset<$pin_budget;$offset+=50) {
                $size=min(50,$pin_budget-$offset);
                $batch=self::query_ids(['post_type'=>$type,'numberposts'=>$size,'offset'=>$offset,'meta_key'=>'_lst_seo_geo_priority','meta_value'=>'1']);
                $scanned+=count($batch); $pinned=array_merge($pinned,$batch);
                if (count($batch)<$size) { break; }
            }
            $priority=self::ids(array_merge($pinned,$nav),self::MAX_PRIORITY*2);
            // Trusted integrators may reorder or supply IDs; the renderer still checks every post.
            $priority=apply_filters('lst_seo_geo_priority',$priority,$type);
            $priority=is_array($priority)?self::ids($priority,self::MAX_PRIORITY*2):[];
            $budget=min($limit*2,self::MAX_SCAN-$scanned);
            for ($offset=0;$offset<$budget;$offset+=50) {
                $size=min(50,$budget-$offset);
                $batch=self::query_ids(['post_type'=>$type,'numberposts'=>$size,'offset'=>$offset]);
                $scanned+=count($batch); $recent=array_merge($recent,$batch);
                if (count($batch)<$size) { break; }
            }
            $plan[$type]=array_slice(array_values(array_unique(array_merge($priority,$recent))),0,self::MAX_SCAN-$planned);
            $planned+=count($plan[$type]);
        }
        if ($cacheable) { set_transient(self::CACHE,['key'=>$fingerprint,'ids'=>$plan],self::CACHE_TTL); }
        return $plan;
    }
    public static function document(array $settings): string {
        if (!get_option('blog_public') || self::restricted_site()) { return ''; }
        $title = self::markdown($settings['geo_title'] ?: get_bloginfo('name'), 200);
        $summary = self::markdown($settings['geo_description'] ?: get_bloginfo('description'), 500);
        $out = '# '.($title ?: 'Website')."\n\n";
        if ($summary !== '') { $out .= '> '.$summary."\n\n"; }
        $limit = max(1, min(200, (int) $settings['geo_limit']));
        $total = 0; $seen = []; $scanned=0;
        $plan=self::candidates($settings);
        foreach (self::selected($settings) as $type=>$object) {
            if ($total>=self::MAX_TOTAL || $scanned>=self::MAX_SCAN) { break; }
            $lines=[];
            $ids=self::ids((array)($plan[$type]??[]),min(800,self::MAX_SCAN-$scanned));
            foreach (array_chunk($ids,50) as $batch) {
                $scanned+=count($batch);
                // Bulk hydrate and prime WP post/meta caches; never trust old IDs as proof of access.
                $posts=get_posts(['post_type'=>$type,'post_status'=>'publish','has_password'=>false,'post__in'=>$batch,'numberposts'=>count($batch),'offset'=>0,'orderby'=>'post__in','ignore_sticky_posts'=>true,'suppress_filters'=>false,'update_post_meta_cache'=>true,'update_post_term_cache'=>false]);
                foreach ($posts as $post) {
                    if (!$post instanceof \WP_Post || !in_array($post->ID,$batch,true) || $post->post_type!==$type || !self::eligible($post)) { continue; }
                    $url=self::link((string)get_permalink($post)); $name=self::markdown($post->post_title,200);
                    if ($url==='' || $name==='' || isset($seen[$url])) { continue; }
                    $desc=self::markdown(SEO::description($post,false),240);
                    $lines[]='- ['.$name.']('.$url.')'.($desc!==''?': '.$desc:'');
                    $seen[$url]=true; ++$total;
                    if (count($lines)>=$limit || $total>=self::MAX_TOTAL) { break 2; }
                }
            }
            $label=apply_filters('lst_seo_geo_section_label',$object->labels->name,$type);
            if ($lines) { $out.='## '.(self::markdown(is_string($label)?$label:$object->labels->name,120) ?: 'Content')."\n\n".implode("\n",$lines)."\n\n"; }
        }
        return rtrim($out)."\n";
    }
    public static function matches(string $uri): bool {
        return wp_parse_url($uri, PHP_URL_PATH) === wp_parse_url(self::url(), PHP_URL_PATH);
    }
    public static function serve(): void {
        $settings = Config::get();
        if (!$settings['geo'] || self::conflict()) { return; }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (!self::matches($uri)) { return; }
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if (!in_array($method, ['GET','HEAD'], true)) { status_header(405); header('Allow: GET, HEAD'); exit; }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        if (!get_option('blog_public') || self::restricted_site()) { status_header(404); exit; }
        status_header(200);
        if ($method === 'GET') {
            $body = self::document($settings);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown, not HTML; fields are normalized and Markdown-escaped, URLs are same-site validated, MIME is text/plain with nosniff. HTML escaping would corrupt links.
            echo $body;
        }
        exit;
    }
}
