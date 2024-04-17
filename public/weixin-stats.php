<?php
class WEIXIN_Stats extends WEIXIN_Message{
	public static function user_subscribe() {
		WPJAM_Chart::form();

		$start_timestamp	= wpjam_get_chart_parameter('start_timestamp');
		$end_timestamp		= wpjam_get_chart_parameter('end_timestamp');
		$start_date			= wpjam_get_chart_parameter('start_date');
		$end_date			= wpjam_get_chart_parameter('end_date');
		$date_format		= wpjam_get_chart_parameter('date_format');

		$counts	= self::get_user_subscribe_counts($start_timestamp, $end_timestamp);

		echo '<p>从 '.$start_date.' 到 '.$end_date.' 这段时间内，共有 <span class="green">'.$counts['subscribe'].'</span> 人订阅，<span class="red">'.$counts['unsubscribe'].'</span> 人取消订阅，取消率 <span class="red">'.$counts['percent'].'%</span>，净增长 <span class="green">'.$counts['netuser'].'</span> 人。</p>';

		$sum 	= [];
		$sum[]	= "SUM(case when Event='subscribe' then 1 else 0 end) as subscribe";
		$sum[]	= "SUM(case when Event='unsubscribe' then 1 else 0 end) as unsubscribe";
		$sum[] 	= "SUM(case when Event='subscribe' then 1 when Event='unsubscribe' then -1 else 0 end ) as netuser";
		$sum	= implode(', ', $sum);

		$counts	= self::Query()
				->where('appid', weixin_get_appid())
				->where_gt('CreateTime', $start_timestamp)
				->where_lt('CreateTime', $end_timestamp)
				->where('MsgType','event')
				->where_in('Event',['subscribe','unsubscribe'])
				->group_by('day')->order_by('day')
				->get_results("FROM_UNIXTIME(CreateTime, '{$date_format}') as day, count(id) as total, {$sum}");

		$counts_array	= [];

		foreach ($counts as $count) {
			$count['percent']	= $count['subscribe'] ? round($count['unsubscribe']/$count['subscribe'] * 100, 2) : 0;
			$counts_array[$count['day']]	= $count;
		}

		$types 	= ['subscribe'=>'用户订阅', 'unsubscribe'=>'取消订阅', 'percent'=>'取消率%', 'netuser'=>'净增长'];
		
		wpjam_line_chart($counts_array, $types);
	}

	public static function message_overview(){
		WPJAM_Chart::form();

		$start_timestamp	= wpjam_get_chart_parameter('start_timestamp');
		$end_timestamp	= wpjam_get_chart_parameter('end_timestamp');
		$date_format		= wpjam_get_chart_parameter('date_format');

		$counts_array	= self::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $start_timestamp)->where_lt('CreateTime', $end_timestamp)->group_by('day')->order_by('day')->get_results("FROM_UNIXTIME(CreateTime, '{$date_format}') as day, count(id) as cnt, count(DISTINCT FromUserName) as user, (COUNT(id)/COUNT(DISTINCT FromUserName)) as avg");

		$counts_array	= array_combine(array_column($counts_array, 'day'), $counts_array);

