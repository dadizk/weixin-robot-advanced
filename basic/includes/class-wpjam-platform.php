<?php
class WPJAM_Platform extends WPJAM_Register{
	public function __get($key){
		if($key == 'path'){
			return (bool)$this->get_items();
		}

		return parent::__get($key);
	}

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	public function verify(){
		return call_user_func($this->verify);
	}

	public function get_item($page_key, $field=null){
		return is_array($page_key) ? $page_key : parent::get_item($page_key, $field);
	}

	public function get_tabbar($page_key){
		$tabbar	= $this->get_item_arg($page_key, 'tabbar');

		if($tabbar){
			$title	= $this->get_item_arg($page_key, 'title');
			$tabbar	= $tabbar === true ? [] : $tabbar;
			$tabbar	= is_array($tabbar) ? wp_parse_args($tabbar, ['text'=>$title]) : ['text'=>$tabbar];
		}

		return $tabbar;
	}

	public function get_page($page_key){
		$path	= $this->get_item_arg($page_key, 'path');

		return $path ? current(explode('?', $path)) : null;
	}

	public function get_date_type($page_key){
		$page_type	= $this->get_item_arg($page_key, 'page_type');

		return wpjam_get_data_type_object($page_type);
	}

	public function get_fields($page_key){
		$item	= $this->get_item($page_key);
		$fields	= $item['fields'] ?? '';

		if($fields){
			if(is_callable($fields)){
				$fields	= call_user_func($fields, $item, $page_key);
			}
		}else{
			$date_type	= $this->get_date_type($page_key);
			$fields		= $date_type ? $date_type->get_fields($item) : [];
		}

		return $fields ?: [];
	}

	public function has_path($page_key, $strict=false){
		$item	= $this->get_item($page_key);

		if(!$item || ($strict && isset($item['path']) && $item['path'] === false)){
			return false;
		}

		return isset($item['path']) || isset($item['callback']);
	}

	public function get_path($page_key, $args=[], $title=''){
		$item	= $this->get_item($page_key);

		if(!$item){
			return new WP_Error('invalid_page_key', [$title]);
		}

		if(is_array($args)){
			$args	= wp_parse_args(array_filter($args, 'is_exists'), $item);
		}

		$callback	= array_pull($item, 'callback');

		if($callback){
			if(is_callable($callback) && is_array($args)){
				return call_user_func($callback, $args, $page_key) ?: '';
			}
		}else{
			$date_type	= $this->get_date_type($page_key);

			if($date_type){
				return $date_type->get_path($args, $item);
			}
		}

		if(isset($item['path'])){
			return (string)$item['path'];
		}
	}

	public function get_paths($page_key, $query_args=[]){
		$paths		= [];
		$date_type	= $this->get_date_type($page_key);

		if($date_type){
			$args	= $this->get_item($page_key);
			$name	= $date_type->name;

			if(!empty($args[$name])){
				$query_args[$name]	= $args[$name];
			}

			$items	= $date_type->query_items($query_args, false) ?: [];

			foreach($items as $item){
				$path	= $this->get_path($page_key, $item['value']);

				if($path && !is_wp_error($path)){
					$paths[]	= $path;
				}
			}
		}

		return $paths;
	}

