<?php
/** Create UT equipment pages and connect them to both production menus. */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';
global $wpdb;

$services = [
    'ut-tools' => ['UT Tools', 0, 'Gripco deploys portable conventional and advanced ultrasonic instruments selected for the inspection technique, component geometry, code requirements and required data record.', ['Conventional ultrasonic flaw detection', 'Encoded and advanced ultrasonic inspection', 'Field-ready equipment selected for the approved procedure'], 'Equipment identification, calibration status, probes, setup parameters and inspection results are recorded in the applicable inspection report.'],
    'krautkramer-usm-36' => ['Krautkrämer USM 36', 'ut-tools', 'The Waygate Technologies Krautkrämer USM 36 is a rugged portable ultrasonic flaw detector used by Gripco for conventional weld, forging, thickness and corrosion examinations.', ['Conventional pulse-echo UT', 'Weld and forging inspection', 'Thickness and corrosion measurement'], 'Gripco documents the instrument, probe, calibration block, range, sensitivity, scan coverage and evaluated indications in accordance with the approved procedure.'],
    'usm-100-pro' => ['Krautkrämer USM 100 Pro', 'ut-tools', 'The Krautkrämer USM 100 Pro supports connected conventional ultrasonic inspection with modern data handling and optional B-scan and C-scan capability for repeatable field examinations.', ['Conventional weld and component inspection', 'Encoded B-scan or C-scan workflows where specified', 'Traceable digital inspection data'], 'Reports identify the equipment configuration, calibration, scan plan, recorded data and indication evaluation against the project criteria.'],
    'omniscan-x4' => ['Olympus/Evident OmniScan X4', 'ut-tools', 'The Evident OmniScan X4 is a portable multitechnology ultrasonic platform used for phased array, TOFD, TFM and other advanced inspection configurations when required by the approved technique.', ['PAUT and TOFD weld inspection', 'TFM and advanced imaging applications', 'Corrosion, cracking and complex-component assessment'], 'Deliverables can include setup files, encoded scan data, calibrated views, indication tables and a reviewed inspection report.'],
    'proceq-uci-hardness-testing' => ['Proceq UCI Hardness Testing', 'uci-hardness', 'Gripco uses Proceq Equotip UCI technology for localized portable hardness measurements where a small indentation and controlled test load are beneficial.', ['Welds and heat-affected zones', 'Thin, small or difficult-access components', 'Field verification of heat-treatment condition'], 'The report records the Proceq instrument and probe, test load, reference-block verification, surface condition, locations, readings and any documented conversions.'],
];

$ids = [];
$existing_parent = get_page_by_path('material-testing/hardness-testing/uci-hardness');
if (!$existing_parent) { fwrite(STDERR,"UCI Hardness parent page not found.\n"); exit(1); }
$ids['uci-hardness'] = $existing_parent->ID;

$pending = $services;
while ($pending) {
    $progress=false;
    foreach ($pending as $slug=>$s) {
        [$title,$parent,$lead,$apps,$report]=$s;
        if (is_string($parent) && empty($ids[$parent])) continue;
        $parent_id = is_string($parent) ? $ids[$parent] : 0;
        $existing=get_posts(['name'=>$slug,'post_type'=>'page','post_status'=>'any','post_parent'=>$parent_id,'numberposts'=>1]);
        $content='<main class="gripco-service"><div class="gripco-service__eyebrow">Gripco Inspection Equipment</div><h1>'.esc_html($title).'</h1><p class="gripco-service__lead">'.esc_html($lead).'</p><div class="gripco-service__grid"><section class="gripco-service__card"><h2>Inspection capabilities</h2><ul>';
        foreach($apps as $app)$content.='<li>'.esc_html($app).'</li>';
        $content.='</ul></section><section class="gripco-service__card"><h2>Equipment selection</h2><p>Gripco selects the instrument, probe and accessories for the material, geometry, expected discontinuities and governing procedure. Calibration and functional checks are completed before examination.</p></section><section class="gripco-service__card"><h2>Quality controls</h2><ul><li>Current equipment identification and calibration status</li><li>Reference-block and sensitivity checks</li><li>Qualified personnel working to an approved procedure</li></ul></section><section class="gripco-service__card"><h2>Reporting</h2><p>'.esc_html($report).'</p></section></div><section class="gripco-service__cta"><h2>Plan your inspection</h2><p>Send Gripco the component details, material, inspection code, access conditions and required coverage so the appropriate equipment and technique can be confirmed.</p></section></main>';
        $post=['post_title'=>$title,'post_name'=>$slug,'post_parent'=>$parent_id,'post_type'=>'page','post_status'=>'publish','post_content'=>$content,'post_excerpt'=>$lead];
        if($existing){$post['ID']=$existing[0]->ID;$id=wp_update_post($post,true);}else{$id=wp_insert_post($post,true);}
        if(is_wp_error($id)){fwrite(STDERR,$id->get_error_message()."\n");exit(1);}
        update_post_meta($id,'_gripco_service_catalogue',1);$ids[$slug]=(int)$id;unset($pending[$slug]);$progress=true;
    }
    if(!$progress){fwrite(STDERR,"Unresolved equipment hierarchy.\n");exit(1);}
}

