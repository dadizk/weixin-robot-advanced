<?php
/*
Plugin Name: 有道中英翻译
Plugin URI: http://wpjam.net/item/wpjam-weixin-youdao-translate/
Description: 发送【翻译 xxx】来就可以进行翻译了，比如翻译中文为英语。
Version: 1.4
Author URI: http://blog.wpjam.com/
*/
class WEIXIN_Youdao{
	public static function translate_reply($keyword, $weixin_reply){
		if($keyword == '翻译' || $keyword == '中英翻译'){
			$weixin_reply->set_context_reply('翻译');
			$weixin_reply->textReply("你已经进入翻译模式，请输入要翻译单词或者句子。\n\n退出翻译请输入：Q，或者1分钟后自动退出");
		}elseif($keyword == 'q'){
			$weixin_reply->delete_context_reply();
			$weixin_reply->textReply("你已经退出了翻译模式，下次要进行翻译，请再次输入：翻译");
		}else{
			$keyword = str_replace(['翻译','fy'], '', $keyword);
			$results = $keyword ? self::get_translate_results($keyword) : false;

			if($results){
				$weixin_reply->textReply($results);
			}else{
				$weixin_reply->textReply('翻译失败');
			}
		}

		return true;
	}

	public static function get_translate_results($keyword){
		$weixin_setting	= weixin_get_setting();

		$url = 'http://fanyi.youdao.com/openapi.do?keyfrom='.$weixin_setting['youdao_translate_key_from']."&key=".$weixin_setting['youdao_translate_api_key'].'&type=data&doctype=json&version=1.1&q='.urlencode($keyword);
		
		
		$responese = wpjam_remote_request($url);

		if(is_wp_error($responese)){
			return false;
		}
		
		if(isset($responese['errorCode'])){
			$result = "";

			switch ($responese['errorCode']){
				case 0:
					$translation = $responese['translation'] ?? '';

					if($translation){
						$result .= $translation[0]."\n";
						if (isset($responese['basic'])){
							$result .= isset($responese['basic']['phonetic'])?($responese['basic']['phonetic'])."\n":"";
							foreach ($responese['basic']['explains'] as $value) {
								$result .= $value."\n";
							}
						}
					}else{
						$result = "错误：请重试";
					}
					break;
				case 20:
					$result = "错误：要翻译的文本过长";
					break;
				case 30:
					$result = "错误：无法进行有效的翻译";
					break;
				case 40:
					$result = "错误：不支持的语言类型";
					break;
				case 50:
					$result = "错误：无效的密钥";
					break;
				default:
					$result = "错误：原因未知，错误码：".$responese['errorCode'];
					break;
			}
			return trim($result);
		}else{
			return false;
		}
	}
}

add_action('weixin_reply_loaded', function(){
	weixin_register_reply('fy',		['type'=>'prefix',	'reply'=>'中英翻译',	'response'=>'translate', 'callback'=>['WEIXIN_Youdao', 'translate_reply']]);
	weixin_register_reply('翻译',	['type'=>'prefix',	'reply'=>'中英翻译',	'response'=>'translate', 'callback'=>['WEIXIN_Youdao', 'translate_reply']]);
	weixin_register_reply('中英翻译',	['type'=>'full',	'reply'=>'中英翻译',	'response'=>'translate', 'callback'=>['WEIXIN_Youdao', 'translate_reply']]);

	weixin_register_response_type('translate', '有道翻译');
});	

if(is_admin()){
	wpjam_add_admin_load([
		'plugin_page'	=> 'weixin-setting',
		'callback'		=> function(){
			add_filter('weixin_setting',function ($sections){
				return $sections + ['youdao_translate'=>[
					'title'		=>'有道翻译', 
					'fields'	=>[
						'youdao_translate_api_key'	=> ['title'=>'有道翻译API Key',	'type'=>'text',	 'description'=>'点击<a href="http://fanyi.youdao.com/openapi?path=data-mode">这里</a>申请有道翻译API！'],
						'youdao_translate_key_from'	=> ['title'=>'有道翻译KEY FROM',	'type'=>'text',	 'description'=>'申请有道翻译API的时候同时填写并获得KEY FROM']
					]]
				];
			},11);
		}
	]);
}

