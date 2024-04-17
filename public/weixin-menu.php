<?php
class WEIXIN_Menu_Button{
	public static function get_primary_key(){
		return 'pos';
	}

	public static function get_menu(){
		$menu_id	= wpjam_get_data_parameter('menu_id');

		return WEIXIN_Menu::get($menu_id);
	}

	public static function get($pos){
		$menu	= self::get_menu();

		if(!$menu){
			return ['pos'=>$pos];
		}

		list($position, $sub_position)	= explode('_', $pos);

		if($sub_position == -1){
			$button	= $menu['button'][$position]??[];
		}else{
			$button	= $menu['button'][$position]['sub_button'][$sub_position]??[];
		}

		$button['pos']	= $pos;

		return $button;
	}

	public static function update($pos, $data){
		$menu	= self::get_menu();
		$data	= array_except($data, 'menu_id');

		$buttons	= $menu['button'] ?? [];

		list($position, $sub_position)	= explode('_', $pos);

		if($sub_position == -1){
			if($data['type'] == 'main'){
				$data['type']	= '';
				$data['key']	= $data['url'] = '';

				if(!empty($buttons[$position]['sub_button'])){
					$data['sub_button']	= $buttons[$position]['sub_button'];
				}
			}

			$buttons[$position]	= $data;
		}else{
			$buttons[$position]['sub_button'][$sub_position]	= $data;
		}

		if($menu){
			$index	= $menu['index'] ?? '';

			return WEIXIN_Menu::update($index, ['button'=>$buttons]);
		}else{
			return WEIXIN_Menu::insert(['button'=>$buttons,'type'=>'menu']);
		}
	}

	public static function reply($pos, $data){
		$reply_type	= $data['reply_type'] ?? 'text';
		$reply		= $data[$reply_type];
		$button		= self::get($pos);

		return WEIXIN_Reply_Setting::set_by_keyword($button['key'], [
			'match'		=> 'full',
			'type'		=> $reply_type,
			$reply_type	=> $reply,
			'status'	=> 1
		]);
	}

	public static function delete($pos){
		$menu	= self::get_menu();

		if(!$menu){
			return;
		}

		list($position, $sub_position)	= explode('_', $pos);

		$buttons	= $menu['button'];

		if($sub_position == -1){
			$buttons	= array_values(array_except($buttons, $position));
		}else{
			$buttons[$position]['sub_button']	= array_values(array_except($buttons[$position]['sub_button'], $sub_position));
		}

		$index	= $menu['index'] ?? '';

		return WEIXIN_Menu::update($index, ['button'=>$buttons]);
	}

	public static function move_down($pos){
		return self::move($pos, 'down');
	}

	public static function move_up($pos){
		return self::move($pos, 'up');
	}

	public static function move($pos, $type='up'){
		$menu	= self::get_menu();

		if(!$menu){
			return;
		}

		list($position, $sub_position)	= explode('_', $pos);

		$buttons	= $menu['button'];
		$offset		= $type == 'up' ? -1 : 1;

		if($sub_position == -1){
			$temp_button	= $buttons[$position];
			$old_position	= $position+$offset;

			$buttons[$position]		= $buttons[$old_position];
			$buttons[$old_position]	= $temp_button;

		}else{
			$temp_sub_button	= $buttons[$position]['sub_button'][$sub_position];
			$old_sub_position	= $sub_position+$offset;

			$buttons[$position]['sub_button'][$sub_position]		= $buttons[$position]['sub_button'][$old_sub_position];
			$buttons[$position]['sub_button'][$old_sub_position]	= $temp_sub_button;
		}

		$index	= $menu['index'] ?? '';

		return WEIXIN_Menu::update($index, ['button'=>$buttons]);
	}

	public static function sync(){
		return WEIXIN_Menu::sync();
	}

	public static function create($data=[]){
		$menu_id	= wpjam_get_data_parameter('menu_id');

		return WEIXIN_Menu::create_menu($menu_id);
	}