	public function registered(){
		if($this->name == 'template'){
			wpjam_register_path('home',		'template',	['title'=>'首页',		'path'=>home_url(),	'group'=>'tabbar']);
			wpjam_register_path('category',	'template',	['title'=>'分类页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('post_tag',	'template',	['title'=>'标签页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('author',	'template',	['title'=>'作者页',		'path'=>'',	'page_type'=>'author']);
			wpjam_register_path('post',		'template',	['title'=>'文章详情页',	'path'=>'',	'page_type'=>'post_type']);
			wpjam_register_path('external', 'template',	['title'=>'外部链接',		'path'=>'',	'fields'=>[
				'url'	=> ['title'=>'',	'type'=>'url',	'required'=>true,	'placeholder'=>'请输入外部链接地址，仅适用网页版。']
			]]);
		}
	}

	public static function get_options($output=''){
		$objects	= self::get_registereds();
		$objects	= array_except($objects, 'web');

		return wp_list_pluck($objects, 'title', $output);
	}

	public static function get_current($args=[], $output='object'){
		if($output == 'bit' && wp_is_numeric_array($args)){
			$objects	= self::get_by(['path'=>true]);
			$bits		= wp_list_pluck($objects, 'bit');
			$args		= array_values(wp_array_slice_assoc(array_flip($bits), $args));
		}

		$args	= $args ?: ['path'=>true];

		foreach(self::get_by($args) as $object){
			if($object && $object->verify()){
				if($output == 'bit'){
					return $object->bit;
				}elseif($output == 'object'){
					return $object;
				}else{
					return $object->name;
				}
			}
		}
	}

	protected static function get_config($key){
		if($key == 'defaults'){
			return [
				'weapp'		=> ['bit'=>1,	'order'=>4,		'title'=>'小程序',	'verify'=>'is_weapp'],
				'weixin'	=> ['bit'=>2,	'order'=>4,		'title'=>'微信网页',	'verify'=>'is_weixin'],
				'mobile'	=> ['bit'=>4,	'order'=>8,		'title'=>'移动网页',	'verify'=>'wp_is_mobile'],
				'web'		=> ['bit'=>8,	'order'=>10,	'title'=>'网页',		'verify'=>'__return_true'],
				'template'	=> ['bit'=>8,	'order'=>10,	'title'=>'网页',		'verify'=>'__return_true']
			];
		}elseif($key == 'orderby'){
			return true;
		}elseif($key == 'order'){
			return 'ASC';
		}
	}
}

class WPJAM_Platforms{
	use WPJAM_Instance_Trait;

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	private $platforms	= [];

	private $fields	= [];
	private $groups	= [];

	private $backup_fields	= [];
	private $backup_groups	= [];
	private $backup_show_if	= [];

	protected function __construct($platforms){
		$this->platforms	= $platforms;
	}

	protected function has_path($path, $operator='AND', $strict=false){
		$platforms	= $this->platforms;

		if($path->name == 'web_view' && $operator == 'AND'){
			$platforms	= array_except($platforms, ['web','template']);
		}

		foreach($platforms as $platform){
			$has	= $platform->has_path($path->name, $strict);

			if($operator == 'AND'){
				if(!$has){
					return false;
				}
			}elseif($operator == 'OR'){
				if($has){
					return true;
				}
			}
		}

		if($operator == 'AND'){
			return true;
		}elseif($operator == 'OR'){
			return false;
		}
	}

	protected function parse_path($platform, $item, $postfix='', $title='', $backup=false, $wp_error=false){
		if($backup){
			$postfix	.= '_backup';
			$title		= '备用'.$title;
		}

		$page_key	= array_pull($item, 'page_key'.$postfix);

		if($page_key == 'none'){
			if(!empty($item['video'])){
				return [
					'type'	=> 'video',
					'video'	=> $item['video'],
					'vide'	=> wpjam_get_qqv_id($item['video'])
				];
			}else{
				return ['type'=>'none'];
			}
		}elseif($page_key == 'external'){
			if(in_array($platform->name, ['web', 'template'])){
				return [
					'type'	=> 'external',
					'url'	=> $item['url']
				];
			}
		}elseif($page_key == 'web_view'){
			if(in_array($platform->name, ['web', 'template'])){
				return [
					'type'	=> 'external',
					'url'	=> $item['src']
				];
			}else{
				return [
					'type'	=> 'web_view',
					'src'	=> $item['src']
				];
			}
		}elseif($page_key){
			if($postfix){
				foreach($platform->get_fields($page_key) as $key => $field){
					$item[$key]	= array_pull($item, $key.$postfix, '');
				}

				$item	= array_filter($item, 'is_exists');
			}

			$path	= $platform->get_path($page_key, $item, $title);

			if(is_wp_error($path)){
				return $wp_error ? $path : [];
			}elseif(is_array($path)){
				return $path;
			}elseif(isset($path)){
				return [
					'type'		=> '',
					'page_key'	=> $page_key,
					'path'		=> $path
				];
			}
		}

		return [];
	}

	protected function add_group($path, $backup=false){
		if($backup === 'both'){
			$this->add_group($path);
			$this->add_group($path, true);
		}else{
			$prop	= $backup ? 'backup_groups' : 'groups';
			$groups	= &$this->$prop;

			if(is_object($path)){
				$group	= $path->group ?: ($path->tabbar ? 'tabbar' : 'others');
				$name	= $path->name;
				$title	= $path->title;
			}else{
				$group	= array_shift($path);
				$name	= array_shift($path);
				$title	= array_shift($path);
			}

			$groups[$group]['options'][$name]	= $title;
		}
	}

	protected function add_fields($path, $postfix='', $prepend='', $backup=false){
		$this->add_group($path);

		$props	= ['fields'];

		if($backup){
			if($this->has_path($path, 'AND')){
				$props[]	= 'backup_fields';

				$this->add_group($path, $backup);
			}else{
				$this->backup_show_if['value'][]	= $path->name;
			}
		}

		$path_fields	= [];

		foreach($this->platforms as $platform){
			$path_fields	= array_merge($path_fields, $platform->get_fields($path->name));
		}

		foreach($props as $prop){
			$fields		= &$this->$prop;
			$postfix	.= $prop == 'backup_fields' ? '_backup' : '';

			foreach($path_fields as $key => $field){
				$key	.= $postfix;

				if($prepend){
					$field['prepend_name']	= $prepend;
				}

				if(isset($field['show_if'])){
					$fields[$key]	= $field;
				}else{
					if(isset($fields[$key])){
						$fields[$key]['show_if']['value'][]	= $path->name;
					}else{
						$fields[$key]	= array_merge($field, [
							'title'		=> '',
							'show_if'	=> ['key'=>'page_key'.$postfix, 'compare'=>'IN', 'value'=>[$path->name]]
						]);
					}
				}
			}
		}
	}

	public function get_current($output='object'){
		if(count($this->platforms) > 1){
			$platform	= current($this->platforms);

			return $output == 'object' ? $platform : $platform->name;
		}else{
			return WPJAM_Platform::get_current(array_keys($this->platforms), $output);
		}
	}

	public function get_fields($args, $strict=false){
		$prepend	= array_pull($args, 'prepend_name');
		$postfix	= array_pull($args, 'postfix');
		$title		= array_pull($args, 'title') ?: '页面';
		$page_key	= 'page_key'.$postfix;
		$backup		= count($this->platforms) > 1 && !$strict;

		$this->groups	= WPJAM_Path::get_groups();
		$this->fields	= [$page_key=>['options'=>&$this->groups, 'prepend_name'=>$prepend]];

		if($backup){
			$backup_title	= '备用'.$title;

			$this->backup_show_if	= ['key'=>$page_key, 'compare'=>'IN', 'value'=>[]];
			$this->backup_groups	= WPJAM_Path::get_groups();
			$this->backup_fields	= [$page_key.'_backup'=>[
				'options'		=> &$this->backup_groups, 
				'description'	=> $backup_title.'在'.$title.'无效时启用',
				'prepend_name'	=> $prepend
			]];
		}

		foreach(WPJAM_Path::get_by($args) as $path){
			if($this->has_path($path, 'OR', $strict)){
				$this->add_fields($path, $postfix, $prepend, $backup);
			}
		}

		$fields	= [$page_key.'_set'=>[
			'title'		=> $title,
			'type'		=> 'fieldset',
			'fields'	=> $this->fields
		]];

		if(!$strict){
			$this->add_group(['tabbar', 'none', '只展示不跳转'], 'both');

			if($backup){
				$fields[$page_key.'_backup_set']	= [
					'title'		=> $backup_title,
					'type'		=> 'fieldset',
					'fields'	=> $this->backup_fields,
					'show_if'	=> $this->backup_show_if
				];
			}
		}

		return $fields;
	}

	public function parse_item($item, $postfix='', $title=''){
		$parsed		= null;
		$platform	= $this->get_current();

		if($platform){
			$parsed	= $this->parse_path($platform, $item, $postfix, $title);

			if(count($this->platforms) > 1 && !$parsed){
				$parsed	= $this->parse_path($platform, $item, $postfix, $title, true);
			}
		}

		return $parsed ?: ['type'=>'none'];
	}

	public function validate_item($item, $postfix='', $title='', $backup=false){
		foreach($this->platforms as $platform){
			$result	= $this->parse_path($platform, $item, $postfix, $title, $backup, true);

			if(is_wp_error($result)){
				if(!$backup && count($this->platforms) > 1 && $result->get_error_code() == 'invalid_page_key'){
					return $this->validate_item($item, $postfix, $title, true);
				}

				return $result;
			}
		}

		return $result;
	}

	public static function get_instance($platforms=null){
		$args		= is_null($platforms) ? ['path'=>true] : (array)$platforms;
		$objects	= array_filter(WPJAM_Platform::get_by($args));

		if($objects){
			$key	= implode('-', array_keys($objects));
			$object	= self::instance_exists($key);

			return $object ?: self::add_instance($key, new self($objects));
		}
	}
}

class WPJAM_Path extends WPJAM_Register{
	public function __get($key){
		if(in_array($key, ['platform', 'path_type'])){
			return array_keys($this->get_items());
		}

		return parent::__get($key);
	}

	protected static function parse_platform(&$args){
		$platform	= array_pulls($args, ['platform', 'path_type']);

		return $platform ? current($platform) : null;
	}

	protected static function add_group($args){
		if(isset($args['group']) && is_array($args['group'])){
			$group	= array_pull($args, 'group');

			if(isset($group['key'], $group['title'])){
				wpjam_add_item('path_group', $group['key'], ['title'=>$group['title'], 'options'=>[]]);

				$args['group']	= $group['key'];
			}
		}

		return $args;
	}

	public static function get_groups(){
		return array_merge(
			['tabbar'=>['title'=>'菜单栏/常用', 'options'=>[]]],
			wpjam_get_items('path_group'),
			['others'=>['title'=>'其他页面', 'options'=>[]]]
		);
	}

	public static function create($name, ...$args){
		$object	= self::get($name);
		$object	= $object ?: self::register($name, []);
		$args	= count($args) == 2 ? $args[1]+['platform'=>$args[0]] : $args[0];
		$args	= wp_is_numeric_array($args) ? $args : [$args];

		foreach($args as $_args){
			foreach((array)self::parse_platform($_args) as $pf){
				$platform	= WPJAM_Platform::get($pf);

				if($platform){
					$page_type	= array_get($_args, 'page_type');

					if($page_type && in_array($page_type, ['post_type', 'taxonomy']) && empty($_args[$page_type])){
						$_args[$page_type]	= $name;
					}

					$_args	= self::add_group($_args);
					$_args	= array_merge($_args, ['platform'=>$pf, 'path_type'=>$pf]);

					$object->update_args($_args, false)->add_item($pf, $_args);

					$platform->add_item($name, $_args);
				}
			}
		}

		return $object;
	}

	public static function remove($name, $pf=''){
		if($pf){
			$object		= self::get($name);
			$platform	= WPJAM_Platform::get($pf);

			if($object){
				$object->delete_item($pf);
			}

			if($platform){
				$platform->delete_item($name);
			}
		}else{
			self::unregister($name);

			foreach(WPJAM_Platform::get_registereds() as $platform){
				$platform->delete_item($name);
			}
		}
	}
}

class WPJAM_Data_Type extends WPJAM_Register{
	public function __call($method, $args){
		if($this->parse_method($method)){
			return $this->call_method($method, ...$args);
		}

		if(in_array($method, ['parse_value', 'validate_value', 'render_value', 'parse_item', 'query_label', 'filter_query_args'])){
			return $args[0];
		}elseif(in_array($method, ['get_field', 'get_fields'])){
			return [];
		}

		return null;
	}

	public function get_meta_type($args){
		return $this->meta_type ?: $this->call_method('get_meta_type', $args);
	}

	public function prepare_value($value, $parse, $args=[]){
		return $parse ? $this->parse_value($value, $args) : $value;
	}

	public function query_items($args, $wp_error=true){
		if(!$this->parse_method('query_items')){
			return $wp_error ? new WP_Error('undefined_method', ['query_items', '回调函数']) : [];
		}

		$args	= array_filter($args, 'is_exists');
		$items	= $this->call_method('query_items', $args) ?: [];

		if(is_wp_error($items)){
			return $wp_error ? $items : [];
		}

		foreach($items as &$item){
			$item	= $this->parse_item($item, $args);
		}

		return array_values($items);
	}

	public function parse_query_args($args){
		$query_args	= $args['query_args'] ?? [];
		$query_args	= $query_args ? wp_parse_args($query_args) : [];

		if(!empty($args[$this->name])){
			$query_args[$this->name]	= $args[$this->name];
		}

		return $this->filter_query_args($query_args, $args);
	}

	public static function parse_json_module($args){
		$data_type	= array_pull($args, 'data_type');
		$object		= self::get($data_type);

		if(!$object){
			return new WP_Error('invalid_data_type');
		}

		$query_args	= array_get($args, 'query_args', $args);
		$query_args	= $query_args ? wp_parse_args($query_args) : [];
		$query_args	= array_merge($query_args, ['search'=>wpjam_get_parameter('s')]);

		return ['items'=>$object->query_items($query_args, false)];
	}

	public static function get_config($key){
		if($key == 'defaults'){
			return [
				'post_type'	=> ['model'=>'WPJAM_Post_Type_Data_Type',	'meta_type'=>'post'],
				'taxonomy'	=> ['model'=>'WPJAM_Taxonomy_Data_Type',	'meta_type'=>'term'],
				'author'	=> ['model'=>'WPJAM_Author_Data_Type',		'meta_type'=>'user'],
				'model'		=> ['model'=>'WPJAM_Model_Data_Type'],
				'video'		=> ['model'=>'WPJAM_Video_Data_Type'],
			];
		}
	}
}

class WPJAM_Post_Type_Data_Type{
	public static function filter_query_args($query_args, $args){
		if(!empty($args['size'])){
			$query_args['thumbnal_size']	= $args['size'];
		}

		return $query_args;
	}

	public static function query_items($args){
		if(!isset($args['s']) && isset($args['search'])){
			$args['s']	= $args['search'];
		}

		return get_posts(wp_parse_args($args, [
			'posts_per_page'	=> $args['number'] ?? 10,
			'suppress_filters'	=> false,
		])) ?: [];
	}

	public static function parse_item($post){
		return ['label'=>$post->post_title, 'value'=>$post->ID];
	}

	public static function query_label($post_id){
		if($post_id && is_numeric($post_id)){
			return get_the_title($post_id) ?: (int)$post_id;
		}

		return '';
	}

	public static function validate_value($value, $args){
		if(!$value){
			return null;
		}

		$current 	= is_numeric($value) ? get_post_type($value) : null;

		if($current){
			$post_type	= array_get($args, 'post_type') ?: $current;

			if(in_array($current, (array)$post_type, true)){
				return (int)$value;
			}
		}

		return new WP_Error('invalid_post_id', [$args['title']]);
	}

	public static function parse_value($value, $args=[]){
		return wpjam_get_post($value, $args);
	}

	public static function update_caches($ids){
		return WPJAM_Post::update_caches($ids);
	}

	public static function get_path(...$args){
		$post_type	= is_array($args[0]) ? $args[0]['post_type'] : get_post_type($args[0]);
		$pt_object	= wpjam_get_post_type_object($post_type);

		return $pt_object ? $pt_object->get_path(...$args) : '';
	}

	public static function get_field($args){
		$title		= array_pull($args, 'title');
		$post_type	= array_pull($args, 'post_type');

		if(is_null($title) && $post_type && is_string($post_type)){
			$title	= wpjam_get_post_type_setting($post_type, 'title');
		}

		return wp_parse_args($args, [
			'title'			=> $title,
			'type'			=> 'text',
			'class'			=> 'all-options',
			'data_type'		=> 'post_type',
			'post_type'		=> $post_type,
			'placeholder'	=> '请输入'.$title.'ID或者输入关键字筛选',
			'show_in_rest'	=> ['type'=>'integer']
		]);
	}

	public static function get_fields($args){
		$post_type	= $args['post_type'];
		$object		= get_post_type_object($post_type);

		return $object ? [$post_type.'_id' => self::get_field(['post_type'=>$post_type, 'required'=>true])] : [];
	}
}

class WPJAM_Taxonomy_Data_Type{
	public static function filter_query_args($query_args, $args){
		if($args['creatable']){
			$query_args['creatable']	= $args['creatable'];
		}

		unset($args['creatable']);

		return $query_args;
	}

	public static function query_items($args){
		return get_terms(wp_parse_args($args, [
			'number'		=> (isset($args['parent']) ? 0 : 10),
			'hide_empty'	=> 0
		])) ?: [];
	}

	public static function parse_item($term){
		if(is_object($term)){
			return ['label'=>$term->name, 'value'=>$term->term_id];
		}else{
			return ['label'=>$term['name'], 'value'=>$term['id']];
		}
	}

	public static function query_label($term_id, $args){
		if($term_id && is_numeric($term_id)){
			return get_term_field('name', $term_id, $args['taxonomy']) ?: (int)$term_id;
		}

		return '';
	}

	public static function validate_value($value, $args){
		if(!$value){
			return null;
		}

		$taxonomy	= $args['taxonomy'];

		if(is_numeric($value)){
			if(get_term($value, $taxonomy)){
				return (int)$value; 
			}
		}elseif(is_array($value)){
			$object	= wpjam_get_taxonomy_object($taxonomy);
			$levels	= $object ? $object->levels : 0;
			$prev	= 0;

			for($level=0; $level < $levels; $level++){
				$_value	= $value['level_'.$level] ?? 0;

				if(!$_value){
					return $prev;
				}

				$prev	= $_value;
			}

			return $prev;
		}else{
			$result	= term_exists($value, $taxonomy);

			if($result){
				return is_array($result) ? $result['term_id'] : $result;
			}elseif(!empty($args['creatable'])){
				return WPJAM_Term::insert(['name'=>$value, 'taxonomy'=>$taxonomy]);
			}
		}

		return new WP_Error('invalid_term_id', [$args['title']]);
	}

	public static function parse_value($value, $args=[]){
		return wpjam_get_term($value, $args);
	}

	public static function render_value($value, $args){
		$taxonomy	= $args['taxonomy'];
		$object		= wpjam_get_taxonomy_object($taxonomy);
		$levels		= $object ? $object->levels : 0;

		if($levels && $value){
			$ancestors	= get_ancestors($value, $taxonomy, 'taxonomy');
			$term_ids	= array_merge([$value], $ancestors);
			$term_ids	= array_reverse($term_ids);
			$terms		= wpjam_get_terms(['taxonomy'=>$taxonomy, 'hide_empty'=>0]);
			$value		= [];

			for($level=0; $level < $levels; $level++){
				$term_id	= $term_ids[$level] ?? 0;

				if($level == 0){
					$value['level_'.$level]	=  ['value'=>$term_id, 'parent'=>0];
				}else{
					if(!$parent){
						break;
					}

					foreach($terms as $term){
						if($term['id'] == $parent){
							$terms	= $term['children'];
						}
					}

					$value['level_'.$level]	=  [
						'value'		=> $term_id,
						'parent'	=> $parent,
						'items'		=> array_map([self::class, 'parse_item'], $terms)
					];
				}

				$parent	= $term_id;
			}
		}

		return $value;
	}

	public static function update_caches($ids){
		return WPJAM_Term::update_caches($ids);
	}

	public static function get_path(...$args){
		$taxonomy	= is_array($args[0]) ? $args[0]['taxonomy'] : get_term_taxonomy($args[0]);
		$object		= wpjam_get_taxonomy_object($taxonomy);

		return $object ? $object->get_path(...$args) : '';
	}

	public static function get_field($args){
		$taxonomy	= $args['taxonomy'] ?? '';
		$object		= ($taxonomy && is_string($taxonomy)) ? wpjam_get_taxonomy_object($taxonomy) : null;
		$type		= $args['type'] ?? '';
		$title		= $args['title'] ?? null;
		$title		= $args['title'] = (is_null($title) && $object) ? $object->title : $title;

		if($object && $object->hierarchical && $type != 'text'){
			if(is_admin()){
				$selectable	= wp_count_terms($taxonomy) <= 30;
			}else{
				$selectable	= $type == 'select' || ($type == 'mu-text' && array_get($args, 'item_type') == 'select');
			}

			if($selectable){
				$type		= $type ?: 'select';
				$levels		= $type == 'mu-text' ? 0 : $object->levels;
				$defaults	= [];
				$option_all	= array_pull($args, 'option_all', true);

				if($option_all !== false){
					$option_all	= $option_all === true ? '请选择' : $option_all;
					$defaults	= [''=>$option_all];
				}

				if($levels > 1 && $type == 'select'){
					$fields	= [];

					for($level=0; $level < $levels; $level++){
						if($level == 0){
							$terms		= wpjam_get_terms(['taxonomy'=>$taxonomy, 'hide_empty'=>0], 1);
							$options	= $terms ? array_column($terms, 'name', 'id') : [];
							$show_if	= [];
						}else{
							$options	= [];
							$show_if	= ['key'=>'level_'.($level-1), 'compare'=>'!=', 'value'=>0, 'query_arg'=>'parent'];
						}

						$options	= $defaults+$options;
						$sub_key	= 'level_'.$level;

						$fields[$sub_key]	= [
							'data-sub_key'	=> $sub_key,
							'type'			=> 'select', 
							'options'		=> $options,
							'show_if'		=> $show_if,
							'show_in_rest'	=> ['type'=>'integer']
						];

						if($level > 0){
							$fields[$sub_key]	+= [
								'data_type'		=> 'taxonomy',
								'taxonomy'		=> $taxonomy,
								'wrap_class'	=> 'sublevel hidden',
								'data'			=> ['option_all'=>$option_all],
							];
						}
					}

					$field	= wp_parse_args($args, [
						'type'			=> 'fields',
						'fieldset_type'	=> 'array',
						'class'			=> 'cascading-dropdown',
						'fields'		=> $fields,
						'data_type'		=> 'taxonomy',
						'show_in_rest'	=> ['type'=>'integer']
					]);

					if($option_all === false){
						$field['value']	= $fields['level_0']['options'] ? array_key_first($fields['level_0']['options']) : 0;
					}

					return $field;
				}else{
					$terms		= wpjam_get_terms(['taxonomy'=>$taxonomy, 'hide_empty'=>0, 'format'=>'flat']);
					$options	= $terms ? array_column($terms, 'name', 'id') : [];
					$options	= $defaults+$options;

					if($type == 'mu-text'){
						$args['item_type']	= 'select';
					}

					return wp_parse_args($args, [
						'type'			=> 'select',
						'options'		=> $options,
						'show_in_rest'	=> ['type'=>'integer']
					]);
				}
			}
		}

		return wp_parse_args($args, [
			'type'			=> 'text',
			'class'			=> 'all-options',
			'data_type'		=> 'taxonomy',
			'placeholder'	=> '请输入'.$title.'ID或者输入关键字筛选',
			'show_in_rest'	=> ['type'=>'integer']
		]);
	}

	public static function get_fields($args){
		$taxonomy	= $args['taxonomy'];
		$object		= wpjam_get_taxonomy_object($taxonomy);

		return $object ? [$object->query_key => self::get_field(['taxonomy'=>$taxonomy, 'required'=>true])] : [];
	}
}

class WPJAM_Author_Data_Type{
	public static function get_path(...$args){
		if(is_array($args[0])){
			$args	= $args[0];
			$author	= (int)array_pull($args, 'author');
		}else{
			$author	= $args[0];
			$args	= $args[1];
		}

		if(!$author){
			return new WP_Error('invalid_author', ['作者']);
		}

		if($args['platform'] == 'template'){
			return get_author_posts_url($author);
		}

		return str_replace('%author%', $author, $args['path']);
	}

	public static function get_fields(){
		return ['author' => ['type'=>'select',	'options'=>wp_list_pluck(wpjam_get_authors(), 'display_name', 'ID')]];
	}
}

class WPJAM_Video_Data_Type{
	public static function get_video_mp4($id_or_url){
		if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
			if(preg_match('#http://www.miaopai.com/show/(.*?).htm#i',$id_or_url, $matches)){
				return 'http://gslb.miaopai.com/stream/'.esc_attr($matches[1]).'.mp4';
			}elseif(preg_match('#https://v.qq.com/x/page/(.*?).html#i',$id_or_url, $matches)){
				return self::get_qqv_mp4($matches[1]);
			}elseif(preg_match('#https://v.qq.com/x/cover/.*/(.*?).html#i',$id_or_url, $matches)){
				return self::get_qqv_mp4($matches[1]);
			}else{
				return wpjam_zh_urlencode($id_or_url);
			}
		}else{
			return self::get_qqv_mp4($id_or_url);
		}
	}

	public static function get_qqv_id($id_or_url){
		if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
			foreach([
				'#https://v.qq.com/x/page/(.*?).html#i',
				'#https://v.qq.com/x/cover/.*/(.*?).html#i'
			] as $pattern){
				if(preg_match($pattern,$id_or_url, $matches)){
					return $matches[1];
				}
			}

			return '';
		}else{
			return $id_or_url;
		}
	}

