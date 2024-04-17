<?php
class WEIXIN_Hook{
	public static function get_sections(){
		if(is_wp_error(get_option('weixin-robot'))){
			delete_option('weixin-robot');
		}

		$sections	= [];

		$sections['weixin']	= ['title'=>'微信设置',	'fields'=>[
			'weixin_app_id'		=> ['title'=>'AppID',		'type'=>'text',		'class'=>'',	'required',	'description'=>'应用ID'],
			'weixin_app_secret'	=> ['title'=>'Secret',		'type'=>'password',	'class'=>'all-options',	'required',	'description'=>'应用密钥'],
			'weixin_id'			=> ['title'=>'微信号',   	'type'=>'text', 	'class'=>'',	'description'=>'公众号微信号，用于生成公众号二维码'],
			'weixin_type'		=> ['title'=>'公众号类型',	'type'=>'select',	'options'=>['-1'=>' ','1'=>'订阅号','2'=>'服务号','3'=>'认证订阅号','4'=>'认证服务号']],
			'weixin_reply'		=> ['title'=>'自定义回复',	'type'=>'checkbox',	'class'=>'show-if-key',	'description'=>'开启自定义回复功能，在 WordPress 后台自定义公众号关键字回复。']
		]];

		if(weixin_get_appid()){
			$sections['reply']	= ['title'=>'回复设置',	'show_if'=>['key'=>'weixin_reply', 'value'=>1],	'fields'=>[
				'weixin_url'			=> ['title'=>'服务器地址',		'type'=>'view',		'value'=>home_url('/weixin/reply/')],
				'weixin_message_mode'	=> ['title'=>'消息加解密方式',		'type'=>'view',		'value'=>'请在微信公众号后台选用<strong>安全模式</strong>。'],
				'weixin_token'			=> ['title'=>'Token(令牌)',		'type'=>'text',		'class'=>'all-options'],
				'weixin_encodingAESKey'	=> ['title'=>'EncodingAESKey',	'type'=>'text',		'description'=>'请输入兼容或者安全模式下的消息加解密密钥'],
				'weixin_keyword_length'	=> ['title'=>'关键字最大长度',		'type'=>'number',	'class'=>'small-text',	'description'=>'一个汉字算两个，一个英文单词算两个，空格不算，搜索多个关键字可以用空格分开！',	'min'=>8,	'max'=>20,	'step'=>2,	'value'=>10],
				'weixin_search'			=> ['title'=>'文章搜索回复',		'type'=>'checkbox',	'description'=>'开启<strong>博客文章搜索</strong>，在自定义回复和内置回复没有相关的关键字，微信机器人会去搜索博客文章。'],
				'weixin_text_search'	=> ['title'=>'搜索结果',			'type'=>'checkbox',	'show_if'=>['key'=>'weixin_search', 'value'=>1],	'description'=>'文章搜索结果使用文本而非图文的方式回复。'],
				'weixin_search_url'		=> ['title'=>'图文地址',			'type'=>'checkbox',	'show_if'=>['key'=>'weixin_search', 'value'=>1],	'description'=>'搜索结果多余一篇文章跳转搜索结果页面或者分类/标签列表页。'],
				'weixin_realtime_message'	=> ['title'=>'实时消息',		'type'=>'checkbox',	'description'=>'用户发送给公众号的消息实时写入数据库。'],
				'weixin_permanent_message'	=> ['title'=>'永久消息',		'type'=>'checkbox',	'description'=>'用户发送给公众号的消息永久保存，不勾选则保留30天。']
			]];

			if(!wp_using_ext_object_cache()){
				unset($sections['reply']['fields']['weixin_realtime_message']);
			}

			if(is_multisite()){
				$sections['weixin']['fields']['weixin_app_id']['type']	= 'view';
			}

			$sections	= apply_filters('weixin_setting', $sections);
		}else{
			unset($sections['weixin']['fields']['weixin_reply']);
		}

		return $sections;
	}

