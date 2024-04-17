<?php
class WEIXIN_Reply_Settings_Admin extends WEIXIN_Reply_Setting{
	public static function get($id){
		$data	= parent::get($id);

		if($data){
			$type	= $data['type'] ?? 'text';
			$data[$type]	= maybe_unserialize($data['reply']);
		}

		return self::parse_data($data);
	}

	public static function parse_data($data){
		$type	= $data['type'] ?? '';

		if($type == 'img'){
			if($data['img']){
				$data['img']	= explode(',', $data['img']);
				$data['img']	= $data['img'][0];
			}
		}elseif($type == 'img2'){
			if($data['img2'] && !is_array($data['img2'])){
				$lines	= explode("\n", $data['img2']);
				$data['img2']	= [];
				$data['img2']['title']			= $lines[0];
				$data['img2']['description']	= $lines[1];
				$data['img2']['pic_url']		= $lines[2];
				$data['img2']['url']			= $lines[3];
			}
		}

		return $data;
	}

	public static function get_views(){
		$views	= [];
		$items	= self::get_by('appid', weixin_get_appid());
		$total	= count(wp_list_filter($items, ['status'=>1]));

		$views['all']	= ['filter'=>['status'=>1, 'match'=>null, 'type'=>null], 'label'=>'全部', 'count'=>$total];

		foreach(self::get_matches() as $match => $title){
			$filtered	= wp_list_filter($items, ['status'=>1, 'match'=>$match]);

			if($filtered){
				$views[$match]	= ['filter'=>['status'=>1, 'match'=>$match], 'label'=>$title, 'count'=>count($filtered)];
			}
		}

		foreach(self::get_types() as $type => $title){
			$filtered	= wp_list_filter($items, ['status'=>1, 'type'=>$type]);

			if($filtered){
				$views[$type]	= ['filter'=>['status'=>1, 'type'=>$type], 'label'=>$title, 'count'=>count($filtered)];
			}
		}

		$status_0	= wp_list_filter($items, ['status'=>0]);

		if($status_0){
			$views['status-0']	= ['filter'=>['status'=>0], 'label'=>'未激活',	'count'=>count($status_0)];
		}

		return $views;
	}

	public static function query_items($args){
		$args['appid']	= weixin_get_appid();
		$args['status']	= wpjam_get_data_parameter('status');
		$args['type']	= wpjam_get_data_parameter('type');
		
		return parent::query_items($args);
	}

