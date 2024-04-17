<?php
class WEIXIN_Material{
	public static function get_type(){
		return wpjam_get_current_tab_setting('material_type');
	}

	public static function get($media_id){
		return ['media_id'=>$media_id];
	}

	public static function insert($data){
		return weixin_add_material($data, self::get_type());
	}

	public static function delete($media_id){
		$weixin	= weixin();

		$weixin->cache_delete('material'.$media_id);

		return $weixin->post('/cgi-bin/material/del_material', compact('media_id'));
	}

	public static function reply($media_id, $data){
		$type	= self::get_type();
		$result	= WEIXIN_Reply_Setting::set_by_keyword($data['keyword'], [
			'match'		=> $data['match'] ?? 'full',
			'type'		=> $type,
			$type		=> maybe_serialize($data[$type]),
			'status'	=> 1
		]);

		return is_wp_error($result) ? $result : admin_url('page=weixin-replies&id='.$result);
	}

	public static function download($media_id){

	}

	public static function query_items($limit, $offset){
		$material = weixin()->post('/cgi-bin/material/batchget_material', ['type'=>self::get_type(), 'offset'=>$offset, 'count'=>$limit]);

		if(is_wp_error($material)){
			return $material;
		}

		if(isset($material['item'])){
			$items	= $material['item'];
			$total	= $material['total_count'];
		}else{
			$items	= [];
			$total	= 0;
		}

		return ['items'=>$items, 'total'=>$total];
	}

	public static function render_item($item){
		if(self::get_type() == 'image' && !empty($item['url'])){
			$item['image']	= '<a referrerPolicy="no-referrer" href="'.$item['url'].'" target="_blank"><img referrerPolicy="no-referrer" src="'.$item['url'].'" /></a>';
		}

		$item['id']				= $item['media_id'];
		$item['update_time']	= get_date_from_gmt(date('Y-m-d H:i:s',$item['update_time']));

		return $item;
	}

	public static function get_actions(){
		return [
			'reply'		=> ['title'=>'添加到自定义回复',	'response'=>'redirect'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,	'confirm'=>true,	'bulk'=>true],
		];
	}

	public static function get_fields($action_key='', $media_id=''){
		$type	= self::get_type();

		if($action_key == 'reply'){
			$fields		= WEIXIN_Reply_Setting::get_reply_fields();

			$fields['type']['value']	= $type;
			$fields['type']['type']		= 'radio';
			$fields['type']['options']	= wp_array_slice_assoc($fields['type']['options'], [$type]);
			$fields[$type]['value']		= $media_id;

			return array_except($fields, 'status');
		}else{
			$fields = [
				'name'			=> ['title'=>'名称',			'type'=>'text',	'show_admin_column'=>true],
				'media_id'		=> ['title'=>'Media ID',	'type'=>'text',	'show_admin_column'=>true],
				'update_time'	=> ['title'=>'最后更新时间',	'type'=>'text',	'show_admin_column'=>true]
			];

			if($type == 'image'){
				$fields	= ['image'=>['title'=>'图片',	'type'=>'view',	'show_admin_column'=>'only']]+$fields;
			}

			return $fields;
		}
	}

	public static function get_list_table(){
		return [
			'singular'			=> 'weixin-material',
			'plural'			=> 'weixin-materials',
			'primary_column'	=> 'media_id',
			'primary_key'		=> 'media_id',
			'model'				=> self::class,
			'per_page'			=> 20
		];
	}

	public static function get_tabs(){
		$tabs	= [];
		$weixin	= weixin();

		$material_count = $weixin->cache_get('material_count');

		if($material_count === false){
			$material_count = $weixin->get('/cgi-bin/material/get_materialcount');

			if(is_wp_error($material_count)){
				wp_die($material_count->get_error_message());
			}

			$weixin->cache_set('material_count', $material_count, 60);
		}

		wp_add_inline_style('list-tables', join("\n",[
			'th.column-update_time{width:90px;}',
			'td.column-image a{display:block;}',
			'td.column-image a img{width:150px; height:150px; object-fit: contain;}'
		]));

		foreach(['image'=>'图片', 'voice'=>'语音', 'video'=>'视频'] as $type => $name){
			$tabs[$type]	= [
				'name'			=> $name,
				'tab_title'		=> $name.' <small>('.$material_count[$type.'_count'].')</small>',
				'material_type'	=> $type,
				'function'		=> 'list',
				'list_table'	=> self::class,
				'title'			=> $name.'素材',
			];
		}

		return $tabs;

		// if(isset($_FILES['image'])){
		// 	if(!current_user_can('manage_options')){
		// 		wp_die('无权限！');
		// 	}

		// 	$media	= $_FILES['image'];

		// 	if(empty($media['tmp_name'])){
		// 		wp_die('请上传导入文件');
		// 	}

		// 	if($media['error']){
		// 		wp_die('导入文件异常'.$media['error']);
		// 	}

		// 	$response	= weixin_add_material($media['tmp_name'], 'image', ['filename'=>$media['name'], 'filetype'=>$media['type']]);

		// 	if(is_wp_error($response)){
		// 		wpjam_admin_add_error($response->get_error_code().'：'.$response->get_error_message());
		// 	}else{
		// 		wpjam_admin_add_error('图片新增成功');
		// 	}
		// }
	}

