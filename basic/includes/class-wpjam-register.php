<?php
trait WPJAM_Call_Trait{
	protected static $_closures	= [];

	public static function add_dynamic_method($method, $closure){
		if(is_closure($closure)){
			$name	= self::get_called_name();

			self::$_closures[$name][$method]	= $closure;
		}
	}

	public static function remove_dynamic_method($method){
		$name	= self::get_called_name();

		unset(self::$_closures[$name][$method]);
	}

	protected static function get_called_name(){
		return strtolower(get_called_class());
	}

	protected function bind_if_closure($closure){
		return is_closure($closure) ? $closure->bindTo($this, get_called_class()) : $closure;
	}

	protected function parse_dynamic_method($method, $name=null) {
		$name		= $name ? strtolower($name) : self::get_called_name();
		$closure	= self::$_closures[$name][$method] ?? null;

		if(!$closure){
			$parent		= get_parent_class($name);
			$closure	= $parent ? $this->parse_dynamic_method($method, $parent) : null;
		}

		return $this->bind_if_closure($closure);
	}

	protected function call_dynamic_method($method, ...$args){
		$callback	= is_closure($method) ? $this->bind_if_closure($method) : $this->parse_dynamic_method($method);

		return $callback ? call_user_func_array($callback, $args) : null;
	}

	public function call($method, ...$args){
		try{
			return $this->try($method, ...$args);
		}catch(WPJAM_Exception $e){
			return $e->get_wp_error();
		}catch(Exception $e){
			return new WP_Error($e->getCode(), $e->getMessage());
		}
	}

	protected function try($method, ...$args){
		try{
			if(is_closure($method)){
				$callback	= $this->bind_if_closure($method);
			}elseif(method_exists($this, $method)){
				$callback	= [$this, $method];
			}else{
				$callback	= $this->parse_dynamic_method($method) ?: [$this, $method];
			}

			$result	= call_user_func_array($callback, $args);

			return wpjam_throw_if_error($result);
		}catch(Exception $e){
			throw $e;
		}
	}

	protected function map($data, $method, ...$args){
		if($data && is_array($data)){
			foreach($data as $key => &$item){
				$_args	= array_merge($args, [$key]);
				$item	= $this->try($method, $item, ...$_args);
			}
		}

		return $data;
	}
}

trait WPJAM_Items_Trait{
	public function get_items($field=''){
		$field	= $field ?: '_items';
		$items	= $this->$field;

		return $items ?: [];
	}

	public function update_items($items, $field=''){
		$field	= $field ?: '_items';

		$this->$field	= $items;

		return $this;
	}

	public function get_item_keys($field=''){
		return array_keys($this->get_items($field));
	}

	public function item_exists($key, $field=''){
		return array_key_exists($key, $this->get_items($field));
	}

	public function get_item($key, $field=''){
		$items	= $this->get_items($field);

		return $items[$key] ?? null;
	}

	public function get_item_arg($key, $arg, $field=''){
		$item	= $this->get_item($key, $field);

		return $item ? array_get($item, $arg) : null;
	}

	public function has_item($item, $field=''){
		$items	= $this->get_items($field);

		return in_array($item, $items);
	}

	public function add_item(...$args){
		if(count($args) == 1 || is_array($args[0])){
			$key	= null;
			$item	= $args[0];
			$field	= $args[1] ?? null;
		}else{
			$key	= $args[0];
			$item	= $args[1];
			$field	= $args[2] ?? null;
		}

		return $this->item_action('add', $key, $item, $field);
	}

	public function remove_item($item, $field=''){
		return $this->item_action('remove', null, $item, $field);
	}

	public function edit_item($key, $item, $field=''){
		return $this->item_action('edit', $key, $item, $field);
	}

	public function replace_item($key, $item, $field=''){
		return $this->item_action('replace', $key, $item, $field);
	}

	public function set_item($key, $item, $field=''){
		return $this->item_action('set', $key, $item, $field);
	}

	public function delete_item($key, $field=''){
		$result	= $this->item_action('delete', $key, null, $field);

		if(!is_wp_error($result)){
			$this->after_delete_item($key, $field);
		}

		return $result;
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		$items	= $this->get_items($field);
		$new	= array_pulls($items, $orders);

		return $this->update_items(array_merge($new, $items), $field);
	}

	protected function item_action($action, $key, $item, $field=''){
		$result	= $this->validate_item($item, $key, $action, $field);

		if(is_wp_error($result)){
			return $result;
		}

		$items	= $this->get_items($field);

		if(isset($key)){
			if($this->item_exists($key, $field)){
				if($action == 'add'){
					return new WP_Error('invalid_item_key', '「'.$key.'」已存在，无法添加');
				}
			}else{
				if(in_array($action, ['edit', 'replace'])){
					return new WP_Error('invalid_item_key', '「'.$key.'不存在，无法编辑');
				}elseif($action == 'delete'){
					return new WP_Error('invalid_item_key', '「'.$key.'不存在，无法删除');
				}
			}

			if(isset($item)){
				$items[$key]	= $this->sanitize_item($item, $key, $action, $field);
			}else{
				$items	= array_except($items, $key);
			}
		}else{
			if($action == 'add'){
				$items[]	= $this->sanitize_item($item, $key, $action, $field);
			}elseif($action == 'remove'){
				$items		= array_diff($items, [$item]);
			}else{
				return new WP_Error('invalid_item_key', '必须设置key');
			}
		}

		return $this->update_items($items, $field);
	}

	protected function validate_item($item=null, $key=null, $action='', $field=''){
		return true;
	}

	protected function sanitize_item($item, $id=null, $field=''){
		return $item;
	}