	public static function duplicate(){
		$menu_id	= wpjam_get_data_parameter('menu_id');

		if($menu_id){
			$menu	= WEIXIN_Menu::get();
			return WEIXIN_Menu::update($menu_id, ['button'=>$menu['button']]);
		}else{
			return new WP_Error('invalid_menuid', '非法menuid');
		}
	}

	public static function parse($item=[]){
		list($position, $sub_position)	= explode('_', $item['pos']);

		if(empty($item['name'])){
			$item['add']	= true;
			$item['name']	= '[row_action name="edit" title="新增" id="'.$item['pos'].'"]';
		}

		if($sub_position != -1){
			$item['name']		= '└── '.$item['name'];
			$item['position']	= '└─ '.($sub_position+1);
		}else{
			$item['name']		= $item['name'];
			$item['position']	= $position+1;
		}

		$type	= $item['type']??'';

		if(empty($type)){
			$item['value']	= '';
		}elseif($type == 'view'){
			$item['value']	= $item['url'];
		}elseif($type == 'miniprogram'){
			$item['value']	= '小程序AppID：'.$item['appid'] . '<br />小程序页面路径：'. $item['pagepath'];
		}elseif($type == 'view_limited' || $type == 'media_id'){
			$item['value']	= $item['media_id'];
		}else{
			$item['value']	= $item['key'];
		}

		return $item;
	}

	public static function editable(){
		return true;
	}

	public static function query_items($limit, $offset){
		$menu_id	= wpjam_get_data_parameter('menu_id');
		$menu		= WEIXIN_Menu::get($menu_id);
		$buttons	= $menu['button'] ?? [];

		$editable 	= static::editable();

		$items		= [];

		for($position=0; $position <3 ; $position++){ 
			$button	= $buttons[$position] ?? '';

			if($button){
				$button['pos']	= $position.'_'.'-1';
				$items[]		= $button;

				if(!empty($button['sub_button'])){
					for ($sub_position=0; $sub_position <5 ; $sub_position++) { 
						$sub_button		= $button['sub_button'][$sub_position]??'';

						if($sub_button){
							$sub_button['pos']	= $position.'_'.$sub_position;
							$items[]	= $sub_button;
						}elseif($editable){
							$items[]	= ['pos'=>$position.'_'.$sub_position];
							break;
						}
					}
				// }elseif($editable && empty($button['key']) && empty($button['url'])){	// 主按钮没有设置 key 就可以设置子按钮
				}elseif($editable && empty($button['type'])){	// 主按钮没有设置 key 就可以设置子按钮
					$items[]	= ['pos'=>$position.'_'.'0'];
				}
			}elseif($editable){
				$items[]	= ['pos'=>$position.'_'.'-1'];
				break;
			}
		}

		return ['items'=>$items, 'total'=>count($items)];
	}

	public static function render_item($item){
		$menu_id	= wpjam_get_data_parameter('menu_id');
		$menu		= WEIXIN_Menu::get($menu_id);
		$tab		= wpjam_get_current_tab_setting('tab');

		$buttons	= $menu['button'] ?? [];
		$editable 	= static::editable();

		$item		= self::parse($item);

		if($tab == 'tree'){
			return $item;
		}

		$click_types = self::get_types(true);

		if(!empty($item['add'])){
			unset($item['row_actions']);
		}else{
			list($position, $sub_position)	= explode('_', $item['pos']);

			if($editable){
				if($sub_position == -1){
					if($position == 0){
						unset($item['row_actions']['move_up']);
					}

					if($position >= count($buttons)-1){
						unset($item['row_actions']['move_down']);
					}
				}else{
					if($sub_position == 0){
						unset($item['row_actions']['move_up']);
					}

					if($sub_position >= count($buttons[$position]['sub_button'])-1){
						unset($item['row_actions']['move_down']);
					}
				}
			}else{
				unset($item['row_actions']);
			}

			$type	= $item['type']??'';

			if(empty($type) || $type == 'view' || $type =='miniprogram' || $type == 'mian') {
				unset($item['row_actions']['reply']);
			}
		}

		return $item;
	}

