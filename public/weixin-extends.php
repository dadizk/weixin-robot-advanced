<?php
class WEIXIN_Extend extends WPJAM_Register{
	public static function debug_callback($args){
		return strpos($args['caller'], 'weixin') !== false || strpos($args['caller'], 'WEIXIN') !== false || strpos($args['file'], 'weixin-') !== false;
	}

	public static function lazyload_callback($openids, $appid){
		WEIXIN_Account::update_caches($appid, $openids);
	}

	public static function activation(){
		self::call_active('create_table');
	}

	public static function redirect($action){
		self::call_active('redirect', $action);
	}

	public static function get_rewrite_rule(){
		add_rewrite_tag('%weixin%', '([^/]+)', "module=weixin&action=");
		add_permastruct('weixin', 'weixin/%weixin%', ['with_front'=>false, 'paged'=>false, 'feed'=>false]);
	}

	protected static function get_config($key){
		if(in_array($key, ['menu_page', 'register_json', 'init'])){
			return true;
		}
	}
}

function weixin_register_extend($name, $args){
	return WEIXIN_Extend::register($name, $args);
}

function weixin_activation(){
	flush_rewrite_rules();

	WEIXIN_Extend::activation();
}

weixin_register_extend('hook', [
	'title'	=> 'Hook',
	'model'	=> 'WEIXIN_Hook',
	'file'	=> __DIR__.'/weixin-hook.php',
]);

weixin_register_extend('jssdk', [
	'title'	=> 'JSSDK',
	'model'	=> 'WEIXIN_JSSDK',
	'file'	=> __DIR__.'/weixin-jssdk.php',
]);

weixin_register_extend('oauth', [
	'title'		=> 'OAuth',
	'model'		=> 'WEIXIN_OAuth',
	'file'		=> __DIR__.'/weixin-oauth.php',
]);

weixin_register_extend('template', [
	'title'	=> '模板消息',
	'model'	=> 'WEIXIN_Template',
	'file'	=> __DIR__.'/weixin-template.php',
]);

wpjam_load('wpjam_debug_loaded', function(){
	wpjam_register_debug_type('weixin', [
		'name'		=> '微信公众号插件警告',
		'callback'	=> ['WEIXIN_Extend', 'debug_callback']
	]);
});

wpjam_register_lazyloader('weixin_account',	[
	'filter'		=> 'weixin_get_account',
	'callback'		=> ['WEIXIN_Extend', 'lazyload_callback'],
	'accepted_args'	=> 2
]);

wpjam_register_route('weixin', [
	'callback'		=> ['WEIXIN_Extend', 'redirect'],
	'rewrite_rule'	=> ['WEIXIN_Extend', 'get_rewrite_rule']
]);

if(weixin_doing_reply()){
	ini_set('display_errors', 0);

	// 优化微信自定义回复
	remove_action('set_comment_cookies', 'wp_set_comment_cookies', 10, 3);
	remove_action('sanitize_comment_cookies', 'sanitize_comment_cookies');

	remove_filter('determine_current_user', 'wp_validate_auth_cookie');
	remove_filter('determine_current_user', 'wp_validate_logged_in_cookie', 20);

	remove_action('init', 'wp_widgets_init', 1);
	remove_action('init', 'maybe_add_existing_user_to_blog');
	remove_action('init', 'check_theme_switched', 99);
	remove_action('init', '_register_core_block_patterns_and_categories' );
	remove_action('init', ['WP_Block_Supports', 'init'], 22 );

	remove_action('plugins_loaded', 'wp_maybe_load_widgets', 0);
	remove_action('plugins_loaded', 'wp_maybe_load_embeds', 0);
	remove_action('plugins_loaded', '_wp_customize_include');
	remove_action('plugins_loaded', '_wp_theme_json_webfonts_handler');

	remove_action('wp_loaded', '_custom_header_background_just_in_time');
}
	