	/*public static function extra_tablenav($which){
		if(self::get_type() == 'image' && $which == 'top'){ ?>
			<input id="new_image" type="file" name="image" style="filter:alpha(opacity=0);position:absolute;opacity:0;width:80px;height:34px; margin:-5px 0;" hidefocus>  
			<a href="#" class="page-title-action button-primary" style="position:static;">上传图片</a>
			<script type="text/javascript">
			jQuery(function($){
				$('body').on('change', '#new_image', function(){
					if($('#new_image').val()){
						$('form#list_table_form')
						.attr('enctype', 'multipart/form-data')
						.attr('encoding', 'multipart/form-data')	// for ie
						.submit();
					}
				});
			});
			</script>
		<?php }
	}*/
}

class WEIXIN_Draft{
	public static function get($media_id){
		return ['media_id'=>$media_id];
	}

	public static function insert($data){
		$response	= weixin()->post('/cgi-bin/draft/add', ['articles'=>$data]);

		return is_wp_error($response) ? $response : $response['media_id'];
	}

	public static function delete($media_id){
		return weixin()->post('/cgi-bin/draft/delete', ['media_id'=>$media_id]);
	}

	public static function submit($media_id){
		$response	= weixin()->post('/cgi-bin/freepublish/submit', ['media_id'=>$media_id]);

		if(is_wp_error($response)){
			return $response;
		}

		$response	= weixin()->post('/cgi-bin/freepublish/get', ['publish_id'=>$response['publish_id']]);

		if(is_wp_error($response)){
			return $response;
		}

		$publish_status	= $response['publish_status'];

		if($publish_status){
			$statuses	= [
				1	=> '发布中',
				2	=> '原创失败',
				3	=> '常规失败',
				4	=> '平台审核不通过',
				5	=> '成功后用户删除所有文章',
				6	=> '成功后系统封禁所有文章',
			];

			$status_msg	= $statuses[$publish_status];

			return new WP_Error('publish_status_'.$publish_status, $status_msg);
		}

		return admin_url('page=weixin-draft&tab=publish&id='.$response['article_id']);
	}

	public static function query_items($limit, $offset){
		$response = weixin()->post('/cgi-bin/draft/batchget', ['offset'=>$offset, 'count'=>$limit,  'no_content'=>1]);

		if(is_wp_error($response)){
			return $response;
		}

		if(isset($response['item'])){
			$items	= $response['item'];
			$total	= $response['total_count'];
		}else{
			$items	= [];
			$total	= 0;
		}

		return ['items'=>$items, 'total'=>$total];
	}

	public static function render_item($item){
		if(is_array($item['content']['news_item'] ) ){
			$content	= '';
			$count		= count($item['content']['news_item']);

			$item_class	= 'big'; 
			$img_width	= 360;
			$img_height	= 0;

			foreach($item['content']['news_item'] as $news_item){
				if($news_item['thumb_url']){
					$item_content	= '<span class="item-thumb"><img referrerPolicy="no-referrer" src="'.$news_item['thumb_url'].'" '.image_hwstring($img_width, $img_height).' /></span>';
				}else{
					$item_content	= '<span class="item-thumb" style="background:linear-gradient(180deg,transparent,rgba(0,0,0,.4));" /></span>';
				}

				$item_content	.= '<a target="_blank" href="'.$news_item['url'].'"><span class="news-item-title">'.$news_item['title'].'</span></a>';

				if($count == 1 && $news_item['digest']){
					$item_content	.= '<span class="news-item-excerpt">'.$news_item['digest'].'</span>';
				}

				$content	.= '<div class="news-item '.$item_class.'">'.$item_content.'</div>';

				$item_class	= 'small'; 
				$img_width	= 0;
				$img_height	= 60;
			}

			$item['content'] 	= '<div class="news-items">'.$content.'</div>';
		}

		$item['id']				= $item['media_id'];
		$item['update_time']	= get_date_from_gmt(date('Y-m-d H:i:s',$item['update_time']));

		return $item;
	}

