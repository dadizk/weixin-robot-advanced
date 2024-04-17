<?php
function weixin($appid=''){
	$appid	= $appid ?: weixin_get_current_appid();

	if($appid){
		$setting	= weixin_get_setting($appid);

		if($setting){
			$secret	= $setting['secret'] ?? $setting['weixin_app_secret'];

			return WEIXIN::get_instance($appid, $secret);
		}

		$wp_error	= new WP_Error('empty_weixin_setting', '请先在后台公众号设置中加入该公众号');
	}else{
		$wp_error	= new WP_Error('empty_appid', '公众号 appid 为空');
	}

	if(wpjam_is_json_request()){
		trigger_error($wp_error->get_error_code());
		wpjam_send_json($wp_error);
	}elseif(!wp_doing_cron()){
		trigger_error($wp_error->get_error_code());
		wp_die($wp_error);
	}
}

function weixin_exists($appid, $secret){
	$weixin	= new WEIXIN($appid, $secret);
	$result	= $weixin->get_access_token($force=true);

	return !is_wp_error($result);
}

function weixin_get_setting_object($appid=''){
	if(is_multisite()){
		if($appid){
			$data	= WEIXIN_Setting::get($appid);

			if(!$data){
				return null;
			}

			$blog_id	= $data['blog_id'];
		}else{
			$blog_id	= get_current_blog_id();
		}
	}else{
		$blog_id	= 0;
	}

	return WPJAM_Setting::get_instance('option', 'weixin-robot', $blog_id);
}

// weixin_get_setting() $appid = weixin_get_current_appid()
// weixin_get_setting($appid)
// weixin_get_setting($name)
// weixin_get_setting($name, $appid)
function weixin_get_setting(...$args){
	$appid		= '';
	$name		= '';
	$args_num	= count($args);

	if($args_num == 1){
		$name	= $args[0];

		if($name && preg_match('/(wx[A-Za-z0-9]{15,17})/', $name)){
			$appid	= $name;
			$name	= '';
		}
	}elseif($args_num == 2){
		$name	= $args[0];
		$appid	= $args[1];
	}

	$object	= weixin_get_setting_object($appid);

	if($object){
		if(is_multisite()){
			$appid	= $appid ?: weixin_get_current_appid();

			if($appid){
				$values	= array_merge(WEIXIN_Setting::get($appid), $object->get_option());

				return $name ? ($values[$name] ?? null) : $values;
			}
		}else{
			return $object->get_setting($name);
		}
	}

	return $name ? null : [];
}

function weixin_update_setting($name, $value, $appid=''){
	$object	= weixin_get_setting_object($appid);

	return $object ? $object->update_setting($name, $value) : false;
}

function weixin_delete_setting($name, $appid=''){
	$object	= weixin_get_setting_object($appid);

	return $object ? $object->delete_setting($name) : false;
}

function weixin_has_feature($feature, $appid=''){
	return (bool)weixin_get_setting($feature, $appid);
}

function weixin_get_type($appid=''){
	return weixin_get_setting('weixin_type', $appid);
}

function weixin_doing_reply(){
	if(get_option('permalink_structure')){
		if(strpos($_SERVER['REQUEST_URI'], '/weixin/reply') === 0){
			return true;
		}
	}else{
		if(isset($_GET['module']) && $_GET['module'] == 'weixin' && $_GET['action'] == 'reply'){
			return true;
		}
	}

	return false;
}

function weixin_get_appid(){
	return weixin_get_current_appid();
}

function weixin_get_current_appid(){
	$object	= weixin_get_setting_object();
	$appid	= $object->get_setting('weixin_app_id');
	$appid	= trim($appid);

	if($appid){
		if(!preg_match('/(wx[A-Za-z0-9]{15,17})/', $appid)){
			return '';
		}elseif(is_multisite() && !WEIXIN_Setting::get($appid)){
			return '';
		}
	}

	return $appid;
}

