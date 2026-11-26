<?php
namespace Leadstart\SEO;
if (!defined('ABSPATH')) { exit; }
final class Schema {
    public static function types(): array { return ['WebPage','WebSite','Article','BlogPosting','NewsArticle','Organization','Person','JobPosting','Event','Place','LocalBusiness','Product','Offer','Service','VideoObject','BroadcastEvent','BreadcrumbList','FAQPage','ItemList','ProfilePage']; }
    public static function defaults(): array { return ['post'=>'BlogPosting','page'=>'WebPage','job_listing'=>'JobPosting','tribe_events'=>'Event','tribe_venue'=>'Place','tribe_organizer'=>'Organization','company'=>'Organization','investor'=>'Organization','accelerator'=>'Organization']; }
    public static function type(int $id): string { $t=get_post_meta($id,'_lst_seo_schema_type',true); if ($t==='none') { return ''; } if (in_array($t,self::types(),true)) { return $t; } $s=Config::get(); $t=$s['schema_map'][get_post_type($id)]??''; return in_array($t,self::types(),true)?$t:''; }
    /** Decode, validate and sanitize each value. Keep case-sensitive Schema.org keys. */
    public static function json_fields(string $raw, string $type = '') {
        if (strlen($raw)>32768) { return new \WP_Error('schema','Schema fields exceed 32 KB.'); }
        if (trim($raw)==='') { return []; }
        $decoded=json_decode($raw,false,12);
        if (!($decoded instanceof \stdClass) || json_last_error()!==JSON_ERROR_NONE) { return new \WP_Error('schema','Schema fields must be a valid JSON object.'); }
        return self::sanitize_fields((array)$decoded,0,$type);
    }
    private static function invalid(): \WP_Error { return new \WP_Error('schema','Invalid schema property or value. Check the field names, URLs, dates and value types.'); }
    /** Validate objects and lists recursively; lists inherit the property of their parent. */
    private static function sanitize_fields(array $values,int $depth,string $type,string $parent='') {
        if ($depth>9 || count($values)>100) { return self::invalid(); }
        if (isset($values['@type'])) {
            if (!is_string($values['@type']) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D',$values['@type'])) { return self::invalid(); }
            if ($depth>0) { $type=$values['@type']; }
        }
        $clean=[];
        foreach ($values as $k=>$value) {
            if (is_string($k) && ($k==='@context' || !preg_match('/^(?:@type|@id|@value|[A-Za-z][A-Za-z0-9]*)$/D',$k))) { return self::invalid(); }
            if (is_int($k) && ($depth===0 || !array_is_list($values))) { return self::invalid(); }
            $field=is_string($k)?$k:$parent;
            if (is_array($value) || $value instanceof \stdClass) {
                if ($field==='@type' || ($field==='@id')) { return self::invalid(); }
                if ($value instanceof \stdClass) { foreach (array_keys((array)$value) as $key) { if (!is_string($key)) { return self::invalid(); } } }
                $value=self::sanitize_fields((array)$value,$depth+1,$type,$field);
                if (is_wp_error($value)) { return $value; }
            } else {
                $value=self::sanitize_value($field,$value,$type);
                if (is_wp_error($value)) { return $value; }
            }
            $clean[$k]=$value;
        }
        return $clean;
    }
    private static function sanitize_value(string $field,$value,string $type) {
        if (!is_scalar($value) && $value!==null) { return self::invalid(); }
        if (is_float($value) && !is_finite($value)) { return self::invalid(); }
        if ($value===null) { return $field==='@type' || $field==='@id'?self::invalid():null; }
        if (in_array($field,['isLiveBroadcast','isAccessibleForFree','valueAddedTaxIncluded'],true)) { return is_bool($value)?$value:self::invalid(); }
        $urls=['@id','url','sameAs','image','thumbnailUrl','contentUrl','embedUrl','logo','mainEntityOfPage','item','target','license','downloadUrl','installUrl','discussionUrl','significantLink'];
        $dates=['datePosted','validThrough','datePublished','dateModified','dateCreated','startDate','endDate','uploadDate','birthDate','deathDate','foundingDate','dissolutionDate','priceValidUntil'];
        if (!is_string($value)) {
            if (in_array($field,$urls,true) || in_array($field,$dates,true) || in_array($field,['email','@type','description','urlTemplate'],true)) { return self::invalid(); }
            return $value;
        }
        if (strlen($value)>16000) { return self::invalid(); }
        if ($field==='@type') { return preg_match('/^[A-Za-z][A-Za-z0-9]*$/D',$value)?sanitize_text_field($value):self::invalid(); }
        if (in_array($field,$urls,true)) {
            $value=trim($value);
            if ($value==='') { return ''; }
            if (preg_match('/[\x00-\x20\x7f<>"\\\\]/',$value)) { return self::invalid(); }
            if ($field==='@id' && preg_match('/^#[A-Za-z0-9_.:-]+$/D',$value)) { return sanitize_text_field($value); }
            $parts=wp_parse_url($value);
            if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme']??''),['http','https'],true) || isset($parts['user']) || isset($parts['pass'])) { return self::invalid(); }
            $clean=esc_url_raw($value,['http','https']);
            return $clean!==''?$clean:self::invalid();
        }
        if ($field==='urlTemplate') {
            // URI template syntax includes braces. Validate an expanded example as HTTP(S), retain the template as text.
            $value=trim($value); $example=preg_replace('/\{[A-Za-z0-9_.,?&+#\/;=:%*-]+\}/','placeholder',$value);
            if (!is_string($example) || strpbrk($example,'{}')!==false || is_wp_error(self::sanitize_value('url',$example,$type))) { return self::invalid(); }
            $clean=sanitize_text_field($value); return $clean===$value?$clean:self::invalid();
        }
        if ($field==='email') {
            $value=trim($value); if ($value==='') { return ''; }
            $clean=sanitize_email($value); return $clean===$value && is_email($clean)?$clean:self::invalid();
        }
        if (in_array($field,$dates,true)) {
            $value=trim($value); if ($value==='') { return ''; }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?$/D',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) { return self::invalid(); }
            return sanitize_text_field($value);
        }
        if ($field==='description') { return $type==='JobPosting'?wp_kses_post($value):sanitize_textarea_field($value); }
        return sanitize_text_field($value);
    }
    /** Re-encode cleaned values only; never store the submitted JSON string. */
    public static function clean_json(string $raw,string $type='') {
        $fields=self::json_fields($raw,$type);
        if (is_wp_error($fields)) { return $fields; }
        if (trim($raw)==='') { return ''; }
        $encoded=wp_json_encode((object)$fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($encoded) && strlen($encoded)<=32768?$encoded:self::invalid();
    }
    public static function mapping(string $raw) {
        if (trim($raw)==='') { return []; } $v=json_decode($raw,true,4);
        $allowed=['name','headline','description','startDate','endDate','datePosted','validThrough','datePublished','dateModified','employmentType','jobLocationType','price','priceCurrency','sku','availability','telephone','email','url','image','uploadDate','duration','thumbnailUrl','serviceType','legalName'];
        if (strlen($raw)>4096 || !is_array($v) || !str_starts_with(ltrim($raw),'{') || count($v)>20) { return new \WP_Error('mapping','Expected a small JSON object.'); }
        foreach ($v as $field=>$meta) { if (!in_array($field,$allowed,true) || !is_string($meta) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D',$meta) || is_protected_meta($meta,'post') || preg_match('/secret|token|password|credential|private|api.?key|auth|session|nonce/i',$meta)) { return new \WP_Error('mapping','Only allowed schema properties and non-protected, non-sensitive public meta keys can be mapped.'); } $v[$field]=sanitize_text_field($meta); } return $v;
    }
    public static function build(int $id) {
        $p=get_post($id); $type=self::type($id); if (!$p || !$type) { return new \WP_Error('schema','No schema type selected.'); }
        $url=get_permalink($p); $title=wp_strip_all_tags(get_the_title($p)); $desc=SEO::description($p); $data=['@context'=>'https://schema.org','@type'=>$type,'@id'=>$url.'#lst-schema'];
        if (!in_array($type,['BreadcrumbList','ItemList','FAQPage','Offer','BroadcastEvent'],true)) { $data+=['name'=>$title,'url'=>$url]; }
        if ($desc && !in_array($type,['BreadcrumbList','ItemList','FAQPage'],true)) { $data['description']=$desc; }
        if (in_array($type,['Article','BlogPosting','NewsArticle'],true)) { $data+=['headline'=>$title,'datePublished'=>get_post_time(DATE_W3C,true,$p),'dateModified'=>get_post_modified_time(DATE_W3C,true,$p),'mainEntityOfPage'=>$url,'author'=>['@type'=>'Person','name'=>get_the_author_meta('display_name',(int)$p->post_author)]]; $img=get_the_post_thumbnail_url($p,'full'); if ($img) { $data['image']=$img; } }
        if ($type==='JobPosting') {
            $data['title']=$title; $data['description']=wp_kses_post($p->post_content); $data['datePosted']=get_post_time('Y-m-d',true,$p);
            $company=get_post_meta($id,'_company_name',true); if (is_string($company) && trim($company)!=='') { $data['hiringOrganization']=['@type'=>'Organization','name'=>wp_strip_all_tags($company)]; }
            $expires=get_post_meta($id,'_job_expires',true); if (is_string($expires) && Config::date_ok($expires)) { $data['validThrough']=$expires; }
            // Location requires structured facts, not guessing from a free-text city.
        }
        $mapping=Config::get()['field_map'][$p->post_type]??[]; $mapping=self::mapping(wp_json_encode((object)$mapping)); if (!is_wp_error($mapping)) { foreach ($mapping as $field=>$meta) { $value=get_post_meta($id,$meta,true); if (is_scalar($value) && (string)$value!=='') { $clean=self::sanitize_value($field,$value,$type); if (!is_wp_error($clean)) { $data[$field]=$clean; } } } }
        $fields=self::json_fields((string)get_post_meta($id,'_lst_seo_schema_fields',true),$type); if (is_wp_error($fields)) { return $fields; }
        unset($fields['@type'],$fields['@id']); $data=array_replace($data,$fields);
        // Trusted filter for extending factual schema. Preserve the context/type/identity and validate fields.
        $filtered=apply_filters('lst_seo_schema_data',$data,$p);
        if (is_array($filtered) && $filtered !== $data) {
            unset($filtered['@context'],$filtered['@type'],$filtered['@id']);
            $filtered=self::sanitize_fields($filtered,0,$type); if (is_wp_error($filtered)) { return $filtered; }
            $data=['@context'=>$data['@context'],'@type'=>$data['@type'],'@id'=>$data['@id']]+$filtered;
        }
        $required=['JobPosting'=>['title','description','datePosted','hiringOrganization'], 'Event'=>['name','startDate','location'],'Product'=>['name'],'Offer'=>['price','priceCurrency'],'VideoObject'=>['name','description','thumbnailUrl','uploadDate'],'BroadcastEvent'=>['startDate','endDate','isLiveBroadcast'],'BreadcrumbList'=>['itemListElement'],'ItemList'=>['itemListElement'],'FAQPage'=>['mainEntity'],'ProfilePage'=>['mainEntity'],'LocalBusiness'=>['name','address']];
        foreach ($required[$type]??[] as $field) { if (!array_key_exists($field,$data) || $data[$field]===null || $data[$field]==='' || $data[$field]===[]) { return new \WP_Error('schema','Missing schema field: '.$field); } }
        if ($type==='JobPosting' && (empty($data['jobLocation']) && (($data['jobLocationType']??'')!=='TELECOMMUTE' || empty($data['applicantLocationRequirements'])))) { return new \WP_Error('schema','JobPosting requires jobLocation or remote applicant location requirements.'); }
        if ($type==='JobPosting' && (!is_array($data['hiringOrganization']) || empty($data['hiringOrganization']['name']))) { return new \WP_Error('schema','Hiring organization must have a name.'); }
        return $data;
    }
}
