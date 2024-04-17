<?php
class WEIXIN_Develop{
	public static function get_tabs(){
		return [
			'clear_quota'	=> [
				'title'		=> '限额清零',
				'function'	=> 'form',
				'form_name'	=> 'clear_quota',
				'form'		=> [self::class, 'get_form']
			],
			'get_quota'		=> [
				'title'		=> '限额查询',
				'function'	=> 'form',
				'form_name'	=> 'get_quota',
				'form'		=> [self::class, 'get_form']
			],
			'get_rid'		=> [
				'title'		=> 'rid查询',
				'function'	=> 'form',
				'form_name'	=> 'get_rid',
				'form'		=> [self::class, 'get_form']

			],
			'check_callback'=> [
				'title'		=> '网络检测',
				'function'	=> [self::class, 'check_callback_page']
			],
			'ip'			=> [
				'title'		=>'IP列表',	
				'function'	=>[self::class, 'callback_ip_page']
			]
		];
	}

	public static function get_form($name){
		if($name == 'clear_quota'){
			return [
				'submit_text'	=> '清零',
				'summary'		=> [self::class, 'get_summary'],
				'validate'		=> true,
				'direct'		=> true, 
				'confirm'		=> true,
				'callback'		=> [self::class, 'callback']
			];
		}elseif($name == 'get_quota'){
			return [
				'submit_text'	=> '查询',
				'validate'		=> true,
				'response'		=> 'append',
				'fields'		=> ['cgi_path'	=>['title'=>'API路径',	'type'=>'text']],
				'callback'		=> [self::class, 'callback']
			];
		}elseif($name == 'get_rid'){
			return [
				'submit_text'	=> '查询',
				'validate'		=> true,
				'response'		=> 'append',
				'fields'		=> ['rid'	=>['title'=>'接口报错返回的rid',	'type'=>'text']],
				'callback'		=> [self::class, 'callback']
			];
		}
	}

	public static function get_summary(){
		return wpautop('开发者可以登录微信公众平台，在帐号后台开发者中心接口权限模板查看帐号各接口当前的日调用上限和实时调用量，对于认证帐号可以对实时调用量清零，说明如下：

		1、由于指标计算方法或统计时间差异，实时调用量数据可能会出现误差，一般在1%以内。
		2、每个帐号每月共10次清零操作机会，清零生效一次即用掉一次机会（10次包括了平台上的清零和调用接口API的清零）。
		3、每个有接口调用限额的接口都可以进行清零操作。
		');
	}

	public static function callback($data, $name){
		$weixin	= weixin();

		if($name == 'clear_quota'){
			if($weixin->cache_get('clear_quota') === false){
				$weixin->cache_set('clear_quota', true, HOUR_IN_SECONDS);
				
				$response	= $weixin->post('/cgi-bin/clear_quota', ['appid'=>weixin_get_appid()]);

				return is_wp_error($response) ? $response : true;			
			}else{
				return new WP_Error('-1', '一小时内你刚刚清理过');
			}
		}elseif($name == 'get_quota'){
			$cgi_path	= $data['cgi_path'];
			$response	= $weixin->post('/cgi-bin/openapi/quota/get', ['cgi_path'=>$cgi_path]);

			if(is_wp_error($response)){
				return $response;
			}

			$quota	= '<p><strong>「'.$cgi_path.'」接口调用限额：</strong></p>';
			$quota	.= '每日上限：'.$response['quota']['daily_limit'];
			$quota	.= '，已用：'.$response['quota']['used'];
			$quota	.= '，还剩：'.$response['quota']['remain'];

			return $quota;
		}elseif($name == 'get_rid'){
			$rid		= $data['rid'];
			$response	= weixin()->post('/cgi-bin/openapi/rid/get', ['rid'=>$rid]);

			if(is_wp_error($response)){
				return $response;
			}

			$request	= '<p><strong>「'.$rid.'」详细信息：</strong></p>';
			$request	.= '<table class="widefat striped"><tbody>';
			$request	.= '<tr><td style="width:140px;">发起请求的时间戳：'.'</td><td>'.$response['request']['invoke_time'].'</td></tr>';
			$request	.= '<tr><td>请求毫秒级耗时：'.'</td><td>'.$response['request']['cost_in_ms'].'</td></tr>';
			$request	.= '<tr><td>请求的URL参数：'.'</td><td><code>'.$response['request']['request_url'].'</code></td></tr>';
			$request	.= '<tr><td>POST请求的请求参数：'.'</td><td><code>'.$response['request']['request_body'].'</code></td></tr>';
			$request	.= '<tr><td>接口请求返回参数：'.'</td><td><code>'.$response['request']['response_body'].'</code></td></tr>';
			$request	.= '</tbody></table><br /><br />';

			return $request;
		}
	}

	public static function check_callback_page(){
		echo wpautop('该接口实现微信对服务器的域名解析，然后对所有IP进行一次ping操作，得到丢包率和耗时。');

		$weixin			= weixin();
		$action			= 'all';
		$check_operator	= 'DEFAULT';
		$response		= $weixin->cache_get('check_callback:'.$action.':'.$check_operator);

		if($response === false){
			$response	= $weixin->post('/cgi-bin/callback/check', compact('action', 'check_operator'));

			if(is_wp_error($response)){
				echo wpautop($response->get_error_message());
			}else{
				$weixin->cache_set('check_callback:'.$action.':'.$check_operator, $response, MINUTE_IN_SECONDS*5);
			}
		}

		echo wpjam_print_r($response);
	}

	public static function callback_ip_page(){
		$weixin	= weixin();

		$callback_ip	= $weixin->cache_get('callback_ip');

		if($callback_ip === false){
			$response	= $weixin->get('/cgi-bin/getcallbackip');

			if(!is_wp_error($response)){
				$callback_ip = $response['ip_list'];
				$weixin->cache_set('callback_ip', $callback_ip, DAY_IN_SECONDS);
			}	
		}

		$api_domain_ip	= $weixin->cache_get('api_domain_ip');

		if($api_domain_ip === false){
			$response	= $weixin->get('/cgi-bin/get_api_domain_ip');

			if(!is_wp_error($response)){
				$api_domain_ip = $response['ip_list'];
				$weixin->cache_set('api_domain_ip', $api_domain_ip, DAY_IN_SECONDS);
			}
		}

		if(is_wp_error($callback_ip) || is_wp_error($api_domain_ip)){
			if(is_wp_error($callback_ip)){
				echo wpautop($callback_ip->get_error_message());
			}

			if(is_wp_error($api_domain_ip)){
				echo wpautop($api_domain_ip->get_error_message());
			}
		}else{
			echo '<table class="widefat striped" style="max-width:600px;">
			<thead>
				<tr><th>微信服务器IP地址</th><th>微信API接口IP地址</th></tr>
			</thead>
			<tbody>
				<tr><td>'.wpautop(implode("\n", $callback_ip)).'</td><td>'.wpautop(implode("\n", $api_domain_ip)).'</td></tr>
			</tbody>

			</table>';
		}
	}
}