	public static function render_item($item){
		$type	= $item['type'];

		if($type == '3rd'){
			$weixin_setting	= weixin_get_setting();
			$item['reply']	= $weixin_setting['weixin_3rd_'.$item['reply']];
		}elseif($type == 'img'){
			$reply_post_ids	= explode(',', $item['reply']);
			$item['reply']	= '';

			$count			= count($reply_post_ids);
			$item_class		= 'big'; 
			$img_width		= 360;
			$img_height		= 0;
			$item_size		= [720,320];

			if($reply_post_ids){
				foreach ($reply_post_ids as $reply_post_id) {
					if($reply_post_id){
						if($reply_post = get_post($reply_post_id)){
							$item_img		= wpjam_get_post_thumbnail_url($reply_post, $item_size);

							if(!$weixin_url = get_post_meta( $reply_post_id, 'weixin_url', true )){
								$weixin_url = get_permalink( $reply_post_id);
							}

							$item_content	= '<span class="item-thumb"><img referrerPolicy="no-referrer" src="'.$item_img.'" '.image_hwstring($img_width, $img_height).' /></span>';
							$item_content	.= '<a target="_blank" href="'.$weixin_url .'"><span class="news-item-title">'.$reply_post->post_title.'</span></a>';

							if($count == 1){
								$item_content	.= '<span class="news-item-excerpt">'.get_the_excerpt($reply_post).'</span>';
							}

							$item['reply']	.= '<div class="news-item '.$item_class.'">'.$item_content.'</div>';

							break;

							$item_class	= 'small'; 
							$img_width	= 0;
							$img_height	= 60;
							$item_size	= [120,120];
						}
					}
				}
				$item['reply']	= '<div class="news-items">'.$item['reply'].'</div>';
			}
		}elseif($type == 'img2'){
			$raw_reply	= str_replace("\r\n", "\n", maybe_unserialize($item['reply']));

			if(is_array($raw_reply)){
				$item_title		= $raw_reply['title'] ?? '';
				$item_excerpt	= $raw_reply['description'] ?? '';
				$item_img		= $raw_reply['pic_url'] ?? '';
				$item_url		= $raw_reply['url'] ?? '';
			}else{
				$lines = explode("\n", $raw_reply);

				$item_title		= $lines[0] ?? '';
				$item_excerpt	= $lines[1] ?? '';
				$item_img		= $lines[2] ?? '';
				$item_url		= $lines[3] ?? '';
			}

			$item_class	= 'big'; 
			$img_width	= 360;
			$img_height	= 0;

			$item_content	= '<span class="item-thumb"><img referrerPolicy="no-referrer" src="'.$item_img.'" '.image_hwstring($img_width, $img_height).' /></span>';
			$item_content	.= '<a target="_blank" href="'.$item_url.'"><span class="news-item-title">'.$item_title.'</span></a>';		
			$item_content	.= '<span class="news-item-excerpt">'.$item_excerpt.'</span>';

			$item['reply']	= '<div class="news-items"><div class="news-item '.$item_class.'">'.$item_content.'</div></div>';
		}elseif($type == 'news'){
			if(weixin_get_type() >= 3){
				$material	= weixin_get_material($item['reply'], 'news');
				if(is_wp_error($material)){
					if($material->get_error_code() == '40007'){
						self::update($item['id'], ['status'=>0]);
					}

					$item['reply'] = $material->get_error_code().' '.$material->get_error_message();
				}else{
					$count	= count($material['news_item']);
					$item['reply']	= '';

					$item_class	= 'big'; 
					$img_width	= 360;
					$img_height	= 0;

					foreach($material['news_item'] as $news_item){
						$item_content	= '<span class="item-thumb"><img referrerPolicy="no-referrer" src="'.$news_item['thumb_url'].'" '.image_hwstring($img_width, $img_height).' /></span>';
						$item_content	.= '<a target="_blank" href="'.$news_item['url'].'"><span class="news-item-title">'.$news_item['title'].'</span></a>';

						if($count == 1 && $news_item['digest']){
							$item_content	.= '<span class="news-item-excerpt">'.$news_item['digest'].'</span>';
						}

						$item['reply']	.= '<div class="news-item '.$item_class.'">'.$item_content.'</div>';

						$item_class	= 'small'; 
						$img_width	= 0;
						$img_height	= 60;

						// break;
					}

					$item['reply'] 	= '<div class="news-items">'.$item['reply'].'</div>';
				}
			}
		}elseif($type == 'image'){
			$response	= weixin_get_material($item['reply'], 'image');

			if(!is_wp_error($response)){
				$image	= weixin()->get_media_url($item['reply']);
				$item['reply']	= '<a href="'.$image.'" target="_blank"><img src="'.$image.'" style="max-width:200px;" /></a>';
			}
		}elseif($type == 'function'){
			if(is_array($item['reply'])){
				$item['reply']	= wpautop($item['reply'][0].'::'.$item['reply'][1]);
				unset($item['row_actions']);
			}else{
				$item['reply']	= wpautop($item['reply']);
			}
		}else{
			$item['reply']	= wpautop($item['reply']);
		}

		return $item;
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建'],
			'edit'		=> ['title'=>'编辑'],
			'duplicate'	=> ['title'=>'复制'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,	'bulk'=>true, 'confirm'=>true],
		];
	}

	public static function get_fields($action_key='', $id=0, $type_key='type'){
		$fields	= parent::get_reply_fields($type_key);

		if($action_key == ''){
			$fields['reply']	= ['title'=>'回复内容',	'type'=>'textarea',	'show_admin_column'=>'only'];
		}

		return $fields;
	}

	public static function get_tabs(){
		$tabs	= [];

		$tabs['custom'] = [
			'title'		=> '自定义回复',
			'function'	=> 'list',
			'defaults'	=> ['status'=>1],
			'singular'	=> 'weixin-custom-reply',
			'plural'	=> 'weixin-rcustom-eplies',
			'model'		=> 'WEIXIN_Reply_Settings_Admin'
		];

		$tabs['default'] = [
			'title'		=> '默认回复',
			'function'	=> 'list',
			'singular'	=> 'weixin-default-reply',
			'plural'	=> 'weixin-default-replies',
			'model'		=> 'WEIXIN_Default_Reply_Setting',
			'search'	=> false
		];

		$tabs['builtin'] = [
			'title'		=> '内置回复',
			'function'	=> 'list',
			'singular'	=> 'weixin-builtin-reply',
			'plural'	=> 'weixin-builtin-replies',
			'model'		=> 'WEIXIN_Builtin_Replies_Admin',
			'per_page'	=> 200,
		];

		$tabs['text']	 = [
			'title'			=> '文本回复附加信息',
			'function'		=> 'option',
			'option_name'	=> 'weixin-robot',
			'order'			=> 8,
			'summary'		=> '文本回复附加信息是指统一在文本回复之后统一添加一段文字。',
			'fields'		=> ['weixin_text_reply_append'	=> ['title'=>'', 'type'=>'textarea', 'style'=>'max-width:640px;', 'rows'=>10]]
		];

		$tabs['third'] 	= [
			'title'			=> '第三方平台',
			'function'		=> 'option',
			'option_name'	=> 'weixin-robot',
			'order'			=> 8,
			'summary'		=> '如果第三方的回复的数据对所有用户都相同，建议缓存。',
			'fields'		=> [
				'weixin_3rd_1_fieldset'	=> ['title'=>'第三方平台1',	'type'=>'fieldset',	'summary'=>'设置「第三方平台1」',	'fields'=>[
					'weixin_3rd_1'			=> ['title'=>'名称',		'type'=>'text',		'class'=>'all-options'],
					'weixin_3rd_cache_1'	=> ['title'=>'缓存时间',	'type'=>'number',	'class'=>'all-options',	'description'=>'秒，输入空或者0为不缓存！'],
					'weixin_3rd_url_1'		=> ['title'=>'链接',		'type'=>'url'],
					'weixin_3rd_search'		=> ['title'=>'',		'type'=>'checkbox',	'description'=>'所有在本站找不到内容的关键词都提交到第三方平台1处理。']
				]],
				'weixin_3rd_2_fieldset'	=> ['title'=>'第三方平台2',	'type'=>'fieldset',	'summary'=>'设置「第三方平台2」',	'fields'=>[
					'weixin_3rd_2'			=> ['title'=>'名称',		'type'=>'text',		'class'=>'all-options'],
					'weixin_3rd_cache_2'	=> ['title'=>'缓存时间',	'type'=>'number',	'class'=>'all-options',	'description'=>'秒'],
					'weixin_3rd_url_2'		=> ['title'=>'链接',		'type'=>'url']
				]],
				'weixin_3rd_3_fieldset'	=> ['title'=>'第三方平台3',	'type'=>'fieldset',	'summary'=>'设置「第三方平台3」',	'fields'=>[
					'weixin_3rd_3'			=> ['title'=>'名称',		'type'=>'text',		'class'=>'all-options'],
					'weixin_3rd_cache_2'	=> ['title'=>'缓存时间',	'type'=>'number',	'class'=>'all-options',	'description'=>'秒'],
					'weixin_3rd_url_3'		=> ['title'=>'链接',		'type'=>'url']
				]]
			]
		];

		if(weixin_get_type() >= 3){
			$tabs['messages']	= [
				'tab_title'	=> '最新消息',
				'order'		=> 8,
				'function'	=> 'list',
				'title'		=> '消息管理',	
				'singular'	=> 'weixin-message',
				'plural'	=> 'weixin-messages',
				'model'		=> 'WEIXIN_Messages_Admin',
			];
		}

		return $tabs;
	}
}

