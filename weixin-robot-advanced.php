<?php
/*
Plugin Name: 微信机器人高级版
Plugin URI: https://blog.wpjam.com/project/weixin-robot-advanced/
Description: 微信机器人的主要功能就是能够将你的公众账号和你的 WordPress 博客联系起来，搜索和用户发送信息匹配的文章，并自动回复用户，让你使用微信进行营销事半功倍。
Version: 6.2.2.1
Requires at least: 6.0
Tested up to: 6.2
Requires PHP: 7.2
Author: Denis
Author URI: http://blog.wpjam.com/
Update URI: https://blog.wpjam.com/project/weixin-robot-advanced/
*/
function weixin_loaded(){
	if(defined('WEIXIN_ROBOT_PLUGIN_DIR')){
		return;
	}

	define('WEIXIN_ROBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
	define('WEIXIN_ROBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));

	include __DIR__.'/includes/class-weixin.php';
	include __DIR__.'/includes/class-weixin-user.php';

	include __DIR__.'/public/weixin-setting.php';
	include __DIR__.'/public/weixin-extends.php';

	do_action('weixin_loaded');
}

if(did_action('wpjam_loaded')){
	weixin_loaded();
}else{
	add_action('wpjam_loaded', 'weixin_loaded');
}


