<?php
// '微信JS-SDK是微信公众平台 面向网页开发者提供的基于微信内的网页开发工具包。
// 通过使用微信JS-SDK，网页开发者可借助微信高效地使用拍照、选图、语音、位置等手机系统的能力，
// 同时可以直接使用微信分享、扫一扫、卡券、支付等微信特有的能力，为微信用户提供更优质的网页体验。';
class WEIXIN_JSSDK{
	public static function get_menu_page(){
		if(weixin_get_type() >= 3){
			return [
				'parent'		=> 'weixin',
				'menu_slug'		=> 'weixin-jssdk',
				'menu_title' 	=> '网页分享',
				'order'			=> 9,
				'function'		=> 'option',
				'option_name'	=> 'weixin-robot',
				'option'		=> ['model'=>self::class, 'ajax'=> false]
			];
		}
	}

	public static function get_summary(){
		return wpautop('
		1. 先登录「微信公众平台」进入「公众号设置」-「功能设置」
		2. 点击配置「JS接口安全域名」，复制验证文件名填到下面对应框并保存。
		3. 返回公众号后台，将博客地址填入「JS接口安全域名」。');
	}

	public static function get_fields(){
		$verify_txt	= wpjam_register_verify_txt('weixin', ['title'=>'微信公众号验证文件']);

		return [
			'weixin_share'	=> ['title'=>'开启网页分享',	'type'=>'checkbox',	'description'=>'开启网页分享，直接调用文章标题，摘要，缩略图，链接用于微信分享。'],
			'js_api_debug'	=> ['title'=>'开启调试模式',	'type'=>'checkbox',	'show_if'=>['key'=>'weixin_share', 'value'=>1],	'description'=>'调用的所有api的返回值会在客户端alert出来，若要查看传入的参数，可以在pc端打开，参数信息会通过log打出，仅在pc端时才会打印。'],
			'verify_txt'	=> ['title'=>'域名验证文件名',	'type'=>'text', 	'value'=>$verify_txt->get_data('name'), 'description'=>'请输入微信公众号后台提供的域名验证文件名（包括.txt），保存之后到微信验证。'],
			'share_image'	=> ['title'=>'分享图片',		'type'=>'img',		'item_type'=>'url',	'size'=>'120x120',	'description'=>'网站所有页面使用统一的分享图片'],
			// 'js_api_list'	=> ['title'=>'JS接口列表',	'type'=>'checkbox',	'options'=>wp_list_pluck(self::get_js_api_list(), 'title'),	'value'=>['share']],
		];
	}

	public static function sanitize_callback($value){
		$verify_txt	= WPJAM_Verify_TXT::get('weixin');
		$txt_name	= array_pull($value, 'verify_txt');

		if($txt_name && preg_match('/MP_verify_(.*)\.txt/i', $txt_name, $match)){
			$verify_txt->set_data(['name'=>$txt_name, 'value'=>$match[1]]);
		}

		return $value;
	}

	public static function register_json($json){
		if(weixin_get_current_appid()){
			if(in_array($json, ['weixin.jssdk', 'weixin.jsapi_ticket'])) {
				return wpjam_register_json($json, ['callback'=>[self::class, 'json_callback']]);
			}
		}
	}

	public static function json_callback(){
		$response	= weixin_get_ticket('jsapi');

		if(is_wp_error($response)){
			return $response;
		}

		if(wpjam_get_current_json() == 'weixin.jsapi_ticket'){
			if(isset($response['expires_at'])){
				$response['expires_in']	= array_pull($response, 'expires_at')-time();
			}else{
				$response['expires_in']	-= time();
			}
		}else{
			$ticket		= $response['ticket'];
			$url		= $_REQUEST['url'];
			$timestamp	= time();
			$nonceStr	= wp_generate_password(16, false);
			$signature	= sha1("jsapi_ticket=$ticket&noncestr=$nonceStr&timestamp=$timestamp&url=$url");
			$response	= [
				'appId'		=> weixin_get_current_appid(),
				'url'		=> $url,
				'timestamp'	=> $timestamp,
				'nonceStr'	=> $nonceStr,
				'signature'	=> $signature,
			];
		}

		wpjam_send_json($response);
	}

	public static function redirect($action){
		if($action != 'jssdk'){
			return;
		}

		if(empty($_REQUEST['callback'])){
			exit;
		}

		$callback	= htmlspecialchars($_REQUEST['callback']);
		$ticket		= weixin_get_ticket('jsapi');

		if(is_wp_error($ticket)){
			wpjam_send_json($ticket);
		}

		$ticket	= $ticket['ticket'];

		header('Access-Control-Allow-Origin: *');
		header('Content-type: application/json');

		$url		= $_REQUEST['url'];
		$timestamp	= time();
		$nonceStr	= wp_generate_password(16, false);
		$signature	= sha1("jsapi_ticket=$ticket&noncestr=$nonceStr&timestamp=$timestamp&url=$url");

		$response	= [
			'errno'		=> 0,			
			'errmsg'	=> 'SUCCESS',			
			'data'		=> [
				'appId'		=> weixin_get_appid(),
				'timestamp'	=> $timestamp,
				'nonceStr'	=> $nonceStr,
				'signature'	=> $signature,
				'jsApiList'	=> ['checkJsApi', 'onMenuShareTimeline', 'onMenuShareAppMessage', 'onMenuShareQQ', 'onMenuShareWeibo', 'onMenuShareQZone'],
			],
			'time'		=> $timestamp,
			'hasFlush'	=> true,
			'format'	=> 'jsonp'
		];

		echo $callback . "(" . json_encode($response) . ")";

		exit;
	}

	public static function get_js_api_list($list=[]){
		$weixin_type	= weixin_get_type();
		$js_api_list	= [];

		if($weixin_type >= 3){
			$js_api_list['share']	= [
				'title'	=> '分享接口',
				'list'	=> ['updateAppMessageShareData', 'updateTimelineShareData']
			];
		}

		$js_api_list['ui']		= [
			'title'	=> '界面操作',
			'list'	=> ['closeWindow', 'hideMenuItems', 'showMenuItems', 'hideAllNonBaseMenuItem', 'showAllNonBaseMenuItem']
		];

		$js_api_list['image']	= [
			'title'	=> '图像接口',
			'list'	=> ['chooseImage', 'previewImage', 'uploadImage', 'downloadImage', 'getLocalImgData']
		];

		$js_api_list['voice']	= [
			'title'	=> '音频接口',
			'list'	=> ['startRecord', 'stopRecord', 'onVoiceRecordEnd', 'playVoice', 'pauseVoice', 'stopVoice', 'onVoicePlayEnd', 'uploadVoice', 'downloadVoice', 'translateVoice']
		];

		$js_api_list['location']	= [
			'title'	=> '地理位置',
			'list'	=> ['openLocation', 'getLocation', 'uploadImage', 'downloadImage', 'getLocalImgData']
		];

		$js_api_list['beacon']	= [
			'title'	=> '摇一摇周边',
			'list'	=> ['startSearchBeacons', 'stopSearchBeacons', 'onSearchBeacons']
		];

		$js_api_list['qrcode']	= [
			'title'	=> '微信扫一扫',
			'list'	=> ['scanQRCode']
		];

		if($weixin_type >= 3){
			$js_api_list['card']	= [
				'title'	=> '微信卡券',
				'list'	=> ['chooseCard', 'addCard', 'openCard']
			];
		}

		if($weixin_type == 4){
			$js_api_list['wxpay']	= [
				'title'	=> '微信支付',
				'list'	=> ['chooseWXPay', 'openAddress']
			];
		}

		if($list){
			return wp_array_slice_assoc($js_api_list, $list);
		}else{
			return $js_api_list; 
		}
	}

	public static function get_share_script($appid=null){
		$ticket		= weixin_get_ticket('jsapi', $appid);

		if(is_wp_error($ticket)){
			return $ticket;
		}

		$ticket			= $ticket['ticket'];
		$url			= wpjam_get_current_page_url();
		$timestamp		= time();
		$nonce_str		= wp_generate_password(16, false);
		$signature		= sha1("jsapi_ticket=$ticket&noncestr=$nonce_str&timestamp=$timestamp&url=$url");
		$link			= remove_query_arg(['weixin_openid', 'weixin_access_token', 'isappinstalled', 'from', 'weixin_refer','nsukey'], $url);

		$share_image	= weixin_get_setting('share_image', $appid);
		$share_image	= $share_image ? wpjam_get_thumbnail($share_image, '120x120') : '';
		
		if(is_singular()){
			$img	= $share_image ?: wpjam_get_post_thumbnail_url(get_queried_object_id(),[120,120]);
			$title	= wp_title('', false);
			$desc	= get_the_excerpt();
		}else{
			if($share_image){
				$img	= $share_image;
			}else{
				$default	= wpjam_get_default_thumbnail_url([120,120]);

				if(is_category() || is_tag() || is_tax()){
					$img	= wpjam_get_term_thumbnail_url(get_queried_object_id(),[120,120]);
				}

				$img	= !empty($img) ? $img : $default;
			}
			
			$title	= wp_title('', false) ?: get_bloginfo('name');
			$desc	= '';
		}

		$js_api_list	= self::get_js_api_list(['share']);
		$js_api_list	= $js_api_list ? array_merge(...array_column($js_api_list, 'list')) : [];
		$js_api_list	= array_merge(['checkJsApi'], $js_api_list);

		$weixin_share	= [
			'appid' 		=> weixin_get_appid(),
			'debug' 		=> weixin_get_setting('js_api_debug'),
			'timestamp'		=> $timestamp,
			'nonce_str'		=> $nonce_str,
			'signature'		=> $signature,

			'img'			=> apply_filters('weixin_share_img', $img),
			'title'			=> apply_filters('weixin_share_title', trim(html_entity_decode($title))),
			'desc'			=> apply_filters('weixin_share_desc', trim(html_entity_decode($desc))),
			'link'			=> apply_filters('weixin_share_url', $link),

			'jsApiList'		=> $js_api_list,
			'openTagList'	=> ['wx-open-audio','wx-open-launch-app','wx-open-launch-weapp']
		];

		return 'let weixin_share	= '.wpjam_json_encode($weixin_share).';';
	}

	public static function get_inline_script(){
		return '
	weixin_share.desc	= weixin_share.desc || weixin_share.link;

	/*微信 JS SDK 封装*/
	wx.config({
		debug:			weixin_share.debug,
		appId: 			weixin_share.appid,
		timestamp:		weixin_share.timestamp,
		nonceStr:		weixin_share.nonce_str,
		signature:		weixin_share.signature,
		jsApiList:		weixin_share.jsApiList,
		openTagList:	weixin_share.openTagList
	});

	wx.ready(function(){
		wx.updateAppMessageShareData({
			title:	weixin_share.title,
			desc:	weixin_share.desc,
			link: 	weixin_share.link,
			imgUrl:	weixin_share.img,
			success: function(res){
				console.log(res);
			}
		});

		wx.updateTimelineShareData({
			title:	weixin_share.title,
			link: 	weixin_share.link,
			imgUrl:	weixin_share.img,
			success: function(res){
				console.log(res);
			}
		});
	});

	wx.error(function(res){
		console.log(res);
	});';
	}

	public static function on_enqueue_scripts(){
		wp_register_style('weui', 'https://res.wx.qq.com/open/libs/weui/2.4.1/weui.min.css');
	
		if(is_404()){
			return;
		}

		$share_script	= self::get_share_script();

		if(!is_wp_error($share_script)){
			wp_enqueue_script('jweixin', 'https://res.wx.qq.com/open/js/jweixin-1.6.0.js');
			wp_add_inline_script('jweixin', $share_script);
		}
		
		$inline_script	= self::get_inline_script();

		if(did_action('wpjam_static')){
			wpjam_register_static('jweixin-script',	['title'=>'微信网页分享',	'type'=>'script',	'source'=>'value',	'value'=>$inline_script]);
		}else{
			wp_add_inline_script('jweixin', $inline_script);
		}
	}

	public static function script(){
		echo "<script type='text/javascript' src='https://res.wx.qq.com/open/js/jweixin-1.6.0.js?ver=5.8.1' id='jweixin-js'></script>\n";
		echo "<script type='text/javascript' id='jweixin-js-after'>\n".self::get_share_script()."\n".self::get_inline_script()."\n</script>";
	}

	public static function add_hooks(){
		if(weixin_get_type() >= 3 && weixin_get_setting('weixin_share')){
			add_action('wp_enqueue_scripts', [self::class, 'on_enqueue_scripts'], 9999);
		}
	}
}