	public static function sanitize_callback($value){
		$appid	= $value['weixin_app_id'];
		$secret	= $value['weixin_app_secret'];
		$weixin	= new WEIXIN($appid, $secret);

		$value['access_token']	= wpjam_try([$weixin, 'get_access_token'], true);

		if(isset($value['api_disabled'])){
			unset($value['api_disabled']);
		}

		if(is_multisite()){
			$exist	= WEIXIN_Setting::get($appid);

			if($exist){
				if($exist['blog_id'] != get_current_blog_id()){
					return new WP_Error('error', '该公众号已经绑定其他站点。');
				}

				$result	= WEIXIN_Setting::update($appid, ['secret'=>$secret]);
			}else{
				$result	= WEIXIN_Setting::insert(['appid'=>$appid, 'secret'=>$secret, 'blog_id'=>get_current_blog_id()]);
			}

			if(is_wp_error($result)){
				return $result;
			}
		}

		if(weixin_get_appid() == $appid){
			weixin_activation();
		}

		return $value;
	}

	public static function add_hooks(){
		add_filter('get_avatar_data',	[self::class, 'filter_avatar_data']);
		add_filter('the_comments',		[self::class, 'filter_comments']);

		if(is_weixin()){
			add_filter('wpjam_current_user',	[self::class, 'filter_current_user']);
		}

		if(is_null(get_option('weixin-extends', null))){
			$appid	= weixin_get_appid();

			if($appid){
				$value	= get_option('weixin_'.$appid.'_extends');

				if($value){
					update_option('weixin-extends', $value);
					delete_option('weixin_'.$appid.'_extends');
				}
			}
		}

		wpjam_register_extend_type('weixin-extends', dirname(__DIR__).'/extends', [
			'hook'		=> 'init',
			'menu_page'	=> [
				'parent'		=> 'weixin',
				'menu_title'	=> '扩展管理',
				'order'			=> 3,
				'function'		=> 'option',
			]
		]);
	}

	public static function filter_current_user($current_user){
		if(!weixin_get_setting()){
			return $current_user;
		}

		$openid	= weixin_get_current_openid();

		if(is_wp_error($openid)){
			return $openid;
		}

		$appid		= weixin_get_current_appid();
		$account	= weixin_get_user_object($openid, $appid);

		if($account){
			$current_user	= $account->parse_for_json();

			$user_id	= $account->user_id ?? 0;
			$wp_user 	= $user_id ? get_userdata($user_id) : null;

			if(!$user_id || !$wp_user){
				$current_user['user_id']	= 0;
			}

			$current_user['nickname']	= $current_user['nickname'] ?: $openid;
		}else{
			$current_user['nickname']	= $openid;
		}

		$current_user['platform']	= 'weixin';
		$current_user['user_email']	= $openid.'@'.$appid.'.weixin';

		return $current_user;
	}

	public static function filter_comments($comments){
		$openids_list	= [];

		foreach($comments as $comment){
			$email	= $comment->comment_author_email;

			if(strrpos($email, '.weixin')){
				$parts	= explode('@', $email);
				$openid	= $parts[0];
				$appid	= wpjam_remove_postfix($parts[1], '.weixin');

				$openids_list[$appid]	= $openids_list[$appid] ?? [];
				$openids_list[$appid][]	= $openid;
			}
		}

		if($openids_list){
			foreach($openids_list as $appid => $openids){
				// WEIXIN_Account::update_caches($appid, $openids);
				wpjam_lazyload('weixin_account', $openids, $appid);
			}
		}

		return $comments;
	}

	public static function filter_avatar_data($args){
		$email	= $args['email'] ?? '';

		if($email && str_ends_with($email, '.weixin')){
			$parts	= explode('@', $email);
			$openid	= $parts[0];
			$appid	= wpjam_remove_postfix($parts[1], '.weixin');
			$user	= weixin_get_user_object($openid, $appid);
			$url	= $user ? $user->headimgurl : '';

			if($url){
				$args['url']	= $url;
			}
		}

		return $args;
	}