	protected function after_delete_item($key, $field=''){
	}

	public static function item_list_action($id, $data, $action_key=''){
		if(!method_exists(get_called_class(), 'get_instance')){
			wp_die($model.'->get_instance() 为定义');
		}

		$object	= static::get_instance($id);

		if(!$object){
			wp_die('invaid_id');
		}

		$i	= wpjam_get_data_parameter('i');

		if($action_key == 'add_item'){
			return $object->add_item($i, $data);
		}elseif($action_key == 'edit_item'){
			return $object->edit_item($i, $data);
		}elseif($action_key == 'del_item'){
			return $object->del_item($i);
		}elseif($action_key == 'move_item'){
			$orders	= wpjam_get_data_parameter('item') ?: [];

			return $object->move_item($orders);
		}
	}

	public static function item_data_action($id){
		if(!method_exists(get_called_class(), 'get_instance')){
			wp_die($model.'->get_instance() 为定义');
		}

		$object	= static::get_instance($id);

		if(!$object){
			wp_die('invaid_id');
		}

		$i	= wpjam_get_data_parameter('i');

		return $object->get_item($i);
	}

	public static function get_item_actions(){
		$item_action	= [
			'callback'		=> [self::class, 'item_list_action'],
			'data_callback'	=> [self::class, 'item_data_action'],
			'row_action'	=> false,
		];

		return [
			'add_item'	=>['title'=>'添加项目',	'page_title'=>'添加项目',	'dismiss'=>true]+$item_action,
			'edit_item'	=>['title'=>'修改项目',	'page_title'=>'修改项目',	]+$item_action,
			'del_item'	=>['title'=>'删除项目',	'page_title'=>'删除项目',	'direct'=>true,	'confirm'=>true]+$item_action,
			'move_item'	=>['title'=>'移动项目',	'page_title'=>'移动项目',	'direct'=>true]+$item_action,
		];
	}
}

class WPJAM_Args implements ArrayAccess, IteratorAggregate, JsonSerializable{
	use WPJAM_Call_Trait;

	protected $args;
	protected $_archives	= [];

	public function __construct($args=[]){
		$this->args	= $args;
	}

	public function __get($key){
		$args	= $this->get_args();

		if(array_key_exists($key, $args)){
			return $args[$key];
		}

		return $key == 'args' ? $args : null;
	}

	public function __set($key, $value){
		$this->filter_args();

		$this->args[$key]	= $value;
	}

	public function __isset($key){
		if(array_key_exists($key, $this->get_args())){
			return true;
		}

		return $this->$key !== null;
	}

	public function __unset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		$args	= $this->get_args();

		return $args[$key] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->filter_args();

		if(is_null($key)){
			$this->args[]		= $value;
		}else{
			$this->args[$key]	= $value;
		}
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return array_key_exists($key, $this->get_args());
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_args());
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize(){
		return $this->get_args();
	}

	public function invoke(...$args){
		$invoke	= $this->invoke;

		return $invoke ? $this->call_dynamic_method($invoke, ...$args) : null;
	}

	protected function filter_args(){
		if(!$this->args && !is_array($this->args)){
			$this->args = [];
		}

		return $this->args;
	}

	public function get_args(){
		return $this->filter_args();
	}

	public function set_args($args){
		$this->args	= $args;

		return $this;
	}

	public function update_args($args, $replace=true){
		foreach($args as $key => $value){
			if($replace || !isset($this->$key)){
				$this->$key	= $value;
			}
		}

		return $this;
	}

	public function get_arg($key, $default=null){
		return array_get($this->get_args(), $key, $default);
	}

	public function update_arg($key, $value=null){
		$this->filter_args();

		array_set($this->args, $key, $value);

		return $this;
	}

	public function delete_arg($key){
		$this->args	= array_except($this->get_args(), $key);

		return $this;
	}

	public function push_arg($key, ...$values){
		$value	= wp_parse_list($this->$key??'');
		$values	= array_filter($values, 'is_exists');

		if($values){
			array_push($value, ...$values);

			$this->$key	= $value;
		}

		return $this;
	}

	public function pull($key, $default=null){
		if(isset($this->$key)){
			$value	= $this->$key;

			unset($this->$key);

			return $value;
		}

		return $default;
	}

	public function pulls($keys){
		$data	= [];

		foreach($keys as $key){
			if(isset($this->$key)){
				$data[$key]	= $this->pull($key);
			}
		}

		return $data;
	}

	public function to_array(){
		return $this->get_args();
	}

	public function get_archives(){
		return $this->_archives;
	}

	public function archive(){
		array_push($this->_archives, $this->get_args());

		return $this;
	}

	public function restore(){
		if($this->_archives){
			$this->args	= array_pop($this->_archives);
		}

		return $this;
	}

	public function sandbox($callback, ...$args){
		$this->archive();

		$result	= call_user_func_array($callback, $args);

		$this->restore();

		return $result;
	}

	protected function sanitize_value($value){
		if($this->sanitize_callback && is_callable($this->sanitize_callback)){
			return call_user_func($this->sanitize_callback, $value);
		}

		return $value;
	}

	protected function validate_value($value){
		if($this->validate_callback && is_callable($this->validate_callback)){
			return call_user_func($this->validate_callback, $value);
		}

		return true;
	}

	protected function error($errcode, $errmsg){
		return new WP_Error($errcode, $errmsg);
	}

	public function filter_parameter_default($default, $name){
		return $this->defaults[$name] ?? $default;
	}
}

class WPJAM_Singleton extends WPJAM_Args{
	protected function __construct(){}