	public static function get_types($require_reply=false){
		$types	= array(
			'main'				=> '主菜单（含有子菜单）', 
			'view'				=> '跳转URL',
			'click'				=> '点击推事件', 
			'miniprogram'		=> '小程序',
			'scancode_push'		=> '扫码推事件',
			'scancode_waitmsg'	=> '扫码带提示',
			'pic_sysphoto'		=> '系统拍照发图',
			'pic_photo_or_album'=> '拍照或者相册发图',
			'pic_weixin'		=> '微信相册发图器',
			'location_select'	=> '地理位置选择器',
			// 'media_id'			=> '下发素材消息',
			// 'view_limited'		=> '跳转图文消息URL',
		);

		if($require_reply){
			unset($types['main']);
			unset($types['view']);
			unset($types['miniprogram']);
		}

		return $types;
	}

	public static function get_actions(){
		return [
			'edit'		=> ['title'=>'编辑',		'response'=>'list'],
			'reply'		=> ['title'=>'设置回复',	'response'=>'list',	'page_title'=>'设置自定义回复', 'submit_text'=>'设置'],
			'delete'	=> ['title'=>'删除',		'response'=>'list',	'direct'=>true,	'confirm'=>true],
			'move_up'	=> ['dashicon'=>'arrow-up-alt',		'page_title'=>'向上移动',	'direct'=>true,	'response'=>'list'],
			'move_down'	=> ['dashicon'=>'arrow-down-alt',	'page_title'=>'向下移动',	'direct'=>true,	'response'=>'list'],
			'create'	=> ['title'=>'同步到微信',	'overall'=>true,	'direct'=>true],
			'sync'		=> ['title'=>'从微信获取',	'overall'=>true,	'direct'=>true]
		];
	}

