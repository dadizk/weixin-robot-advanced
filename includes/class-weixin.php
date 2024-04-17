<?php
class WEIXIN{
	use WPJAM_Instance_Trait;

	private $appid;
	private $secret;
	private $api_host	= 'https://api.weixin.qq.com';

	public function __construct($appid, $secret){
		$this->appid	= $appid;
		$this->secret	= trim($secret);
	}

	public function __call($method, $args){
		if(str_starts_with($method, 'cache_')){
			$object	= WPJAM_Cache_Group::get_instance('weixin', [
				'global'	=> true, 
				'prefix'	=> $this->appid
			]);

			return call_user_func_array([$object, $method], $args);
		}
	}

	public function get_appid(){
		return $this->appid;
	}

	public function get_access_token($force=false){
		if(!$force){
			$response	= weixin_get_setting('access_token', $this->appid);
			$disabled	= weixin_get_setting('api_disabled', $this->appid);

			if($disabled){
				return new WP_Error($disabled['errcode'], $disabled['errmsg']);
			}
		}else{
			$response	= false;
		}

		if(!$response || !is_array($response) || !isset($response['expires_at']) || $response['expires_at'] <= time()){
			$response = $this->http_request($this->api_host.'/cgi-bin/token?grant_type=client_credential', ['using_secret'=>true]);

			if(is_wp_error($response)){
				$errcode	= $response->get_error_code(); 

				if($errcode == '40164'){
					$errmsg	= '未把服务器IP填入微信公众号IP白名单，请仔细检查后重试。并且填入之后，在 WordPress 后台公众号设置再保存一次。';
				}elseif($errcode == '40125' || $errcode == '40001'){
					$errmsg	= '公众号ID或者密钥错误，请到公众号后台获取重新输入。';
				}else{
					$errmsg	= '';
				}

				if($errmsg){
					if(!$force){
						weixin_update_setting('api_disabled', compact('errcode', 'errmsg'), $this->appid);

						wpjam_add_admin_notice(['type'=>'error', 'notice'=>$errmsg]);
					}

					return new WP_Error($errcode, $errmsg);
				}

				return $response;
			}

			$response['expires_at']	= array_pull($response, 'expires_in')+time()-600;

			if(!$force){
				weixin_update_setting('access_token', $response, $this->appid);
			}
		}

		return $response;
	}

	public function post($path, $data=[]){
		return $this->http_request($path, ['method'=>'POST',	'body'=>$data]);
	}

	public function get($path, $args=[]){
		$args	= array_filter($args);
		$path	= $args ? add_query_arg($args, $path) : $path;

		return $this->http_request($path);
	}

	public function file($path, $media, $data=[]){
		$name		= array_pull($data, 'name') ?: 'media';
		$filename	= array_pull($data, 'filename') ?: basename($media);
		$timeout	= array_pull($data, 'timeout') ?: 5;
		$data		= array_merge($data, [$name => new CURLFile($media, '', $filename)]);

		return $this->http_request($path, [
			'method'	=> 'FILE',
			'timeout'	=> $timeout,
			'body'		=> $data
		]);
	}

	public function http_request($path, $args=[]){
		$args = wp_parse_args( $args, [
			'json_encode_required'	=> true
		]);

		if(array_pull($args, 'using_secret')){
			$path	.= '&appid='.$this->appid.'&secret='.$this->secret;
		}elseif(!str_starts_with($path, '/sns/')){
			$response = $this->get_access_token();

			if(is_wp_error($response)){
				return $response;
			}

			$path	= add_query_arg(['access_token'=>$response['access_token']], $path);
			$path	= str_replace('%40', '@', $path);
		}

		if(!str_starts_with($path, 'http')){
			$path	= $this->api_host.$path;
		}

		$response	= wpjam_http_request($path, $args);

		if(is_wp_error($response)){
			$errcode	= $response->get_error_code();

			if($errcode == '48001'){
				return new WP_Error($errcode, '微信公众号号没有该接口权限 ｜ '.$response->get_error_message());
			}elseif($errcode == '40001' || $errcode == '40014' || $errcode == '42001'){
				// 40001 获取access_token时AppSecret错误，或者access_token无效。请开发者认真比对AppSecret的正确性，或查看是否正在为恰当的公众号调用接口
				// 40014 不合法的access_token，请开发者认真比对access_token的有效性（如是否过期），或查看是否正在为恰当的公众号调用接口
				// 42001 access_token超时，请检查access_token的有效期，请参考基础支持-获取access_token中，对access_token的详细机制说明
				weixin_delete_setting('access_token', $this->appid);
			}elseif($errcode == '50002'){
				// 50002 用户受限，可能是违规后接口被封禁
				// $robot_option = wpjam_get_option('weixin-robot');
				// $robot_option['weixin_type'] = -1;
				// update_option('weixin-robot', $robot_option);
			}
		}

		return $response;
	}

	public static function get_instance($appid, $secret){
		$instance	= self::instance_exists($appid);

		return $instance ?: self::add_instance($appid, new self($appid, $secret));
	}
}

class WEIXIN_Setting extends WPJAM_Model{
	const SOURCE_DEFAULT = 0;
	const SOURCE_AUTHORIZE = 1;