	public static function create_table(){
		include __DIR__.'/weixin-reply.php';

		WEIXIN_Setting::create_table();
		WEIXIN_Message::create_table();
		WEIXIN_Reply_Setting::create_table();

		$appid	= weixin_get_current_appid();

		if($appid){
			WEIXIN_Account::create_table($appid);

			do_action('weixin_activation', $appid);
		}
	}

	public static function redirect($action){
		if($action == 'reply'){
			include dirname(__DIR__).'/template/'.$action.'.php';
		}
	}

	public static function non_exists(){
		$options	= ['-1'=>' ','1'=>'订阅号','2'=>'服务号','3'=>'认证订阅号','4'=>'认证服务号'];
		$type		= weixin_get_type();

		echo wpautop($options[$type].'没有「'.wpjam_get_plugin_page_setting('menu_title').'」接口权限');
	}

	public static function get_menu_page(){
		wp_add_inline_style('list-tables', "\n".implode("\n", [
			'#adminmenu div.dashicons-weixin{background-repeat: no-repeat; background-position: center; background-size: 20px auto; background-image: url('.WEIXIN_ROBOT_PLUGIN_URL.'static/icon.svg) !important;}',
			'#adminmenu .wp-has-current-submenu div.dashicons-weixin{background-image: url('.WEIXIN_ROBOT_PLUGIN_URL.'static/icon-active.svg) !important;}'
		])."\n");

		$subs	= [];

		if(weixin_get_appid()){
			$subs['weixin']	= [
				'menu_title'	=> '数据预览',
				'function'		=> 'dashboard',
				'widgets'		=> ['WEIXIN_Stats', 'get_widgets'],
				'page_file'		=> [
					__DIR__.'/weixin-reply.php',
					__DIR__.'/weixin-stats.php'
				]
			];

			if(weixin_has_feature('weixin_reply')){
				$subs['weixin-replies']	= [
					'menu_title'	=> '自定义回复',
					'function'		=> 'tab',
					'tabs'			=> ['WEIXIN_Reply_Setting', 'get_tabs'],
					'page_file'		=> __DIR__.'/weixin-reply.php'
				];
			}

			if(weixin_get_type() >= 2){
				$subs['weixin-menu']	= [
					'menu_title'	=> '自定义菜单',
					'function'		=> 'tab',
					'tabs'			=> ['WEIXIN_Menu', 'get_tabs'],
					'page_file'		=> [
						__DIR__.'/weixin-reply.php',
						__DIR__.'/weixin-menu.php'
					]
				];
			}else{
				$subs['weixin-menu']	= [
					'menu_title'	=> '自定义菜单',
					'function'		=> [self::class, 'non_exists']
				];
			}

			$subs['weixin-material']	= [
				'menu_title'	=> '素材管理',
				'function'		=> 'tab',
				'tabs'			=> ['WEIXIN_Material', 'get_tabs'],
				'page_file'		=> [
					__DIR__.'/weixin-reply.php',
					__DIR__.'/weixin-material.php'
				]
			];

			$subs['weixin-draft']	= [
				'menu_title'	=> '草稿管理',
				'function'		=> 'tab',
				'tabs'			=> ['WEIXIN_Draft', 'get_tabs'],
				'page_file'		=> [
					__DIR__.'/weixin-reply.php',
					__DIR__.'/weixin-material.php'
				]
			];

			if(weixin_get_type() >= 3){
				$subs['weixin-users']	= [
					'menu_title' 	=> '用户管理',
					'function'		=> 'tab',
					'tabs'			=> ['WEIXIN_User', 'get_tabs'],
					'page_file'		=>__DIR__.'/weixin-reply.php',
				];

				if(weixin_get_type() >= 4){
					$subs['weixin-qrcodes']	= [
						'menu_title' 	=> '渠道管理',
						'order'			=> 9,
						'function'		=> 'list',
						'list_table'	=> 'WEIXIN_Qrcode',
						'page_file'		=> [
							__DIR__.'/weixin-reply.php',
							__DIR__.'/weixin-qrcode.php',
						]
					];
				}
			}else{
				$subs['weixin-users']	= [
					'menu_title' 	=> '用户管理',
					'function'		=> [self::class, 'non_exists']
				];

				$subs['weixin-jssdk']	= [
					'menu_title' 	=> '网页分享',
					'function'		=> [self::class, 'non_exists']
				];
			}

			$subs['weixin-stats']	= [
				'menu_title'	=> '数据统计',
				'order'			=> 4,
				'chart'			=> ['show_date_type'=>true],
				'function'		=> 'tab',
				'tabs'			=> ['WEIXIN_Stats', 'get_tabs'],
				'page_file'		=> [
					__DIR__.'/weixin-reply.php',
					__DIR__.'/weixin-stats.php'
				]
			];

			$subs['weixin-develop']	= [
				'menu_title'	=> '开发管理',
				'order'			=> 3,
				'function'		=> 'tab',
				'tabs'			=> ['WEIXIN_Develop', 'get_tabs'],
				'page_file'		=> __DIR__.'/weixin-develop.php',
			];

			$subs['weixin-setting']	= [
				'menu_title'	=> '公众号设置',
				'order'			=> 2,
				'function'		=> 'option',
				'option_name'	=> 'weixin-robot',
				'option'		=> ['model'=>self::class, 'ajax'=> false]
			];
		}else{
			$subs['weixin'] = [
				'menu_title'	=> '微信公众号',
				'order'			=> 2,
				'function'		=> 'option',
				'option_name'	=> 'weixin-robot',
				'option'		=> ['model'=>self::class, 'ajax'=> false]
			];
		}

		if(is_multisite() && current_user_can('manage_sites')){
			$subs['weixin-settings']	= [
				'menu_title'	=> '所有公众号',
				'order'			=> 1,
				'function'		=> 'list',
				'capability'	=> 'manage_sites',
				'list_table'	=> 'WEIXIN_Setting',
			];
		}

		return [
			'menu_slug'		=> 'weixin',
			'menu_title'	=> '微信公众号',
			'network'		=> false,
			'icon'			=> 'dashicons-weixin',
			'position'		=> '3.91',
			'subs'			=> $subs
		];
	}