	public static function get_instance(){
		$name	= self::get_called_name();

		return $GLOBALS[$name] = ($GLOBALS[$name] ?? new static());
	}
}

class WPJAM_Register extends WPJAM_Args{
	use WPJAM_Items_Trait;

	protected $name;
	protected $_group;
	protected $_filtered	= false;

	public function __construct($name, $args=[], $group=''){
		$this->name		= $name;
		$this->args		= $args;
		$this->_group	= self::parse_group($group, 'name');

		if($this->is_active() || !empty($args['active'])){
			$this->args	= $this->preprocess_args($args);
		}

		$this->args	= array_merge($this->args, ['name'=>$name]);
	}

	protected function preprocess_args($args){
		$model_config	= static::get_config('model');
		$model_config	= $model_config ?? true;

		$model	= $model_config ? array_get($args, 'model') : null;
		$group	= self::parse_group($this->_group);
		$hooks	= array_pull($args, 'hooks');
		$init	= array_pull($args, 'init');

		if($model || $hooks || $init){
			$file	= array_pull($args, 'file');

			if($file && is_file($file)){
				include_once $file;
			}
		}

		if($model && is_subclass_of($model, 'WPJAM_Register')){
			$model_class	= is_object($model) ? get_class($model) : $model;
			trigger_error('「'.$model_class.'」是 WPJAM_Register 子类');
		}

		if($model){
			if($model_config === 'object'){
				if(!is_object($model)){
					if(class_exists($model)){
						$model = $args['model']	= new $model(array_merge($args, ['object'=>$this]));
					}else{
						trigger_error('model 无效');
					}
				}	
			}else{
				$group->add_model($model, $this);
			}

			if($hooks === true || is_null($hooks)){
				if(method_exists($model, 'add_hooks')){
					$hooks	= [$model, 'add_hooks'];
				}
			}

			if($init === true || (is_null($init) && static::get_config('init'))){
				if(method_exists($model, 'init')){
					$init	= [$model, 'init'];
				}
			}
		}

		if($hooks && $hooks !== true){
			wpjam_hooks($hooks);
		}

		if($init && $init !== true){
			wpjam_load('init', $init);
		}

		if(!$group->hooked()){
			if(static::get_config('loaded')){
				add_action('wp_loaded', [get_called_class(), 'loaded']);
			}

			if(static::get_config('register_json')){
				add_action('wpjam_api', [get_called_class(), 'on_register_json']);
			}

			if(is_admin() && (static::get_config('menu_page') || static::get_config('admin_load'))){
				add_action('wpjam_admin_init', [get_called_class(), 'on_admin_init']);
			}
		}

		return $args;
	}

	protected function filter_args(){
		if(!$this->_filtered){
			$this->_filtered	= true;

			$class		= self::get_called_name();
			$filter		= $class == 'wpjam_register' ? 'wpjam_'.$this->_group.'_args' : $class.'_args';
			$this->args	= apply_filters($filter, $this->args, $this->name);
		}

		return $this->args;
	}

	public function get_arg($key, $default=null, $do_callback=true){
		$value	= parent::get_arg($key);

		if(is_null($value) && $this->model && $key && is_string($key) && !str_contains($key, '.')){
			$value	= $this->parse_method('get_'.$key, 'model');
		}

		if($do_callback && $value && is_callable($value)){
			return call_user_func($this->bind_if_closure($value), $this->name);
		}else{
			return $value ?? $default;
		}
	}

	protected function parse_method($method, $type=null){
		if($type == 'model'){
			if($this->model && method_exists($this->model, $method)){
				return [$this->model, $method];
			}
		}elseif($type == 'property'){
			if($this->$method && is_callable($this->$method)){
				return $this->bind_if_closure($this->$method);
			}
		}else{
			foreach(['model', 'property'] as $type){
				$called = $this->parse_method($method, $type);

				if($called){
					return $called;
				}
			}
		}
	}

	protected function call_method($method, ...$args){
		$called	= $this->parse_method($method);

		if($called){
			return call_user_func_array($called, $args);
		}

		if(str_starts_with($method, 'filter_')){
			return $args[0] ?? null;
		}
	}

	public function get_parent(){
		return $this->sub_name ? self::get($this->name) : null;
	}

	public function get_sub($name){
		return $this->get_item($name, 'subs');
	}

	public function get_subs(){
		return $this->get_items('subs');
	}

	public function register_sub($name, $args){
		$args	= array_merge($args, ['sub_name'=>$name]);
		$sub	= new static($this->name, $args);

		$this->add_item($name, $sub, 'subs');

		return self::register($this->name.':'.$name, $sub);
	}

	public function unregister_sub($name){
		$this->delete_item($name, 'subs');

		return self::unregister($this->name.':'.$name);
	}

	public function is_active(){
		return true;
	}

	protected static function get_config($key){
		return null;
	}

	protected static function validate_name($name){
		$prefix	= self::class.'的注册 name';

		if(empty($name)){
			trigger_error($prefix.' 为空');
			return;
		}elseif(is_numeric($name)){
			trigger_error($prefix.'「'.$name.'」'.'为纯数字');
			return;
		}elseif(!is_string($name)){
			trigger_error($prefix.'「'.var_export($name, true).'」不为字符串');
			return;
		}

		return true;
	}

	protected static function sanitize_name($name, $args){
		return $name;
	}

	protected static function parse_group($group='', $output=''){
		$group	= $group ? strtolower($group) : self::get_called_name();

		return $output == 'name' ? $group : WPJAM_Register_Group::get_instance($group);
	}