class WEIXIN_Default_Reply_Setting extends WEIXIN_Reply_Settings_Admin{
	public static function get_primary_key(){
		return 'key';
	}

	public static function set($keyword, $data){
		return self::set_by_keyword('['.$keyword.']', $data);
	}

	public static function get($id){
		$defaults		= self::get_defaults();
		$keyword		= '['.$id.']';
		$data			= self::get_by_keyword($keyword);
		$data['key']	= $id;
		$data['title']	= $defaults[$keyword]['title'];

		return self::parse_data($data);
	}

	public static function get_views(){
		return [];
	}

	public static function query_items($args){
		$items	= self::get_defaults();

		if(weixin_get_type() < 4){
			unset($items['[event-location]']);
		}

		array_walk($items, function(&$item, $key){
			$item['keyword']	= $key;
			$item['key']		= str_replace(['[',']'], '', $key);
		});

		return ['items'=>$items, 'total'=>count($items)];
		
	}

	public static function render_item($item){
		$data	= self::get_by_keyword($item['keyword']);
		$item	= wp_parse_args($item, $data);

		return parent::render_item($item);
	}

	public static function get_actions(){
		return ['set'	=> ['title'=>'设置']];
	}

	public static function get_fields($action_key='', $id=0, $type_key='type'){
		$fields	= parent::get_fields($action_key='', $id, $type_key);
		$fields	= ['title'=>['title'=>'类型', 'type'=>'view', 'show_admin_column'=>true]]+$fields;

		$fields['keyword']['type']	= 'hidden';

		return array_except($fields, 'match');
	}