function weixin_get_current_openid(){
	$openid	= wpjam_get_current_var('weixin_openid');

	if(weixin_doing_reply()){
		return $openid;
	}

	if(!is_weixin()){
		return new WP_Error('illegal_platform', '该函数只能在微信浏览器或者自定义回复中调用');
	}

	if(is_null($openid)){
		$weixin_type	= weixin_get_type();
		$json_request	= wpjam_is_json_request();

		if($weixin_type == 4 && $json_request){	// 用于 API 接口
			$access_token	= wpjam_get_parameter('access_token');

			if(empty($access_token)){
				if((isset($_GET['debug']) || isset($_GET['debug_openid'])) && isset($_GET['openid'])){
					return $_GET['openid'];	// 用于测试
				}
			}
		}else{	// 用于网页
			if($weixin_type < 4 && isset($_GET['weixin_access_token'])){
				$access_token	= $_GET['weixin_access_token'];
			}else{
				$access_token	= $_COOKIE['weixin_access_token'] ?? '';
			}
		}

		$appid	= weixin_get_current_appid();
		$openid = WEIXIN_Account::get_openid_by_access_token($appid, $access_token);

		if(!is_wp_error($openid)){
			weixin_set_current_openid($openid);

			if($weixin_type < 4 || !$json_request){
				$object	= weixin_get_user_object($openid, $appid);

				$object->set_cookie($access_token);
			}
		}
	}

	return $openid;
}

function weixin_set_current_openid($openid){
	wpjam_set_current_var('weixin_openid', $openid);
}

function weixin_get_user_object($openid, $appid=''){
	$appid	= $appid ?: weixin_get_current_appid();

	return WEIXIN_Account::get_instance($appid, $openid);
}

function weixin_get_user($openid, $appid=''){
	$appid	= $appid ?: weixin_get_current_appid();
	$user	= WEIXIN_Account::get($appid, $openid);

	if(weixin_get_type($appid) >= 3){
		if(!$user || $user['last_update'] < time() - DAY_IN_SECONDS){
			$result	= WEIXIN_Account::sync($appid, $openid);

			if(!is_wp_error($result)){
				$user	= WEIXIN_Account::get($appid, $openid);
			}
		}
	}

	return $user;
}

function weixin_get_user_tags($appid=''){
	if(weixin_get_type($appid) < 3){
		return [];
	}

	$appid	= $appid ?: weixin_get_current_appid();

	return WEIXIN_Account::get_tags($appid);
}

function weixin_send_template_message($openid, $data, $appid=''){
	$object	= weixin_get_user_object($openid, $appid);

	return $object ? $object->send_message($data, 'template') : false;
}

function weixin_create_qrcode($scene, $expire=0, $appid=''){
	$data	= [];

	if($expire){
		$data['action_name']	= 'QR_';
		$data['expire_seconds']	= (int)$expire;
	}else{
		$data['action_name']	= 'QR_LIMIT_';
	}

	if(is_numeric($scene) && $scene <= 100000){
		$data['action_info']	= ['scene'=>['scene_id'=>(int)$scene]];
		$data['action_name']	.= 'SCENE';
	}else{
		$data['action_info']	=['scene'=>['scene_str'=>(string)$scene]];
		$data['action_name']	.= 'STR_SCENE';
	}

	return weixin($appid)->post('/cgi-bin/qrcode/create', $data);
}

function weixin_get_user_by($type, $value, $appid=''){
	$appid	= $appid ?: weixin_get_current_appid();

	if($type == 'verify_code'){
		$openid	= WEIXIN_Account::get_openid_by_verify_code($appid, $value);
	}elseif($type == 'access_token'){
		$openid	= WEIXIN_Account::get_openid_by_access_token($appid, $value);
	}

	if(is_wp_error($openid)){
		return $openid;
	}

	return WEIXIN_Account::get_instance($appid, $openid);
}

function weixin_get_ticket($type='jsapi', $appid=''){
	$weixin		= weixin($appid);
	$response	= $weixin->cache_get($type.'_ticket');

	if($response === false){
		$response	= $weixin->get('/cgi-bin/ticket/getticket?type='.$type);

		if(!is_wp_error($response)){
			$response['expires_at']	= time()+$response['expires_in']-600;

			$weixin->cache_set($type.'_ticket', $response, $response['expires_in']);
		}
	}

	return $response;
}