	public static function get_fields($action_key='', $pos=0){
		$key_views	= [
			'click' 			=> '请输入按钮KEY值，KEY值可以为搜索关键字，或者个性化菜单定义的关键字。用户点击按钮后，微信服务器会推送event类型消息，并且带上按钮中开发者填写的KEY值',
			'scancode_push'		=> '请输入按钮KEY值，用户点击按钮后，微信客户端将调起扫一扫工具，完成扫码操作后显示扫描结果（如果是URL，将进入URL）。',
			'scancode_waitmsg'	=> '请输入按钮KEY值，用户点击按钮后，微信客户端将调起扫一扫工具，完成扫码操作后，将推送扫码的结果，同时收起扫一扫工具，然后弹出“消息接收中”提示框。',
			'pic_sysphoto'		=> '请输入按钮KEY值，用户点击按钮后，微信客户端将调起系统相机，完成拍照操作后，将推送拍摄的相片和事件，同时收起系统相机。',
			'pic_photo_or_album'=> '请输入按钮KEY值，用户点击按钮后，微信客户端将弹出选择器供用户选择“拍照”或者“从手机相册选择”。用户选择后即走其他两种流程。',
			'pic_weixin'		=> '请输入按钮KEY值，用户点击按钮后，微信客户端将调起微信相册，完成选择操作后，将推送选择的相片和事件，同时收起相册。',
			'location_select'	=> '请输入按钮KEY值，用户点击按钮后，微信客户端将调起地理位置选择工具，完成选择操作后，将推送选择的地理位置，同时收起位置选择工具。',
			'media_id'			=> '请输入永久素材的 Media_id，用户点击按钮后，微信服务器会将永久素材 Media_id 对应的素材下发给用户，永久素材类型可以是图片、音频、视频、图文消息。',
			'view_limited'		=> '请输入图文永久素材的 Media_id，用户点击按钮后，微信客户端将打开文永久素材的 Media_id 对应的图文消息URL，永久素材类型只支持图文消息。'
		];

		$key_append	= '<br /><br />*用于消息接口（event类型）推送，不超过128字节，如果按钮还有子按钮，可不填，其他必填，否则报错。';

		$key_fields['key']	= ['type'=>'text'];

		foreach($key_views as $key => $value){
			$value	= '<span class="description" style="line-height: initial; display: inline-block; margin-top:-6px;">'.$value.$key_append.'</span>';

			$key_fields[$key]	= ['type'=>'view',	'value'=>$value,	'show_if'=>['key'=>'type', 'value'=>$key]];
		}

		$url_views	= [
			'view'				=> '请输入要跳转的链接。用户点击按钮后，微信客户端将会打开该链接。',
			'miniprogram'		=> '请输入不支持小程序的老版本客户端将打开的链接，必填，否则无法提交！'
		];

		if(weixin_get_type() == 4){
			$url_views['view']	.= '<br />可与网页授权获取用户基本信息接口结合，获得用户基本信息。';
		}

		$url_fields['url']	= ['type'=>'url'];

		foreach($url_views as $key => $value){
			$value	= '<span class="description" style="line-height: initial; display: inline-block; margin-top:-6px;">'.$value.'</span>';

			$url_fields[$key]	= ['type'=>'view',	'value'=>$value,	'show_if'=>['key'=>'type', 'value'=>$key]];
		}

		$fields	= [
			'name'		=> ['title'=>'按钮名称',		'type'=>'text',		'show_admin_column'=>true,	'description'=>'按钮名称，既描述，不超过16个字节，子菜单不超过40个字节'],
			'position'	=> ['title'=>'位置',			'type'=>'view',		'show_admin_column'=>'only'],
			'type'		=> ['title'=>'按钮类型',		'type'=>'select',	'show_admin_column'=>true,	'options'=>self::get_types()],
			'value'		=> ['title'=>'KEY/URL',		'type'=>'text',		'show_admin_column'=>'only'],
			'appid'		=> ['title'=>'小程序AppID',	'type'=>'text',		'show_if'=>['key'=>'type', 'compare'=>'=', 'value'=>'miniprogram']],
			'pagepath'	=> ['title'=>'页面路径',		'type'=>'text',		'show_if'=>['key'=>'type', 'compare'=>'=', 'value'=>'miniprogram']],
			'url_set'	=> ['title'=>'链接',			'type'=>'fieldset',	'fields'=>$url_fields,	'show_if'=>['key'=>'type', 'compare'=>'IN', 'value'=>['view', 'miniprogram']]],
			'key_set'	=> ['title'=>'按钮KEY值',	'type'=>'fieldset',	'fields'=>$key_fields,	'show_if'=>['key'=>'type', 'compare'=>'NOT IN', 'value'=>['main', 'view', 'miniprogram']]]
			
		];

		if($action_key == 'edit'){
			list($position, $sub_position)	= explode('_', $pos);

			if($sub_position != -1){
				unset($fields['type']['options']['main']);
			}
		}elseif($action_key == 'reply'){
			$fields		= WEIXIN_Reply_Setting::get_reply_fields('reply_type');
			$button		= self::get($pos);
			$keyword	= $button['key']??'';

			$fields['keyword']['value']	= $keyword;
			$fields['keyword']['type']	= 'view';
			$fields['keyword']['title']	= '按钮KEY值';

			unset($fields['match']);

			$custom_reply	= WEIXIN_Reply_Setting::get_by_keyword($keyword);

			if($custom_reply){
				$reply_type		= $custom_reply['type'];
				$fields['reply_type']['value']	= $reply_type;

				if($fields[$reply_type]['type'] == 'fieldset'){
					foreach($fields[$reply_type]['fields'] as $reply_sub_key=>&$reply_sub_field){
						$reply_sub_field['value']	= $custom_reply[$reply_type][$reply_sub_key] ?? '';
					}
				}else{
					$fields[$reply_type]['value']	= $custom_reply[$reply_type];
				}
			}

			unset($fields['status']);
		}

		return $fields;
	}
}

class WEIXIN_Menu_Button_Stats extends WEIXIN_Menu_Button{
	public static function extra_tablenav($which){
		if($which == 'top'){
			WPJAM_Chart::form();
		}
	}

	public static function editable(){
		return false;
	}