	public static function get_filterable_fields(){
		return [];
	}
}

class WEIXIN_Builtin_Replies_Admin{
	public static function get_primary_key(){
		return 'function';
	}

	public static function query_items($args){
		$items = [];

		foreach(WEIXIN_Builtin_Reply::get_registereds() as $keyword => $builtin){
			$function	= $builtin->get_callback();

			if(is_array($function)){
				if(is_object($function[0])){
					$function	= get_class($function[0]).'->'.$function[1];
				}else{
					$function	= implode('->', $function);
				}
			}

			$keywords = isset($items[$function]['keywords']) ? $items[$function]['keywords'].', ' : '';

			$items[$function]['keywords']	= $keywords.$keyword;
			$items[$function]['type'] 		= $builtin->type;
			$items[$function]['reply'] 		= $builtin->reply;
			$items[$function]['function'] 	= $function;
		}

		return ['items'=>$items, 'total'=>count($items)];
	}

	public static function get_actions(){
		return [];
	}

	public static function get_fields($key='', $id=0){
		$matches	= WEIXIN_Reply_Setting::get_matches();
		return [
			'keywords'	=> ['title'=>'关键字',	'type'=>'view',	'show_admin_column'=>true],
			'type'		=> ['title'=>'匹配方式',	'type'=>'view',	'show_admin_column'=>true,	'options'=>$matches],
			'reply'		=> ['title'=>'描述',		'type'=>'view',	'show_admin_column'=>true],
			'function'	=> ['title'=>'处理函数',	'type'=>'view',	'show_admin_column'=>true]
		];
	}
}

class WEIXIN_Messages_Admin extends WEIXIN_Message{
	public static function reply($id, $data){
		if(!self::can_send($openid)){
			return new WP_Error('out_of_custom_message_time_limit', '48小时没有互动过，无法发送消息！');
		}

		$response	= weixin()->send_custom_message([
			'touser'	=> $data['FromUserName'],
			'msgtype'	=> 'text',
			'text'		=> $data['content']
		]);

		if(is_wp_error($response)){
			return $response;
		}

		$insert_id	= self::insert([
			'MsgType'		=> 'manual',
			'CreateTime'	=> time(),
			'FromUserName'	=> $data['FromUserName'],
			'Content'		=> $data['content'],
		]);

		if(is_wp_error($insert_id)){
			return $insert_id;
		}

		return self::update($id, ['Response'=>$insert_id]);
	}