	public static function ajax_fetch(){
		$mp_url	= wpjam_get_data_parameter('mp_url');

		if(empty($mp_url)){
			return new WP_Error('emoty_mp_url','输入图文链接不能为空');
		}

		$article = weixin_parse_mp_article($mp_url);

		if(is_wp_error($article)){
			return $article;
		}

		$file	= download_url($article['thumb_url']);

		if(is_wp_error($file)){
			return $file;
		}

		$filetype	= wp_get_image_mime($file);
		$filename	= md5($image_url).'.'.(explode('/', $filetype)[1]);
		$response	= weixin_add_material($file, 'image', compact('filetype', 'filename'));

		unlink($file);

		if(is_wp_error($response)){
			return $response;
		}

		$media_id	= $response['media_id'];

		$article['thumb_media_id']			= $media_id;
		$article['show_cover_pic']			= 0;
		$article['need_open_comment']		= 1;
		$article['only_fans_can_comment']	= 1;
		$article['content']					= strip_tags($article['content'],'<p><img><br><span><section><strong><iframe><blockquote>');

		unset($article['thumb_url']);

		$result	= weixin()->post('/cgi-bin/material/add_news', ['articles'=>[$article]]);

		if(is_wp_error($result)){
			return $result;
		}

		return ['errmsg'=>'一键转载成功，<a href="'.admin_url('page=weixin-material').'">请点击这里查看。</a>'];
	}

	public static function get_actions(){
		return [
			'submit'	=> ['title'=>'发布',	'direct'=>true,	'response'=>'redirect'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,	'confirm'=>true,	'bulk'=>true],
		];
	}

	public static function get_fields($action_key='', $media_id=''){
		$fields = [
			'content'		=> ['title'=>'草稿',			'type'=>'text',	'show_admin_column'=>true],
			'media_id'		=> ['title'=>'Media ID',	'type'=>'text',	'show_admin_column'=>true],
			'update_time'	=> ['title'=>'最后更新时间',	'type'=>'text',	'show_admin_column'=>true]
		];

		return $fields;
	}

	public static function get_list_table(){
		return [
			'singular'			=> 'weixin-draft',
			'plural'			=> 'weixin-drafts',
			'primary_column'	=> 'media_id',
			'primary_key'		=> 'media_id',
			'model'				=> 'WEIXIN_Draft',
			'per_page'			=> 20
		];
	}

	public static function get_tabs(){
		return [
			'draft'		=> [
				'title'			=> '草稿箱',
				'function'		=> 'list',
				'list_table'	=> 'WEIXIN_Draft'
			],
			'publish'	=> [
				'title'			=> '已发布',
				'function'		=> 'list',
				'list_table'	=> 'WEIXIN_Publish'
			]
		];
	}
}

class WEIXIN_Publish{
	public static function get($article_id){
		return ['article_id'=>$article_id];
	}

	// public static function delete($article_id){
	// 	return weixin()->post('/cgi-bin/freepublish/delete', ['article_id'=>$article_id, 'index'=>$index??]);
	// }

	public static function query_items($limit, $offset){
		$response = weixin()->post('/cgi-bin/freepublish/batchget', ['offset'=>$offset, 'count'=>$limit, 'no_content'=>1]);

		if(is_wp_error($response)){
			return $response;
		}

		if(isset($response['item'])){
			$items	= $response['item'];
			$total	= $response['total_count'];
		}else{
			$items	= [];
			$total	= 0;
		}

		return ['items'=>$items, 'total'=>$total];
	}

	public static function render_item($item){
		if(is_array($item['content']['news_item'] ) ){
			$content	= '';
			$count		= count($item['content']['news_item']);

			$item_class	= 'big'; 
			$img_width	= 360;
			$img_height	= 0;

			foreach($item['content']['news_item'] as $news_item){
				$item_content	= '<span class="item-thumb"><img referrerPolicy="no-referrer" src="'.$news_item['thumb_url'].'" '.image_hwstring($img_width, $img_height).' /></span>';
				$item_content	.= '<a target="_blank" href="'.$news_item['url'].'"><span class="news-item-title">'.$news_item['title'].'</span></a>';

				if($count == 1 && $news_item['digest']){
					$item_content	.= '<span class="news-item-excerpt">'.$news_item['digest'].'</span>';
				}

				$content	.= '<div class="news-item '.$item_class.'">'.$item_content.'</div>';

				$item_class	= 'small'; 
				$img_width	= 0;
				$img_height	= 60;
			}

			$item['content'] 	= '<div class="news-items">'.$content.'</div>';
		}

		$item['id']				= $item['article_id'];
		$item['update_time']	= get_date_from_gmt(date('Y-m-d H:i:s',$item['update_time']));

		return $item;
	}

	public static function get_actions(){
		return [
			// 'delete'	=> ['title'=>'删除',	'direct'=>true,	'confirm'=>true,	'bulk'=>true],
		];
	}

	public static function get_fields($action_key='', $article_id=''){
		$fields = [
			'content'		=> ['title'=>'草稿',			'type'=>'text',	'show_admin_column'=>true],
			'article_id'	=> ['title'=>'图文消息 ID',	'type'=>'text',	'show_admin_column'=>true],
			'update_time'	=> ['title'=>'最后更新时间',	'type'=>'text',	'show_admin_column'=>true]
		];

		return $fields;
	}

	public static function get_list_table(){
		return [
			'singular'			=> 'weixin-publish',
			'plural'			=> 'weixin-publishs',
			'primary_column'	=> 'article_id',
			'primary_key'		=> 'article_id',
			'model'				=> 'WEIXIN_Publish',
			'per_page'			=> 20
		];
	}
}