<?php
if(!function_exists('is_exists')){
	function is_exists($var){
		return isset($var);
	}
}

if(!function_exists('is_blank')){
	function is_blank($var){
		return empty($var) && !is_numeric($var);
	}
}

if(!function_exists('is_populated')){
	function is_populated($var){
		return !is_blank($var);
	}
}

if(!function_exists('is_closure')){
	function is_closure($object){
		return $object instanceof Closure;
	}
}

if(!function_exists('base64_urlencode')){
	function base64_urlencode($str){
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}
}

if(!function_exists('base64_urldecode')){
	function base64_urldecode($str){
		return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '='));
	}
}

if(!function_exists('wp_hash')){
	function wp_hash($data, $scheme='auth', $algo='md5') {
		return hash_hmac($algo, $data, wp_salt($scheme));
	}
}

// cache
function wpjam_cache($group, $args){
	return new WPJAM_Cache($group, $args);
}

// crypt
function wpjam_generate_random_string($length){
	return WPJAM_Crypt::generate_random_string($length);
}

// user agent
function wpjam_parse_user_agent($user_agent=null){
	$object	= new WPJAM_User_Agent($user_agent);

	return $object->get_parsed();
}

function wpjam_get_user_agent(){
	return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function wpjam_get_ip(){
	return $_SERVER['REMOTE_ADDR'] ??'';
}

function wpjam_parse_ip($ip=''){
	$ip	= $ip ?: wpjam_get_ip();

	if($ip == 'unknown'){
		return false;
	}

	$data	= [
		'ip'		=> $ip,
		'country'	=> '',
		'region'	=> '',
		'city'		=> '',
		'isp'		=> '',
	];

	if(file_exists(WP_CONTENT_DIR.'/uploads/17monipdb.dat')){
		$ipdata	= IP::find($ip);

		return array_merge($data, [
			'country'	=> $ipdata['0'] ?? '',
			'region'	=> $ipdata['1'] ?? '',
			'city'		=> $ipdata['2'] ?? '',
		]);
	}

	return $data;
}

function wpjam_current(){
	global $wpjam_current;

	if(!$wpjam_current){
		$wpjam_current	= new WPJAM_Args(wpjam_parse_user_agent());
	}

	return $wpjam_current;
}

function wpjam_get_current_var($name, &$isset=false){
	$object	= wpjam_current();
	$isset	= isset($object->$name);

	return $object->$name;
}

function wpjam_set_current_var($name, $value){
	$object	= wpjam_current();

	return $object->$name = $value;
}

function wpjam_get_device(){
	return wpjam_get_current_var('device');
}

function wpjam_get_os(){
	return wpjam_get_current_var('os');
}

function wpjam_get_browser(){
	return wpjam_get_current_var('browser');
}

function wpjam_get_app(){
	return wpjam_get_current_var('app');
}

function wpjam_get_browser_version(){
	return wpjam_get_current_var('browser_version');
}

function wpjam_get_app_version(){
	return wpjam_get_current_var('app_version');
}

function wpjam_get_os_version(){
	return wpjam_get_current_var('os_version');
}

function is_ipad(){
	return wpjam_get_device() == 'iPad';
}

function is_iphone(){
	return wpjam_get_device() == 'iPone';
}

function is_ios(){
	return wpjam_get_os() == 'iOS';
}

function is_macintosh(){
	return wpjam_get_os() == 'Macintosh';
}

function is_android(){
	return wpjam_get_os() == 'Android';
}

function is_weixin(){ 
	if(isset($_GET['weixin_appid'])){
		return true;
	}

	return wpjam_get_app() == 'weixin';
}

function is_weapp(){ 
	if(isset($_GET['appid'])){
		return true;
	}

	return wpjam_get_app() == 'weapp';
}

function is_bytedance(){ 
	if(isset($_GET['bytedance_appid'])){
		return true;
	}

	return wpjam_get_app() == 'bytedance';
}

function wpjam_is_webp_supported(){
	return $GLOBALS['is_chrome'] || is_android() || (is_ios() && version_compare(wpjam_get_os_version(), 14) >= 0);
}

function wpjam_url($dir, $scheme=null){
	return home_url(str_replace(ABSPATH, '', $dir), $scheme);
}

function wpjam_dir($url, $scheme=null){
	return ABSPATH.str_replace(home_url(), '', $url);
}

// File
function wpjam_get_attachment_value($id, $field='file'){
	$post_type	= $id ? get_post_type($id) : null;

	if($post_type == 'attachment'){
		if($field == 'id'){
			return $id;
		}elseif($field == 'file'){
			return get_attached_file($id);
		}elseif($field == 'url'){
			return wp_get_attachment_url($id);
		}elseif($field == 'size'){
			$data	= wp_get_attachment_metadata($id);

			return $data ? wp_array_slice_assoc($data, ['width', 'height']) : [];
		}	
	}
}

function wpjam_upload($name, $relative=false){
	if(is_array($name)){
		if(isset($name['bits'])){
			$bits	= $name['bits'];
			$name	= $name['name'] ?? '';
			$upload	= wp_upload_bits($name, null, $bits);
		}else{
			$upload	= wp_handle_sideload($name, ['test_form'=>false]);
		}
	}else{
		$upload	= wp_handle_upload($_FILES[$name], ['test_form'=>false]);
	}

	if(isset($upload['error']) && $upload['error'] !== false){
		return new WP_Error('upload_error', $upload['error']);
	}

	if($relative){
		$upload['file']	= WPJAM_File::convert($upload['file'], 'file', 'path');
	}

	return $upload;
}

function wpjam_upload_bits($bits, $name, $media=true, $post_id=0){
	$args	= WPJAM_File::parse_args($name, $media, $post_id);
	$name	= $args['name'];
	$field	= $args['field'];
	$parts	= explode('.', $name);

	if(preg_match('/data:image\/([^;]+);base64,(.*)/i', $bits, $matches)){
		$bits	= base64_decode(trim($matches[2]));

		if(count($parts) > 1){
			array_pop($parts);
		}

		$parts[]= $matches[1];
		$name	= implode('.', $parts);
	}

	$upload	= wp_upload_bits($name, null, $bits);

	if(is_wp_error($upload)){
		return $upload;
	}

	if($args['media']){
		$id	= WPJAM_File::add_to_media($upload, $parts[0], $args['post_id']);

		if(is_wp_error($id) || $field == 'id'){
			return $id;
		}
	}

	return $upload[$field];
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	$id		= WPJAM_File::get_id_by_meta($url, 'source_url');

	if($id){
		return wpjam_get_attachment_value($id, $field);
	}

	$args	= WPJAM_File::parse_args($name, $media, $post_id);
	$name	= $args['name'];
	$field	= $args['field'];

	$tmp_file	= download_url($url);

	if(is_wp_error($tmp_file)){
		return $tmp_file;
	}

	if(!$name){
		$type	= wp_get_image_mime($tmp_file);
		$name	= md5($url).'.'.(explode('/', $type)[1]);
	}

	$file	= ['name'=>$name, 'tmp_name'=>$tmp_file];

	if($args['media']){
		$id	= media_handle_sideload($file, $args['post_id']);

		if(is_wp_error($id)){
			@unlink($tmp_file);

			return $id;
		}

		update_post_meta($id, 'source_url', $url);

		return wpjam_get_attachment_value($id, $field);
	}else{
		$upload	= wpjam_upload($file);

		return is_wp_error($upload) ? $upload : $upload[$field];
	}
}

function wpjam_is_external_url($url, $scene=''){
	$site_url	= str_replace(['http://', 'https://'], '//', site_url());
	$status		= strpos($url, $site_url) === false;	

	return apply_filters('wpjam_is_external_url', $status, $url, $scene);
}

function wpjam_scandir($dir, $callback=null){
	$files	= [];

	foreach(scandir($dir) as $file){
		if($file == '.' || $file == '..'){
			continue;
		}

		$file 	= $dir.'/'.$file;

		if(is_dir($file)){
			$files 		= array_merge($files, wpjam_scandir($file));
		}else{
			$files[]	= $file;
		}
	}

	if($callback && is_callable($callback)){
		$output	= [];

		foreach($files as $file){
			$result	= call_user_func_array($callback, [$file, &$output]);
		}

		return $output;
	}else{
		return $files;
	}
}

function wpjam_file($value, $type='file'){
	return new WPJAM_File($value, $type);
}

// Image
function wpjam_image($value, $type=''){
	return new WPJAM_Image($value, $type);
}

// 1. $img_url
// 2. $img_url, array('width'=>100, 'height'=>100)	// 这个为最标准版本
// 3. $img_url, 100x100
// 4. $img_url, 100
// 5. $img_url, array(100,100)
// 6. $img_url, array(100,100), $crop=1, $ratio=1
// 7. $img_url, 100, 100, $crop=1, $ratio=1
function wpjam_get_thumbnail($img_url, ...$args){
	return wpjam_image($img_url)->get_thumbnail(...$args);
}

function wpjam_get_image_size($value, $type='id'){
	return wpjam_image($value, $type)->get_size();
}

function wpjam_is_image($img_url){
	return wpjam_image($img_url)->is_valid();
}

function wpjam_parse_image_query($img_url){
	return wpjam_image($img_url)->parse_query();
}

function wpjam_get_thumbnail_args(...$args){
	$args	= WPJAM_Image::parse_thumbnail_args(...$args);

	return apply_filters('wpjam_thumbnail', '', $args);
}

function wpjam_parse_size($size, $ratio=1){
	return WPJAM_Image::parse_size($size, $ratio);
}

function wpjam_constrain_size($size, $max_width, $max_height){
	return WPJAM_Image::constrain_size($size, $max_width, $max_height);
}

function wpjam_fetch_external_images(&$img_urls, $post_id=0){
	$search	= $replace	= [];
	$args	= [
		'post_id'	=> $post_id, 
		'media'		=> $post_id ? true : false,
		'field'		=> 'url'
	];
		
	foreach($img_urls as $img_url){
		if($img_url && wpjam_is_external_url($img_url, 'fetch')){
			$download	= wpjam_download_url($img_url, $args);

			if(!is_wp_error($download)){
				$search[]	= $img_url;
				$replace[]	= $download;
			}	
		}
	}

	$img_urls	= $search;

	return $replace;
}

// Attr
function wpjam_attr($attr, $type=''){
	return WPJAM_Attr::create($attr, $type);
}

function wpjam_is_bool_attr($attr){
	return WPJAM_Attr::is_bool($attr);
}

function wpjam_parse_name($name){
	if(preg_match('/\[([^\]]*)\]/', $name)){
		$names	= [];
		$arr	= wp_parse_args($name);

		while($arr){
			$name		= array_key_first($arr);
			$arr		= $arr[$name];
			$names[]	= $name;
		}

		return $names;
	}

	return [$name];		
}

function wpjam_parse_options($options){
	$values	= [];

	foreach($options as $opt_value => $opt_title){
		if(is_array($opt_title)){
			if(isset($opt_title['options'])){
				$values	= array_replace($values, wpjam_parse_options($opt_title['options']));
			}elseif(!empty($opt_title['title'])){
				$values[$opt_value]	= $opt_title['title'];
			}elseif(!empty($opt_title['label'])){
				$values[$opt_value]	= $opt_title['label'];
			}
		}else{
			$values[$opt_value]	= $opt_title;
		}
	}

	return $values;
}

function wpjam_strip_data_type($args){
	$value	= array_pull($args, 'data_type');

	return $value ? array_except($args, $value) : $args;
}

function wpjam_parse_data_type(&$args, $strip=false){
	$data_type	= $strip ? array_pull($args, 'data_type') : array_get($args, 'data_type');

	if($data_type){
		$type_value	= $strip ? array_pull($args, $data_type) : array_get($args, $data_type);

		return ['data_type'=>$data_type, $data_type=>($type_value ?: '')];
	}

	return [];
}

// Tag
function wpjam_tag($tag='', $attr=[], $text=''){
	return new WPJAM_Tag($tag, $attr, $text);
}

function wpjam_wrap($text, $wrap='', ...$args){
	if((is_array($wrap) || is_closure($wrap))){
		$text	= is_callable($wrap) ? call_user_func_array($wrap, $text, $args) : $text;
		$wrap	= '';
	}

	return wpjam_tag()->text($text)->wrap($wrap, ...$args);
}

function wpjam_is_single_tag($tag){
	return WPJAM_Tag::is_single($tag);;
}

function wpjam_html_tag_processor($html, $query=null){
	if(version_compare($GLOBALS['wp_version'], '6.2', '>=')){ 
		$proc	= new WP_HTML_Tag_Processor($html);

		return $proc->next_tag($query) ? $proc : null;
	}
}

// Field
function wpjam_fields($fields, $args=[]){
	$object	= WPJAM_Fields::create($fields);

	if($args){
		$echo		= array_pull($args, 'echo', true);
		$rendered	= $object->render($args);

		if($echo){
			echo $rendered;
		}else{
			return $rendered;
		}
	}

	return $object;
}

function wpjam_field($field, $args=[]){
	$object	= WPJAM_Field::create($field);

	if($args){
		if(isset($args['wrap_tag'])){
			$tag	= array_pull($args, 'wrap_tag');

			return $object->wrap($tag, $args);
		}else{
			return $object->render($args);
		}
	}

	return $object;
}

function wpjam_add_fields_parser($type, $callback){
	return wpjam_add_item('fields_parser', $type, $callback);
}

function wpjam_parse_fields($fields, $type='fieldset'){
	if($type == 'fieldset'){
		$parsed	= [];

		foreach($fields as $key => $field){
			if(array_get($field, 'type') == 'fieldset'&& array_get($field, 'fieldset_type', 'single') == 'single'){
				$parsed	= array_merge($parsed, $field['fields']);
			}else{
				$parsed[$key]	= $field;
			}
		}

		return $parsed;
	}else{
		$fields	= $fields ?: [];
		$parser	= $type ? wpjam_get_item('fields_parser', $type) : null;

		return $parser ? wpjam_call($parser, $fields, $type) : $fields;
	}
}

// wpjam_compare()
// wpjam_compare($value, $args)
// wpjam_compare($value, $values)
// wpjam_compare($value, $operator, $compare_value);
function wpjam_compare(...$args){
	$object	= WPJAM_Compare::get_instance();

	if($args){
		$value	= array_shift($args);

		if(count($args) == 1 || is_array($args[0])){
			$args	= wpjam_is_assoc_array($args[0]) ? $args[0] : ['value'=>$args[0]];
		}else{
			$args	= ['compare'=>$args[0], 'value'=>$args[1]];
		}

		return $object->compare($value, $args);
	}

	return $object;
}

function wpjam_match($item, $args=[], $operator='AND'){
	return wpjam_compare()->match($item, $args, $operator);
}

function wpjam_parse_show_if($args){
	if(is_array($args) && !empty($args['key'])){
		return wpjam_compare()->parse_show_if($args);
	}

	return false;
}

function wpjam_show_if($item, $args){
	if(wp_is_numeric_array($args)){
		foreach($args as $_args){
			if(!wpjam_show_if($item, $_args, $parse)){
				return false;
			}
		}
	}else{
		if(is_array($args) && !empty($args['key']) && empty($args['external'])){
			return wpjam_compare($item, $args);
		}
	}

	return true;
}

// Array
function wpjam_array($value=null, $output=''){
	$data	= [];

	if(is_object($value)){
		if(method_exists($value, 'to_array')){
			$data	= $value->to_array();
		}elseif($value instanceof ArrayAccess){
			foreach($value as $k => $v){
				$data[$k]	= $v;
			}
		}
	}elseif(!is_null($value)){
		$data	= (array)$value;
	}

	return $output == 'object' ? new WPJAM_Array($data) : $data;
}

if(!function_exists('array_wrap')){
	function array_wrap($value){
		return wpjam_array($value);
	}
}

function wpjam_is_assoc_array($array){
	return is_array($array) && !wp_is_numeric_array($array);
}

if(!function_exists('is_assoc_array')){
	function is_assoc_array($array){
		return wpjam_is_assoc_array($array);
	}
}

if(!function_exists('array_accessible')){
	function array_accessible($array){
		return is_array($array) || $array instanceof ArrayAccess;
	}
}

if(!function_exists('array_get')){
	function array_get($array, $key, $default=null){
		if(!array_accessible($array)){
			return $default;
		}

		if(is_null($key)){
			return $array;
		}

		if(!is_array($key)){
			if(isset($array[$key]) || !str_contains($key, '.')){
				return $array[$key] ?? $default;
			}

			$key	= explode('.', $key);
		}

		foreach($key as $k){
			if(!array_accessible($array) || !isset($array[$k])){
				return $default;
			}

			$array	= $array[$k];
		}

		return $array;
	}
}

if(!function_exists('array_set')){
	function array_set(&$array, $key, $value){
		if(is_null($key)){
			return $array = $value;
		}

		if(isset($array[$key]) || !str_contains($key, '.')){
			$array[$key] = $value;

			return $array;
		}

		$keys	= explode('.', $key);
		$sub	= &$array;

		while($keys){
			$key	= array_shift($keys);

			if(empty($keys)){
				$sub[$key]	= $value;
			}else{
				if(!isset($sub[$key]) || !array_accessible($sub[$key])){
					$sub[$key] = [];
				}

				$sub	= &$sub[$key];
			}
		}

		return $array;
	}
}

if(!function_exists('array_add')){
	function array_add($array, $key, $value){
		if(is_null(array_get($array, $key))){
			array_set($array, $key, $value);
		}

		return $array;
	}
}

if(!function_exists('array_pull')){
	function array_pull(&$array, $key, $default=null){
		if(is_array($key)){
			$data	= array_pulls($array, $key);
			$value	= $data ? current($data) : null;
		}else{
			$value	= array_get($array, $key, $default);
			$array	= array_except($array, $key);
		}

		return $value;
	}
}

if(!function_exists('array_pulls')){
	function array_pulls(&$array, $keys){
		$data	= wp_array_slice_assoc($array, $keys);
		$array	= array_except($array, $keys);

		return $data;
	}
}

if(!function_exists('array_except')){
	function array_except($array, $keys){
		$keys	= (array)$keys;

		if(count($keys) == 0){
			return $array;
		}

		foreach($keys as $key){
			$sub	= &$array;

			if(isset($sub[$key]) || !str_contains($key, '.')){
				unset($sub[$key]);

				continue;
			}

			$key	= explode('.', $key);

			while($key){
				$k	= array_shift($key);

				if(empty($key)){
					unset($sub[$k]);
				}elseif(isset($sub[$k])){
					$sub = &$sub[$k];
				}else{
					break;
				}
			}

		}

		return $array;
	}
}

if(!function_exists('array_first')){
	function array_first($array, $callback=null, $default=null){
		if(is_null($callback)){
			foreach($array as $item){
				return $item;
			}

			return $default;
		}

		foreach($array as $key => $value){
			if(call_user_func($callback, $value, $key)){
				return $value;
			}
		}

		return $default;
	}
}

if(!function_exists('merge_deep')){		// 深度合并两个关联数组
	function merge_deep($array, $data){
		foreach($data as $key => $value){
			if(wpjam_is_assoc_array($value) && isset($array[$key]) && wpjam_is_assoc_array($array[$key])){
				$array[$key]	= merge_deep($array[$key], $value);
			}else{
				$array[$key]	= $value;
			}
		}

		return $array;
	}
}

if(!function_exists('filter_deep')){	// 深度过滤数组
	function filter_deep($array, $callback){
		foreach($array as $key => &$value){
			if(is_array($value)){
				$value	= filter_deep($value, $callback);
			}
		}

		return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
	}
}

if(!function_exists('array_move')){
	function array_move($array, $id, $pos){
		if(!in_array($id, $array)){
			return new WP_Error('invalid_id', '无效的 ID');
		}

		$value	= null;

		if(is_numeric($pos)){
			$value	= $pos;
			$offset	= 0;
		}else{
			if(isset($pos['next'])){
				$value	= (int)$pos['next'];
				$offset	= 0;
			}elseif(isset($pos['prev'])){
				$value	= (int)$pos['prev'];
				$offset	= 1;
			}
		}

		if(is_null($value) || !in_array($value, $array)){
			return new WP_Error('invalid_position', '无效的移动位置');
		}

		$array	= array_values(array_diff($array, [$id]));
		$offset	+= array_search($value, $array);

		return array_merge(array_slice($array, 0, $offset), [$id], array_slice($array, $offset));
	}
}

function wpjam_has_bit($value, $bit){
	$bit	= (int)$bit;

	return ((int)$value & $bit) == $bit;
}

function wpjam_add_bit($value, $bit){
	$value	= (int)$value;

	return $value = $value | (int)$bit;
}

function wpjam_remove_bit($value, $bit){
	$value	= (int)$value;

	return $value = $value & (~(int)$bit);
}


function wpjam_create_uuid(){
	$chars	= md5(uniqid(mt_rand(), true));
	
	return substr($chars, 0, 8).'-'
	.substr($chars, 8, 4).'-'
	.substr($chars, 12, 4).'-'
	.substr($chars, 16, 4).'-'
	.substr($chars, 20, 12);
}

// String
function wpjam_add_prefix($str, $prefix){
	if(!str_starts_with($str, $prefix)){
		return $prefix.$str;
	}

	return $str;
}

function wpjam_remove_prefix($str, $prefix){
	if(str_starts_with($str, $prefix)){
		return substr($str, strlen($prefix));
	}

	return $str;
}

function wpjam_add_postfix($str, $postfix){
	if(!str_ends_with($str, $postfix)){
		return $str.$postfix;
	}

	return $str;
}

function wpjam_remove_postfix($str, $postfix){
	if(str_ends_with($str, $postfix)){
		return substr($str, 0, strlen($str) - strlen($postfix));
	}

	return $str;
}

function wpjam_unserialize(&$serialized){
	if($serialized){
		$fixed	= preg_replace_callback('!s:(\d+):"(.*?)";!', function($m) {
			return 's:'.strlen($m[2]).':"'.$m[2].'";';
		}, $serialized);

		$unserialized	= unserialize($fixed);

		if($unserialized && is_array($unserialized)){
			$serialized	= $fixed;

			return $unserialized;
		}
	}

	return false;
}

// 去掉非 utf8mb4 字符
function wpjam_strip_invalid_text($text, $charset='utf8mb4'){
	if(!$text){
		return '';
	}

	$regex	= '/
		(
			(?: [\x00-\x7F]                  # single-byte sequences   0xxxxxxx
			|   [\xC2-\xDF][\x80-\xBF]       # double-byte sequences   110xxxxx 10xxxxxx';

	if($charset === 'utf8mb3' || $charset === 'utf8mb4'){
		$regex	.= '
		|   \xE0[\xA0-\xBF][\x80-\xBF]   # triple-byte sequences   1110xxxx 10xxxxxx * 2
			|   [\xE1-\xEC][\x80-\xBF]{2}
			|   \xED[\x80-\x9F][\x80-\xBF]
			|   [\xEE-\xEF][\x80-\xBF]{2}';
	}

	if($charset === 'utf8mb4'){
		$regex	.= '
			|    \xF0[\x90-\xBF][\x80-\xBF]{2} # four-byte sequences   11110xxx 10xxxxxx * 3
			|    [\xF1-\xF3][\x80-\xBF]{3}
			|    \xF4[\x80-\x8F][\x80-\xBF]{2}';
	}

	$regex		.= '
		){1,40}                  # ...one or more times
		)
		| .                      # anything else
		/x';

	return preg_replace($regex, '$1', $text);
}

// 去掉 4字节 字符
function wpjam_strip_4_byte_chars($text){
	return $text ? preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text) : '';
	// return preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $this->text);
}