	protected static function validate_data($data, $appid=''){
		if($appid){
			$current	= self::get($appid);

			if(!$current){
				return new WP_Error('weixin_setting_not_exists', '系统中没有你更新的公众号，可能已经被删除了。');
			}

			if(empty($current['component_blog_id']) && empty($data['component_blog_id']) && isset($data['secret'])){
				if($data['secret'] != $current['secret'] && !weixin_exists($appid, $data['secret'])){
					return new WP_Error('weixin_not_exists', '输入的 appid 和 secret 有误，请仔细核对！');
				}
			}
		}else{
			if(empty($data['blog_id'])){
				return new WP_Error('empty_blog_id', 'blog_id 不能为空');
			}

			if(self::get_by('blog_id', $data['blog_id'])){
				return new WP_Error('weixin_exists', '该站点已经绑定了微信公众号');
			}

			if(empty($data['component_blog_id'])){
				$appid	= $data['appid'];
				$secret	= $data['secret'];

				if(!weixin_exists($appid, $secret)){
					return new WP_Error('weixin_not_exists', '输入的 appid 和 secret 有误，请仔细核对！');
				}
			}
		}
		return true;
	}

	protected static function sanitize_data($data, $appid=''){
		if($appid){
			unset($data['blog_id']);	// 不能修改 blog_id
		}else{
			$data['time']	= time();
		}

		return $data;
	}

	public static function delete($appid){
		$current = self::get($appid);

		if(!$current){
			return new WP_Error('weixin_setting_not_exists', '系统中没有你更新的公众号，可能已经被删除了。');
		}

		if($current['blog_id']){
			delete_blog_option($current['blog_id'], 'weixin-robot');
		}

		$wpdb	= $GLOBALS['wpdb'];
		$table	= $wpdb->base_prefix . 'weixin_'.$appid.'_users';

		$wpdb->query("DROP TABLE {$table}");

		return parent::delete($appid);
	}

	public static function query_items($args){
		list('items'=>$items, 'total'=>$total) = parent::query_items($args);

		if($items){
			_prime_site_caches(wp_list_pluck($items, 'blog_id'));
		}

		return compact('items', 'total');
	}

	public static function render_item($item){
		$detail	= get_blog_details($item['blog_id']);

		if($detail){
			$blogname		= $detail->blogname ?: '<span class="blue">未命名站点</span>';
			$item['name']	= $item['name'] ?: $blogname;
			$item['name']	= '<a href="'.get_admin_url($item['blog_id'], 'page=weixin-settings').'">'.$item['name'].'</a>';
			$item['blog']	= $blogname;
		}else{
			$item['name']	= '<span class="red">站点已经删除</span>';
		}

		$item['time']	= wpjam_date('Y-m-d H:i:s', $item['time']);

		return $item;
	}

	public static function get_actions(){
		return ['delete'	=> ['title'	=>'删除',	'confirm'=>true,	'direct'=>true]];
	}

	public static function get_fields($action_key='', $id=''){
		return [
			'name'		=> ['title'=>'公众号名',	'type'=>'text',	'show_admin_column'=>true, 	'required'],
			'appid'		=> ['title'=>'公众号ID',	'type'=>'text',	'show_admin_column'=>true,	'required'],
			// 'secret'	=> ['title'=>'公众号密钥','type'=>'text',	'required'],
			'blog'		=> ['title'=>'所属站点',	'type'=>'text',	'show_admin_column'=>'only',	'value'=>get_current_blog_id()],
			// 'component_blog_id'	=> ['title'=>'第三方平台',	'type'=>'text',	'show_admin_column'=>'only'],
			'time'		=> ['title'=>'添加时间',	'type'=>'view',	'show_admin_column'=>'only',	'sortable_column'=>true],
		];
	}

	public static function get_handler(){
		$table		= self::get_table();
		$handler	= wpjam_get_handler($table);

		return $handler ?: wpjam_register_handler([
			'table_name'		=> $table,
			'primary_key'		=> 'appid',
			'cache_key'			=> 'blog_id',
			'cache_group'		=> ['weixin_settings', true],
			'field_types'		=> ['blog_id'=>'%d', 'time'=>'%d'],
			'searchable_fields'	=> ['appid', 'name'],
			'filterable_fields'	=> ['component_blog_id'],
		]);
	}

	public static function get_table(){
		return $GLOBALS['wpdb']->base_prefix.'weixins';
	}

	public static function create_table(){
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		$table	= self::get_table();

		if($GLOBALS['wpdb']->get_var("show tables like '{$table}'") != $table) {
			$sql = "
			CREATE TABLE IF NOT EXISTS `{$table}` (
				`blog_id` bigint(20) NOT NULL,
				`name` varchar(255) NOT NULL,
				`appid` varchar(32) NOT NULL,
				`secret` varchar(40) NOT NULL,
				`type` varchar(7) NOT NULL,
				`component_blog_id` bigint(20) NOT NULL DEFAULT 0,
				`time` int(10) NOT NULL,

				PRIMARY KEY	(`appid`),
				KEY `type` (`type`),
				KEY `blog_id` (`blog_id`)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			";
	 
			dbDelta($sql);
		}
	}

	public static function get_list_table(){
		return [
			'title'			=> '公众号',
			'singular'		=> 'weixin-setting',
			'plural'		=> 'weixin-settings',
			'model'		 	=> self::class,
			'primary_key'	=> 'appid'
		];
	}
}