	public static function get_stats(){
		static $menu_stats;

		if(!isset($menu_stats)){
			$menu_stats	= apply_filters('weixin-menu-tree-stats', false);

			$wpjam_start_timestamp	= wpjam_get_chart_parameter('start_timestamp');
			$wpjam_end_timestamp	= wpjam_get_chart_parameter('end_timestamp');

			if(!$menu_stats){
				$counts = WEIXIN_Message::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $wpjam_start_timestamp)->where_lt('CreateTime', $wpjam_end_timestamp)->where('MsgType', 'event')->where_in('Event',['CLICK','VIEW','scancode_push','scancode_waitmsg','location_select','pic_sysphoto','pic_photo_or_album','pic_weixin'])->where_not('EventKey', '')->group_by('EventKey')->get_results('EventKey, count(*) as count');

				$counts	= wp_list_pluck($counts, 'count', 'EventKey');

				$total = WEIXIN_Message::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $wpjam_start_timestamp)->where_lt('CreateTime', $wpjam_end_timestamp)->where('MsgType', 'event')->where_in('Event',['CLICK','VIEW','scancode_push','scancode_waitmsg','location_select','pic_sysphoto','pic_photo_or_album','pic_weixin'])->where_not('EventKey', '')->get_var("count(*)");

				$menu_stats = compact('total', 'counts');
			}
		}

		return $menu_stats;
	}

	public static function render_item($item){
		$item	= self::parse($item);
		$stats	= self::get_stats();	
	
		$total	= $stats['total'];
		$counts	= $stats['counts'];

		if(!empty($item['key']) || !empty($item['url'])){
			$item['count']		= $counts[$item['value']] ?? 0;
			$item['percent']	= round($item['count']/$total*100,2).'%'; 
		}else{
			$item['count']		= '';
			$item['percent']	= '';
		}

		return $item;
	}

	public static function get_actions(){
		return [];
	}

	public static function get_fields($action_key='', $pos=0){
		return [
			'name'		=> ['title'=>'按钮名称',		'type'=>'view',	'show_admin_column'=>'only'],
			'position'	=> ['title'=>'位置',			'type'=>'view',	'show_admin_column'=>'only'],
			'type'		=> ['title'=>'按钮类型',		'type'=>'view',	'show_admin_column'=>'only',	'options'=>self::get_types()],
			'value'		=> ['title'=>'KEY/URL',		'type'=>'view',	'show_admin_column'=>'only'],
			'count'		=> ['title'=>'点击数',		'type'=>'view',	'show_admin_column'=>'only'],
			'percent'	=> ['title'=>'点击率',		'type'=>'view',	'show_admin_column'=>'only'],
		];
	}
}

class WEIXIN_Menu_Conditional_Button extends WEIXIN_Menu_Button{
	public static function editable(){
		$menu_id	= wpjam_get_data_parameter('menu_id');
		$menu		= WEIXIN_Menu::get($menu_id);

		return empty($menu['menuid']);
	}

	public static function get_actions(){
		$actions	= array_except(parent::get_actions(), ['create', 'sync']);

		if(self::editable()){
			$actions['duplicate']	= ['title'=>'从默认菜单复制',		'page_title'=>'从默认菜单复制',	'overall'=>true,	'direct'=>true];
			$actions['create']		= ['title'=>'添加个性化菜单',		'page_title'=>'添加个性化菜单',	'overall'=>true,	'direct'=>true];
		}else{
			$actions['reply']		= array_pull($actions, 'reply'); 
		}

		return $actions;
	}
}

class WEIXIN_Menu{
	public static function sync(){
		$response	= weixin()->get('/cgi-bin/menu/get');

		if(is_wp_error($response)){
			return $response;
		}

		$menus	=['menu'=>[],	'conditionalmenu'=>[]];

		if(isset($response['menu']['button'])){
			$menus['menu']	= $response['menu'];
		}

		if(isset($response['conditionalmenu'])){
			foreach($response['conditionalmenu'] as $conditionalmenu){
				$menuid	= $conditionalmenu['menuid'];

				$menus['conditionalmenu'][$menuid]	= array_merge($conditionalmenu, ['index'=>$menuid]);
			}
		}

		return self::update_menus($menus);
	}

