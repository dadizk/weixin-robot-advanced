<?php
class WEIXIN_Template{
	public static function get_sub_pages(){
		if(weixin_get_type() == 4 && weixin_get_templates()) {
			return ['weixin-templates'	=>	[
				'menu_title'	=> '消息模板',	
				'function'		=> 'list',	
				'list_table'	=> self::class
			]];
		}
	}

	private static $templates = [];

	public static function register($key, $args){
		if(isset(self::$templates[$key])){
			wp_die('「'.$key.'」消息目标已被使用');
		}

		self::$templates[$key]	= $args;
	}

	public static function get_templates(){
		return self::$templates;
	}

	public static function get_template_id($key, $appid=''){
		$template_ids	= self::get_template_ids($appid);

		if($template_ids && isset($template_ids[$key])){
			return $template_ids[$key];
		}

		return false;
	}

	protected static function get_template_ids($appid=''){
		return weixin_get_setting('templates', $appid);
	}

	protected static function update_template_ids($template_ids, $appid=''){
		weixin_update_setting('templates', $template_ids, $appid);
	}

	protected static function generate_templates($appid=''){
		if(empty(self::$templates)){
			return;
		}

		$doing_generate	= get_transient('doing_generate_weixin_templates');

		if($doing_generate){
			return;
		}

		set_transient('doing_generate_weixin_templates', 1, 100);

		$weixin	= weixin($appid);
		$result	= self::get_all_private();

		if(is_wp_error($result)){
			return $result;
		}

		$template_list	= $result['template_list'];
		$has_templates	= $template_list ? wp_list_pluck($template_list, 'template_id', 'title') : [];
		$template_ids	= [];
		
		foreach (self::$templates as $k => $config) {
			// if(!empty($config['industry']) && !in_array($config['industry'], $industries)){
			// 	continue;
			// }
			
			$title	= $config['title'];

			if($has_templates && isset($has_templates[$title])){
				$template_id	= $has_templates[$title];
			}else{
				$response	= $weixin->post('/cgi-bin/template/api_add_template', ['template_id_short'=>$config['template_id_short']]);

				if(!is_wp_error($response)){
					$template_id	= $response['template_id'];
				}
			}

			if(isset($template_id)){
				$template_ids[$k]	= $template_id;
			}
		}

		if($template_ids != self::get_template_ids($appid)){
			self::update_template_ids($template_ids, $appid);
		}

		return $template_ids;
	}

	public static function get_primary_key(){
		return 'template_id';
	}

	public static function delete($template_id){
		return weixin()->post('/cgi-bin/template/del_private_template', compact('template_id'));
	}

	public static function get_industry(){
		$industry	= wp_cache_get('weixin_template_industry_'.weixin_get_appid(), 'counts');

		if($industry == false){
			$industry = weixin()->get('/cgi-bin/template/get_industry');

			if(is_wp_error($industry)){
				return $industry;
			}

			wp_cache_set('weixin_template_industry_'.weixin_get_appid(), $industry, 'counts');
		}
		
		return $industry;
	}

	public static function set_industry($industry_id1,$industry_id2){
		return weixin()->post('/cgi-bin/template/api_set_industry', compact('industry_id1', 'industry_id2'));
	}
	
	public static function query_items($args){
		$industry	= self::get_industry();

		if(is_wp_error($industry)){
			if($industry->get_error_code() == '40102'){
				return new WP_Error('40102', '请先到微信公众号后台开通模板消息');
			}else{
				return $industry;
			}
		}

		self::generate_templates();

		$result	= self::get_all_private();

		if(is_wp_error($result)){
			return $result;
		}

		$items	= $result['template_list'];
		$total	= count($items);

		return compact('items', 'total');
	}

	public static function render_item($item){
		if($template_ids = self::get_template_ids()){
			$template_keys	= array_flip($template_ids);
			$template_key	= $template_keys[$item['template_id']] ?? '';

			if($template_key){
				$item['template_id']	= '模板&ensp;ID：'.$item['template_id'].= '<br />调用Key：'.$template_key;
			}
		}
		
		$item['content']	= wpautop($item['content']);
		$item['example']	= wpautop($item['example']);

		return $item;
	}

	public static function get_actions(){
		return [
			'delete'=>['title'=>'删除',	'direct'=>true,	'confirm'=>true,	'bulk'=>true,	'response'=>'list']
		];	
	}

	public static function get_fields($action_key='', $tid=''){
		return [
			'title'			=> ['title'=>'模板标题',		'type'=>'text',	'show_admin_column'=>true],
			'content'		=> ['title'=>'模板内容',		'type'=>'text',	'show_admin_column'=>true],
			'example'		=> ['title'=>'模板内容示例',	'type'=>'text',	'show_admin_column'=>true],
			'template_id'	=> ['title'=>'模板ID',		'type'=>'text',	'show_admin_column'=>true]
		];
	}

	public static function get_list_table(){
		return [
			'title'		=> '消息模板',
			'singular'	=> 'weixin-template',
			'plural'	=> 'weixin-templates',
			'model'		=> 'WEIXIN_Template',
			'per_page'	=> 30,
			'style'		=> 'th.manage-column.column-content{width:20%;} th.manage-column.column-example{width:30%;}'
		];
	}
}

function weixin_register_template($key, $args){
	WEIXIN_Template::register($key, $args);
}

function weixin_get_template_id($key, $appid=''){
	return WEIXIN_Template::get_template_id($key, $appid);
}

function weixin_get_templates(){
	return WEIXIN_Template::get_templates();
}