// 去掉控制字符
function wpjam_strip_control_chars($text){
	// 移除 除了 line feeds 和 carriage returns 所有控制字符
	return $text ? preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/u', '', $text) : '';
	// return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $text);
}

function wpjam_strip_control_characters($text){
	return wpjam_strip_control_chars($text);
}

function wpjam_strip_tags($text){
	if($text){
		$text	= wp_strip_all_tags($text);
		$text	= trim($text);
	}

	return $text;
}

//获取纯文本
function wpjam_get_plain_text($text){
	$text	= wpjam_strip_tags($text);

	if($text){
		$text	= str_replace(['"', '\''], '', $text);
		$text	= str_replace(["\r\n", "\n", "  "], ' ', $text);
		$text	= trim($text);
	}

	return $text;
}

//获取第一段
function wpjam_get_first_p($text){
	$text	= wpjam_strip_tags($text);

	return $text ? trim((explode("\n", $text))[0]) : '';
}

//中文截取方式
function wpjam_mb_strimwidth($text, $start=0, $width=40, $trimmarker='...', $encoding='utf-8'){
	$text	= wpjam_get_plain_text($text);

	return $text ? mb_strimwidth($text, $start, $width, $trimmarker, $encoding) : '';
}

function wpjam_unicode_decode($text){
	// [U+D800 - U+DBFF][U+DC00 - U+DFFF]|[U+0000 - U+FFFF]
	return preg_replace_callback('/(\\\\u[0-9a-fA-F]{4})+/i', function($matches){
		return json_decode('"'.$matches[0].'"') ?: $matches[0];
		// return mb_convert_encoding(pack("H*", $matches[1]), 'UTF-8', 'UCS-2BE');
	}, $text);
}

