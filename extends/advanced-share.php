<?php
/*
Plugin Name: 高级分享
Plugin URI: 
Description: 可以单独设置每篇文章的分享标题，链接，缩略图，摘要。
Version: 1.0
Author URI: http://blog.wpjam.com/
*/
foreach (['weixin_share_title', 'weixin_share_desc', 'weixin_share_img', 'weixin_share_url'] as $weixin_share_filter) {
	add_filter($weixin_share_filter, function($original){
		if(is_singular()){
			if($filtered = get_post_meta(get_the_ID(), current_filter(), true)){
				return $filtered;
			}
		}

		return $original;
	});
}

if(is_admin()){
	wpjam_register_post_option('weixin-share', [
		'title'		=> '微信分享设置',
		'fields'	=> [
			'weixin_share_title'	=> ['title'=>'分享标题',	'type'=>'text'],
			'weixin_share_desc'		=> ['title'=>'分享描述',	'type'=>'textarea'],
			'weixin_share_img'		=> ['title'=>'分享图片',	'type'=>'image'],
			'weixin_share_url'		=> ['title'=>'分享链接',	'type'=>'url'],
		]
	]);
}