	public static function register_by_group($group, $name, $args=[]){
		$group	= self::parse_group($group);

		if(is_object($name)){
			$args	= $name;
			$name	= $args->name ?? null;
		}elseif(is_array($name)){
			[$args, $name]	= [$name, $args];

			$name	= array_pull($args, 'name') ?: ($name ?: '__'.count($group->get_objects()));
		}

		if(self::validate_name($name)){
			if(is_object($args)){
				$object	= $args;
			}else{
				if(!empty($args['admin']) && !is_admin()){
					return;
				}

				$object	= new static($name, $args, $group->name);
				$name	= static::sanitize_name($name, $args);
			}

			$group->add_object($name, $object, static::get_config('orderby'), static::get_config('order'));

			if(method_exists($object, 'registered')){
				$object->registered();
			}

			return $object;
		}
	}

	public static function unregister_by_group($group, $name){
		return (self::parse_group($group))->remove_object($name);
	}

	public static function get_by_group($group, $args=[], $operator='AND'){
		return (self::parse_group($group))->get_objects($args, $operator);
	}

	public static function register($name, $args=[]){
		return self::register_by_group(null, $name, $args);
	}

	public static function re_register($name, $args, $merge=true){
		self::unregister($name);

		return self::register($name, $args);
	}

	public static function register_default($name=''){
		$defaults	= static::get_config('defaults');

		if($defaults){
			if($name){
				if(isset($defaults[$name])){
					return self::register($name, $defaults[$name]);
				}
			}else{
				foreach($defaults as $name => &$args){
					$args	= self::get($name, $args) ?: self::register($name, $args);
				}

				return $defaults;
			}
		}
	}

	public static function unregister($name){
		self::unregister_by_group(null, $name);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		self::register_default();

		$objects	= static::get_by_group(null, $args, $operator);

		if(in_array($output, ['args', 'settings'])){
			trigger_error('123');
			return array_map(function($object){ return $object->to_array(); }, $objects);
		}else{
			return $output == 'names' ? array_keys($objects) : $objects;
		}
	}

	public static function get_by(...$args){
		$args	= $args ? (is_array($args[0]) ? $args[0] : [$args[0] => $args[1]]) : [];

		return self::get_registereds($args);
	}

	public static function get_by_model($model, $top=''){
		return (self::parse_group())->get_by_model($model, $top);
	}

	public static function get_setting_fields($args=[]){
		if(static::get_config('single')){
			$args	= wp_parse_args($args, [
				'name'				=> wpjam_remove_prefix(self::get_called_name(), 'wpjam_'),
				'title'				=> '',
				'title_field'		=> 'title',
				'show_option_none'	=> __('&mdash; Select &mdash;'),
				'option_none_value'	=> ''
			]);

			$options	= $args['show_option_none'] ? [$args['option_none_value'] => $args['show_option_none']] : [];
			$fields		= [$args['name'] => ['title'=>$args['title'], 'type'=>'select', 'options'=>&$options]];
		}else{
			$fields		= [];
		}

		foreach(self::get_registereds() as $name => $object){
			if(static::get_config('single')){
				$options[$name]	= $object->{$args['title_field']};

				foreach(($object->get_arg('fields') ?: []) as $key => $field){
					$fields[$key]	= array_merge($field, ['show_if'=>['key'=>$args['name'], 'value'=>$name]]);
				}
			}else{
				if(is_null($object->active)){
					$fields[$name]	= wp_parse_args(($object->field ?: []), ['type'=>'checkbox', 'description'=>$object->title]);
				}
			}
		}

		return $fields;
	}

	public static function get($name){
		if($name){
			$objects	= self::get_by_group(null);

			return $objects[$name] ?? self::register_default($name);
		}
	}

	public static function exists($name){
		return self::get($name) ? true : false;
	}

	public static function get_active($key=null){
		$return	= [];

		foreach(self::get_registereds() as $name => $object){
			$active	= $object->active ?? $object->is_active();
			$value	= $active ? ($key ? $object->get_arg($key) : $object) : null;

			if(!is_null($value)){
				$return[$name]	= $value;
			}
		}

		return $return;
	}

	public static function call_active($method, ...$args){
		if(str_starts_with($method, 'filter_')){
			$type	= 'filter';
		}elseif(str_starts_with($method, 'get_')){
			$return	= [];
			$type	= 'get';
		}else{
			$type	= '';
		}

		foreach(self::get_active() as $object){
			$result	= $object->call_method($method, ...$args);	// 不能调用对象本身的方法，会死循环

			if(is_wp_error($result)){
				return $result;
			}

			if($type == 'filter'){
				$args[0]	= $result;
			}elseif($type == 'get'){
				if($result && is_array($result)){
					$return	= array_merge($return, $result);
				}
			}
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return;
		}
	}

	public static function loaded(){
		return static::call_active('loaded');
	}

	public static function on_admin_init(){
		foreach(['menu_page', 'admin_load'] as $key){
			if(static::get_config($key)){
				$active	= static::get_active($key);

				array_walk($active, 'wpjam_add_'.$key);
			}
		}
	}

	public static function on_register_json($json){
		return static::call_active('register_json', $json);
	}

	protected static function get_model($args){	// 兼容
		$file	= array_pull($args, 'file');

		if($file && is_file($file)){
			include_once $file;
		}

		return $args['model'] ?? null;
	}
}

class WPJAM_Register_Group extends WPJAM_Args{
	use WPJAM_Items_Trait;

	public function hooked(){
		$prev	= $this->hooked;

		if(!$this->hooked){
			$this->hooked	= true;
		}

		return $prev;
	}

	protected function parse_model($model){
		return ($model && is_string($model)) ? strtolower($model) : null;
	}