function wpjam_zh_urlencode($url){
	return $url ? preg_replace_callback('/[\x{4e00}-\x{9fa5}]+/u', function($matches){ 
		return urlencode($matches[0]); 
	}, $url) : '';
}

// 检查非法字符
function wpjam_blacklist_check($text, $name='内容'){
	if(!$text){
		return false;
	}

	$pre	= apply_filters('wpjam_pre_blacklist_check', null, $text, $name);

	if(!is_null($pre)){
		return $pre;
	}

	$words	= explode("\n", get_option('disallowed_keys'));
	foreach((array)$words as $word){
		$word	= trim($word);

		if($word){
			$word	= preg_quote($word, '#');

			if(preg_match("#$word#i", $text)){
				return true;
			}
		}
	}

	return false;
}

function wpjam_hex2rgba($color, $opacity=null){
	if($color[0] == '#'){
		$color	= substr($color, 1);
	}

	if(strlen($color) == 6){
		$hex	= [$color[0].$color[1], $color[2].$color[3], $color[4].$color[5]];
	}elseif(strlen($color) == 3) {
		$hex	= [$color[0].$color[0], $color[1].$color[1], $color[2].$color[2]];
	}else{
		return $color;
	}

	$rgb	= array_map('hexdec', $hex);
	$rgb	= implode(",",$rgb);

	if(isset($opacity)){
		$rgb	.= ','.($opacity > 1 ? 1.0 : $opacity);
	}

	return 'rgb('.$rgb.')';
}

