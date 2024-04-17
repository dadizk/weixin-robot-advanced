<?php
class WEIXIN_OAuth{
	public static function register_json($json){
		if($json == 'weixin.oauth' && weixin_get_current_appid()){
			return wpjam_register_json($json, ['callback'=>[self::class, 'json_callback']]);
		}
	}

	public static function json_callback(){
		$code	= wpjam_get_parameter('code',	['method'=>'REQUEST',	'required'=>true]);

		$access_token	= self::get_access_token($code);

		if(is_wp_error($access_token)){
			return $access_token;
		}

		$openid		= $access_token['openid'];
		$account	= weixin_get_user_object($openid);
		$user		= $account->parse_for_json();

		do_action('weixin_user_signuped', $user);

		return [
			'errcode'		=> 0,
			'access_token'	=> $account->generate_access_token(),
			'expired_in'	=> DAY_IN_SECONDS - 600,
			'user'			=> $user
		];
	}

	public static function redirect($action){
		if(in_array($action, ['reply', 'jssdk'])){
			return;
		}

		if(!is_weixin()){
			wp_die('请在微信中访问');
		}

		if(weixin_get_type() == 4){
			self::request();
		}

		$openid	= weixin_get_current_openid();

		if(is_wp_error($openid)){
			wp_die('未登录');
		}

		$user	= weixin_get_user($openid);

		if($action == 'oauth'){
			$redirect_url	= $_GET['redirect_url'] ?? '';

			if(empty($redirect_url)){
				wp_die('未传递跳转链接');
			}

			$account		= weixin_get_user_object($openid);
			$access_token	= $account->generate_access_token();
			$redirect_url	= add_query_arg(compact('access_token'), $redirect_url);

			wp_redirect($redirect_url);
		}else{
			if($user && $user['subscribe']){
				if($template = apply_filters('weixin_template', '', $action)){
					if(is_file($template)){
						return $template;
					}
				}
			}else{
				wp_die('未关注');
			}
		}
	}

	public static function request($scope='snsapi_userinfo'){
		global $weixin_did_oauth;
		
		if(isset($weixin_did_oauth)){	// 防止重复请求
			return;
		}
			
		$weixin_did_oauth	= true;

		if(!empty($_GET['scope'])){
			$scope	= $_GET['scope'];
		}

		if(!in_array($scope, ['snsapi_userinfo', 'snsapi_base']) || self::has_access_token($scope)){
			return;
		}

		$redirect_url	= remove_query_arg(['code', 'state', 'scope', 'get_openid', 'weixin_oauth', 'nsukey'], wpjam_get_current_page_url());

		if(isset($_GET['code']) && isset($_GET['state']) && isset($_GET['scope'])){		// 微信 OAuth 请求

			if($_GET['code'] == 'authdeny'){
				wp_die('用户拒绝');
			}

			if(!wp_verify_nonce($_GET['state'], $scope)){
				wp_die("非法操作");
			}		

			$response	= self::get_access_token($_GET['code']);

			if(is_wp_error($response)){
				wp_die($response);
			}

			$openid		= $response['openid'];
			$account	= weixin_get_user_object($openid);

			$account->generate_access_token(true);
		}else{
			$redirect_url	= add_query_arg(compact('scope'), $redirect_url);
			$redirect_url	= 'https://open.weixin.qq.com/connect/oauth2/authorize?appid='.weixin_get_current_appid().'&redirect_uri='.urlencode($redirect_url).'&response_type=code&scope='.$scope.'&state='.wp_create_nonce($scope).'&connect_redirect=1#wechat_redirect';
		}

		wp_redirect($redirect_url);

		exit;
	}

	public static function has_access_token($scope='snsapi_base'){
		$response	= self::get_access_token();

		if(!$response || is_wp_error($response)){
			return false;
		}

		if($scope == 'snsapi_userinfo'){
			return $response['scope'] == 'snsapi_userinfo';
		}else{
			return true;
		}
	}

	public static function get_access_token($code=''){
		if($code){
			$response	= self::get_access_token_by_code($code);

			if(is_wp_error($response)){
				return $response;
			}

			$openid	= $response['openid'];
		}else{
			$openid	= weixin_get_current_openid();

			if(is_wp_error($openid)){
				return $openid;
			}

			$response	= self::get_access_token_by_openid($openid);

			if(is_wp_error($response)){
				return $response;
			}
		}

		$appid		= weixin_get_current_appid();
		$account	= weixin_get_user_object($openid);
		
		if($response['scope'] == 'snsapi_userinfo'){
			if($account->last_update < time() - DAY_IN_SECONDS
				|| (!$account->headimgurl && !$account->nickname )  
			){
				$userinfo	= weixin()->get('/sns/userinfo?access_token='.$response['access_token'].'&openid='.$openid.'&lang=zh_CN');

				if(!is_wp_error($userinfo)){
					$userinfo['privilege']	= maybe_serialize($userinfo['privilege']);

					WEIXIN_Account::sync($appid, $openid, $userinfo);	
				}
			}
		}

		return $response;
	}

	public static function get_access_token_by_code($code){
		$appid		= weixin_get_current_appid();
		$weixin		= weixin($appid);
		$response	= $weixin->cache_get('oauth_access_token:'.$code);

		if($response === false) {
			$response	= $weixin->http_request('/sns/oauth2/access_token?code='.$code.'&grant_type=authorization_code', ['using_secret'=>true]);

			if(is_wp_error($response)){
				return $response;
			}

			$response['expires_in']	= $response['expires_in'] + time() - 600;

			$weixin->cache_set('oauth_access_token:'.$code,	$response, MINUTE_IN_SECONDS*5);	// 防止同个 code 多次请求
			$weixin->cache_set('oauth_access_token:'.$response['openid'], $response, DAY_IN_SECONDS*29);	// refresh token 有限期为30天
		}

		return $response;
	}

	public static function get_access_token_by_openid($openid){
		$appid		= weixin_get_current_appid();
		$weixin		= weixin($appid);
		$response	= $weixin->cache_get('oauth_access_token:'.$openid);

		if($response && $response['expires_in'] > time()){	// 内存中有效
			return $response;
		}

		$refresh_token	= $response ? ($response['refresh_token'] ?? '') : '';

		if(!$refresh_token){
			return new WP_Error('empty_oauth_access_token', '服务器缓存的 oauth_access_token 失效！');
		}

		$response	= $weixin->get('/sns/oauth2/refresh_token?appid='.$appid.'&grant_type=refresh_token&refresh_token='.$refresh_token);

		if(!is_wp_error($response)){
			$response['expires_in']	= $response['expires_in'] + time() - 600;
			
			$weixin->cache_set('oauth_access_token:'.$response['openid'], $response, DAY_IN_SECONDS*29);	// refresh token 有限期为30天
		}

		return $response;
	}
}

function weixin_oauth_request($scope='snsapi_userinfo'){
	WEIXIN_OAuth::request($scope);
}