	public function get_objects($args=[], $operator='AND'){
		$objects	= $this->get_items();

		if($args){
			$matched	= [];
			$names		= wp_is_numeric_array($args) ? $args : [];
	
			foreach($objects as $name => $object){
				if(($names && in_array($name, $names)) || (!$names && wpjam_match($object, $args, $operator))){
					$matched[$name]	= $object;
				}
			}

			return $names ? ($matched + array_fill_keys($names, null)) : $matched;
		}

		return $objects;
	}

	public function add_object($name, $object, $orderby, $order){
		if($this->get_item($name)){
			trigger_error($this->name.'「'.$name.'」已经注册。');
		}

		if($orderby){
			$objects	= $this->get_objects();
			$orderby	= $orderby === true ? 'order' : $orderby;
			$order		= $order ?: 'DESC';
			$current	= $object->$orderby ?? 10;
			$sorted		= [];

			foreach($objects as $_name => $_object){
				if(!isset($sorted[$name])){
					$value	= $current - ($_object->$orderby ?? 10);
					$value	= strcasecmp($order, 'DESC') === 0 ? $value : (0 - $value);

					if($value > 0){
						$sorted[$name]	= $object;
					}
				}

				$sorted[$_name]	= $_object;
			}

			$sorted[$name]	= $object;

			$this->update_items($sorted);
		}else{
			$this->add_item($name, $object);
		}
	}

	public function remove_object($name){
		$object	= $this->get_item($name);

		if($object){
			$model	= $this->parse_model($object->model);

			if($model){
				$this->delete_item($model, 'models');
			}

			$this->delete_item($name);
		}
	}

	public function add_model($model, $object){
		$model	= $this->parse_model($model);

		if($model){
			// if($this->get_item($model, 'models')){
			// 	trigger_error($model.'已经被使用');
			// }

			$this->add_item($model, $object, 'models');
		}
	}

	public function get_by_model($model, $top=''){
		while($model && strcasecmp($model, $top) !== 0){
			$model	= strtolower($model);
			$object	= $this->get_item($model, 'models');

			if($object){
				return $object;
			}

			$model	= strtolower(get_parent_class($model));
		}
	}

	protected static $_groups	= [];

	public static function get_instance($name){
		return self::$_groups[$name]	= self::$_groups[$name] ?? new self(['name'=>$name]);
	}
}

class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(str_ends_with($method, '_option')){
			$method	= wpjam_remove_postfix($method, '_option');	// get_option register_option unregister_option
			$name	= array_shift($args);

			if($method == 'register'){
				$args	= array_merge(array_shift($args), ['meta_type'=>$this->name]);

				if($this->name == 'post'){
					$args	= wp_parse_args($args, ['fields'=>[], 'priority'=>'default']);

					if(!isset($args['post_type']) && isset($args['post_types'])){
						$args['post_type']	= array_pull($args, 'post_types') ?: null;
					}
				}elseif($this->name == 'term'){
					if(!isset($args['taxonomy']) && isset($args['taxonomies'])){
						$args['taxonomy']	= array_pull($args, 'taxonomies') ?: null;
					}

					if(!isset($args['fields'])){
						$args['fields']		= [$name => array_except($args, 'taxonomy')];
						$args['from_field']	= true;
					}
				}

				$object	= new WPJAM_Meta_Option($name, $args);
				$args	= [$object];
			}

