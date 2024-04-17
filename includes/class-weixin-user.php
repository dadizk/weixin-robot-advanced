<?php
class WEIXIN_Account{
	use WPJAM_Instance_Trait;

	private $appid;
	private $openid;

	private function __construct($appid, $openid){
		$this->appid	= $appid;
		$this->openid	= $openid;
	}

	public function __get($key){
		if(in_array($key, ['appid', 'openid'])){
			return $this->$key;
		}elseif($key == 'data'){
			return self::get($this->appid, $this->openid);
		}else{
			return $this->data[$key] ?? null;
		}
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function save($data){
		if(isset($data['openid']) && $data['openid'] != $this->openid){
			return new WP_Error('openid_modified_not_allowed', '不能修改 openid');
		}

		$data	= array_except($data, ['appid', 'blog_id']);
		$data	= array_merge($data, ['last_update'=>time()]);

		return $data ? self::update($this->appid, $this->openid, $data) : true;
	}

	public function refresh(){
		$result	= self::sync($this->appid, $this->openid);

		return is_wp_error($result) ? $result : true;
	}

	public function tag($tagid_list){
		$tagid_list	= wp_is_numeric_array($tagid_list) ? $tagid_list : ($tagid_list['tagid_list'] ?? []);
		$current	= $this->tagid_list;
		$untagging	= array_diff($current, $tagid_list);
		$tagging 	= array_diff($tagid_list, $current);
		$weixin		= weixin($this->appid);

		foreach($untagging as $tagid){
			$result = $weixin->post('/cgi-bin/tags/members/batchuntagging', ['openid_list'=>[$this->openid],'tagid'=>$tagid]);

			if(is_wp_error($result)){
				return $result;
			}
		}

		foreach($tagging as $tagid){
			$result = $weixin->post('/cgi-bin/tags/members/batchtagging', ['openid_list'=>[$this->openid],'tagid'=>$tagid]);

			if(is_wp_error($result)){
				return $result;
			}
		}

		return $this->refresh();
	}

	public function black(){
		$weixin	= weixin($this->appid);
		$weixin->cache_delete('blacklist');

		return $weixin->post('/cgi-bin/tags/members/batchblacklist', ['openid_list'=>[$this->openid]]);
	}

	public function unblack(){
		$weixin		= weixin($this->appid);
		$weixin->cache_delete('blacklist');

		return $weixin->post('/cgi-bin/tags/members/batchunblacklist', ['openid_list'=>[$this->openid]]);
	}

	public function in_blacklist(){
		$blacklist	= self::get_blacklist($this->appid);

		return $blacklist ? in_array($this->openid, $blacklist) : false;
	}

	public function remark($remark){
		$data	= is_array($remark) ? $remark : ['remark'=>$remark];
		$result	= weixin($this->appid)->post('/cgi-bin/user/info/updateremark', array_merge($data, ['openid'=>$this->openid]));

		return is_wp_error($result) ? $result : $this->refresh();
	}

	public function send_message($data=[], $type='custom'){
		if(!$this->subscribe){
			return new WP_Error('subscribe_required', '无法发送给未订阅用户。');
		}

		if($this->in_blacklist()){
			return new WP_Error('blacklist_openid', '无法发送给黑名单中用户。');
		}

		$data['touser']	= $this->openid;

		if($type == 'template'){
			$data	= wp_parse_args($data, [
				'template_id'	=> '',
				'url'			=> '',
				'miniprogram'	=> [],
				'data'			=> [],
			]);

			$result	= weixin($this->appid)->post('/cgi-bin/message/template/send', $data);

			if(is_wp_error($result) && $result->get_error_code() == '43004'){
				$this->save(['subscribe'=>0]);
			}

			return $result;
		}else{
			return weixin($this->appid)->post('/cgi-bin/message/custom/send', $data);
		}
	}

	public function parse_for_json(){
		if(!$this->data){
			return [];
		}

		$avatar	= str_replace('/0', '/132', $this->headimgurl);
		$json	= [
			'openid'			=> $this->openid,
			'unionid'			=> $this->unionid,
			'subscribe'			=> (int)$this->subscribe,
			'subscribe_time'	=> (int)$this->subscribe_time,
			'nickname'			=> $this->nickname,
			'avatarurl'			=> $avatar,
			'headimgurl'		=> $avatar,
			'remark'			=> $this->remark,
			'language'			=> (string)$this->language,
			'user_id'			=> (int)$this->user_id,
			'appid'				=> $this->appid,
			'blog_id'			=> (int)$this->blog_id,
		];

		return doing_filter('weixin_user_json') ? $json : apply_filters('weixin_user_json', $json, $this->openid);
	}

	public function generate_access_token($cookie=false){
		$token	= wpjam_generate_jwt([
			'appid'		=> $this->appid,
			'openid'	=> $this->openid,
			'exp'		=> time()+WEEK_IN_SECONDS,
			'token'		=> self::get_auth_token($this->appid),
		]);

		if($cookie){
			$this->set_cookie($token);
		}

		return $token;
	}

	public function set_cookie($token){
		wpjam_set_cookie('weixin_access_token', $token);

		$_COOKIE['weixin_access_token'] = $token;
	}

	public function generate_verify_code(){
		while(true){
			$code	= rand(100000, 999999);
			$exist	= self::cache_get($this->appid, 'verify_code:'.$code);

			if($exist === false){
				break;
			}
		}

		$expired	= MINUTE_IN_SECONDS*6;
		$data		= ['openid'=>$this->openid, 'appid'=>$this->appid, 'expired_time'=>time()+$expired];
		$current	= self::cache_get($this->appid, 'verify_code:'.$this->openid);

		if($current !== false){
			self::cache_delete($this->appid, 'verify_code:'.$current);
		}

		self::cache_set($this->appid, 'verify_code:'.$code, $data, $expired);
		self::cache_set($this->appid, 'verify_code:'.$this->openid, $code, $expired);

		return $code;
	}

	public static function get_auth_token($appid){
		$auth_token	= weixin_get_setting('auth_token', $appid);

		if(!$auth_token){
			$auth_token	= wp_generate_password(8, false, false);

			weixin_update_setting('auth_token', $auth_token, $appid);
		}

		return $auth_token;
	}

	public static function get_by_access_token($appid, $token){
		$openid	= self::get_openid_by_access_token($appid, $token);

		return is_wp_error($openid) ? $openid : self::get_instance($appid, $openid);
	}

	public static function get_openid_by_access_token($appid, $token){
		$data	= wpjam_verify_jwt($token);

		if($data === false 
			|| empty($data['openid']) 
			|| empty($data['token']) 
			|| $data['token'] != self::get_auth_token($appid)
		){
			return new WP_Error('illegal_access_token', 'Token 非法或已过期！');
		}

		if(empty($data['appid']) || ($data['appid'] != $appid)){
			return new WP_Error('illegal_appid', 'appid 不匹配！');
		}

		return $data['openid'];
	}

	public static function get_openid_by_verify_code($appid, $code){
		$data	= $code ? self::cache_get($appid, 'verify_code:'.$code) : false;
		$openid	= is_array($data) ? ($data['openid'] ?? '') : '';

		if(!$openid){
			return new WP_Error('illegal_verify_code');
		}

		if(empty($data['appid']) || ($data['appid'] != $appid)){
			return new WP_Error('illegal_appid', 'appid 不匹配！');
		}

		$verify	= self::cache_get($appid, 'verify_code:'.$openid);

		if($verify === false || $verify != $code){
			return new WP_Error('illegal_verify_code');
		}

		self::cache_delete($appid, 'verify_code:'.$openid);
		self::cache_delete($appid, 'verify_code:'.$code);

		return $openid;
	}

	public static function get($appid, $openid){
		if(!$openid || strlen($openid) < 28 || strlen($openid) > 34){
			trigger_error($openid);
			return false;
		}

		$cache	= self::get_by_subscribe($appid, $openid);
		$data	= self::get_by_handler($appid, $openid);

		if($data){
			$data	= array_merge($data, $cache);

			$data['tagid_list']	= $data['tagid_list'] ? explode(',', $data['tagid_list']) : [];
		}else{
			$data	= $cache;
		}

		if($data){
			$data['appid']		= $appid;
			$data['blog_id']	= get_current_blog_id();
		}

		return $data;
	}

	public static function sync($appid, $openid, $data=null){
		$openid	= trim($openid);

		if(weixin_get_type($appid) >= 3){
			if(!$data){
				$weixin	= weixin($appid);
				$lock	= 'user_lock:'.$openid;

				if($weixin->cache_get($lock) !== false){
					return false;
				}

				$weixin->cache_set($lock, true, 1);	// 1 秒的内存锁，防止重复远程请求微信用户资料

				$data	= $weixin->get('/cgi-bin/user/info', ['openid'=>$openid]);

				if(is_wp_error($data)){
					return $data;
				}
			}

			$data	= self::sanitize_data($appid, $data, $openid);
			$result	= self::insert($appid, $data);

			return is_wp_error($result) ? $result : true;
		}

		return true;
	}

	public static function sanitize_data($appid, $data, $openid){
		if(weixin_get_type($appid) >= 3){
			$data	= array_except($data, ['sex', 'city', 'province', 'country', 'groupid']);

			if(isset($data['subscribe'])){
				if($data['subscribe']){
					foreach(['nickname', 'headimgurl'] as $key){
						if(isset($data[$key]) && empty($data[$key])){
							unset($data[$key]);
						}
					}

					if(isset($data['nickname'])){
						$data['nickname']	= mb_substr(wpjam_strip_invalid_text($data['nickname']), 0, 254);
					}

					if(isset($data['tagid_list']) && is_array($data['tagid_list'])){
						$data['tagid_list']	= implode(',', $data['tagid_list']);
					}

					$data['last_update']	= time();
				}else{
					$data	= ['subscribe'=>0];
				}	
			}
		}

		return array_merge($data, ['openid'=>$openid]);
	}

	public static function batch_get_user_info($appid, $openids, $force=false){
		$openids	= array_unique($openids);
		$openids	= array_filter($openids);
		$openids	= array_values($openids);

		if($force === false){	// 先从内存和数据库中取
			$users	= self::get_by_ids($appid, $openids);
			$users	= array_filter($users, function($user){
				return is_array($user) && isset($user['last_update']) && $user['last_update'] > time() - MONTH_IN_SECONDS*3;
			});

			if(count($users) >= count($openids)){
				return $users;
			}
		}

		$user_list	= array_map(function($openid){ return ['openid'=>$openid, 'lang'=>'zh_CN']; }, $openids);
		$response	= weixin($appid)->post('/cgi-bin/user/info/batchget', ['user_list'=>$user_list]);

		if(is_wp_error($response)){
			return $response;
		}

		if($response && isset($response['user_info_list'])){
			$subscribes	= $unsubscribes	= [];

			foreach($response['user_info_list'] as $user){
				if($user['subscribe']){
					$subscribes[]	= self::sanitize_data($appid, $user, $user['openid']);
				}else{
					$unsubscribes[]	= $user;
				}
			}

			if($subscribes){
				self::insert_multi($appid, $subscribes);
			}

			if($unsubscribes){
				self::insert_multi($appid, $unsubscribes);
			}
		}

		return self::get_by_ids($appid, $openids);
	}

	public static function get_tags($appid, $output=''){
		$weixin	= weixin($appid);
		$tags	= $weixin->cache_get('user_tags');

		if($tags === false){
			$response	= $weixin->get('/cgi-bin/tags/get');

			if(is_wp_error($response)){
				$tags	= [];
				$time	= MONTH_IN_SECONDS*5;
			}else{
				$tags	= $response['tags'];
				$time	= DAY_IN_SECONDS*10;
			}

			$weixin->cache_set('user_tags', $tags, $time);
		}

		return $output == 'options'	? wp_list_pluck($tags, 'name', 'id') : $tags;
	}

	public static function create_tag($appid, $name){
		$weixin		= weixin($appid);

		$weixin->cache_delete('user_tags');

		return $weixin->post('/cgi-bin/tags/create', ['tag'=>['name'=>$name]]);
	}

	public static function update_tag($appid, $tag_id, $name){
		$weixin	= weixin($appid);

		$weixin->cache_delete('user_tags');

		return $weixin->post('/cgi-bin/tags/update', ['tag'=>['id'=>(int)$tag_id,'name'=>$name]]);
	}

	public static function delete_tag($appid, $tag_id){
		$weixin	= weixin($appid);

		$weixin->cache_delete('user_tags');

		return $weixin->post('/cgi-bin/tags/delete', ['tag'=>['id'=>(int)$tag_id]]);
	}

	public static function bulk_tag($appid, $openid_list, $tagid_list){
		$weixin			= weixin($appid);
		$openid_list	= (array)$openid_list;
		$tagid_list		= wp_is_numeric_array($tagid_list) ? $tagid_list : $tagid_list['tagid_list'];

		foreach($tagid_list as $tagid){
			$result = $weixin->post('/cgi-bin/tags/members/batchtagging', compact('openid_list','tagid'));

			if(is_wp_error($result)){
				return $result;
			}
		}

		return self::batch_get_user_info($appid, $openid_list, true);
	}

	public static function get_blacklist($appid, $next_openid=''){
		$weixin	= weixin($appid);

		if(!$next_openid){
			$blacklist	= $weixin->cache_get('blacklist');
		}

		if($next_openid || $blacklist === false){
			$response	= $weixin->post('/cgi-bin/tags/members/getblacklist', compact('next_openid'));

			if(is_wp_error($response)){
				return [];
			}

			if($response['total']){
				$blacklist	= $response['data']['openid'];

				if($response['total'] > $response['count']){
					$next_openid	= $response['next_openid'];
					// 继续获取功能以后再写，谁有一万个黑名单用户的时候，我一定写。
				}
			}else{
				$blacklist	= [];
			}

			if($next_openid == ''){
				$weixin->cache_set('blacklist', $blacklist, HOUR_IN_SECONDS);
			}
		}

		return $blacklist;
	}

	public static function create($appid, $data){
		$openid	= $data['openid'] ?? '';

		if(empty($openid) || strlen($openid) < 28 || strlen($openid) > 34){
			return new WP_Error('invalid_openid');
		}

		$cache	= self::get_by_subscribe($appid, $openid);
		$data	= array_merge($data, $cache, ['last_update'=>time()]);
		$result	= self::insert($appid, $data);

		return is_wp_error($result) ? $result : self::get_instance($appid, $openid);
	}

	protected static function get_subscribes($appid){
		$subscribes	= self::cache_get($appid, 'subscribes');

		return $subscribes ?: ['time'=>time(), 'users'=>[]];
	}

	protected static function get_by_subscribe($appid, $openid){
		$subscribes	= self::get_subscribes($appid);

		if($subscribes && isset($subscribes['users'][$openid])){
			return $subscribes['users'][$openid];
		}

		return [];
	}

	public static function subscribe($appid, $openid, $subscribe=1){
		$subscribes	= self::get_subscribes($appid);

		$subscribes['users'][$openid]	= [
			'openid'			=> trim($openid),
			'subscribe'			=> $subscribe,
			'last_update'		=> time(),
			'unsubscribe_time'	=> $subscribe ? 0 : time(),
		];

		if(count($subscribes['users']) < 20 && (time()-$subscribes['time'] < 300)){
			self::cache_set($appid, 'subscribes', $subscribes, DAY_IN_SECONDS);
		}else{
			// 达到了 20 个用户或者过了5分钟再去写数据库
			self::cache_delete($appid, 'subscribes');
			self::insert_multi($appid, array_values($subscribes['users']));
		}
	}

	public static function unsubscribe($appid, $openid){
		self::subscribe($appid, $openid, 0);
	}

	public static function get_instance($appid, $openid){
		$check	= apply_filters('weixin_get_account', null, $appid, $openid);

		if(null !== $check){
			return $check;
		}

		$key	= $appid.':'.$openid;
		$object	= self::instance_exists($key);

		if(!$object){
			if(self::get_by_handler($appid, $openid)){
				$object	= new self($appid, $openid);
			}else{
				$object	= self::create($appid, compact('openid'));
			}

			return self::add_instance($key, $object);
		}

		return $object;
	}

	public static function __callStatic($method, $args){
		$appid		= array_shift($args);
		$handler	= self::get_handler($appid);

		if(strtolower($method) == 'query'){
			return $args ? $handler->query($args[0], 'object') : $handler;
		}elseif(str_ends_with($method, '_by_handler')){
			$method	= wpjam_remove_postfix($method, '_by_handler');
		}

		return call_user_func([$handler, $method], ...$args);
	}

	public static function call_method($method, ...$args){
		$parsed	= wpjam_parse_method(self::class, $method, $args, 2);

		return is_wp_error($parsed) ? $parsed : call_user_func_array($parsed, $args);
	}

	public static function get_table($appid){
		return $GLOBALS['wpdb']->base_prefix.'weixin_'.$appid.'_users';
	}

	public static function get_handler($appid){
		$table		= self::get_table($appid);
		$handler	= wpjam_get_handler($table);

		return $handler ?: wpjam_register_handler([
			'table_name'		=> $table,
			'primary_key'		=> 'openid',
			'cache_prefix'		=> $appid,
			'cache_group'		=> ['weixin_users', true],
			'field_types'		=> ['subscribe'=>'%d', 'subscribe_time'=>'%d', 'unsubscribe_time'=>'%d', 'last_update'=>'%d'],
			'searchable_fields'	=> ['openid', 'nickname'],
			'filterable_fields'	=> ['subscribe_scene'],
		]);
	}

	public static function create_table($appid){
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		$table	= self::get_table($appid);

		if($GLOBALS['wpdb']->get_var("show tables like '{$table}'") != $table) {

			$sql = "
			CREATE TABLE IF NOT EXISTS {$table} (
				`openid` varchar(30) NOT NULL,
				`nickname` varchar(255) NOT NULL,
				`subscribe` int(1) NOT NULL default '1',
				`subscribe_time` int(10) NOT NULL,
				`unsubscribe_time` int(10) NOT NULL,
				`language` varchar(255) NOT NULL,
				`headimgurl` varchar(255) NOT NULL,
				`tagid_list` text NOT NULL,
				`privilege` text NOT NULL,
				`unionid` varchar(30) NOT NULL,
				`remark` text NOT NULL,
				`subscribe_scene` varchar(32) NOT NULL,
				`qr_scene` int(6) NOT NULL,
				`qr_scene_str` varchar(64) NOT NULL,
				`user_id` bigint(20) NOT NULL,
				`last_update` int(10) NOT NULL,
				PRIMARY KEY  (`openid`),
				KEY `user_idx` (`user_id`),
				KEY `subscribe_time` (`subscribe_time`),
				KEY `subscribe` (`subscribe`),
				KEY `last_update` (`last_update`)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			";

			dbDelta($sql);
		}

		do_action('weixin_user_table_created', $table);

		if($GLOBALS['wpdb']->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'user_id'") != 'user_id'){
			$GLOBALS['wpdb']->query("ALTER TABLE $table ADD COLUMN user_id BIGINT(20) NOT NULL");
		}
	}

	public static function get_openid_by($appid, $key, $value){
		return self::get_handler($appid)->where($key, $value)->get_var('openid');
	}

	public static function get_email($appid, $openid){
		return $openid.'@'.$appid.'.weixin';
	}
}

// 1. 最多100个标签
// 2. 用户最多打10个标签
// 3. 3个系统默认保留的标签不能修改
// 4. 粉丝数超过10w的标签不能删除
// 5. 批量为用户打标签每次最多50个用户，取消打标签也是

// 因为获取用户详细资料的接口已有标签信息，所以获取用户标签接口无意义
// 微信 batchget 用户资料里面的 tagid 列表是错的，==> 微信已经修正成对的，

class WEIXIN_Bind extends WPJAM_Qrcode_Bind{
	public function __construct($appid){
		parent::__construct('weixin', $appid);

		if(weixin_get_type($appid) < 4){
			return;
		}

		if(is_multisite()){
			$weixin_setting	= weixin_get_setting($appid);

			if($weixin_setting['blog_id'] != get_current_blog_id()){
				return;
			}
		}

		add_action('wpjam_api',				[$this, 'register_api']);
		add_action('weixin_reply_loaded',	[$this, 'register_reply']);
	}

	public function __call($method, $args){
		return WEIXIN_Account::call_method($method, $this->appid, ...$args);
	}

	public function get_bind($openid, $bind_field, $unionid=false){
		$bind_value	= parent::get_bind($openid, $bind_field);

		if($unionid && empty($bind_value)){
			if(!$this->weapp_appid){
				return null;
			}

			$unionid	= $this->get_unionid($openid);

			if(empty($unionid)){
				return new WP_Error('empty_unionid','请先授权！');
			}

			$weapp_bind_obj	= wpjam_get_bind_object('weapp', $this->weapp_appid);

			if(!$weapp_bind_obj){
				return new WP_Error('weapp_bind_disabled', '请先开启微信小程序登录');
			}

			$weapp_openid	= call_user_func([$weapp_bind_obj, 'get_openid_by'], 'unionid', $unionid);

			if(empty($weapp_openid)){
				return new WP_Error('user_not_exists','用户不存在');
			}

			return call_user_func([$weapp_bind_obj, 'get_bind'], $weapp_openid, $bind_field);
		}

		return $bind_value;
	}

	public function create_qrcode($key, $args=[]){
		$qrcode	= $this->cache_get($key.'_qrcode');

		if($qrcode === false){
			$scene	= wp_generate_password(24, false, false).microtime(true)*10000;
			$qrcode = weixin_create_qrcode($scene, 1200, $this->appid);

			if(is_wp_error($qrcode)){
				return $qrcode;
			}

			$qrcode['qrcode_url']	= 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket='.$qrcode['ticket'];
			$qrcode['type']			= 'weixin';
			$qrcode['key']			= $key;
			$qrcode['scene']		= $scene;
			$qrcode['code']			= rand(100000, 999999);

			$qrcode	= array_merge($args, $qrcode);

			$this->cache_set($key.'_qrcode', $qrcode, 1200);
			$this->cache_set($scene.'_scene', $qrcode, 1200);
		}

		return $qrcode;
	}

	public function get_user($openid){
		if(self::in_blacklist($openid)){
			return new WP_Error('invalid_openid');
		}

		$user	= weixin_get_user($openid, $this->appid);

		if($user){
			$user['avatarurl']	= $user['headimgurl'] ?? '';
		}

		return $user;
	}

	public function update_user($openid, $data){
		if(self::in_blacklist($openid)){
			return new WP_Error('invalid_openid');
		}

		return WEIXIN_Account::update($this->appid, $openid, $data);
	}

	public function get_openid_by($key, $value){
		return WEIXIN_Account::get_openid_by($this->appid, $key, $value); 
	}

	public function register_reply(){
		weixin_register_reply('subscribe',	['type'=>'full',	'reply'=>'未关注扫码回复',	'callback'=>[$this, 'subscribe_reply']]);
		weixin_register_reply('scan',		['type'=>'full',	'reply'=>'已关注扫码回复',	'callback'=>[$this, 'scan_reply']]);
	}

	public function scan_reply($keyword, $weixin_reply){
		$message	= $weixin_reply->get_message();
		$scene		= $message['EventKey'] ?? '';

		if($scene && $this->get_reply($scene, $weixin_reply)){
			$reply_required	= false;
		}else{
			$reply_required	= true;
		}

		return $weixin_reply->scan_reply($keyword, $reply_required);
	}

	public function subscribe_reply($keyword, $weixin_reply){
		$message	= $weixin_reply->get_message();
		$scene		= $message['EventKey'] ?? '';
		$scene		= str_replace('qrscene_','',$scene);

		if($scene && $this->get_reply($scene, $weixin_reply)){
			$reply_required	= false;
		}else{
			$reply_required	= true;
		}

		return $weixin_reply->subscribe_reply($keyword, $reply_required);
	}

	public function get_reply($scene, $weixin_reply){
		if(is_numeric($scene)){
			return false;
		}

		$openid	= $weixin_reply->get_openid();
		$code	= $this->scan_qrcode($openid, $scene);

		if(is_wp_error($code)){
			if($code->get_error_code() == 'invalid_scene'){
				return false;
			}else{
				$reply	= $code->get_error_message();
			}
		}elseif(is_numeric($code)){
			$reply	= '你的验证码是 '.$code;
		}else{
			$reply	= '已绑定，请刷新页面！';
		}

		$weixin_reply->textReply($reply);

		return true;
	}

	public function register_api($json){
		$callbacks	= [
			'weixin.qrcode.create'	=> 'api_qrcode_create',
			'weixin.qrcode.verify'	=> 'api_qrcode_verify',
		];

		if(isset($callbacks[$json])){
			wpjam_register_api($json, ['callback' => [$this, $callbacks[$json]]]);
		}
	}

	public function api_qrcode_create(){
		$key	= wpjam_get_parameter('key');

		if(empty($key)){
			return new WP_Error('empty_key', 'KEY值不能为空');
		}

		$result	= $this->create_qrcode($key);

		if(is_wp_error($result)){
			return new WP_Error('qrcode_create_failed', '二维码创建失败，请稍后重试！');
		}

		return ['scene'=>$result['scene'], 'ticket'=>$result['ticket']];
	}

	public function api_qrcode_verify(){
		$scene	= wpjam_get_parameter('scene',	['method'=>'POST']);
		$code	= wpjam_get_parameter('code',	['method'=>'POST']);

		$qrcode	= $this->verify_qrcode($scene, $code);

		if(is_wp_error($qrcode)){
			return $qrcode;
		}

		$openid	= $qrcode['openid'];

		if(self::in_blacklist($openid)){
			return new WP_Error('invalid_openid');
		}

		$user	= weixin_get_user($openid, $this->appid);
		$user	= apply_filters('wpjam_qrcode_weixin_user', $user, $openid);

		if(!$user){
			return new WP_Error('invalid_openid');
		}

		return ['user'=>$user];
	}
}

class WEIXIN_User{
	use WEIXIN_Trait;

	public static function __callStatic($method, $args){
		if($method == 'reply'){
			$method		= 'send_message';
			$args[1]	= ['msgtype'=>'text', 'text'=>$args[1]];
			$args[2]	= 'custom';
		}

		return WEIXIN_Account::call_method($method, self::get_appid(), ...$args);
	}

	public static function query_items($limit, $offset){
		$orderby	= wpjam_get_data_parameter('orderby') ?: 'subscribe_time';
		$handler	= self::get_handler();

		$handler->order_by($orderby);

		if(wpjam_get_data_parameter('blacklist')){
			$blacklist	= self::get_blacklist();

			if(is_wp_error($blacklist)){
				return $blacklist;
			}

			if($blacklist){
				$total 	= count($blacklist);
				$items	= self::batch_get_user_info(array_slice($blacklist, $offset, $limit));
			}else{
				$total 	= 0;
				$items	= [];
			}
		}elseif($tagid = wpjam_get_data_parameter('tagid')){
			$response = weixin()->post('/cgi-bin/user/tag/get', ['tagid'=>$tagid, 'next_openid'=>'']);

			if(is_wp_error($response)){
				wp_die($response);
			}

			$total 	= $response['count'];
			$items	= $total ? self::batch_get_user_info($response['data']['openid']) : [];


		// }elseif(isset($_GET['scan'])){
		// 	$openids	= WEIXIN_UserSubscribe::Query()->where('type','scan')->where('scene',$_GET['scan'])->order_by('time')->limit($limit)->offset($offset)->get_col('openid');
		// 	$total 		= WEIXIN_UserSubscribe::Query()->find_total();
		// 	$items		= self::batch_get_user_info($openids);
		}elseif($scene = wpjam_get_data_parameter('scene')){
			if(is_numeric($scene)){
				$handler->where('qr_scene', $scene);
			}else{
				$handler->where('qr_scene_str', $scene);
			}

			extract($handler->query_items($limit, $offset));

			// if($items){
			// 	$openids 	= array_column($items, 'openid');
			// 	$items		= self::batch_get_user_info($openids);
			// }
		}else{
			$subscribe	= wpjam_get_data_parameter('subscribe', ['default'=>1]);

			if(is_numeric($subscribe)){
				if(empty($_REQUEST['s'])){
					$handler->where('subscribe', $subscribe);

					extract($handler->query_items($limit, $offset));

					// if($items){
					// 	$openids 	= array_column($items, 'openid');
					// 	$items		= self::batch_get_user_info($openids);
					// }
				}else{
					extract($handler->query_items($limit, $offset));
				}
			}else{
				$subscribe	= str_replace('qrscene_', '', $subscribe);

				// $openids	= WEIXIN_UserSubscribe::Query()->where('type','subscribe')->where('scene',$subscribe)->order_by('time')->limit($limit)->offset($offset)->get_col('openid');
				// $total 		= WEIXIN_UserSubscribe::Query()->find_total();
				// $items		= self::batch_get_user_info($openids);
			}
		}

		if(is_wp_error($items)){
			return $items;
		}

		// $items	= array_filter($items, function($item){
		// 	return !empty($item['subscribe_time']) || !empty($item['subscribe']);	// 至少曾经订阅过
		// });

		return compact('items', 'total');
	}

	public static function render_item($item){
		if(self::in_blacklist($item['openid'])){
			unset($item['row_actions']['black']);
		}else{
			unset($item['row_actions']['unblack']);
		}

		$item['username']	= $item['nickname'] ?: $item['openid'];

		if(!empty($item['headimgurl'])){
			$item['username']	= '<img src="'.str_replace('/0', '/64', $item['headimgurl']).'" width="32" />'.$item['username'];
		}

		$item['openid']	= 'OpenID：'.$item['openid']; 

		if($item['unionid']){
			$item['openid']	.= '<br />UnionID：'.$item['unionid'];
		}

		if(!empty($item['user_id'])){
			$item['openid']	.= '<br />UserID：'.$item['user_id'];
		}

		if($item['tagid_list']){
			$tag_options	= self::get_tags('options');
			$tagid_list		= [];

			foreach(wp_parse_id_list($item['tagid_list']) as $tagid){
				$tag_name		= $tag_options[$tagid] ?? $tagid;
				$tagid_list[]	= '[filter tagid="'.$tagid.'"]'.$tag_name.'[/filter]';
			}

			$item['tagid_list']	= implode(',', $tagid_list);
		}else{
			$item['tagid_list']	= '';
		}

		$item['subscribe_time']		= $item['subscribe_time'] ? get_date_from_gmt(date('Y-m-d H:i:s', $item['subscribe_time'])) : '';
		$item['unsubscribe_time']	= $item['unsubscribe_time'] ? get_date_from_gmt(date('Y-m-d H:i:s', $user['unsubscribe_time'])) : '';

		$item['time']	= [];

		if($item['subscribe_time']){
			$item['time'][]	= '订阅时间：'.$item['subscribe_time'];
		}

		if($item['unsubscribe_time']){
			$item['time'][]	= '取消订阅：'.$item['unsubscribe_time'];
		}

		$item['time']	= implode('<br />', $item['time']);

		return $item;
	}

	public static function get_views(){
		$subscribe	= wpjam_get_data_parameter('subscribe');
		$subscribe	= isset($subscribe) ? (is_numeric($subscribe) ? (int)$subscribe : $subscribe) : '';

		$tagid		= wpjam_get_data_parameter('tagid');
		$blacklist	= wpjam_get_data_parameter('blacklist');

		$views		= [];

		$class		= (empty($tagid) && empty($blacklist) && $subscribe !== 0) ? 'current':'';
		$count		= self::get_handler()->where('subscribe', 1)->order_by('')->get_var('count(*)'); 

		$views['subscribe'] = ['label'=>'订阅用户',	'count'=>$count, 'class'=>$class];

		$user_tags	= self::get_tags();

		if(!is_wp_error($user_tags)){
			foreach($user_tags as $tag){
				if($tag['count'] > 0){
					$views[$tag['id']] = ['filter'=>['tagid'=>$tag['id']], 'label'=>$tag['name'], 'count'=>$tag['count']];
				}
			}
		}

		$blacklist	= self::get_blacklist();

		if($blacklist && !is_wp_error($blacklist)){
			$views['blacklist'] = ['filter'=>['blacklist'=>1], 'label'=>'黑名单', 'count'=>count($blacklist)];
		}

		return $views;
	}

	public static function get_actions(){
		return [
			'refresh'	=> ['title'=>'刷新',		'direct'=>true,	'comfirm'=>true],
			'tag'		=> ['title'=>'标签',		'page_title'=>'设置标签',	'submit_text'=>'设置标签',	'bulk'=>true],
			'remark'	=> ['title'=>'备注',		'page_title'=>'备注'],
			'black'		=> ['title'=>'拉黑',		'direct'=>true,	'comfirm'=>true],
			'unblack'	=> ['title'=>'取消拉黑',	'direct'=>true,	'comfirm'=>true],
			'reply'		=> ['title'=>'回复',		'page_title'=>'回复客服消息'],
		];
	}

	public static function get_fields($action_key='', $openid=0){
		if($action_key==''){
			$options	=[
				'ADD_SCENE_SEARCH'				=> '公众号搜索',
				'ADD_SCENE_ACCOUNT_MIGRATION'	=> '公众号迁移',
				'ADD_SCENE_PROFILE_CARD'		=> '名片分享',
				'ADD_SCENE_QR_CODE'				=> '扫描二维码',
				'ADD_SCENE_PROFILE_LINK'		=> '图文页内名称点击',
				'ADD_SCENE_PROFILE_ITEM'		=> '图文页右上角菜单',
				'ADD_SCENE_PAID'				=> '支付后关注',
				'ADD_SCENE_OTHERS'				=> '其他'
			];

			return [
				'username'			=> ['title'=>'用户',		'type'=>'view',	'show_admin_column'=>'only'],
				'subscribe_scene'	=> ['title'=>'来源',		'type'=>'view',	'show_admin_column'=>'only',	'options'=>$options],
				'time'				=> ['title'=>'时间',		'type'=>'view',	'show_admin_column'=>'only'],
				'tagid_list'		=> ['title'=>'标签',		'type'=>'view',	'show_admin_column'=>'only'],
				'openid'			=> ['title'=>'OpenID',	'type'=>'view',	'show_admin_column'=>'only']
			];
		}elseif($action_key == 'reply'){
			return ['content'	=> ['type'=>'textarea']];
		}elseif($action_key == 'tag'){
			return ['tagid_list'	=> ['type'=>'checkbox',	'max_items'=>20,	'options'=>self::get_tags('options')]];
		}elseif($action_key == 'remark'){
			return ['remark'	=> ['type'=>'textarea']];
		}elseif($action_key == 'edit'){
			return ['user_id'	=> ['title'=>'WP_USER ID',	'type'=>'number']];
		}
	}

	public static function get_tabs(){
		return [
			'users'	=> [
				'title'		=> '用户列表',
				'function'	=> 'list',
				'singular'	=> 'weixin-user',
				'plural'	=> 'weixin-users',
				'model'		=> 'WEIXIN_User',
				'primary_key'	=> 'openid',
			],
			'tags'	=> [
				'title'		=> '标签管理',
				'function'	=> 'list',
				'singular'	=> 'weixin-user-tag',
				'plural'	=> 'weixin-user-tags',
				'model'		=> 'WEIXIN_User_Tag',
			],
			'sync'	=> [
				'title'			=>'同步用户',
				'function'		=>'form',
				'form_name'		=>'weixin_sync_users',
				'submit_text'	=> '同步',
				'fields'		=> [
					// 'type'		=> ['type'=>'hidden',	'value'=>'list'],
					'next_openid'	=> ['type'=>'hidden',	'value'=>''],
				],
				'callback'		=> ['WEIXIN_User', 'ajax_sync'],
				'summary'		=> '从微信获取订阅用户列表，同步到本地数据库。'
			],
		];
	}

	public static function ajax_sync(){
		$type	= wpjam_get_data_parameter('type');

		// if($type == 'list'){
			$next_openid	= wpjam_get_data_parameter('next_openid') ?: '';

			if(empty($next_openid)){
				self::get_handler()->update(['subscribe'=>0]);	// 第一次抓取将所有的用户设置为未订阅
			}

			$response = weixin()->get('/cgi-bin/user/get', ['next_openid'=>$next_openid]);

			if(is_wp_error($response)){
				return $response;
			}

			$next_openid	= $response['next_openid'];
			$count			= $response['count'];

			if($count){
				$datas	= array_map(function($openid){return ['openid'=>$openid, 'subscribe'=>1]; }, $response['data']['openid']);

				self::insert_multi($datas);
			}

			if($next_openid && $count > 0){
				$args	= http_build_query(['type'=>$type, 'next_openid'=>$next_openid]);

				return ['done'=>0,	'args'=>$args, 'errmsg'=>'同步列表中，请勿关闭浏览器。下一组：'.$next_openid];
			}else{
				return true;
				// $args	= http_build_query(['type'=>'batch']);

				// return ['done'=>0, 'type'=>'batch', 'errmsg'=>'开始同步用户信息，请勿关闭浏览器。'];
			}
		// }else{
		// 	$openids	= self::get_handler()->where('subscribe',1)->where_lt('last_update', (time()-DAY_IN_SECONDS))->limit(100)->get_col('openid');

		// 	if($openids){
		// 		$result = self::batch_get_user_info($openids, true);

		// 		if(is_wp_error($result)){
		// 			return $result;
		// 		}

		// 		if(count($openids) > 90){	// 如果有大量的用户，就再抓一次咯
		// 			$args	= http_build_query(['type'=>'batch']);

		// 			return ['done'=>0,	'args'=>$args, 'errmsg'=>'同步用户信息中，请勿关闭浏览器。'];
		// 		}
		// 	}

		// 	return true;
		// }
	}
}

class WEIXIN_User_Tag{
	public static function __callStatic($method, $args){
		return WEIXIN_Account::call_method($method, weixin_get_appid(), ...$args);
	}

	public static function get_primary_key(){
		return 'id';
	}

	public static function get($id){
		$tags 		= self::get_tags();
		$filtered	= wp_list_filter($tags, ['id'=>$id]);

		return $filtered ? current($filtered) : [];
	}

	public static function insert($data){
		$response	= self::create_tag($data['name']);

		return is_wp_error($response) ? $response : $response['tag']['id'];
	}

	public static function update($id, $data){
		return self::update_tag($id, $data['name']);
	}

	public static function delete($id){
		return self::delete_tag($id);
	}

	public static function query_items($args){
		$items		= self::get_tags();
		$orderby	= wpjam_get_data_parameter('orderby');
		$order		= wpjam_get_data_parameter('order');

		if(isset($orderby)){
			$order = ($order == 'desc') ? 'DESC' : 'ASC';
			$items = wp_list_sort($items, $orderby, $order);
		}

		return ['items'=>$items, 'total'=>count($items)];
	}

	public static function render_item($item){
		if($item['id'] < 100){
			unset($item['row_actions']);
		}

		$item['count']	= '<a href="'.admin_url('page=weixin-users&tab=users&tagid='.$item['id']).'">'.$item['count'].'</a>';

		return $item;
	}

	public static function get_fields($action_key='', $id=0){
		return [
			'name'	=> ['title'=>'名称',	'type'=>'text',	'show_admin_column'=>true,	'description'=>'30个字符以内'],
			'id'	=> ['title'=>'ID',	'type'=>'text',	'show_admin_column'=>'only'],
			'count'	=> ['title'=>'数量',	'type'=>'text',	'show_admin_column'=>'only',	'sortable_column'=>true],
		];
	}
}

trait WEIXIN_Trait{
	protected static $appid;

	public static function get_appid(){
		$appid	= static::$appid;
		$weixin	= weixin($appid);

		if(is_wp_error($weixin)){
			if(wpjam_is_json_request()){
				wpjam_send_json($weixin);
			}else{
				wp_die($weixin->get_error_message(), $weixin->get_error_code());
			}
		}else{
			return $weixin->get_appid();
		}
	}

	public static function set_appid($appid=''){
		static::$appid	= $appid ?: weixin_get_appid();
	}
}