	public static function send($openid, $content, $type='text', $kf_account=''){
		if(empty($content)){
			return;
		}

		if($type == 'img'){
			$counter = 0;

			$articles = $article	= [];

			$img_reply_query 	= new WP_Query(array('post__in'=>explode(',', $content),'orderby'=>'post__in','post_type'=>'any'));

			if($img_reply_query->have_posts()){
				while ($img_reply_query->have_posts()) {
					$img_reply_query->the_post();

					$article['title']		= html_entity_decode(get_the_title());
					$article['description']	= html_entity_decode(get_the_excerpt());
					$article['url']			= get_permalink();

					if($counter == 0){
						$article['picurl'] = wpjam_get_post_thumbnail_url('', array(640,320));
					}else{
						$article['picurl'] = wpjam_get_post_thumbnail_url('', array(80,80));
					}
					$counter ++;
					$articles[] = $article;
				}
				$type		= 'news';
				$content	= $articles;
			}
			wp_reset_query();
		}elseif($type == 'img2'){
			$articles = $article	= [];

			$items = explode("\n\n", str_replace("\r\n", "\n", $content));

			foreach($items as $item){
				$lines = explode("\n", $item);
				$article['title']		= isset($lines[0])?$lines[0]:'';
				$article['description']	= isset($lines[1])?$lines[1]:'';
				$article['picurl']		= isset($lines[2])?$lines[2]:'';
				$article['url']			= isset($lines[3])?$lines[3]:'';

				$articles[] = $article;
			}
			$type		= 'news';
			$content	= $articles;
		}elseif($type == 'news'){
			$material	= weixin_get_material($content, 'news');

			if(is_wp_error($material)){
				return $material;
			}else{
				$articles = $article	= [];
				
				foreach($material['news_item'] as $news_item){
					$article['title']		= $news_item['title'];
					$article['description']	= $news_item['digest'];
					$article['picurl']		= $news_item['thumb_url'];
					$article['url']			= $news_item['url'];

					$articles[] = $article;
				}
				$type		= 'news';
				$content	= $articles;
			}
		}elseif($type == 'wxcard'){
			$items 		= explode("\n", $content);
			$card_id	= ($items[0])??'';
			$outer_id	= ($items[1])??'';
			$code		= ($items[2])??'';

			$card_ext	= weixin_robot_generate_card_ext(compact('card_id','outer_id','code','openid'));

			$data	= [
				'touser'	=>$openid,
				'msgtype'	=>'wxcard',
				'wxcard'	=>compact('card_id','card_ext')
			];
		}elseif($type == 'text'){
			$content	= compact('content');
		}

		$data	= [
			'touser'	=> $openid,
			'msgtype'   => $type,
			$type 		=> $content,
		];

		if($kf_account){
			$data['customservice']	= compact('kf_account');
		}

		return weixin()->send_custom_message($data);
	}

	public static function get_views(){
		$views	= [];
		
		$views['all']	= ['filter'=>['MsgType'=>''],	'label'=>'全部'];

		foreach(self::get_types()+['manual'=>'需要人工回复'] as $key => $label) {
			$views[$key] = ['filter'=>['MsgType'=>$key], 'label'=>$label];
		}
	
		return $views;
	}

	public static function query_items($args){
		$args['appid']	= weixin_get_appid();

		if(wpjam_get_data_parameter('MsgType') == 'manual'){
			unset($args['manual']);

			$args['Response__in']	= ['not-found', 'too-long'];
			$args['CreateTime__gt']	= self::get_send_limit();
		}else{
			$args['MsgType__not']	= 'manual';
		}

		list('items'=>$items, 'total'=>$total)	= parent::query_items($args);

		if($items){
			$openids 	= array_column($items, 'FromUserName');
			$users		= WEIXIN_Account::batch_get_user_info(weixin_get_appid(), $openids);
		}

		return compact('items', 'total');
	}