	public static function register_json($json){
		if(in_array($json, ['weixin.access_token', 'weixin.verify', 'weixin.user'])){
			$args	= ['callback'=>[self::class, 'json_callback']];

			if($json == 'weixin.access_token'){
				$args['grant']	= true;
				$args['quota']	= 100;
			}

			wpjam_register_json($json, $args);
		}
	}

	public static function json_callback(){
		$json	= wpjam_get_current_json();

		if($json == 'weixin.access_token'){
			$response	=  weixin()->get_access_token();

			if(is_wp_error($response)){
				return $response;
			}

			$response['expires_in']	= $response['expires_at'] - time();
		}elseif($json == 'weixin.verify'){
			require_once ABSPATH . WPINC . '/class-phpass.php';

			$hasher	= new PasswordHash(8, true);

			if($hash = wpjam_get_parameter('hash',	['method'=>'POST'])){
				$openid	= wpjam_get_parameter('openid',	['method'=>'POST',	'required'=>true]);

				if(!$hasher->CheckPassword($openid, $hash)){
					return new WP_Error('invalid_openid');
				}

				$account	= weixin_get_user_object($openid);
			}else{
				$code		= wpjam_get_parameter('code',	['method'=>'POST',	'required'=>true]);
				$account	= weixin_get_user_by('verify_code', $code);

				if(is_wp_error($account)){
					return $account;
				}

				$openid		= $account->openid;
			}

			$user		= $account->parse_for_json();
			$hash		= $hasher->HashPassword($openid);
			$response	= $user+['hash'=>$hash];
		}elseif($json == 'weixin.user'){
			$openid	= weixin_get_current_openid();

			if(is_wp_error($openid)){
				return $openid;
			}

			$account	= weixin_get_user_object($openid);
			$response	= $account->parse_for_json();
		}

		wpjam_send_json($response);
	}
}