function wpjam_doing_debug(){
	if(isset($_GET['debug'])){
		return $_GET['debug'] ? sanitize_key($_GET['debug']) : true;
	}else{
		return false;
	}
}

function wpjam_parse_shortcode_attr($str, $tagnames=null){
	$pattern = get_shortcode_regex([$tagnames]);

	if(preg_match("/$pattern/", $str, $m)){
		return shortcode_parse_atts($m[3]);
	}else{
		return [];
	}
}

function wpjam_get_current_page_url(){
	return set_url_scheme('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
}

function wpjam_date($format, $timestamp=null){
	if(null === $timestamp){
		$timestamp	= time();
	}elseif(!is_numeric($timestamp)){
		return false;
	}

	return date_create('@'.$timestamp)->setTimezone(wp_timezone())->format($format);
}

function wpjam_strtotime($string){
	return $string ? date_create($string, wp_timezone())->getTimestamp() : 0;
}

function wpjam_human_time_diff($from, $to=0){
	$to	= $to ?: time();

	if($to - $from > 0){
		return sprintf(__('%s ago'), human_time_diff($from, $to));
	}else{
		return sprintf(__('%s from now'), human_time_diff($to, $from));
	}
}

function wpjam_human_date_diff($from, $to=0){
	$zone	= wp_timezone();
	$to		= $to ? date_create($to, $zone) : current_datetime();
	$from	= date_create($from, $zone);
	$diff	= $to->diff($from);
	$days	= (int)$diff->format('%R%a');

	if($days == 0){
		return '今天';
	}elseif($days == -1){
		return '昨天';
	}elseif($days == -2){
		return '前天';
	}elseif($days == 1){
		return '明天';
	}elseif($days == 2){
		return '后天';
	}

	$week_diff	= $from->format('W') - $to->format('W');

	if($week_diff == 0){
		return __($from->format('l'));
	}else{
		return $from->format('m月d日');
	}
}

// 打印
function wpjam_print_r($value){
	$capability	= is_multisite() ? 'manage_site' : 'manage_options';

	if(current_user_can($capability)){
		echo '<pre>';
		print_r($value);
		echo '</pre>'."\n";
	}
}

function wpjam_var_dump($value){
	$capability	= is_multisite() ? 'manage_site' : 'manage_options';
	if(current_user_can($capability)){
		echo '<pre>';
		var_dump($value);
		echo '</pre>'."\n";
	}
}

function wpjam_pagenavi($total=0, $echo=true){
	$args = [
		'prev_text'	=> '&laquo;',
		'next_text'	=> '&raquo;'
	];

	if(!empty($total)){
		$args['total']	= $total;
	}

	$result	= '<div class="pagenavi">'.paginate_links($args).'</div>';

	if($echo){
		echo $result;
	}else{
		return $result; 
	}
}

function wpjam_localize_script($handle, $object_name, $l10n ){
	wp_localize_script($handle, $object_name, ['l10n_print_after' => $object_name.' = '.wpjam_json_encode($l10n)]);
}

function wpjam_is_mobile_number($number){
	return preg_match('/^0{0,1}(1[3,5,8][0-9]|14[5,7]|166|17[0,1,3,6,7,8]|19[8,9])[0-9]{8}$/', $number);
}

function wpjam_set_cookie($key, $value, $expire=DAY_IN_SECONDS){
	$_COOKIE[$key]	= $value;

	$expire	= $expire < time() ? $expire+time() : $expire;

	setcookie($key, $value, $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

	if(COOKIEPATH != SITECOOKIEPATH){
		setcookie($key, $value, $expire, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
	}
}

function wpjam_clear_cookie($key){
	unset($_COOKIE[$key]);

	setcookie($key, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
	setcookie($key, ' ', time() - YEAR_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN);
}

function wpjam_get_filter_name($name='', $type=''){
	$filter	= str_replace('-', '_', $name);
	$filter	= str_replace('wpjam_', '', $filter);

	return 'wpjam_'.$filter.'_'.$type;
}

function wpjam_get_filesystem(){
	if(empty($GLOBALS['wp_filesystem'])){
		if(!function_exists('WP_Filesystem')){
			require_once(ABSPATH.'wp-admin/includes/file.php');
		}

		WP_Filesystem();
	}

	return $GLOBALS['wp_filesystem'];
}
