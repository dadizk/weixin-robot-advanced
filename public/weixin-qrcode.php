<?php
class WEIXIN_Qrcode extends WPJAM_Model{
	public static function insert($data){
		$scene	= $data['scene'] ?? '';

		if(empty($scene)){
			return new WP_Error('empty_scene','场景值不能为空');
		}

		if(self::get($scene)){
			return new WP_Error('scene_already_added','该场景值已存在');
		}

		$type	= $data['type'];

		if($type == 'QR_SCENE' || $type == 'QR_STR_SCENE'){
			$expire	= $data['expire'];

			$data['expire'] = time()+$response['expire_seconds'];
		}else{
			$expire	= 0;
		}

		$response	= weixin_create_qrcode($scene, $expire);

		if(is_wp_error($response)){
			return $response;
		}

		$data['ticket']	= $response['ticket'];

		return parent::insert($data);
	}

	public static function subscribe($scene, $data){
		$reply_type		= $data['reply_type'] ?? 'text';
		$reply			= $data[$reply_type];

		$reply_data		= [
			'match'		=> 'full',
			'type'		=> $reply_type,
			$reply_type	=> $reply,
			'status'	=> 1
		];

		return WEIXIN_Reply_Setting::set_by_keyword($data['keyword'], $reply_data);
	}

	public static function scan($id, $data){
		return self::subscribe($id, $data);
	}

	public static function render_item($item){
		$item['ticket']	= '<img src="https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket='.urlencode($item['ticket']).'" width="100">';

		if($item['type']=='QR_SCENE'){
			$item['expire']	= $item['expire']-time()>0 ? get_date_from_gmt(date('Y-m-d H:i:s', $item['expire'])) : '已过期';
		}else{
			$item['expire']	= '';
		}
		

		return $item;
	}

	public static function get_actions(){
		return [
			'add'		=> ['title' =>'新增'],
			'edit'		=> ['title'	=>'编辑'],
			'subscribe'	=> ['title'	=>'关注回复'],
			'scan'		=> ['title'	=>'扫描回复'],
			'delete'	=> ['title'	=>'删除',	'confirm'=>true,	'direct'=>true,	'bulk'=>true]
		];
	}

	public static function get_fields($action_key='', $scene=''){
		if($action_key == 'subscribe' || $action_key=='scan'){
			global $current_tab;

			$fields		= WEIXIN_Reply_Setting::get_reply_fields('reply_type');
			$item		= self::get($scene);

			if($action_key == 'subscribe'){
				$keyword	= '[subscribe_'.$scene.']';
			}elseif($action_key == 'scan'){
				$keyword	= '[scan_'.$scene.']';
			}

			$fields['keyword']['value']	= $keyword;

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
		}else{
			$fields	= [
				'ticket'	=> ['title'=>'二维码',	'type'=>'text',		'show_admin_column'=>'only'],
				'name'		=> ['title'=>'名称',		'type'=>'text',		'show_admin_column'=>true,	'required',	'description'=>'二维码名称无实际用途，仅用于更加容易区分。'],
				'scene'		=> ['title'=>'场景值',	'type'=>'number',	'show_admin_column'=>true,	'min'=>'1',	'max'=>'100000',	'required',	'description'=>'目前参数只支持1-100000'],
				'type'		=> ['title'=>'类型',		'type'=>'select',	'show_admin_column'=>true,	'options'=> self::get_types()],
				'expire'	=> ['title'=>'过期时间',	'type'=>'text',		'show_admin_column'=>true,	'show_if'=>['key'=>'type','value'=>'QR_SCENE'],	'description'=> '二维码有效时间，以秒为单位。最大不超过1800'],
				
			];

			if($action_key == 'edit'){
				unset($fields['type']);
				unset($fields['expire']);
			}

			return $fields;
		}

		return $fields;
	}

	public static function get_types(){
		return [
			'QR_LIMIT_SCENE'	=> '永久二维码',
			'QR_SCENE'			=> '临时二维码'
		];
	}

	public static function get_handler(){
		$option		= 'weixin_'.weixin_get_appid().'_qrcodes';
		$handler	= wpjam_get_handler($option);

		return $handler ?: wpjam_register_handler([
			'option_name'	=> $option,
			'last'			=> true,
			'total'			=> 50,
			'primary_key'	=> 'scene'
		]);
	}

	public static function get_list_table(){
		return [
			'title'				=> '二维码',
			'primary_column'	=> 'name',
			'singular'			=> 'weixin-qrcode',
			'plural'			=> 'weixin-qrcodes',
			'model'				=> self::class,
		];
	}
}