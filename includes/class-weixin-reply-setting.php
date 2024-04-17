<?php
class WEIXIN_Reply_Setting extends WPJAM_Model{
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

	public static function sanitize_data($data, $id=0){
		if($id == 0){
			$data['time']	= time();
			$data['appid']	= weixin_get_appid();
		}

		$data['type']	= $type = $data['type'] ?? 'text';
		$data['match']	= $data['match'] ?? 'full';
		$data['reply']	= $data['reply'] ?? maybe_serialize(array_pull($data, $type));

		return array_except($data, array_keys(self::get_types()));
	}

	public static function set_by_keyword($keyword, $data){
		if(empty($keyword)){
			return new WP_Error('empty_keyword', '关键字不能为空');
		}

		$reply	= self::get_by_keyword($keyword);
		$id		= ($reply && isset($reply['id'])) ? $reply['id'] : 0;

		if($id){
			$result	= self::update($id, $data);

			return is_wp_error($result) ? $result : $id;
		}else{
			return self::insert(array_merge($data, ['keyword'=>$keyword]));
		}
	}

	public static function get_by_keyword($keyword){
		$customs	= self::get_customs();

		if(isset($customs[$keyword])) {
			$data			= current($customs[$keyword]);
			$data['type']	= $type = $data['type'] ?? 'text';
			$data[$type]	= maybe_unserialize($data['reply']);
		}else{
			$builtin	= WEIXIN_Builtin_Reply::get($keyword);

			if($builtin){
				$data	= $builtin->get_data();
			}else{
				$defaults	= self::get_defaults();

				if(!isset($defaults[$keyword])){
					return [];
				}

				$data	= ['status'=>1, 'keyword'=>$keyword];

				$data['type']	= 'text';
				$data['match']	= 'full';
				$data['reply']	= $data['text']	= $defaults[$keyword]['value'];
			}
		}

		return $data;
	}

	public static function get_custom($keyword){
		foreach(['full', 'prefix', 'fuzzy'] as $match){
			$customs	= self::get_customs($match);

			if($customs){
				if($match == 'full'){
					if(isset($customs[$keyword])){
						$item	= $customs[$keyword];
						break;
					}
				}elseif($match == 'prefix'){
					$prefix_keyword = mb_substr($keyword, 0, 2);	// 前缀匹配，只支持2个字

					if(isset($customs[$prefix_keyword])){
						$item	= $customs[$prefix_keyword];
						break;
					}
				}elseif($match == 'fuzzy'){
					if(preg_match('/'.implode('|', array_keys($customs)).'/', $keyword, $matches)){
						$fuzzy_keyword	= $matches[0];
						$item	= $customs[$fuzzy_keyword];
						break;
					}
				}
			}
		}

		if(isset($item)){
			$rand_key	= array_rand($item, 1);
			return $item[$rand_key];
		}

		return false;
	}

	public static function get_default($keyword){
		$defaults	= self::get_defaults(); 

		return isset($defaults[$keyword]) ? $defaults[$keyword]['value'] : '';
	}

	protected static function get_customs($match=null){
		$customs	= []; 

		foreach(self::get_by('appid', weixin_get_appid()) as $item){
			if($item['status'] != 1 || ($match && $item['match'] != $match)){
				continue;
			}

			$key	= strtolower(trim($item['keyword']));

			if(strpos($key, ',')){
				foreach(explode(',', $key) as $new_key){
					$new_key = strtolower(trim($new_key));

					if($new_key !== ''){
						$customs[$new_key][] = $item;
					}
				}
			}else{
				$customs[$key][] = $item;
			}
		}

		if($match == 'full'){
			foreach(['[too-long]','[default]'] as $keyword){	// 将这两个作为函数回复写入到自定义回复中
				if(isset($customs[$keyword])){
					continue;
				}

				$builtin	= WEIXIN_Builtin_Reply::get($keyword);

				if($builtin){
					$item = [];

					$item['keyword']	= $keyword;
					$item['reply']		= $builtin->function;
					$item['type']		= 'function';

					$customs[$keyword][]	= $item;
				}
			}
		}

		// 按照键的长度降序排序
		uksort($customs, function ($v, $w){
			return (mb_strwidth($v) <=> mb_strwidth($w));
		});

		return $customs;
	}