	public static function create_menu($index){
		$menu		= self::get($index);
		$buttons	= $menu['button'];

		ksort($buttons);	// 按照 key 排序

		$buttons	= array_values($buttons);	// 防止中间某个key未填

		foreach($buttons as $position=>$button){
			if(!empty($button['sub_button'])){
				$sub_buttons = $button['sub_button'];
				ksort($sub_buttons);
				$sub_buttons = array_values($sub_buttons);
				$buttons[$position]['sub_button'] = $sub_buttons;
			}
		}

		if($index){
			$response	= weixin()->post('/cgi-bin/menu/addconditional', ['button'=>$buttons,'matchrule'=>$menu['matchrule']]);

			if(is_wp_error($response)){
				return $response;
			}

			$result	= self::sync();

			if(is_wp_error($result)){
				return $result;
			}

			return ['type'=>'redirect', 'url'=>admin_url('page=weixin-menu&tab=conditional&id='.$response['menuid'])];
		}else{
			return weixin()->post('/cgi-bin/menu/create', ['button'=>$buttons]);
		}
	}

	public static function get($index=''){
		$menus	= self::get_menus();

		if(empty($menus)){
			return [];
		}

		if($index){
			$menus	= $menus['conditionalmenu'];
			return $menus[$index] ?? [];
		}else{
			return $menus['menu'] ?? [];
		}
	}

	public static function update($index, $data){
		$menus	= self::get_menus();

		if($index){
			if(empty($menus['conditionalmenu'])){
				$menus['conditionalmenu']	= [];
			}

			$menus['conditionalmenu'][$index]	= array_merge($menus['conditionalmenu'][$index], $data);
		}else{
			$menus['menu']['button']	= $data['button'];
		}

		return self::update_menus($menus);
	}

	public static function insert($data){
		$menus	= self::get_menus();
		$type	= array_pull($data, 'type') ?: 'conditionalmenu';

		if($type == 'menu'){
			$menus['menu']	= $data;
		}else{
			$data['index']	= $index = time();
			$menus['conditionalmenu'][$index]	= $data;

			if(count($menus['conditionalmenu']) > 20){
				return new WP_Error('conditionalmenu_over_quota', '个性化菜单最多20个');
			}
		}

		$result	= self::update_menus($menus);

		if(is_wp_error($result) || $type == 'menu'){
			return $result;
		}

		return ['id'=>$index, 'last'=>true];
	}

	public static function delete($index){
		$menu	= self::get($index);
		$menus	= self::get_menus();

		unset($menus['conditionalmenu'][$index]);

		$result	= self::update_menus($menus);

		if(!empty($menu['menuid'])){
			$response	= weixin()->post('/cgi-bin/menu/delconditional', ['menuid'=>$menu['menuid']]);

			if(is_wp_error($response)){
				return $response;
			}
		}

		return $result;
	}

	public static function get_menus(){
		return get_option('weixin_'.weixin_get_appid().'_menus', []);
	}

	public static function update_menus($menus){
		return update_option('weixin_'.weixin_get_appid().'_menus', $menus);
	}

	public static function get_primary_key(){
		return 'index';
	}

	public static function query_items($limit, $offset){
		$menus	= self::get_menus();
		$items	= $menus['conditionalmenu'] ?? [];

		return ['items'=>$items, 'total'=>count($items)];
	}

