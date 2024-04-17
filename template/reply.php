<?php
if(isset($_GET['echostr'])){
	echo $_GET['echostr'];exit;
}

if(!defined('ABSPATH')){
	include('../../../../wp-load.php');
}

remove_filter('the_title', 'convert_chars');

include WEIXIN_ROBOT_PLUGIN_DIR.'public/weixin-reply.php';

global $wechatObj, $weixin_reply;

define('DOING_WEIXIN_REPLY', true);

// 如果是在被动响应微信消息，和微信用户界面中，设置 is_home 为 false，
add_action('parse_query',function($query){	
	$query->is_home 	= false;
	$query->is_search 	= false;
	$query->is_weixin 	= true;
});

add_filter('wpjam_current_user',	['WEIXIN_Extend', 'filter_current_user']);

$weixin_appid	= weixin_get_appid();
$weixin_setting	= weixin_get_setting();

if(!defined('WEIXIN_SEARCH')) {
	define('WEIXIN_SEARCH', $weixin_setting['weixin_search'] ?? false);
}

$weixin_reply	= new WEIXIN_Reply($weixin_appid, $weixin_setting['weixin_token'], $weixin_setting['weixin_encodingAESKey']);
$wechatObj		= $weixin_reply; // 兼容

if(isset($_GET['debug'])){
	$keyword	= strtolower(trim(wpjam_get_parameter('t')));
	$weixin_reply->set_keyword($keyword);
	$result	= $weixin_reply->response_msg();
}else{
	$result	= $weixin_reply->verify_msg();

	if($result){
		if($result !== true){
			echo $result;
			exit;
		}
	}else{
		echo ' ';
		exit;
	}

	$result	= $weixin_reply->response_msg();

	if(is_wp_error($result)){
		trigger_error($result->get_error_message());
		echo ' ';
	}

	$response	= $weixin_reply->get_response();
	$message	= $weixin_reply->get_message();

	WEIXIN_Message::insert(array_merge($message, ['Response'=>$response]));

	do_action('weixin_message', $message, $response);	// 数据统计
}

exit;