	protected static function get_defaults(){
		return [
			'[subscribe]'		=> ['title'=>'用户关注时',	'value'=>'欢迎关注！'],
			'[event-location]'	=> ['title'=>'进入服务号',	'value'=>'欢迎再次进来！'],
			'[default]'			=> ['title'=>'没有匹配时',	'value'=>'抱歉，没有找到相关的文章，要不你更换一下关键字，可能就有结果了哦 :-)'],
			'[too-long]'		=> ['title'=>'文本太长时',	'value'=>'你输入的关键字太长了，系统没法处理了，请等待公众账号管理员到微信后台回复你吧。'],
			'[emotion]'			=> ['title'=>'发送表情',		'value'=>'已经收到你的表情了！'],
			'[voice]'			=> ['title'=>'发送语音',		'value'=>''],
			'[location]'		=> ['title'=>'发送位置',		'value'=>''],
			'[image]'			=> ['title'=>'发送图片',		'value'=>''],
			'[link]'			=> ['title'=>'发送链接',		'value'=>'已经收到你分享的信息，感谢分享。'],
			'[video]'			=> ['title'=>'发送视频',		'value'=>'已经收到你分享的信息，感谢分享。'],
			'[shortvideo]'		=> ['title'=>'发送短视频',	'value'=>'已经收到你分享的信息，感谢分享。'],
		];
	}

	public static function get_types($all=false){
		$types = [
			'text'	=> '文本',
			'img2'	=> '自定义图文',
			'img'	=> '文章图文',
			'image'	=> '图片',
			'voice'	=> '语音',
			// 'video'	=> '视频'
		];

		if(weixin_get_type() >=3 || $all){
			$types['news']	= '素材图文';
			$types['music']	= '音乐';
		}

		$types['3rd']		= '转到第三方';
		$types['function']	= '函数';

		return $types;
	}

	public static function get_matches(){
		return [
			'full'		=>'完全匹配',
			'prefix'	=>'前缀匹配',
			'fuzzy'		=>'模糊匹配'
		];
	}

	public static function get_reply_fields($type_key='type'){
		$types		= self::get_types();
		$setting	= weixin_get_setting();

		$third_options	= [];

		foreach([1,2,3] as $i){
			if(!empty($setting['weixin_3rd_'.$i]) && !empty($setting['weixin_3rd_url_'.$i])){
				$third_options[$i] = $setting['weixin_3rd_'.$i];
			}
		}

		if(!$third_options){
			unset($types['3rd']);
		}

		if(empty($setting['weixin_search'])){
			unset($types['img']);
		}

		$type_fields	= [
			'text'		=> ['title'=>'文本内容',		'type'=>'textarea',	'description'=>'请输入要回复的文本，可以使用 a 标签实现链接跳转。'],
			'img2'		=> ['title'=>'自定义图文',	'type'=>'fieldset',	'fieldset_type'=>'array',	'fields'=>[
				'title'			=> ['title'=>'标题',	'type'=>'text'],
				'description'	=> ['title'=>'摘要',	'type'=>'textarea',	'rows'=>3],
				'pic_url'		=> ['title'=>'图片',	'type'=>'image'],
				'url'			=> ['title'=>'链接',	'type'=>'url'],
			]],
			'img'		=> ['title'=>'文章图文',		'type'=>'number',	'description'=>'请输入文章 ID。'],
			'news'		=> ['title'=>'素材图文',		'type'=>'text',		'class'=>'large-text',	'description'=>'请输入图文的 Media ID'],
			'image'		=> ['title'=>'图片',			'type'=>'text',		'class'=>'large-text',	'description'=>'请输入图片的 Media ID'],
			'voice'		=> ['title'=>'语音',			'type'=>'text',		'class'=>'large-text',	'description'=>'请输入语音的 Media ID'],
			'music'		=> ['title'=>'音乐',			'type'=>'textarea',	'description'=>'请输入音乐的标题，描述，链接，高清链接，缩略图的 Media ID，每个一行。'],
			'3rd'		=> ['title'=>'转到第三方',	'type'=>'select',	'options'=>$third_options],
			'wxcard'	=> ['title'=>'微信卡券id',	'type'=>'text',		'class'=>'large-text',	'description'=>'请输入微信卡券ID。'],
			'function'	=> ['title'=>'函数',			'type'=>'text',		'class'=>'large-text',	'description'=>'请输入函数名，该功能仅限于程序员测试使用。',]
		];

		$fields	= [
			'keyword'	=> ['title'=>'关键字',	'type'=>'text',		'show_admin_column'=>true,	'description'=>'多个关键字请用<strong>英文逗号</strong>分开'],
			'match'		=> ['title'=>'匹配方式',	'type'=>'radio',	'show_admin_column'=>true,	'options'=>self::get_matches(),	'description'=>'<p>前缀匹配支持匹配前两个中文字或字母。<br />模糊匹配效率比较低，请不要大量使用。</p>'],
			$type_key	=> ['title'=>'回复类型',	'type'=>'select',	'show_admin_column'=>true,	'options'=>$types]
		];

		foreach($types as $type => $label){
			$fields[$type]	= array_merge($type_fields[$type], ['show_if'=>['key'=>$type_key, 'value'=>$type]]);
		}

		$fields['status']	= ['title'=>'状态',		'type'=>'checkbox',	'description'=>'激活',	'value'=>1];

		return $fields;
	}

