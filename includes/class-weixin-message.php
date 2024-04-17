<?php
class WEIXIN_Message extends WPJAM_Model{
	public static function can_send($openid){
		if(self::get_handler()->where('appid', weixin_get_appid())->where('FromUserName', $openid)->where_gt('CreateTime', self::get_send_limit())->get_row()){
			return true;
		}else{
			return false;
		}
	}

	protected static function get_send_limit(){
		return time()-2*DAY_IN_SECONDS;
	}

	public static function get_can_send_users(){
		$query	= self::query([
			'appid'				=> weixin_get_appid(),
			'CreateTime__gt'	=> time()-HOUR_IN_SECONDS,
			'groupby'			=> 'FromUserName',
			'fields'			=> 'FromUserName'
		]);

		return array_column($query->items, 'FromUserName');
	}

	public static function get_responses(){
		return WEIXIN_Message_Response::get_options();
	}

	public static function get_types($type=''){
		if($type == 'text'){
			return self::get_responses();
		}elseif($type == 'event' || $type == 'card-event'){
			return [
				'click'	=> '点击菜单',
				'view'	=> '跳转URL',

				'subscribe'		=> '用户订阅', 
				'unsubscribe'	=> '取消订阅',

				'scancode_push'			=> '扫码推事件',
				'scancode_waitmsg'		=> '扫码带提示',
				'pic_sysphoto'			=> '系统拍照发图',
				'pic_photo_or_album'	=> '拍照或者相册发图',
				'pic_weixin'			=> '微信相册发图器',
				'location_select'		=> '地理位置选择器',
				'location'				=> '获取用户地理位置',
				'scan'					=> '扫描带参数二维码',
				'view_miniprogram'		=> '跳转小程序',

				'masssendjobfinish'		=> '群发信息',
				'templatesendjobfinish'	=> '收到模板消息',

				'kf_create_session'		=> '多客服接入会话',
				'kf_close_session'		=> '多客服关闭会话',
				'kf_switch_session'		=> '多客服转接会话',

				'qualification_verify_success'	=> '资质认证成功',
				'qualification_verify_fail'		=> '资质认证失败',
				'naming_verify_success'			=> '名称认证成功',
				'naming_verify_fail'			=> '名称认证失败',
				'annual_renew'					=> '年审通知',
				'verify_expired'				=> '认证过期失效通知',

				'user_get_card'					=> '领取卡券',
				'user_del_card'					=> '删除卡券',
				'user_consume_card'				=> '核销卡券',
				'card_pass_check'				=> '卡券通过审核',
				'card_not_pass_check'			=> '卡券未通过审核',
				'user_view_card'				=> '进入会员卡',
				'user_enter_session_from_card'	=> '从卡券进入公众号会话',
				'card_sku_remind'				=> '卡券库存报警',
				'submit_membercard_user_info'	=> '接收会员信息',

				'wificonnected'			=> 'Wi-Fi连网成功',
				'shakearoundusershake'	=> '摇一摇',
				'poi_check_notify'		=> '门店审核',
			];
		}else{
			return [
				'text'			=>'文本消息', 
				'event'			=>'事件消息',  
				'location'		=>'位置消息', 
				'image'			=>'图片消息', 
				'link'			=>'链接消息', 
				'voice'			=>'语音消息',
				'video'			=>'视频消息',
				'shortvideo'	=>'小视频'
			];
		}
	}

	public static function get_user_location($openid){	// 获取用户的最新的地理位置并缓存10分钟。
		$cache_key	= 'location:'.weixin_get_appid().':'.$openid;
		$location	= self::cache_get($cache_key);

		if($location === false){
			$location	= self::get_handler()->where_not('Content', '')->where('FromUserName',$openid)->where('appid', weixin_get_appid())->where_gt('CreateTime', time()-HOUR_IN_SECONDS)->where_fragment("MsgType='Location' OR (MsgType ='Event' AND Event='LOCATION')")->order_by('CreateTime')->order('DESC')->get_var('Content');

			$location	= maybe_unserialize($location);
			self::cache_set($cache_key, $location, 600);
		}

		return $location;
	}