			return call_user_func(['WPJAM_Meta_Option', $method], $this->name.':'.$name, ...$args); 
		}elseif(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
			array_unshift($args, $this->name);

			$callback	= str_replace('data', 'metadata', $method);
		}elseif(str_ends_with($method, '_by_mid')){
			array_unshift($args, $this->name);

			$callback	= str_replace('_by_mid', '_metadata_by_mid', $method);
		}elseif(str_ends_with($method, '_meta')){
			$callback	= [$this, str_replace('_meta', '_data', $method)];
		}elseif(str_contains($method, '_meta')){
			$callback	= [$this, str_replace('_meta', '', $method)];
		}else{
			$callback	= null;
		}

		if($callback){
			return call_user_func_array($callback, $args);
		}else{
			trigger_error('无效的方法'.$method);
		}
	}

	protected function preprocess_args($args){
		$table_name	= $args['table_name'] ?? $this->name.'meta';
		$wpdb		= $GLOBALS['wpdb'];

		if(!isset($wpdb->$table_name)){
			$wpdb->$table_name = $args['table'] ?? $wpdb->prefix.$this->name.'meta';
		}

		return parent::preprocess_args($args);
	}

	public function register_lazyloader(){
		return wpjam_register_lazyloader($this->name.'_meta', [
			'filter'	=> 'get_'.$this->name.'_metadata',
			'callback'	=> [$this, 'update_cache']
		]);
	}

	public function lazyload_data($ids){
		wpjam_lazyload($this->name.'_meta', $ids);
	}

	public function get_options($args=[]){
		$objects	= WPJAM_Meta_Option::get_by(array_merge($args, ['meta_type'=>$this->name]));

		return array_combine(array_column($objects, 'name'), array_values($objects));
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object'){
		if($name == 'object'){
			return $this->name.'_id';
		}elseif($name == 'id'){
			return 'user' == $this->name ? 'umeta_id' : 'meta_id';
		}
	}

	protected function parse_value($value){
		if(wp_is_numeric_array($value)){
			return maybe_unserialize($value[0]);
		}else{
			return array_merge($value, ['meta_value'=>maybe_unserialize($value['meta_value'])]);
		}
	}

	public function get_data_with_default($id, ...$args){
		if(!$args){
			return $this->get_data($id);
		}

		if(is_array($args[0])){
			if($id && $args[0]){
				$defaults	= $this->parse_defaults($args[0]);

				foreach($defaults as $key => &$value){
					$value	= $this->get_data_with_default($id, $key, $default);
				}

				return $defaults;
			}

			return [];
		}else{
			if($id && $args[0]){
				if($args[0] == 'meta_input'){
					return array_map([$this, 'parse_value'], $this->get_data($id));
				}

				if($this->data_exists($id, $args[0])){
					return $this->get_data($id, $args[0], true);
				}
			}

			return $args[1] ?? null;
		}
	}

	public function get_by_key(...$args){
		global $wpdb;

		if(empty($args)){
			return [];
		}

		if(is_array($args[0])){
			$key	= $args[0]['meta_key'] ?? ($args[0]['key'] ?? '');
			$value	= $args[0]['meta_value'] ?? ($args[0]['value'] ?? '');
		}else{
			$key	= $args[0];
			$value	= $args[1] ?? null;
		}

		$where	= [];

		if($key){
			$where[]	= $wpdb->prepare('meta_key=%s', $key);
		}

		if(!is_null($value)){
			$where[]	= $wpdb->prepare('meta_value=%s', maybe_serialize($value));
		}

		if(!$where){
			return [];
		}

		$where	= implode(' AND ', $where);
		$table	= $this->get_table();
		$data	= $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A) ?: [];

		return array_map([$this, 'parse_value'], $data);
	}

	public function update_data_with_default($id, ...$args){
		if(is_array($args[0])){
			$data	= $args[0];

			if(wpjam_is_assoc_array($data)){
				if((isset($args[1]) && is_array($args[1]))){
					$defaults	= $this->parse_defaults($args[1]);
				}else{
					$defaults	= array_fill_keys(array_keys($data), null);
				}

				if(isset($data['meta_input']) && wpjam_is_assoc_array($data['meta_input'])){
					$this->update_data_with_default($id, array_pull($data, 'meta_input'), array_pull($defaults, 'meta_input'));
				}

				foreach($data as $key => $value){
					$this->update_data_with_default($id, $key, $value, array_pull($defaults, $key));
				}
			}

			return true;
		}else{
			$key		= $args[0];
			$value		= $args[1];
			$default	= $args[2] ?? null;

			if(is_array($value)){
				if($value && (!is_array($default) || array_diff_assoc($default, $value))){
					return $this->update_data($id, $key, $value);
				}
			}else{
				if(isset($value) && ((is_null($default) && $value) || (!is_null($default) && $value != $default))){
					return $this->update_data($id, $key, $value);
				}
			}

			return $this->delete_data($id, $key);
		}
	}

	public function cleanup(){
		if($this->object_key){
			$object_key		= $this->object_key;
			$object_table	= $GLOBALS['wpdb']->{$this->name.'s'};
		}else{
			$object_model	= $this->object_model;

			if($object_model && is_callable([$object_model, 'get_table'])){
				$object_table	= call_user_func([$object_model, 'get_table']);
				$object_key		= call_user_func([$object_model, 'get_primary_key']);
			}else{
				$object_table	= '';
				$object_key		= '';
			}
		}

		$this->delete_orphan_data($object_table, $object_key);
	}

	public function delete_orphan_data($object_table=null, $object_key=null){
		if($object_table && $object_key){
			$wpdb	= $GLOBALS['wpdb'];
			$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$object_table." t ON t.".$object_key." = m.".$this->get_column('object')." WHERE t.".$object_key." IS NULL") ?: [];

			array_walk($mids, [$this, 'delete_by_mid']);
		}
	}

	public function delete_empty_data(){
		$wpdb	= $GLOBALS['wpdb'];
		$mids	= $wpdb->get_col("SELECT ".$this->get_column('id')." FROM ".$this->get_table()." WHERE meta_value = ''") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function delete_by_key($key, $value=''){
		return delete_metadata($this->name, null, $key, $value, true);
	}

	public function delete_by_id($id){
		$wpdb	= $GLOBALS['wpdb'];
		$table	= $this->get_table();
		$column	= $this->get_column();
		$mids	= $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$table} WHERE {$column} = %d ", $id)) ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function update_cache($ids){
		if($ids){
			update_meta_cache($this->name, $ids);
		}
	}

	public function create_table(){
		$table	= $this->get_table();

		if($GLOBALS['wpdb']->get_var("show tables like '{$table}'") != $table){
			$column	= $this->name.'_id';

			$GLOBALS['wpdb']->query("CREATE TABLE {$table} (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				{$column} bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY  (meta_id),
				KEY {$column} ({$column}),
				KEY meta_key (meta_key(191))
			)");
		}
	}

	public static function parse_defaults($defaults){
		$return	= [];

		foreach($defaults as $key => $default){
			if(is_numeric($key)){
				if(is_numeric($default)){
					continue;
				}

				$key		= $default;
				$default	= null;
			}

			$return[$key]	= $default;
		}

		return $return;
	}

	protected static function sanitize_name($name, $args){
		return sanitize_key($name);
	}

	public static function get_config($key){
		if($key == 'defaults'){
			$defaults	= [
				'post'	=> ['order'=>50,	'object_model'=>'WPJAM_Post',	'object_column'=>'title',	'object_key'=>'ID'],
				'term'	=> ['order'=>40,	'object_model'=>'WPJAM_Term',	'object_column'=>'name',	'object_key'=>'term_id'],
				'user'	=> ['order'=>30,	'object_model'=>'WPJAM_User',	'object_column'=>'display_name','object_key'=>'ID'],
			];

			if(is_multisite()){
				$defaults['blog']	= ['order'=>5,	'object_key'=>'blog_id'];
				$defaults['site']	= ['order'=>5];
			}

			return $defaults;
		}elseif($key == 'orderby'){
			return true;
		}
	}
}