	public static function render_item($item){
		$msg_types['manual'] = '需要人工回复';

		$MsgType	= $item['MsgType']; 

		$Response	= $item['Response'];
		$openid		= $item['FromUserName'];
		$user		= weixin_get_user($openid);

		if($user){
			$user['username']	= $user['nickname'] ?? $user['openid'];

			if(!$user['subscribe']){
				$user['username']	= '<span style="color:red; text-decoration:line-through; transform: rotate(1deg);">'.$user['username'].'</span>';
			}

			if(!empty($user['headimgurl'])){
				$user['username']	= '<img src="'.str_replace('/0', '/64', $user['headimgurl']).'" width="32" />'.$user['username'];
			}

			$item['username']	= '[filter FromUserName="'.$openid.'"]'.$user['username'].'[/filter]';
		}else{
			$item['username']	= '';
		}

		$item['name']	= $item['FromUserName'];

		if($MsgType == 'text'){
			$item['Content']	= wp_strip_all_tags($item['Content']); 
		}elseif($MsgType == 'link'){
			$item['Content']	= '<a href="'.$item['Url'].'" target="_blank">'.$item['Title'].'</a>';
		}elseif($MsgType == 'image'){
			if(weixin_get_type() >=3 && $item['CreateTime'] > self::get_send_limit()){
				$item['Content']	= '<a href="'.weixin_get_media($item['MediaId']).'" target="_blank" title="'.$item['MediaId'].'"><img src="'.weixin_get_media($item['MediaId']).'" alt="'.$item['MediaId'].'" width="100px;"></a>';
				$item['Content']	.= '<br /><a href="'.weixin_get_media($item['MediaId'], 'api').'">下载图片</href>';
			}else{
				$item['Content']	.= '图片已过期，不可下载';
			}
			if(isset($_GET['debug'])) $item['Content']	.=  '<br />MediaId：'.$item['MediaId'];
		}elseif($MsgType == 'location'){
			$location = maybe_unserialize($item['Content']);
			if(is_array($location)){
				$item['Content'] = '<img src="http://st.map.qq.com/api?size=300*150&center='.$location['Location_Y'].','.$location['Location_X'].'&zoom=15&markers='.$location['Location_Y'].','.$location['Location_X'].'" />';
				if(isset($location['Label'])) $item['Content'] .= '<br />'.$location['Label'];
			}
		}elseif($MsgType == 'voice'){
			if($item['Content']){
				$item['Content']	= '语音识别成：'.wp_strip_all_tags($item['Content']);
			}
			if(weixin_get_type() >=3 && $item['CreateTime'] > self::get_send_limit()){
				$item['Content']	= $item['Content'].'<br /><a href="'.weixin_get_media($item['MediaId'], 'api').'">下载语音</href>';
			}
			if(isset($_GET['debug'])) $item['Content']	.= '<br />MediaId：'.$item['MediaId'];
		}elseif($MsgType == 'video' || $MsgType == 'shortvideo'){
			if(weixin_get_type() >=3 && $item['CreateTime'] > self::get_send_limit()){
				$item['Content']	= '<a href="'.weixin_get_media($item['MediaId'], 'api').'" target="_blank" title="'.$item['MediaId'].'"><img src="'.weixin_get_media($item['Url']).'" alt="'.$item['Url'].'" width="100px;"><br >点击下载视频</a>';
			}else{
				$item['Content']	.= '视频已过期，不可下载';
			}
		}elseif($MsgType == 'event'){
			$Event = strtolower($item['Event']);
			if($Event == 'click'){
				$item['Content']	= '['.$item['Event'].'] '.$item['EventKey']; 
			}elseif($Event == 'view'){
				$item['Content']	= '['.$item['Event'].'] '.'<a href="'.$item['EventKey'].'">'.$item['EventKey'].'</a>'; 
			}elseif($Event == 'location'){
				// $location = maybe_unserialize($item['Content']);
				// if(is_array($location)){
				// 	$item['Content'] = '<img src="http://st.map.qq.com/api?size=300*150&center='.$location['Location_Y'].','.$location['Location_X'].'&zoom=15&markers='.$location['Location_Y'].','.$location['Location_X'].'" />';
				// }
				$item['Content']	= '['.$item['Event'].'] ';
			}elseif ($Event == 'templatesendjobfinish') {
				$item['Content']	= '['.$item['Event'].'] '.$item['EventKey'];
			}elseif ($Event == 'masssendjobfinish') {
				$count_array		= maybe_unserialize($item['Content']);
				if(is_array($count_array)){
					$item['Content']	= '['.$item['Event'].'] '.$item['EventKey'].'<br />'.'所有：'.$count_array['TotalCount'].'过滤之后：'.$count_array['FilterCount'].'发送成功：'.$count_array['SentCount'].'发送失败：'.$count_array['ErrorCount'];
				}
			}elseif($Event == 'scancode_push' || $Event == 'scancode_waitmsg'){
				$item['Content']	= '['.$item['Event'].'] '.$item['Title'].'<br />'.$item['Content'];
			}elseif($Event == 'location_select'){
				$location = maybe_unserialize($item['Content']);
				if(is_array($location)){
					$item['Content'] = '<img src="http://st.map.qq.com/api?size=300*150&center='.$location['Location_Y'].','.$location['Location_X'].'&zoom=15&markers='.$location['Location_Y'].','.$location['Location_X'].'" />';
					if(isset($location['Label'])) $item['Content'] .= '<br />'.$location['Label'];
				}
			}else{
				$item['Content']	= '['.$item['Event'].'] '.$item['EventKey'];
			}
		}

		if(is_numeric($Response) ){
			$item['Response'] = '人工回复';
			$reply_message = self::get($Response);
			if($reply_message){
				$item['Content']	.= '<br /><span style="background-color:yellow; padding:2px; ">人工回复：'.$reply_message['Content'].'</span>';
			}
		}elseif(isset($responses[$Response])){
			$item['Response'] = $responses[$Response];	
		}

		if($item['CreateTime'] > self::get_send_limit()){
			// $row_actions = [];
			if(is_numeric($Response)){
				// $item['row_actions']['reply']	= '已经回复';
				unset($item['row_actions']['reply']);
				unset($item['row_actions']['delete']);
			}elseif(empty($user['subscribe'])){
				unset($item['row_actions']['reply']);
				unset($item['row_actions']['delete']);
				// $row_actions['reply']	= '<a href="'.admin_url('page=weixin-masssend&tab=custom&openid='.$user['openid'].'&reply_id='.$item['id'].'&TB_iframe=true&width=780&height=420').'" title="回复客服消息" class="thickbox" >回复</a>';
			}
			// $item['row_actions']	= $row_actions;
		}else{
			unset($item['row_actions']['reply']);
			// unset($item['row_actions']['delete']);
		}

		$item['CreateTime']	= get_date_from_gmt(date('Y-m-d H:i:s',$item['CreateTime']));

		return $item;
	}