// Standard WordPress menu hierarchy.
$menu=wp_get_nav_menu_object('header-menu');
$existing_items=[];
foreach(wp_get_nav_menu_items($menu->term_id)?:[] as $item)if($item->type==='post_type'&&$item->object==='page')$existing_items[(int)$item->object_id]=(int)$item->ID;
$add_item=static function(int $page_id,int $parent,string $title)use($menu,&$existing_items){$item_id=$existing_items[$page_id]??0;$result=wp_update_nav_menu_item($menu->term_id,$item_id,['menu-item-parent-id'=>$parent,'menu-item-title'=>$title,'menu-item-object-id'=>$page_id,'menu-item-object'=>'page','menu-item-type'=>'post_type','menu-item-status'=>'publish']);$existing_items[$page_id]=(int)$result;return(int)$result;};
$ut_menu=$add_item($ids['ut-tools'],133,'UT Tools');
$add_item($ids['krautkramer-usm-36'],$ut_menu,'Krautkrämer USM 36');
$add_item($ids['usm-100-pro'],$ut_menu,'Krautkrämer USM 100 Pro');
$add_item($ids['omniscan-x4'],$ut_menu,'Olympus/Evident OmniScan X4');
$uci_menu=$existing_items[$ids['uci-hardness']]??0;
$add_item($ids['proceq-uci-hardness-testing'],$uci_menu,'Proceq UCI Hardness Testing');

// ElementsKit desktop mega menu.
$data=json_decode(get_post_meta(268,'_elementor_data',true),true);
$columns=&$data[0]['elements'][0]['elements'][0]['elements'];
$columns=array_values(array_filter($columns,static fn($c)=>($c['elements'][0]['settings']['ekit_heading_title']??'')!=='UT Tools'));
$prototype=$columns[count($columns)-1];
$column=$prototype;$column['id']='griputcl';$column['settings']['border_border']='solid';
$column['elements'][0]['id']='griputhd';$column['elements'][0]['settings']['ekit_heading_title']='UT Tools';$column['elements'][0]['settings']['ekit_heading_link']['url']=get_permalink($ids['ut-tools']);
$base=$prototype['elements'][1]['settings']['icon_list'][0];$entries=[];
foreach([['Krautkrämer USM 36','krautkramer-usm-36'],['Krautkrämer USM 100 Pro','usm-100-pro'],['Olympus/Evident OmniScan X4','omniscan-x4']] as [$title,$slug]){$e=$base;$e['_id']=substr(md5($slug),0,7);$e['text']=$title;$e['ekit_page_list_website_link']['url']=get_permalink($ids[$slug]);$entries[]=$e;}
$column['elements'][1]['id']='griputls';$column['elements'][1]['settings']['icon_list']=$entries;$columns[]=$column;
// Append Proceq beneath UCI in the Material Testing list.
foreach($columns as &$c){if(($c['elements'][0]['settings']['ekit_heading_title']??'')!=='Material Testing')continue;$list=&$c['elements'][1]['settings']['icon_list'];$list=array_values(array_filter($list,static fn($e)=>!str_contains($e['text']??'','Proceq UCI')));$e=$list[0];$e['_id']='procequ';$e['text']='— Proceq UCI Hardness Testing';$e['ekit_page_list_website_link']['url']=get_permalink($ids['proceq-uci-hardness-testing']);$list[]=$e;}unset($c);
$wpdb->update($wpdb->postmeta,['meta_value'=>wp_json_encode($data)],['post_id'=>268,'meta_key'=>'_elementor_data'],['%s'],['%d','%s']);
clean_post_cache(268);if(class_exists('Elementor\\Plugin'))Elementor\Plugin::$instance->files_manager->clear_cache();
echo "Created five equipment pages and updated both menus.\n";