class WPJAM_Meta_Option extends WPJAM_Register{
	public function __call($method, $args){
		if(str_ends_with($method, '_by_fields')){
			$id		= array_shift($args);
			$fields	= $this->get_fields($id);
			$object	= wpjam_fields($fields);
			$method	= wpjam_remove_postfix($method, '_by_fields');

			return call_user_func_array([$object, $method], $args);
		}
	}

	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'list_table'){
			if(is_null($value) && did_action('current_screen') && !empty($GLOBALS['plugin_page'])){
				return true;
			}
		}elseif($key == 'callback'){
			if(!$value){
				return $this->update_callback;
			}
		}

		return $value;
	}

	public function get_fields($id=null){
		$fields	= $this->fields;

		return is_callable($fields) ? call_user_func($fields, $id, $this->name) : $fields;
	}

	public function parse_list_table_args(){
		return wp_parse_args($this->get_args(), [
			'page_title'	=> '设置'.$this->title,
			'submit_text'	=> '设置',
			'meta_type'		=> $this->name,
			'fields'		=> [$this, 'get_fields']
		]);
	}

	public function prepare($id=null){
		if($this->callback){
			return [];
		}

		return $this->prepare_by_fields($id, array_merge($this->get_args(), ['id'=>$id]));
	}

	public function validate($id=null, $data=null){
		return $this->validate_by_fields($id, $data);
	}

	public function render($id, $args=[]){
		echo $this->render_by_fields($id, array_merge($this->get_args(), ['id'=>$id], $args));
	}

	public function callback($id, $data=null){
		$fields	= $this->get_fields($id);
		$object	= wpjam_fields($fields);
		$data	= $object->validate($data);

		if(is_wp_error($data)){
			return $data;
		}elseif(!$data){
			return true;
		}

		if($this->callback){
			$result	= is_callable($this->callback) ? call_user_func($this->callback, $id, $data, $fields) : false;

			return $result === false ? new WP_Error('invalid_callback') : $result;
		}else{
			return wpjam_update_metadata($this->meta_type, $id, $data, $object->get_defaults());
		}
	}

	public static function create($name, $args){
		$meta_type	= array_get($args, 'meta_type');

		if($meta_type){
			$object	= new self($name, $args);

			return self::register($meta_type.':'.$name, $object);
		}
	}

	public static function get_by(...$args){
		$args		= is_array($args[0]) ? $args[0] : [$args[0] => $args[1]];
		$list_table	= array_pull($args, 'list_table');
		$meta_type	= array_get($args, 'meta_type');

		if(!$meta_type){
			return [];
		}

		if(isset($list_table)){
			$args['title']		= true;
			$args['list_table']	= $list_table ? true : ['compare'=>'!=', 'strict'=>true, 'value'=>'only'];
		}

		if($meta_type == 'post'){
			$post_type	= array_pull($args, 'post_type');

			if($post_type){
				$object	= wpjam_get_post_type_object($post_type);

				if($object){
					$object->register_option($list_table);
				}

				$args['post_type']	= ['value'=>$post_type, 'if_null'=>true, 'callable'=>true];
			}
		}elseif($meta_type == 'term'){
			$taxonomy	= array_pull($args, 'taxonomy');
			$action		= array_pull($args, 'action');

			if($taxonomy){
				$object	= wpjam_get_taxonomy_object($taxonomy);

				if($object){
					$object->register_option($list_table);
				}

				$args['taxonomy']	= ['value'=>$taxonomy, 'if_null'=>true, 'callable'=>true];
			}

			if($action){
				$args['action']		= ['value'=>$action, 'if_null'=>true, 'callable'=>true];
			}
		}

		return static::get_registereds($args);
	}

	public static function get_config($key){
		if($key == 'orderby'){
			return 'order';
		}
	}
}

class WPJAM_Lazyloader extends WPJAM_Register{
	private $pending	= [];

	public function callback($check){
		do_action('wpjam_lazyload_'.$this->name, $this);

		if($this->pending){
			if($this->accepted_args > 1){
				foreach($this->pending as $pending){
					call_user_func($this->callback, $pending['ids'], ...$pending['args']);
				}
			}else{
				call_user_func($this->callback, $this->pending);
			}

			$this->pending	= [];
		}

		$this->remove_filter();

		return $check;
	}

	public function queue_objects($object_ids, ...$args){
		if(!$object_ids){
			return;
		}

		if($this->accepted_args > 1){
			if(count($args) >= ($this->accepted_args-1)){
				$args	= array_slice($args, 0, $this->accepted_args-1);
				$key	= implode(':', $args);

				if(!isset($this->pending[$key])){
					$this->pending[$key]	= ['ids'=>[], 'args'=>$args];
				}

				$this->pending[$key]['ids']	= array_merge($this->pending[$key]['ids'], $object_ids);
				$this->pending[$key]['ids']	= array_unique($this->pending[$key]['ids']);
			}
		}else{
			$this->pending	= array_merge($this->pending, $object_ids);
			$this->pending	= array_unique($this->pending);
		}

		$this->add_filter();
	}

	public function add_filter(){
		if(!$this->filter_added){
			add_filter($this->filter, [$this, 'callback']);

			$this->filter_added	= true;
		}
	}

	public function remove_filter(){
		remove_filter($this->filter, [$this, 'callback']);

		unset($this->filter_added);
	}
}