	public static function get_actions(){
		return [
			'reply'		=> ['title'=>'回复',	'page_title'=>'回复客服消息'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,	'confirm'=>true,	'bulk'=>true],
		];
	}

	public static function get_fields($action_key='', $id=''){
		if($action_key == 'reply'){
			return [
				'FromUserName'	=> ['title'=>'',	'type'=>'hidden'],
				'type'			=> ['title'=>'',	'type'=>'hidden',	'value'=>'text'],
				'content'		=> ['title'=>'',	'type'=>'textarea']
			];
		}else{
			return [
				'username'	=> ['title'=>'用户',	'type'=>'text',		'show_admin_column'=>true],
				// 'address'	=> ['title'=>'地址',	'type'=>'text',		'show_admin_column'=>true],
				'MsgType'	=> ['title'=>'类型',	'type'=>'select',	'show_admin_column'=>true,	'options'=>self::get_types()],
				'Content'	=> ['title'=>'内容',	'type'=>'text',		'show_admin_column'=>true],
				'Response'	=> ['title'=>'回复',	'type'=>'select',	'show_admin_column'=>true,	'options'=>self::get_responses()],
				'CreateTime'=> ['title'=>'时间',	'type'=>'text',		'show_admin_column'=>true],
			];
		}
	}
}

wp_add_inline_style('list-tables', join("\n",[
	'th.column-title{width:126px;}',
	'th.column-keyword{width:20%; min-width:300px;}',
	'th.column-keywords{width:40%;}',
	'th.column-match{width:70px;}',
	'th.column-type{width:84px;}',
	'th.column-status{width:56px;}',
	'th.column-MsgType{width:60px;}',
	'th.column-Response{width:94px;}',
	'th.column-CreateTime{width:84px;}',
	'th.column-username{width:240px;}'
]));