	public static function sanitize_data($message, $id=0){
		$appid	= weixin_get_appid();

		$data	= [
			'appid'			=> $appid,
			'MsgId'			=> $message['MsgId'] ?? '',
			'MsgType'		=> $message['MsgType'] ?? '',
			'FromUserName'	=> $message['FromUserName'] ?? '',
			'CreateTime'	=> $message['CreateTime'] ?? '',
			'Content'		=> '',
			'Event'			=> '',
			'EventKey'		=> '',
			'Title'			=> '',
			'Url'			=> '',
			'MediaId'		=> '',
			'Response'		=> (string)$message['Response'],
		];

		$openid		= $message['FromUserName'] ?? '';
		$msgType	= isset($message['MsgType']) ? strtolower($message['MsgType']) : '';

		if($msgType == 'text'){
			$data['Content']	= $message['Content'] ? strval($message['Content']) : '';
		}elseif($msgType == 'image'){
			$data['Url']		= $message['PicUrl'];
			$data['MediaId']	= $message['MediaId'];
		}elseif($msgType == 'location'){
			$location	= [
				'Location_X'	=>	$message['Location_X'],
				'Location_Y'	=>	$message['Location_Y'],
				'Scale'			=>	$message['Scale'],
				'Label'			=>	$message['Label']
			];
			$data['Content']	= maybe_serialize($location);

			self::cache_set('location:'.$appid.':'.$openid, $location, 600);// 缓存用户地理位置信息
		}elseif($msgType == 'link'){
			$data['Title']		= $message['Title'];
			$data['Content']	= $message['Description'] ?: '';
			$data['Url']		= $message['Url'];
		}elseif($msgType == 'voice'){
			$data['Url']		= $message['Format'];
			$data['MediaId']	= $message['MediaId'];
			$data['Content']	= !empty($message['Recognition']) ? $message['Recognition'] : '';
		}elseif($msgType == 'video' || $msgType == 'shortvideo'){
			$data['MediaId']	= $message['MediaId'];
			$data['Url']		= $message['ThumbMediaId'];
		}elseif($msgType == 'event'){
			$data['Event']		= $message['Event'];
			$Event 				= strtolower($message['Event']);
			$data['EventKey']	= !empty($message['EventKey']) ? $message['EventKey'] : '';

			if($Event == 'view'){
				if(strlen($data['EventKey']) >= 50){
					$data['EventKey']	= md5($data['EventKey']);
				}
			}elseif($Event == 'location'){
				$location	= [
					'Location_X'	=>	$message['Latitude'],
					'Location_Y'	=>	$message['Longitude'],
					'Precision'		=>	$message['Precision'],
				];
				$data['Content']	= maybe_serialize($location);
			}elseif ($Event == 'templatesendjobfinish') {
				$data['EventKey']	= $message['Status'] ?? '';
			}elseif ($Event == 'masssendjobfinish') {
				$data['EventKey']	= $message['Status'];
				$data['MsgId']		= $message['MsgId'] ?? ($message['MsgID'] ?? '');
				// file_put_contents(WP_CONTENT_DIR.'/debug/masssendjobfinish.log',var_export($message,true),FILE_APPEND);
				$data['Content']	= maybe_serialize([
					'Status'		=> $message['Status'],
					'TotalCount'	=> $message['TotalCount'],
					'FilterCount'	=> $message['FilterCount'],
					'SentCount'		=> $message['SentCount'],
					'ErrorCount'	=> $message['ErrorCount']
				]);
			}elseif($Event == 'scancode_push' || $Event == 'scancode_waitmsg'){
				$ScanCodeInfo 		= $message['ScanCodeInfo'];
				$data['Title']		= (string)$ScanCodeInfo['ScanType'];
				$data['Content']	= (string)$ScanCodeInfo['ScanResult'];
			}elseif($Event == 'location_select'){
				$SendLocationInfo	= $message['SendLocationInfo'];
				$location	= [
					'Location_X'	=>	$message['Location_X'],
					'Location_Y'	=>	$message['Location_Y'],
					'Scale'			=>	$message['Scale'],
					'Label'			=>	$message['Label'],
					'Poiname'		=>	$message['Poiname'],
				];
				$data['content']	= maybe_serialize($location);

				self::cache_set('location:'.$appid.':'.$openid, $location, 600);// 缓存用户地理位置信息
			}elseif($Event == 'pic_sysphoto' || $Event == 'pic_photo_or_album' || $Event == 'pic_weixin'){
				$SendPicsInfo		= $message['SendPicsInfo'];
				$Count 				= (string)$SendPicsInfo['Count'];
				$PicList			= (string)$SendPicsInfo['PicList'];
			}elseif ($Event == 'card_not_pass_check' || $Event == 'card_pass_check') {
				$data['EventKey']	= $message['CardId'];
			}elseif ($Event == 'user_get_card') {
				$data['EventKey']	= $message['CardId'];
				$data['Title']		= $message['UserCardCode'];
				$data['MediaId']	= $message['OuterId'];
				$data['Url']		= $message['IsGiveByFriend'];

				$data['content']	= maybe_serialize([
					'FriendUserName'	=>	$message['FriendUserName'],
					'OldUserCardCode'	=>	$message['OldUserCardCode'],
				]);
			}elseif ($Event == 'user_del_card') {
				$data['EventKey']	= $message['CardId'];
				$data['Title']		= $message['UserCardCode'];
			}elseif ($Event == 'user_view_card') {
				$data['EventKey']	= $message['CardId'];
				$data['Title']		= $message['UserCardCode'];
			}elseif ($Event == 'user_enter_session_from_card') {
				$data['EventKey']	= $message['CardId'];
				$data['Title']		= $message['UserCardCode'];
			}elseif ($Event == 'user_consume_card') {
				$data['EventKey']	= $message['CardId'];
				$data['Title']		= $message['UserCardCode'];
				$data['MediaId']	= $message['ConsumeSource'];

				$data['content']	= maybe_serialize([
					'OutTradeNo'	=>	$message['OutTradeNo'],
					'TransId'		=>	$message['TransId'],
					'LocationName'	=>	$message['LocationName'],
					'StaffOpenId'	=>	$message['StaffOpenId'],
				]);
			}elseif($Event == 'submit_membercard_user_info'){
				$data['EventKey']	= $message['CardId'];
				$data['Title']		= $message['UserCardCode'];
			}elseif ($Event == 'wificonnected') {
				$data['EventKey']	= $message['PlaceId'];
				$data['Title']		= $message['DeviceNo'];
				$data['MediaId']	= $message['ConnectTime'];

				$data['content']	= maybe_serialize([
					'ExpireTime'	=>	$message['ExpireTime'],
					'VendorId'		=>	$message['VendorId'],
				]);
			}elseif ($Event == 'shakearoundusershake') {
				$data['Title']		= maybe_serialize($message['ChosenBeacon']);
				$data['Content']	= maybe_serialize($message['AroundBeacons']);
			}elseif ($Event == 'poi_check_notify') {
				$data['EventKey']	= $message['UniqId'];
				$data['Title']		= $message['PoiId'];
				$data['MediaId']	= $message['Result'];
				$data['Content']	= $message['Msg'];
			}elseif($Event == 'qualification_verify_success' || $Event == 'naming_verify_success' || $Event == 'annual_renew' || $Event == 'verify_expired'){
				$data['Title']		= $message['ExpiredTime'];
			}elseif($Event == 'qualification_verify_fail' || $Event == 'naming_verify_fail'){
				$data['Title']		= $message['FailTime'];
				$data['Content']	= $message['FailReason'];
			}elseif($Event == 'kf_create_session' || $Event == 'kf_close_session'){
				$data['Title']		= $message['KfAccount'];
			}elseif($Event == 'kf_switch_session' || $Event == 'kf_close_session'){
				$data['Title']		= $message['FromKfAccount'];
				$data['Content']	= $message['ToKfAccount'];
			}
		}

		return $data;
	}

