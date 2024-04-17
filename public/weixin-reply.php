<?php
if(class_exists('WEIXIN_Reply')){
	return;
}

include WEIXIN_ROBOT_PLUGIN_DIR.'includes/class-weixin-message.php';
include WEIXIN_ROBOT_PLUGIN_DIR.'includes/class-weixin-reply-setting.php';
include WEIXIN_ROBOT_PLUGIN_DIR.'includes/class-weixin-reply.php';

function weixin_register_reply($keyword, $args){
	if(isset($args['response']) && is_array($args['response'])){
		$response_key	= array_key_first($args['response']);
		$response_name	= current($args['response']);

		weixin_register_response_type($response_key, $response_name);

		$args['response']	= $response_key;
	}

	if(isset($args['keywords'])){
		$keywords	= array_pull($args, 'keywords');

		foreach($keywords as $_keyword){
			weixin_register_reply($_keyword, $args);
		}
	}

	$args	= wp_parse_args($args, ['type'=>'full', 'keyword'=>$keyword]);

	return WEIXIN_Builtin_Reply::register($keyword, $args);
}

function weixin_builtin_reply($keyword, $weixin_reply){
	return WEIXIN_Builtin_Reply::reply($keyword, $weixin_reply);
}

function weixin_register_response_type($name, $title){
	return WEIXIN_Message_Response::register($name, ['title'=>$title]);
}

function weixin_register_query($name, $callback){
	return WEIXIN_Query_Reply::register($name, ['callback'=>$callback]);
}

function weixin_query_reply($keyword, $weixin_reply){
	return WEIXIN_Query_Reply::reply($keyword, $weixin_reply);
}

function weixin_get_custom_reply($keyword){
	return WEIXIN_Reply_Setting::get_custom($keyword);
}

function weixin_get_default_reply($keyword){
	return WEIXIN_Reply_Setting::get_default($keyword);
}

if(!weixin_get_appid()){
	return;
}

do_action('weixin_reply_loaded');

foreach([
	'subscribe'		=> '订阅',
	'unsubscribe'	=> '取消订阅',
	'scan'			=> '扫描带参数二维码',

	'custom-text'	=> '自定义文本回复',
	'custom-img'	=> '文章图文回复',
	'custom-img2'	=> '自定义图文回复',
	'custom-news'	=> '自定义素材图文回复',
	'custom-image'	=> '自定义图片回复',
	'custom-voice'	=> '自定义音频回复',
	'custom-music'	=> '自定义音乐回复',
	'custom-video'	=> '自定义视频回复',

	'empty'			=> '空白字符回复',

	'query'			=> '搜索查询回复',
	'too-long'		=> '关键字太长',
	'not-found'		=> '没有匹配内容',

	'3rd'			=> '第三方回复',
	'dkf'			=> '转到多客服'
] as $response_type => $response_title){
	weixin_register_response_type($response_type, $response_title);
}

foreach([
	'[voice]', 
	'[location]', 
	'[image]', 
	'[link]', 
	'[video]', 
	'[shortvideo]',
	'[emotion]'
] as $keyword){
	if(!WEIXIN_Builtin_Reply::get($keyword)){
		weixin_register_reply($keyword,	['type'=>'full',	'reply'=>'默认回复',	'method'=>'default_reply']);
	}
}

foreach([
	'[view]', 
	'[view_miniprogram]',
	'[scancode_push]', 
	'[scancode_waitmsg]', 
	'[location_select]', 
	'[pic_sysphoto]', 
	'[pic_photo_or_album]',
	'[pic_weixin]',

	'[kf_create_session]',
	'[kf_close_session]',
	'[kf_switch_session]',

	'[user_get_card]', 
	'[user_del_card]', 
	'[card_pass_check]', 
	'[card_not_pass_check]', 
	'[user_view_card]', 
	'[user_enter_session_from_card]', 
	'[card_sku_remind]', 
	'[user_consume_card]',
	'[submit_membercard_user_info]',

	'[masssendjobfinish]',
	'[templatesendjobfinish]',

	'[poi_check_notify]',
	'[wificonnected]',
	'[shakearoundusershake]',

	'q'
] as $keyword){
	if(!WEIXIN_Builtin_Reply::get($keyword)){
		weixin_register_reply($keyword,	['type'=>'full',	'reply'=>'']);
	}
}

foreach([
	'[qualification_verify_success]',
	'[qualification_verify_fail]',
	'[naming_verify_success]',
	'[naming_verify_fail]',
	'[annual_renew]',
	'[verify_expired]'
] as $keyword){
	if(!WEIXIN_Builtin_Reply::get($keyword)){
		weixin_register_reply($keyword,		['type'=>'full',	'reply'=>'微信认证回复',	'method'=>'verify_reply']);
	}
}