	public static function get_table(){
		return $GLOBALS['wpdb']->base_prefix.'weixin_replies';
	}

	public static function get_handler(){
		$table		= self::get_table();
		$handler	= wpjam_get_handler($table);

		return $handler ?: wpjam_register_handler([
			'table_name'		=> $table,
			'primary_key'		=> 'id',
			'cache_key'			=> 'appid',
			'cache_group'		=> ['weixin_replies', true],
			'field_types'		=> ['id'=>'%d'],
			'searchable_fields'	=> ['keyword', 'reply'],
			'filterable_fields'	=> ['match','type','status'],
		]);
	}

	public static function create_table(){
		$table = self::get_table();

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		if($GLOBALS['wpdb']->get_var("show tables like '".$table."'") != $table) {
			$sql = "
			CREATE TABLE IF NOT EXISTS {$table} (
				`id` bigint(20) NOT NULL auto_increment,
				`appid` varchar(32) NOT NULL,
				`keyword` varchar(255) NOT NULL,
				`match` varchar(10) NOT NULL default 'full',
				`reply` text NOT NULL,
				`status` int(1) NOT NULL default '1',
				`time` datetime NOT NULL default '0000-00-00 00:00:00',
				`type` varchar(10) NOT NULL default 'text',
				PRIMARY KEY  (`id`)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			";
	 
			dbDelta($sql);

			$GLOBALS['wpdb']->query("ALTER TABLE `{$table}`
				ADD KEY `match` (`match`),
				ADD KEY `status` (`status`),
				ADD KEY `type` (`type`);");
		}
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
			$post_id	= weixin_download_material($item['reply']);

			if(!is_wp_error($post_id)){
				$image	= wp_get_attachment_url($post_id);

				$item['reply']	= '<a href="'.wpjam_get_thumbnail($image).'" target="_blank"><img src="'.wpjam_get_thumbnail($image, '400x').'" style="max-width:200px;" /></a>';
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
		$fields	= self::get_reply_fields($type_key);

		if($action_key == ''){
			$fields['reply']	= ['title'=>'回复内容',	'type'=>'textarea',	'show_admin_column'=>'only'];
		}

		return $fields;
	}

	public static function get_tabs(){
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

		$tabs	= [];

		$tabs['custom'] = [
			'title'		=> '自定义回复',
			'function'	=> 'list',
			'defaults'	=> ['status'=>1],
			'singular'	=> 'weixin-custom-reply',
			'plural'	=> 'weixin-rcustom-eplies',
			'model'		=> 'WEIXIN_Reply_Setting'
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
			'model'		=> 'WEIXIN_Builtin_Reply',
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
				'model'		=> 'WEIXIN_Message',
			];
		}

		return $tabs;
	}
}

class WEIXIN_Default_Reply_Setting extends WEIXIN_Reply_Setting{
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

class WEIXIN_Builtin_Reply extends WPJAM_Register{
	public function get_callback(){
		if($this->callback){
			return $this->callback;
		}elseif($this->function){
			return $this->function;
		}elseif($this->method){
			return ['WEIXIN_Reply', $this->method];
		}else{
			return '';
		}
	}

	public function get_data(){
		$callback	= $this->get_callback();
		$type		= $callback ? 'function' : 'text';

		return [
			'status'	=> 1,
			'keyword'	=> $this->name,
			'match'		=> $this->type,
			'type'		=> $type,
			'reply'		=> $callback,
			$type		=> $callback
		];
	}

	public static function get_object($keyword){
		$object	= self::get($keyword);

		if($object && $object->type == 'full'){
			return $object;
		}

		$keyword	= mb_substr($keyword, 0, 2);	// 前缀匹配，只支持2个字
		$object		= self::get($keyword);

		if($object && $object->type == 'prefix'){
			return $object;
		}

		return false;
	}

	public static function reply($keyword, $weixin_reply){
		$object	= self::get_object($keyword);

		if(empty($object)){
			return false;
		}

		if($object->response){
			$weixin_reply->set_response($object->response);
		}

		if($object->method){
			$callback	= [$weixin_reply, $object->method];
		}else{
			$callback	= $object->get_callback();
		}

		if($callback && is_callable($callback)){
			$result = call_user_func($callback, $weixin_reply->keyword, $weixin_reply);

			return $result === false ? false : true;
		}else{
			echo ' ';

			return true;
		}
	}

	public static function get_primary_key(){
		return 'function';
	}

	public static function query_items($args){
		$items = [];

		foreach(self::get_registereds() as $keyword => $builtin){
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

class WEIXIN_Query_Reply extends WPJAM_Register{
	public static function reply($keyword, $weixin_reply){
		foreach(self::get_registereds() as $object){
			if(call_user_func($object->callback, $keyword, $weixin_reply)){
				return true;
			}
		}
	}
}