	public static function insert($data){
		if(weixin_get_setting('weixin_realtime_message') || !wp_using_ext_object_cache() || count($data) <= 5){
			return parent::insert($data); 
		}

		$appid		= weixin_get_appid();
		$messages	= self::cache_get('messages:'.$appid);
		$messages	= ($messages === false) ? [] : $messages;

		$messages[]	= $data;

		if(count($messages) < 10){
			return self::cache_set('messages:'.$appid, $messages, 3600);
		}else{
			self::cache_delete('messages:'.$appid);
			parent::insert_multi($messages);
		}

		return true;
	}

	public static function delete_old(){
		if(!get_transient('weixin_messages_deleted')){
			set_transient('weixin_messages_deleted', 1, DAY_IN_SECONDS);

			self::get_handler()->where('appid', weixin_get_appid())->where_lt('CreateTime', (time()-MONTH_IN_SECONDS))->delete();
		}
	}

	public static function get_table(){
		return $GLOBALS['wpdb']->base_prefix.'weixin_messages';
	}

	public static function get_handler(){
		$table		= self::get_table();
		$handler	= wpjam_get_handler($table);

		return $handler ?: wpjam_register_handler($table, new WPJAM_DB($table, [
			'primary_key'		=> 'id',
			'cache'				=> false,
			'group_cache_key'	=> 'appid',
			'cache_group'		=> ['weixin_messages', true],
			'field_types'		=> ['id'=>'%d','MsgId'=>'%d','CreateTime'=>'%d'],
			'filterable_fields'	=> ['MsgType','Response','FromUserName'],
		]));
	}