if(!WEIXIN_Builtin_Reply::get('subscribe')){
	weixin_register_reply('subscribe',		['type'=>'full',	'reply'=>'用户订阅',		'method'=>'subscribe_reply']);
}

if(!WEIXIN_Builtin_Reply::get('unsubscribe')){
	weixin_register_reply('unsubscribe',	['type'=>'full',	'reply'=>'用户取消订阅',	'method'=>'unsubscribe_reply']);
}

if(!WEIXIN_Builtin_Reply::get('scan')){
	weixin_register_reply('scan',			['type'=>'full',	'reply'=>'扫描二维码',	'method'=>'scan_reply']);
}

weixin_register_reply('验证码', ['type'=>'full',	'reply'=>'获取验证码',	'method'=>'verify_code_reply',	'response'=>['verify_code'=>'验证码回复']]);

// 定义高级回复的关键字
if(weixin_has_feature('weixin_search')){
	//按照时间排序
	function weixin_new_posts_reply($keyword, $weixin_reply){
		return $weixin_reply->wp_query_reply([]);
	}

	//随机排序
	function weixin_rand_posts_reply($keyword, $weixin_reply){
		return $weixin_reply->wp_query_reply(['orderby'=>'rand']);
	}

	//按照浏览排序
	function weixin_hot_posts_reply($keyword, $weixin_reply, $date=0){
		$query_args	= [
			'meta_key'	=>'views',
			'orderby'	=>'meta_value_num',
		];

		if($date){
			$query_args['date_query']	= [
				'after'	=> date('Y-m-d', current_time() - $date * DAY_IN_SECONDS)
			];
		}

		return $weixin_reply->wp_query_reply($query_args);
	}

	//按照留言数排序
	function weixin_comment_posts_reply($keyword, $weixin_reply, $date=0){
		global $weixin_reply;

		$query_args	= [
			'orderby'	=>'comment_count',
		];

		if($date){
			$query_args['date_query']	= [
				'after'	=> date('Y-m-d', current_time() - $date * DAY_IN_SECONDS)
			];
		}

		return $weixin_reply->wp_query_reply($query_args);
	}

	//7天内最热
	function weixin_hot_7_posts_reply($keyword, $weixin_reply){
		return weixin_hot_posts_reply($keyword, $weixin_reply, 7);
	}

	//30天内最热
	function weixin_hot_30_posts_reply($keyword, $weixin_reply){
		return weixin_hot_posts_reply($keyword, $weixin_reply, 30);
	}

	//7天内留言最多 
	function weixin_comment_7_posts_reply($keyword, $weixin_reply){
		return weixin_comment_posts_reply($keyword, $weixin_reply, 7);
	}

	//30天内留言最多
	function weixin_comment_30_posts_reply($keyword){
		return weixin_comment_posts_reply($keyword, $weixin_reply, 30);
	}

	$setting	= weixin_get_setting();

	foreach ([
		'new'		=> '最新文章',
		'rand'		=> '随机文章',
		'hot'		=> '最热文章',
		'comment'	=> '评论最多文章',
		'hot-7'		=> '一周最热文章',
		'comment-7'	=> '一周评论文章',
	] as $key => $reply){
		if(!empty($setting[$key])){
			weixin_register_reply($setting[$key],	[
				'type'		=> 'full',
				'reply'		=> $reply,
				'callback'	=> 'weixin_'.str_replace('-', '_', $key).'_posts_reply'
			]);
		}
	}

	if(is_admin()){
		wpjam_register_plugin_page_tab('advanced',	[
			'title'			=> '高级回复',
			'plugin_page'	=> 'weixin-replies',
			'function'		=> 'option',
			'option_name'	=> 'weixin-robot',
			'order'			=> 9,
			'summary'		=> '设置返回下面各种类型文章的关键字。',
			'fields'		=> [
				'new'		=> ['title'=>'最新文章',			'type'=>'text',	'class'=>'',	'value'=>'n'],
				'rand'		=> ['title'=>'随机文章',			'type'=>'text',	'class'=>'',	'value'=>'r'],
				'comment'	=> ['title'=>'留言最高文章',		'type'=>'text',	'class'=>'',	'value'=>'c'],
				'comment-7'	=> ['title'=>'7天留言最高文章',	'type'=>'text',	'class'=>'',	'value'=>'c7'],
				'hot'		=> ['title'=>'浏览最高文章',		'type'=>'text',	'class'=>'',	'value'=>'t'],
				'hot-7'		=> ['title'=>'7天浏览最高文章',	'type'=>'text',	'class'=>'',	'value'=>'t7'],
			]
		]);
	}
};

if(!weixin_get_setting('weixin_permanent_message')){
	WEIXIN_Message::delete_old();
}