function weixin_get_media($media_id, $output='url', $appid=''){
	if($output == 'url'){
		$appid	= $appid ?: weixin_get_current_appid();
		$dir	= 'uploads/weixin/'.$appid;
		$file	= WP_CONTENT_DIR.'/'.$dir.'/'.$media_id.'.jpg';

		if(!file_exists($file)){
			if(!is_dir(WP_CONTENT_DIR.'/'.$dir)){
				mkdir(WP_CONTENT_DIR.'/'.$dir, 0777, true);
			}

			$response	= weixin($appid)->http_request('/cgi-bin/media/get?media_id='.$media_id, ['stream'=>true, 'filename'=>$file]);

			if(is_wp_error($response)){
				return '';
			}
		}

		return WP_CONTENT_URL.'/'.$dir.'/'.$media_id.'.jpg';
	}elseif($output == 'api'){
		$response	= weixin($appid)->get_access_token();

		if(is_wp_error($response)){
			return '';
		}

		return 'https://api.weixin.qq.com/cgi-bin/media/get?media_id='.$media_id.'&access_token='.$response['access_token'];
	}
}

function weixin_get_material($media_id, $type='image', $appid=''){
	$weixin		= weixin($appid);
	$response	= $type == 'news' ? $weixin->cache_get('material:'.$media_id) : false;

	if($response === false){
		$response	= $weixin->post('/cgi-bin/material/get_material', ['media_id'=>$media_id]);

		if($type == 'news' && !is_wp_error($response)){
			$weixin->cache_set('material:'.$media_id, $response, DAY_IN_SECONDS);
		}
	}

	return $response;
}

function weixin_add_material($media, $type='image', $args=[], $appid=''){
	$data	= [
		'type'		=> $type, 
		'filename'	=> array_pull($args, 'filename')
	];

	if(!empty($args['description'])){
		$data['description']	= wpjam_json_encode($args['description']);
	}

	return weixin($appid)->file('/cgi-bin/material/add_material', $media, $data);
}

function weixin_upload_material($id, $type='image', $appid=''){
	$media_id	= get_post_meta($id, 'weixin_media_id', true);
	$material	= $media_id ? weixin_get_material($media_id, $type, $appid) : false;

	if($material && !is_wp_error($material)){
		return new WP_Error('uploaded_to_weixin', '该图已上传微信');
	}

	$file	= get_attached_file($id, true);

	if(!file_exists($file)){
		$result	= wpjam_restore_attachment_file($id);

		if(is_wp_error($result)){
			return $result;
		}
	}

	$response	= weixin_add_material($file, $type, $appid);

	if(is_wp_error($response)){
		return $response;
	}

	return update_post_meta($id, 'weixin_media_id', $response['media_id']);
}

function weixin_download_material($media_id, $type='image', $appid=''){
	$post_id	= WPJAM_File::get_id_by_meta($media_id, 'weixin_media_id');

	if(!$post_id){
		$bits		= weixin_get_material($media_id, $type, $appid);
		$post_id	= is_wp_error($bits) ? $bits : wpjam_upload_bits($bits, $media_id.'.jpg');

		if(!is_wp_error($post_id)){
			update_post_meta($post_id, 'weixin_media_id', $media_id);
		}
	}

	return $post_id;
}

function weixin_parse_mp_article($mp_url){
	$mp_html	= wpjam_remote_request($mp_url, ['json_decode_required'=>false]);

	if(is_wp_error($mp_html)){
		return $mp_html;
	}

	$content	= $content_source_url = '';
	$results	= [];

	foreach([
		'title'		=> 'og:title',
		'digest'	=> 'og:description',
		'author'	=> 'og:article:author',
		'thumb_url'	=> 'og:image',
	] as $key => $value){
		if(preg_match_all('/<meta property=\"'.$value.'\" content=\"(.*?)\" \/>/i', $mp_html, $matches)){
			$results[$key]	= str_replace(['&nbsp;','&amp;'], [' ','&'], $matches[1][0]);
		}else{
			$results[$key]	= '';
		}
	}

	if($start = strpos($mp_html, '<div class="rich_media_content')){
		$mp_html	= substr($mp_html, $start);
		$start		= strpos($mp_html, '>');
		$end		= strpos($mp_html, '</div>');

		$results['content']	= substr($mp_html, $start+1, $end-$start);
	}

	if(preg_match_all('/var msg_source_url = \'(.*?)\';/i', $mp_html, $matches)){
		$results['content_source_url']	= $matches[1][0];
	}

	return $results;
}