	public static function create_table(){
		$table = static::get_table();

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		if($GLOBALS['wpdb']->get_var("show tables like '".$table."'") != $table) {
			$sql = "
			CREATE TABLE IF NOT EXISTS ".$table." (
				`id` bigint(20) NOT NULL auto_increment,
				`appid` varchar(32) NOT NULL,
				`MsgId` bigint(20) NOT NULL,
				`FromUserName` varchar(30) NOT NULL,
				`MsgType` varchar(10) NOT NULL,
				`CreateTime` int(10) NOT NULL,
				`Content` longtext NOT NULL,
				`Event` varchar(50) NOT NULL,
				`EventKey` varchar(50) NOT NULL,
				`Title` text NOT NULL,
				`Url` varchar(255) NOT NULL,
				`MediaId` varchar(500) NOT NULL,
				`Response` varchar(255) NOT NULL,
				PRIMARY KEY (`id`)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			";
	 
			dbDelta($sql);

			$GLOBALS['wpdb']->query("ALTER TABLE `".$table."`
				ADD KEY `MsgType` (`MsgType`),
				ADD KEY `CreateTime` (`CreateTime`),
				ADD KEY `Event` (`Event`);");
		}
	}

	public static function reply($id, $data){
		if(!self::can_send($openid)){
			return new WP_Error('out_of_custom_message_time_limit', '48小时没有互动过，无法发送消息！');
		}

		$response	= WEIXIN_User::reply($data['FromUserName'], ['content'	=> $data['content']]);

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

		if($items && weixin_get_type() >= 3){
			$openids 	= array_column($items, 'FromUserName');
			$users		= WEIXIN_Account::batch_get_user_info(weixin_get_appid(), $openids);
		}

		return compact('items', 'total');
	}

	public static function render_item($item){
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
				$image				= weixin_get_media($item['MediaId']);
				$item['Content']	= '<a href="'.$image.'" target="_blank" title="'.$item['MediaId'].'"><img src="'.$image.'" alt="'.$item['MediaId'].'" width="100px;"></a>';
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
				'FromUserName'	=> ['type'=>'hidden'],
				'content'		=> ['type'=>'textarea']
			];
		}else{
			return [
				'username'	=> ['title'=>'用户',	'type'=>'text',		'show_admin_column'=>true],
				'MsgType'	=> ['title'=>'类型',	'type'=>'select',	'show_admin_column'=>true,	'options'=>self::get_types()],
				'Content'	=> ['title'=>'内容',	'type'=>'text',		'show_admin_column'=>true],
				'Response'	=> ['title'=>'回复',	'type'=>'select',	'show_admin_column'=>true,	'options'=>self::get_responses()],
				'CreateTime'=> ['title'=>'时间',	'type'=>'text',		'show_admin_column'=>true],
			];
		}
	}
}

class WEIXIN_Masssend extends WEIXIN_Message{
	public static function get_primary_key(){
		return 'MsgId';
	}

	public static function extra_tablenav($which){
		if($which == 'top'){
			WPJAM_Chart::form();
		}
	}

	public static function get_views(){
		return [];
	}

	public static function query_items($args){
		$args['appid']			= weixin_get_appid();
		$args['Event']			= 'MASSSENDJOBFINISH';
		$args['MsgType']		= 'event';
		$args['CreateTime__gt']	= wpjam_get_chart_parameter('start_timestamp');
		$args['CreateTime__lt']	= wpjam_get_chart_parameter('end_timestamp');

		return parent::query_items($args);
	}

	public static function render_item($item){
		$item['CreateTime']	= get_date_from_gmt(date('Y-m-d H:i:s',$item['CreateTime']));
		$count_list			= maybe_unserialize($item['Content']);

		if($count_list){
			$item['Status']			= isset($count_list['Status'])?$count_list['Status']:'';
			$item['TotalCount']		= $count_list['TotalCount'];
			$item['FilterCount']	= $count_list['FilterCount'];
			$item['SentCount']		= $count_list['SentCount'];
			$item['SentRate']		= round($count_list['SentCount']*100/$count_list['TotalCount'],2).'%';
			$item['ErrorCount']		= $count_list['ErrorCount'];
		}else{
			$item['Status']			= '';
			$item['TotalCount']		= '';
			$item['FilterCount']	= '';
			$item['SentCount']		= '';
			$item['SentRate']		= '';
			$item['ErrorCount']		= '';
		}

		return $item;
	}

	public static function get_actions(){
		return [];
	}

	public static function get_fields($action_key='', $id=''){
		return [
			'MsgId'			=> ['title' => '群发ID',		'type' => 'text',	'show_admin_column' => true],
			'CreateTime'	=> ['title' => '时间',		'type' => 'text',	'show_admin_column' => true],
			'Status'		=> ['title' => '状态',		'type' => 'text',	'show_admin_column' => true],
			'TotalCount'	=> ['title' => '所有',		'type' => 'text',	'show_admin_column' => true],
			'FilterCount'	=> ['title' => '过滤之后',	'type' => 'text',	'show_admin_column' => true],
			'SentCount'		=> ['title' => '发送成功',	'type' => 'text',	'show_admin_column' => true],
			'SentRate'		=> ['title' => '成功率',		'type' => 'text',	'show_admin_column' => true],
			'ErrorCount'	=> ['title' => '发送失败',	'type' => 'text',	'show_admin_column' => true],
		];
	}
}

class WEIXIN_Message_Response extends WPJAM_Register{
	public static function get_options(){
		return wp_list_pluck(self::get_registereds(), 'title');
	}
}