class WPJAM_AJAX extends WPJAM_Register{
	public function registered(){
		add_action('wp_ajax_'.$this->name, [$this, 'callback']);

		if($this->nopriv){
			add_action('wp_ajax_nopriv_'.$this->name, [$this, 'callback']);
		}
	}

	public function callback(){
		if(!$this->callback || !is_callable($this->callback)){
			wp_die('0', 400);
		}

		$data	= wpjam_get_data_parameter();

		if($this->verify !== false){
			if(!check_ajax_referer($this->get_nonce_action($data, 'verify'), false, false)){
				wpjam_send_error_json('invalid_nonce');
			}
		}

		$result	= wpjam_call($this->callback, $data, $this->name);
		$result	= $result === true ? [] : $result;  

		wpjam_send_json($result);
	}

	public function get_attr($data=[], $return=null){
		$attr	= ['action'=>$this->name, 'data'=>$data];

		if($this->verify !== false){
			$attr['nonce']	= wp_create_nonce($this->get_nonce_action($data, 'create'));
		}

		return $return ? $attr : wpjam_attr($attr, 'data');
	}

	protected function get_nonce_action($data, $type='create'){
		$nonce_action	= $this->name;

		if($this->nonce_keys){
			$nonce_data		= wp_array_slice_assoc($data, $this->nonce_keys);
			$nonce_data		= array_filter($nonce_data);

			if($nonce_data){
				$nonce_action	.= ':'.implode(':', $nonce_data);
			}
		}

		return $nonce_action;
	}

	public static function on_enqueue_scripts(){
		wp_register_style('remixicon', 'https://cdn.staticfile.org/remixicon/3.4.0/remixicon.min.css');
		wp_register_script('wpjam-ajax', wpjam_url(dirname(__DIR__).'/static/ajax.js'), ['jquery']);
		wp_add_inline_script('wpjam-ajax', 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";', 'before');
	}
}

class WPJAM_Verification_Code extends WPJAM_Register{
	public function is_over($key){
		if($this->failed_times && (int)$this->cache->get($key.':failed_times') > $this->failed_times){
			return new WP_Error('quota_exceeded', ['尝试的失败次数', '请15分钟后重试。']);
		}

		return false;
	}

	public function generate($key){
		if($over = $this->is_over($key)){
			return $over;
		}

		if($this->interval && $this->cache->get($key.':time') !== false){
			return new WP_Error('error', '验证码'.((int)($this->interval/60)).'分钟前已发送了。');
		}

		$code = rand(100000, 999999);

		$this->cache->set($key.':code', $code, $this->cache_time);

		if($this->interval){
			$this->cache->set($key.':time', time(), MINUTE_IN_SECONDS);
		}

		return $code;
	}

	public function verify($key, $code){
		if($over = $this->is_over($key)){
			return $over;
		}

		$current	= $this->cache->get($key.':code');

		if(!$code || $current === false){
			return new WP_Error('invalid_code');
		}

		if($code != $current){
			if($this->failed_times){
				$failed_times	= $this->cache->get($key.':failed_times') ?: 0;
				$failed_times	= $failed_times + 1;

				$this->cache->set($key.':failed_times', $failed_times, $this->cache_time/2);
			}

			return new WP_Error('invalid_code');
		}

		return true;
	}

	protected function preprocess_args($args){
		return wp_parse_args($args, [
			'failed_times'	=> 5,
			'cache_time'	=> MINUTE_IN_SECONDS*30,
			'interval'		=> MINUTE_IN_SECONDS,
			'cache'			=> wpjam_cache('verification_code', ['global'=>true, 'prefix'=>$this->name]),
		]);
	}

	public static function get_instance($name, $args=[]){
		return self::get($name) ?: self::register($name, $args);
	}
}

class WPJAM_Verify_TXT extends WPJAM_Register{
	public function get_fields(){
		return [
			'name'	=>['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$this->get_data('name'),	'class'=>'all-options'],
			'value'	=>['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$this->get_data('value')]
		];
	}

	public function get_data($key=''){
		$data	= wpjam_get_setting('wpjam_verify_txts', $this->name) ?: [];

		return $key ? ($data[$key] ?? '') : $data;
	}

	public function set_data($data){
		return wpjam_update_setting('wpjam_verify_txts', $this->name, $data) || true;
	}

	public static function __callStatic($method, $args){	// 放弃
		$name	= $args[0];

		if($object = self::get($name)){
			if(in_array($method, ['get_name', 'get_value'])){
				return $object->get_data(str_replace('get_', '', $method));
			}elseif($method == 'set' || $method == 'set_value'){
				return $object->set_data(['name'=>$args[1], 'value'=>$args[2]]);
			}
		}
	}

	public static function filter_root_rewrite_rules($root_rewrite){
		if(empty($GLOBALS['wp_rewrite']->root)){
			$home_path	= parse_url(home_url());

			if(empty($home_path['path']) || '/' == $home_path['path']){
				$root_rewrite	= array_merge(['([^/]+)\.txt?$'=>'index.php?module=txt&action=$matches[1]'], $root_rewrite);
			}
		}

		return $root_rewrite;
	}

	public static function get_rewrite_rule(){
		add_filter('root_rewrite_rules',	[self::class, 'filter_root_rewrite_rules']);
	}

	public static function redirect($action){
		$txts = wpjam_get_option('wpjam_verify_txts');

		if($txts){
			$name	= str_replace('.txt', '', $action).'.txt';

			foreach($txts as $txt) {
				if($txt['name'] == $name){
					header('Content-Type: text/plain');
					echo $txt['value'];

					exit;
				}
			}
		}
	}
}