		wpjam_line_chart($counts_array, array(
			'cnt'	=>'消息发送次数', 
			'user'	=>'消息发送人数', 
			'avg'	=>'人均发送次数#'
		));
	}

	public static function message_stats() {
		global $current_tab;

		$start_timestamp	= wpjam_get_chart_parameter('start_timestamp');
		$end_timestamp		= wpjam_get_chart_parameter('end_timestamp');
		$date_format		= wpjam_get_chart_parameter('date_format');

		$message_types	= self::get_types($current_tab);
		$message_query	= self::Query()->where('appid', weixin_get_appid());

		if($current_tab == 'event'){
			$field		= 'LOWER(Event)';

			$message_query->where('MsgType', 'event');
			// $where_base	= "MsgType = 'event' AND ";
		}elseif ($current_tab == 'text') {
			$field		= 'LOWER(Response)';

			$message_query->where('MsgType', 'text');

			// $where_base	= "MsgType = 'text' AND ";
			if(!empty($_GET['s'])){
				$message_query->where('Content', trim($_GET['s']));
				// $where_base	.= "Content = '".trim($_GET['s'])."' AND ";
			}
		}elseif($current_tab == 'menu'){
			$weixin_menu	= get_option('weixin_'.weixin_get_appid().'_menus', []);
			$menu			= $weixin_menu && $weixin_menu['menu'] ? $weixin_menu['menu'] : [];

			if(!$menu ){
				return;
			}

			$message_types	= [];
			
			if($buttons = $menu['button']){
				foreach($buttons as $button){
					if(empty($button['sub_button'])){
						if($button['type']	== 'view'){
							$message_types[$button['url']]	= $button['name'];
						}elseif(isset($button['key'])){
							$message_types[$button['key']]	= $button['name'];	
						}
					}else{
						foreach ($button['sub_button'] as $sub_button) {
							if($sub_button['type']	== 'view'){
								$message_types[$sub_button['url']]	= $sub_button['name'];
							}elseif($sub_button['type']	== 'miniprogram'){
								// 
							}else{
								$message_types[$sub_button['key']]	= $sub_button['name'];	
							}
						}
					}
				}
			}

			$field		= 'EventKey';

			$message_query->where('MsgType', 'event')->where_in('Event',['CLICK','VIEW','scancode_push','scancode_waitmsg','location_select','pic_sysphoto','pic_photo_or_album','pic_weixin'])->where_not('EventKey', '');

			// $where_base	= "MsgType = 'event' AND Event in('CLICK','VIEW','scancode_push','scancode_waitmsg','location_select','pic_sysphoto','pic_photo_or_album','pic_weixin') AND EventKey !='' AND ";
		}elseif($current_tab == 'subscribe'){
			$field		= 'LOWER(EventKey)';
			$message_query->where('MsgType', 'event')->where_in('Event',['subscribe','unsubscribe']);

			// $where_base	= "MsgType = 'event' AND (Event = 'subscribe' OR Event = 'unsubscribe') AND ";
		}elseif($current_tab == 'wifi-shop'){
			$field		= 'LOWER(EventKey)';

			$message_query->where('MsgType', 'event')->where('Event', 'WifiConnected')->where_not('EventKey', '')->where_not('EventKey', '');

			// $where_base	= "MsgType = 'event' AND Event = 'WifiConnected' AND EventKey!='' AND EventKey!='0' AND ";
		}elseif($current_tab == 'card-event'){
			$title		= '卡券事件统计分析';
			$field		= 'LOWER(Event)';

			$message_query->where('MsgType', 'event')->where_in('Event', ['card_not_pass_check', 'card_pass_check', 'user_get_card', 'user_del_card', 'user_view_card', 'user_enter_session_from_card', 'user_consume_card']);

			// $where_base	= "MsgType = 'event' AND Event in('card_not_pass_check', 'card_pass_check', 'user_get_card', 'user_del_card', 'user_view_card', 'user_enter_session_from_card', 'user_consume_card') AND ";
		}else{
			$field		= 'LOWER(MsgType)';

			$message_query->where_not('MsgType', 'manual');
			// $where_base	= "MsgType !='manual' AND ";
		}

		$message_type 	=  isset($_GET['type'])?$_GET['type']:'';

		if($message_type){
			$message_query->where($field, $message_type);
		}

		if($current_tab == 'menu'){
			echo '<p>下面的名称，如果是默认菜单的按钮，则显示名称，如果是个性化菜单独有的按钮，则显示key。</p>';
		}

		WPJAM_Chart::form();

		$wheres	= $message_query->where_gt('CreateTime', $start_timestamp)->where_lt('CreateTime', $end_timestamp)->get_wheres();

		$counts = self::Query()->where_fragment($wheres)->group_by($field)->order_by('count')->get_results("count(id) as count, {$field} as label");
		$labels	= wp_array_slice_assoc($message_types, array_column($counts, 'label'));
		$total 	= self::Query()->where_fragment($wheres)->get_var('count(*)');

		if(empty($_GET['s'])){
			// wpjam_donut_chart($counts, array('total'=>$total, 'labels'=>$new_message_types, 'show_link'=>true,'chart_width'=>280));
			wpjam_donut_chart($counts, ['total'=>$total, 'labels'=>$labels, 'show_link'=>true,'chart_width'=>280]);
		}

		?>

		<div class="clear"></div>

		<?php

		if($message_type){
			$counts_array	= self::Query()->where_fragment($wheres)->group_by('day')->order_by('day')->get_results("FROM_UNIXTIME(CreateTime, '{$date_format}') as day, count(id) as `{$message_type}`");

			$counts_array	= array_combine(array_column($counts_array, 'day'), $counts_array);

			$message_type_label = $message_types[$message_type]??$message_type;

			wpjam_line_chart($counts_array, [$message_type=>$message_type_label]);
		}else{
			if(empty($_GET['s'])){
				$sum = array();
				foreach (array_keys($message_types) as $message_type){
					$sum[] = "SUM(case when {$field}='{$message_type}' then 1 else 0 end) as `{$message_type}`";
				}
				$sum = implode(', ', $sum);
			
				$counts_array	= self::Query()->where_fragment($wheres)->group_by('day')->order_by('day')->get_results("FROM_UNIXTIME(CreateTime, '{$date_format}') as day, count(id) as total, {$sum}");

				$counts_array	= array_combine(array_column($counts_array, 'day'), $counts_array);

				$labels = ['total'=>'所有#']+$labels;
				wpjam_line_chart($counts_array, $labels);
			}else{
				$counts_array	= self::Query()->where_fragment($wheres)->group_by('day')->order_by('day')->get_results("FROM_UNIXTIME(CreateTime, '{$date_format}') as day, count(id) as total");

				$counts_array	= array_combine(array_column($counts_array, 'day'), $counts_array);

				wpjam_line_chart($counts_array, array('total'=>$_GET['s']));
			}
			
		}
	}

	public static function message_summary(){
		WPJAM_Chart::form();

		$start_timestamp	= wpjam_get_chart_parameter('start_timestamp');
		$end_timestamp		= wpjam_get_chart_parameter('end_timestamp');
		$date_format		= wpjam_get_chart_parameter('date_format');
		
		$responses			= self::get_responses();

		$wheres	= self::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $start_timestamp)->where_lt('CreateTime', $end_timestamp)->where('MsgType', 'text')->where_not('Response', '')->get_wheres();

		$counts_array	= self::Query()->where_fragment($wheres)->group_by('Response')->order_by('count')->get_results("COUNT( * ) AS count, Response as label");

		wpjam_donut_chart($counts_array, array('labels'=>$responses, 'show_link'=>true, 'chart_width'=> '280'));
		?>

		<div style="clear:both;"></div>

		<?php


		$response_type 	= wpjam_get_data_parameter('type');
		$hot_messages	= self::Query()->where_fragment($wheres)->where('Response', $response_type)->where_not('Content', '')->group_by('Content, Response')->order_by('count')->limit(100)->get_results("COUNT( * ) AS count, Response, MsgType, LOWER(Content) as Content");

		if($hot_messages){
		?>
		<table class="widefat striped" cellspacing="0">
		<thead>
			<tr>
				<th style="width:42px">排名</th>
				<th style="width:42px">数量</th>
				<th>关键词</th>
				<th style="width:91px">回复类型</th>
				<th style="width:28px">操作</th>
			</tr>
		</thead>
		<tbody>
		<?php
		$i = 0;
		foreach ($hot_messages as $message) { $i++; ?>
			<tr>
				<td><?php echo $i; ?></td>
				<td><?php echo $message['count']; ?></td>
				<td><?php echo wp_strip_all_tags($message['Content']); ?></td>
				<td><?php echo $responses[$message['Response']] ?? $message['Response']; ?></td>
				<td><?php echo wpjam_get_page_button('delete_keyword', ['data'=>[
					'keyword'			=> wp_strip_all_tags($message['Content']),
					'type'				=> $message['Response'],
					'start_timestamp'	=> $start_timestamp,
					'end_timestamp'		=> $end_timestamp,
				]]); ?></td>
			</tr>
		<?php } ?>
			<tr>
				<td></td>
				<td></td>
				<td></td>
				<td></td>
				<td><?php echo wpjam_get_page_button('delete_keyword', ['data'=>[
					'type'				=> $message['Response'],
					'start_timestamp'	=> $start_timestamp,
					'end_timestamp'		=> $end_timestamp,
				]]); ?></td>
			</tr>
		</tbody>
		</table>
		<?php
		}
	}

	public static function delete_keyword(){
		$keyword			= wpjam_get_data_parameter('keyword');
		$response			= wpjam_get_data_parameter('type') ?: '';
		$start_timestamp	= wpjam_get_data_parameter('start_timestamp');
		$end_timestamp		= wpjam_get_data_parameter('end_timestamp');

		self::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $start_timestamp)->where_lt('CreateTime', $end_timestamp)->where('MsgType', 'text')->where('Content', $keyword)->where('Response', $response)->delete();

		return true;
	}

	public static function overview_widget(){
		$today						= wpjam_date('Y-m-d');
		$today_start_timestamp		= strtotime(get_gmt_from_date($today.' 00:00:00'));
		$today_end_timestamp		= time();

		$yesterday					= wpjam_date('Y-m-d', time()-DAY_IN_SECONDS);
		$yesterday_start_timestamp	= strtotime(get_gmt_from_date($yesterday.' 00:00:00'));
		$yesterday_end_timestamp	= strtotime(get_gmt_from_date($yesterday.' 23:59:59'));

		$yesterday_end_timestamp_c	= time()-DAY_IN_SECONDS;

		$today_counts 				= self::get_user_subscribe_counts($today_start_timestamp, $today_end_timestamp);
		$yesterday_counts 			= self::get_user_subscribe_counts($yesterday_start_timestamp, $yesterday_end_timestamp);
		$yesterday_compare_counts	= self::get_user_subscribe_counts($yesterday_start_timestamp, $yesterday_end_timestamp_c);
		
		?>
		<h3>用户订阅</h3>
		<table class="widefat" cellspacing="0">
			<thead>
				<tr>
					<th>时间</th>
					<th>用户订阅</th>	
					<th>取消订阅</th>	
					<th>取消率%</th>	
					<th>净增长</th>	
				</tr>
			</thead>
			<tbody>
				<tr class="alternate">
					<td>今日</td>
					<td><?php echo $today_counts['subscribe'];?></td>
					<td><?php echo $today_counts['unsubscribe'];?></td>
					<td><?php echo $today_counts['percent'];?></td>
					<td><?php echo $today_counts['netuser'];?></td>
				</tr>
				<tr class="">
					<td>昨日</td>
					<td><?php echo $yesterday_counts['subscribe'];?></td>
					<td><?php echo $yesterday_counts['unsubscribe'];?></td>
					<td><?php echo $yesterday_counts['percent'];?></td>
					<td><?php echo $yesterday_counts['netuser'];?></td>
				</tr>
				<tr class="alternate" style="font-weight:bold;">
					<td>预计今日</td>
					<td><?php echo $expected_subscribe = self::get_expected_count($today_counts['subscribe'], $yesterday_counts['subscribe'], $yesterday_compare_counts['subscribe']); ?></td>
					<td><?php echo $expected_unsubscribe = self::get_expected_count($today_counts['unsubscribe'], $yesterday_counts['unsubscribe'], $yesterday_compare_counts['unsubscribe'], false); ?></td>
					<td><?php echo self::get_expected_count($today_counts['percent'], $yesterday_counts['percent'],'',false); ?></td>
					<td><?php echo self::get_expected_count(intval($expected_subscribe) - intval($expected_unsubscribe), $yesterday_counts['netuser']); ?></td>
				</tr>
			</tbody>
		</table>

		<p><a href="<?php echo admin_url('page=weixin-stats&tab=subscribe');?>">详细用户订阅数据...</a></p>
		<hr />
		<?php

		$today_counts 				= self::get_message_counts($today_start_timestamp, $today_end_timestamp);
		$yesterday_counts 			= self::get_message_counts($yesterday_start_timestamp, $yesterday_end_timestamp);
		$yesterday_compare_counts	= self::get_message_counts($yesterday_start_timestamp, $yesterday_end_timestamp_c);
		?>
		<h3>消息统计</h3>
		<table class="widefat" cellspacing="0">
			<thead>
				<tr>
					<th>时间</th>
					<th>消息发送次数</th>	
					<th>消息发送人数</th>	
					<th>人均发送次数</th>	
				</tr>
			</thead>
			<tbody>
				<tr class="alternate">
					<td>今日</td>
					<td><?php echo $today_counts['total']; ?>
					<td><?php echo $today_counts['people']; ?>
					<td><?php echo $today_counts['avg']; ?>
				</tr>
				<tr class="">
					<td>昨日</td>
					<td><?php echo $yesterday_counts['total']; ?>
					<td><?php echo $yesterday_counts['people']; ?>
					<td><?php echo $yesterday_counts['avg']; ?>
				</tr>
				<tr class="alternate" style="font-weight:bold;">
					<td>预计今日</td>
					<td><?php echo self::get_expected_count($today_counts['total'], $yesterday_counts['total'], $yesterday_compare_counts['total']); ?>
					<td><?php echo self::get_expected_count($today_counts['people'], $yesterday_counts['people'], $yesterday_compare_counts['people']); ?>
					<td><?php echo self::get_expected_count($today_counts['avg'], $yesterday_counts['avg']); ?>
				</tr>
			</tbody>
		</table>

		<p><a href="<?php echo admin_url('page=weixin-stats&tab=stats');?>">详细消息统计...</a></p>
		<?php
	}

	public static function hot_keyword_widget(){
		$end 	= time();
		$start	= $end - (DAY_IN_SECONDS);

		$where = " CreateTime > {$start} AND CreateTime < {$end}";

		$hot_messages	= self::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $start)->where_lt('CreateTime', $end)->where('MsgType','text')->where_not('Content','')->group_by('Content, Response')->order_by('count')->order('DESC')->limit(10)->get_results("COUNT( * ) AS count, Response, MsgType, LOWER(Content) as Content");

		$responses = self::get_responses();

		$i= 0;
		if($hot_messages){ ?>
		<table class="widefat" cellspacing="0">
			<tbody>
			<?php foreach ($hot_messages as $message) { $alternate = empty($alternate)?'alternate':''; $i++; ?>
				<tr class="<?php echo $alternate; ?>">
					<td style="width:18px;"><?php echo $i; ?></td>
					<td><?php echo wp_strip_all_tags($message['Content']); ?></td>
					<td style="width:32px;"><?php echo $message['count']; ?></td>
					<td style="width:98px;"><?php echo $responses[$message['Response']] ?? ''; ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		<p><a href="<?php echo admin_url('page=weixin-stats&tab=summary');?>">更多热门关键字...</a></p>
		<?php
		}
	}

	public static function get_user_subscribe_counts($start, $end){
		$counts	= self::Query()
				->where('appid', weixin_get_appid())
				->where_gt('CreateTime', $start)
				->where_lt('CreateTime', $end)
				->where('MsgType','event')
				->where_in('Event',['subscribe','unsubscribe'])
				->group_by('Event')
				->order_by('count')
				->get_results("Event as label, count(*) as count");

		if($counts){
			$counts			= wp_list_pluck($counts, 'count', 'label');

			$subscribe		= $counts['subscribe'] ?? 0;
			$unsubscribe	= $counts['unsubscribe'] ?? 0;
		}else{
			$subscribe		= 0;
			$unsubscribe	= 0;
		}
		
		$netuser	= $subscribe - $unsubscribe;
		$percent	= $subscribe ? round($unsubscribe/$subscribe, 4)*100 : 0;

		return compact('subscribe', 'unsubscribe', 'netuser', 'percent');
	}

	public static function get_message_counts($start, $end){
		$total	= self::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $start)->where_lt('CreateTime', $end)->get_var("count(id) as total");
		$people	= self::Query()->where('appid', weixin_get_appid())->where_gt('CreateTime', $start)->where_lt('CreateTime', $end)->get_var("count(DISTINCT FromUserName) as people");
		
		$avg	= ($people)?round($total/$people,4):0;

		return compact('total', 'people', 'avg');
	}

	public static function get_expected_count($today_count, $yesterday_count, $yesterday_compare_count='', $asc=true){
		if($yesterday_compare_count){
			$expected_count = round($today_count/$yesterday_compare_count*$yesterday_count);
		}else{
			$expected_count	= $today_count;
		}

		if(floatval($expected_count) >= floatval($yesterday_count)){
			if($asc){
				$expected_count	.= '<span class="green">&uarr;</span>';
			}else{
				$expected_count	.= '<span class="red">&uarr;</span>';
			}
		}else{
			if($asc){
				$expected_count	.= '<span class="red">&darr;</span>';
			}else{
				$expected_count	.= '<span class="green">&darr;</span>';
			}
		}

		return $expected_count;
	}

	public static function get_widgets(){
		return [
			'overview'	=> ['title'=>'数据预览',			'callback'=>['WEIXIN_Stats', 'overview_widget']],
			'keyword'	=> ['title'=>'24小时热门关键字',	'callback'=>['WEIXIN_Stats', 'hot_keyword_widget'],	'context'=>'side'],
		];
	}
	public static function get_tabs(){
		$tabs	= [];

		$tabs['subscribe']	= ['title'=>'用户增长',	'function'=>[self::class, 'user_subscribe']];
		$tabs['masssend']	= ['title'=>'群发统计',	'function'=>'list',	'model'=>'WEIXIN_Masssend',	'chart'=>true];
		$tabs['stats']		= ['title'=>'消息预览',	'function'=>[self::class, 'message_overview']];
		$tabs['message']	= ['title'=>'消息统计',	'function'=>[self::class, 'message_stats']];
		$tabs['event']		= ['title'=>'事件统计',	'function'=>[self::class, 'message_stats']];

		if(weixin_get_type() >= 3){
			$tabs['menu']	= ['title'=>'菜单统计',	'function'=>[self::class, 'message_stats']];
		}

		$tabs['text']		= ['title'=>'文本统计',	'function'=>[self::class, 'message_stats']];
		$tabs['summary']	= ['title'=>'文本汇总',	'function'=>[self::class, 'message_summary']];

		return $tabs;
	}
}

wpjam_register_page_action('delete_keyword', [
	'button_text'	=>'删除',
	'class'			=>'',
	'direct'		=>true,
	'confirm'		=>true,
	'response'		=>'redirect',
	'callback'		=>['WEIXIN_Stats', 'delete_keyword']
]);