	public static function get_qqv_mp4($vid){
		if(strlen($vid) > 20){
			return new WP_Error('error', '无效的腾讯视频');
		}

		$mp4 = wp_cache_get($vid, 'qqv_mp4');

		if($mp4 === false){
			$response	= wpjam_remote_request('http://vv.video.qq.com/getinfo?otype=json&platform=11001&vid='.$vid, [
				'timeout'				=> 4,
				'json_decode_required'	=> false
			]);

			if(is_wp_error($response)){
				return $response;
			}

			$response	= trim(substr($response, strpos($response, '{')),';');
			$response	= wpjam_try('wpjam_json_decode', $response);

			if(empty($response['vl'])){
				return new WP_Error('error', '腾讯视频不存在或者为收费视频！');
			}

			$u		= $response['vl']['vi'][0];
			$p0		= $u['ul']['ui'][0]['url'];
			$p1		= $u['fn'];
			$p2		= $u['fvkey'];
			$mp4	= $p0.$p1.'?vkey='.$p2;

			wp_cache_set($vid, $mp4, 'qqv_mp4', HOUR_IN_SECONDS*6);
		}

		return $mp4;
	}

	public static function query_items($args){
		return [];
	}

	public static function parse_value($value, $args=[]){
		return self::get_video_mp4($value);
	}
}

