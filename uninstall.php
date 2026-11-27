<?php
/** Remove only Leadstart-owned data when the administrator opted in. */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
function lst_seo_uninstall_site() {
    $settings=get_option('lst_seo_settings',[]);
    if (empty($settings['purge'])) { return; }
    wp_clear_scheduled_hook('lst_seo_tick'); wp_clear_scheduled_hook('lst_seo_run');
    // Register the non-public type so WordPress APIs can find its records after deactivation.
    register_post_type('lst_seo_job',['public'=>false,'show_ui'=>false,'rewrite'=>false]);
    do { $ids=get_posts(['post_type'=>'lst_seo_job','post_status'=>['private','publish','draft','pending','future','trash'],'numberposts'=>100,'fields'=>'ids','suppress_filters'=>false]); foreach ($ids as $id) { if (get_post_type($id)==='lst_seo_job') { wp_delete_post($id,true); } } } while (count($ids)===100 && get_post($ids[0])===null);
    foreach (['title','description','keyword','canonical','schema_type','schema_fields','noindex','google_eligible','geo_exclude','geo_priority'] as $field) { delete_post_meta_by_key('_lst_seo_'.$field); }
    foreach (['settings','secret_google_json','secret_bing_key','quota','cache_epoch','worker_lock','worker_lock_time'] as $key) { delete_option('lst_seo_'.$key); }
    delete_transient('lst_seo_geo_candidates');
    // Short-lived report, flash and dedupe transients expire naturally; no broad database deletes.
}
if (is_multisite()) {
    $lst_seo_offset=0; do { $lst_seo_sites=get_sites(['fields'=>'ids','number'=>100,'offset'=>$lst_seo_offset]); foreach ($lst_seo_sites as $lst_seo_site) { switch_to_blog((int)$lst_seo_site); lst_seo_uninstall_site(); restore_current_blog(); } $lst_seo_offset+=count($lst_seo_sites); } while (count($lst_seo_sites)===100);
} else { lst_seo_uninstall_site(); }
