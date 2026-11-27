<?php
namespace LST_SEO;
if (!defined('ABSPATH')) { exit; }
final class Config {
    const OPTION = 'lst_seo_settings';
    public static function defaults(): array { return ['google_auto'=>[], 'indexnow_auto'=>[], 'google_limit'=>200,'indexnow'=>false,'bing_fallback'=>false,'index_key'=>'','key_path'=>'','gsc_property'=>'','ga_property'=>'','meta'=>false,'use_excerpt'=>true,'excerpt_types'=>null,'geo'=>false,'geo_types'=>['post','page'],'geo_limit'=>50,'geo_title'=>'','geo_description'=>'','geo_navigation'=>true,'geo_cache'=>true,'geo_bots'=>[],'schema'=>false,'schema_map'=>[], 'field_map'=>[], 'ai'=>false,'ai_provider'=>'','ai_fallback'=>false,'report_start'=>'','report_start_manual'=>false,'purge'=>false]; }
    public static function get(): array {
        $stored=(array)get_option(self::OPTION, []);
        $settings=array_replace(self::defaults(),$stored);
        // The former default was indistinguishable from a saved copy of that default.
        // Treat it as automatic unless explicitly saved as a manual override in this release.
        if ($settings['report_start']==='2020-10-14' && empty($stored['report_start_manual'])) { $settings['report_start']=''; }
        return $settings;
    }
    public static function secret(string $name): string {
        $constant = 'LST_SEO_' . strtoupper($name);
        if (defined($constant)) { return is_string(constant($constant)) ? constant($constant) : ''; }
        $stored = get_option('lst_seo_secret_'.$name, '');
        if (!is_string($stored) || !$stored || !function_exists('openssl_decrypt')) { return ''; }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw)<29) { return ''; }
        $plain = openssl_decrypt(substr($raw,28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16), $name);
        return is_string($plain) ? $plain : '';
    }
    private static function key(): string { return hash('sha256', wp_salt('auth').'|'.wp_salt('secure_auth').'|leadstart-seo|'.get_current_blog_id(), true); }
    public static function set_secret(string $name, string $value) {
        if (!in_array($name,['google_json','bing_key'],true)) { return new \WP_Error('credential','Unknown credential.'); }
        if (defined('LST_SEO_'.strtoupper($name))) { return new \WP_Error('constant','Credential is managed in wp-config.php.'); }
        if ($value==='') { delete_option('lst_seo_secret_'.$name); self::rotate_cache(); return true; }
        if ($name==='google_json') {
            $validated=self::google_json($value); if (is_wp_error($validated)) { return $validated; }
            $value=wp_json_encode($validated,JSON_UNESCAPED_SLASHES); if (!is_string($value)) { return new \WP_Error('credential','Credential encoding failed.'); }
        } elseif (!preg_match('/^[A-Za-z0-9_-]{8,256}$/D',$value)) { return new \WP_Error('credential','Invalid Bing key format.'); }
        if (!function_exists('openssl_encrypt')) { return new \WP_Error('openssl','OpenSSL is required to store credentials.'); }
        try { $iv=random_bytes(12); $tag=''; $encrypted=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,$name); } catch (\Throwable $e) { return new \WP_Error('encrypt','Credential encryption failed.'); }
        if ($encrypted===false || strlen($tag)!==16) { return new \WP_Error('encrypt','Credential encryption failed.'); }
        $encoded=base64_encode($iv.$tag.$encrypted); update_option('lst_seo_secret_'.$name,$encoded,false);
        if (get_option('lst_seo_secret_'.$name)!==$encoded) { return new \WP_Error('save','Credential storage failed.'); }
        self::rotate_cache(); return true;
    }
    public static function rotate_cache(): void { update_option('lst_seo_cache_epoch',wp_generate_uuid4(),false); }
    public static function cache_key(string $purpose, $data): string { return 'lst_seo_'.substr(hash('sha256',$purpose.'|'.get_option('lst_seo_cache_epoch','1').'|'.wp_json_encode($data)),0,40); }
    public static function google_json(string $raw) {
        if (strlen($raw)>32768 || !function_exists('openssl_pkey_get_private')) { return new \WP_Error('credential','Invalid credential size or missing OpenSSL.'); }
        $v=json_decode($raw,true);
        if (!is_array($v) || ($v['type']??'')!=='service_account' || !is_string($v['client_email']??null) || !is_email($v['client_email']) || !is_string($v['private_key']??null) || !in_array($v['token_uri']??'', ['https://oauth2.googleapis.com/token','https://accounts.google.com/o/oauth2/token'],true)) { return new \WP_Error('credential','Expected a Google service account JSON with a supported token endpoint.'); }
        $key=openssl_pkey_get_private($v['private_key']); $details=$key?openssl_pkey_get_details($key):false;
        if (!$details || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<2048) { return new \WP_Error('credential','Expected a valid RSA private key (at least 2048 bits).'); }
        // Store only validated fields required by OAuth. Export the parsed RSA key as normalized PEM.
        $pem=''; if (!openssl_pkey_export($key,$pem) || $pem==='') { return new \WP_Error('credential','Private key normalization failed.'); }
        $email=sanitize_email($v['client_email']); if ($email!==$v['client_email']) { return new \WP_Error('credential','Invalid service account email.'); }
        return ['type'=>'service_account','client_email'=>$email,'private_key'=>$pem,'token_uri'=>'https://oauth2.googleapis.com/token'];
    }
    public static function url(string $url): string {
        $url=trim($url); if (strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) { return ''; }
        $p=wp_parse_url($url); $h=wp_parse_url(home_url('/'));
        if (!$p || !$h || !isset($p['scheme'],$p['host'],$h['scheme'],$h['host']) || !in_array(strtolower($p['scheme']),['http','https'],true) || strtolower($p['host'])!==strtolower($h['host']) || strtolower($p['scheme'])!==strtolower($h['scheme']) || ($p['port']??null)!==($h['port']??null) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { return ''; }
        $base=rtrim($h['path']??'','/'); $path=$p['path']??'/';
        if ($base!=='' && $path!==$base && !str_starts_with($path,$base.'/')) { return ''; }
        if (preg_match('~(?:^|/)(?:\.{1,2})(?:/|$)~',rawurldecode($path)) || preg_match('~/(?:wp-admin|wp-login\.php|wp-json)(?:/|$)~i',rawurldecode($path))) { return ''; }
        $q=[]; wp_parse_str($p['query']??'', $q); foreach (array_keys($q) as $k) { if (preg_match('/preview|nonce|rest_route|password|token|(?:^|_)key(?:_|$)|secret|credential|session|auth/i',(string)$k)) { return ''; } } if (preg_match('/[\x00-\x1f\x7f\\\\]/',rawurldecode($path))) { return ''; }
        return esc_url_raw($url,['http','https']);
    }
    public static function property_ok(string $p): bool { if ($p==='sc-domain:'.wp_parse_url(home_url('/'),PHP_URL_HOST)) { return true; } return rtrim($p,'/')===rtrim(home_url('/'),'/'); }
    public static function date_ok(string $date): bool { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date); return $d && $d->format('Y-m-d')===$date; }
}