class WPJAM_Model_Data_Type{
	public static function filter_query_args($query_args, $args){
		$model	= array_get($query_args, 'model');

		if(!$model || !class_exists($model)){
			wp_die(' model 未定义');
		}

		return $query_args;
	}

	public static function query_items($args){
		$args	= array_except($args, ['label_key', 'id_key']);
		$args	= wp_parse_args($args, ['number'=>10]);
		$model	= array_pull($args, 'model');
		$query	= wpjam_call_method($model, 'query', $args);

		return is_wp_error($query) ? $query : $query->items;
	}

	public static function parse_item($item, $args){
		$label_key	= array_pull($args, 'label_key', 'title');
		$id_key		= array_pull($args, 'id_key', 'id');

		return ['label'=>$item[$label_key], 'value'=>$item[$id_key]];
	}

	public static function query_label($id, $args){
		$model	= array_pull($args, 'model');
		$data	= wpjam_call_method($model, 'get', $id);

		if($data && !is_wp_error($data)){
			$label_key	= $args['label_key'];

			return $data[$label_key] ?: $id;
		}

		return '';
	}

	public static function validate_value($value, $args){
		if($value){
			$model	= array_pull($args, 'model');
			$result	= wpjam_call_method($model, 'get', $value);

			return is_wp_error($result) ? $result : $value;
		}

		return null;
	}

	public static function get_meta_type($args){
		$model		= array_pull($args, 'model');
		$meta_type	= wpjam_call_method($model, 'get_meta_type');

		return is_wp_error($meta_type) ? '' : $meta_type;
	}
}