	public static function render_item($item){
		global $plugin_page;

		$item['menuid']		= $item['menuid'] ?? '';

		if($item['matchrule'] && isset($item['matchrule']['group_id'])){
			$tag_id	= $item['matchrule']['group_id'];

			unset($item['matchrule']['group_id']);
		}else{
			$tag_id	= $item['matchrule']['tag_id'] ?? '';
		}

		$item['matchrule']	= array_filter($item['matchrule']);

		if($tag_id !== ''){
			$item['matchrule']['tag_id']	= $tag_id;
		}

		if($item['matchrule']){
			$matchrule			= [];
			$matchrule_fields	= self::get_fields()['matchrule']['fields'];

			foreach ($item['matchrule'] as $key => $value) {
				if(!empty($matchrule_fields[$key]['options'])){
					$value	= $matchrule_fields[$key]['options'][$value] ?? $value;
				}

				$matchrule[]	= '<span class="matchrule-title">'.$matchrule_fields[$key]['title'].'</span><span class="matchrule-value">'.$value.'</span>';
			}

			$item['matchrule']	= wpautop(implode("\n\n", $matchrule));
		}else{
			$item['matchrule']	= '';
		}

		$set_title	= $item['menuid'] ? '查看按钮' : '设置按钮';
		$edit_title	= $item['menuid'] ? '修改名称' : '设置匹配规则';

		$item['row_actions']['edit']	= str_replace('编辑', $edit_title, $item['row_actions']['edit']);
		$item['row_actions']['set']		= '<a href="'.admin_url('page='.$plugin_page).'&tab=buttons&menu_id='.$item['index'].'" title="'.$set_title.'">'.$set_title.'</a>';

		return $item;
	}

	public static function get_fields($action_key='', $index=''){
		$user_tags		= weixin_get_user_tags();
		$tag_options	= wp_list_pluck($user_tags, 'name', 'id');
		$tag_options	= array_merge([''=>'所有'], $tag_options);

		$fields = [
			'matchrule'	=> ['title'=>'匹配规则',	'type'=>'fieldset',	'show_admin_column'=>true,	'fieldset_type'=>'array',	'fields'=>[
				'tag_id'				=> ['title'=>'用户标签',		'type'=>'select',	'options'=>$tag_options],
				'client_platform_type'	=> ['title'=>'客户端版本',	'type'=>'select',	'options'=>[''=>'所有', 1=>'iOS', 2=>'Android', 3=>'其他']],
			]],
			'menuid'	=> ['title'=>'菜单ID',	'type'=>'view',		'show_admin_column'=>'only']
		];

		if($action_key == 'edit'){
			$menu	= self::get($index);

			if($menu['menuid']){
				unset($fields['matchrule']);
			}
		}

		return $fields;
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'width'=>400],
			'edit'		=> ['title'=>'编辑',	'width'=>400],
			'set'		=> ['title'=>'设置'],
			'duplicate'	=> ['title'=>'复制'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,	'bulk'=>true, 'confirm'=>true],
		];
	}

	public static function get_tabs(){
		wp_add_inline_style('list-tables', join("\n",[
			'td.column-value{word-break: break-all;}',
			'span.matchrule-title{width:100px; display: inline-block;}'
		]));

		$tabs	= [];
		$title	= weixin_get_type() > 2 ? '默认菜单' : '自定义菜单';

		$tabs['default']	= [
			'title'		=> $title,
			'tab'		=> 'default',	
			'function'	=> 'list',
			'singular'	=> 'weixin-button',
			'plural'	=> 'weixin-buttons',
			'model'		=> 'WEIXIN_Menu_Button'
		];

		$tabs['tree']		= [
			'title'		=> $title.'统计',
			'chart'		=> true,
			'function'	=> 'list',
			'singular'	=> 'weixin-button',
			'plural'	=> 'weixin-buttons',
			'model'		=> 'WEIXIN_Menu_Button_Stats'
		];

		if(weixin_get_type() > 2){
			$tabs['conditional']	= [
				'title'		=> '个性化菜单',	
				'function'	=> 'list',	
				'singular'	=> 'weixin-menu',
				'plural'	=> 'weixin-menus',
				'model'		=> 'WEIXIN_Menu'
			];

			if($menu_id	= wpjam_get_data_parameter('menu_id')){
				if($menu = WEIXIN_Menu::get($menu_id)){
					$tabs['buttons']	= [
						'title'			=> isset($menu['menuid']) ? '查看按钮' : '设置按钮',
						'tab'			=> 'buttons',
						'query_args'	=> ['menu_id'],
						'function'		=> 'list',
						'singular'		=> 'weixin-button',
						'plural'		=> 'weixin-buttons',
						'model'			=> 'WEIXIN_Menu_Conditional_Button'
					];
				}else{
					wp_die('个性化菜单不存在');
				}
			}
		}

		return $tabs;
	}
}