<?php
trait WPJAM_Instance_Trait{
	use WPJAM_Call_Trait;

	protected static $_instances	= [];

	protected static function call_instance($action, $name=null, $instance=null){
		$group	= strtolower(get_called_class());

		if($action == 'get_all'){
			return self::$_instances[$group] ?? [];
		}elseif($action == 'get'){
			return self::$_instances[$group][$name] ?? null;
		}elseif($action == 'add'){
			self::$_instances[$group][$name]	= $instance;

			return $instance;
		}elseif($action == 'remove'){
			unset(self::$_instances[$group][$name]);
		}
	}

	protected static function get_instances(){
		return self::call_instance('get_all');
	}

	public static function instance_exists($name){
		return self::call_instance('get', $name) ?: false;
	}

	protected static function create_instance(...$args){
		return new static(...$args);
	}

	public static function add_instance($name, $instance){
		return self::call_instance('add', $name, $instance);
	}

	public static function remove_instance($name){
		self::call_instance('remove', $name);
	}

	public static function instance(...$args){
		$name	= $args ? implode(':', array_filter($args, 'is_exists')) : 'singleton';

		return self::instance_exists($name) ?: self::add_instance($name, static::create_instance(...$args));
	}
}

abstract class WPJAM_Model implements ArrayAccess, IteratorAggregate{
	use WPJAM_Instance_Trait;

	protected $_id;
	protected $_data	= []; 

	public function __construct($data=[], $id=null){
		if($id){
			$this->_id	= $id;

			if($data){
				$raw			= static::get($this->id);
				$this->_data	= array_diff_assoc($data, $raw);
			}
		}else{
			$this->_data	= $data;

			$key	= static::get_primary_key();

			if(isset($data[$key])){
				$raw	= static::get($data[$key]);

				if($raw){
					$this->_id		= $data[$key];
					$this->_data	= array_diff_assoc($data, $raw);
				}
			}
		}
	}

	public function __get($key){
		$data	= $this->get_data();

		return array_key_exists($key, $data) ? $data[$key] : $this->meta_get($key);
	}

	public function __isset($key){
		return (isset($this->_data[$key]) 
			|| array_key_exists($key, $this->get_data())
			|| $this->meta_exists($key)
		);
	}

	public function __set($key, $value){
		$this->set_data($key, $value);
	}

	public function __unset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		$data	= $this->get_data();

		return array_key_exists($key, $data);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->get_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->set_data($key, $value);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_data());
	}

	public function get_primary_id(){
		$key	= static::get_primary_key();

		return $this->get_data($key);
	}

	public function get_data($key=''){
		$data	= is_null($this->_id) ? [] : static::get($this->_id);
		$data	= array_merge($data, $this->_data);

		if($key){
			return $data[$key] ?? null;
		}

		return $data;
	}

	public function set_data($key, $value){
		if(!is_null($this->_id) && self::get_primary_key() == $key){
			trigger_error('不能修改主键的值');
		}else{
			$this->_data[$key]	= $value;
		}

		return $this;
	}

	public function unset_data($key){
		$this->_data[$key]	= null;
	}

	public function reset_data($key=''){
		if($key){
			unset($this->_data[$key]);
		}else{
			$this->_data	= [];
		}
	}

	public function to_array(){
		return $this->get_data();
	}

	public function is_deletable(){
		return true;
	}

	public function save($data=[]){
		$meta_type	= self::get_meta_type();
		$meta_input	= $meta_type ? array_pull($data, 'meta_input') : null;

		$data	= array_merge($this->_data, $data);

		if($this->_id){
			$data	= array_except($data, static::get_primary_key());
			$result	= $data ? static::update($this->_id, $data) : false;
		}else{
			$result	= static::insert($data);

			if(!is_wp_error($result)){
				$this->_id	= $result;
			}
		}

		if(!is_wp_error($result)){
			if($this->_id && $meta_input){
				$this->meta_input($meta_input);
			}

			$this->reset_data();
		}

		return $result;
	}

	public function meta_get($key){
		$meta_type	= $this->_id ? self::get_meta_type() : null;

		return $meta_type ? wpjam_get_metadata($meta_type, $this->_id, $key, null) : null;
	}

	public function meta_exists($key){
		$meta_type	= $this->_id ? self::get_meta_type() : null;

		return $meta_type ? metadata_exists($meta_type, $this->_id, $key) : false;
	}

	public function meta_input(...$args){
		if($args && $this->_id){
			$meta_type	= self::get_meta_type();

			return $meta_type ? wpjam_update_metadata($meta_type, $this->_id, ...$args) : null;
		}
	}

	public static function find($id){
		return static::get_instance($id);
	}

	public static function get_instance($id){
		$object	= self::instance_exists($id);

		if(!$object){
			$data	= $id ? static::get($id) : null;
			$object	= $data ? static::add_instance($id, new static([], $id)) : null;
		}

		return $object;
	}

	protected static function get_handler_name(){
		return strtolower(get_called_class());
	}

	public static function get_handler(){
		$handler	= wpjam_get_handler(self::get_handler_name());

		if(!$handler && property_exists(get_called_class(), 'handler')){
			return static::$handler;
		}

		return $handler;
	}

	public static function set_handler($handler){
		return wpjam_register_handler(self::get_handler_name(), $handler);
	}

	public static function get_primary_key(){
		return static::call_handler('get_primary_key');
	}

	protected static function validate_data($data, $id=0){
		return true;
	}

	protected static function sanitize_data($data, $id=0){
		return $data;
	}

	public static function insert($data){
		$result	= static::validate_data($data);

		if(is_wp_error($result)){
			return $result;
		}

		$data	= static::sanitize_data($data);

		return static::call_handler('insert', $data);
	}

	public static function update($id, $data){
		$result	= static::validate_data($data, $id);

		if(is_wp_error($result)){
			return $result;
		}

		$data	= static::sanitize_data($data, $id);

		return static::call_handler('update', $id, $data);
	}

	public static function get($id){
		return static::call_handler('get', $id);
	}

	public static function delete($id){
		$object	= self::get_instance($id);
		$result	= $object ? $object->is_deletable() : true;

		if(is_wp_error($result)){
			return $result;
		}

		return static::call_handler('delete', $id);
	}

	public static function delete_multi($ids){
		if(static::method_exists('delete_multi')){
			return static::call_handler('delete_multi', $ids);
		}

		foreach($ids as $id){
			$result	= static::call_handler('delete', $id);

			if(is_wp_error($result)){
				return $result;
			}
		}

		return true;
	}

	public static function insert_multi($datas){
		foreach($datas as &$data){
			$result	= static::validate_data($data);

			if(is_wp_error($result)){
				return $result;
			}

			$data	= static::sanitize_data($data);
		}

		if(static::method_exists('insert_multi')){
			return static::call_handler('insert_multi', $datas);
		}

		foreach($datas as $data){
			$result	= static::call_handler('insert', $data);

			if(is_wp_error($result)){
				return $result;
			}
		}

		return true;
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'dismiss'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true, 'confirm'=>true,	'bulk'=>true],
		];
	}

	protected static function method_exists($method){
		$handler	= static::get_handler();

		return $handler && method_exists($handler, $method);
	}

	// get_by($field, $value, $order='ASC')
	// get_by_ids($ids)
	// get_searchable_fields()
	// get_filterable_fields()
	// update_caches($values)
	// move($id, $data)

	// get_cache_key($key)
	// get_last_changed
	// get_cache_group
	// cache_get($key)
	// cache_set($key, $data, $cache_time=DAY_IN_SECONDS)
	// cache_add($key, $data, $cache_time=DAY_IN_SECONDS)
	// cache_delete($key)
	public static function call_handler($method, ...$args){
		if(in_array($method, ['item_callback', 'render_item', 'parse_item', 'render_date'])){
			return $args[0];
		}

		$handler	= static::get_handler();

		if(!$handler){
			return new WP_Error('undefined_handler');
		}

		if(is_a($handler, 'WPJAM_DB')){
			if($method == 'get_one_by'){
				$items	= $handler->get_by(...$args);

				return $items ? current($items) : [];
			}elseif(in_array($method, ['query_items', 'query_data'])){
				if(is_array($args[0])){
					$query	= $handler->query($args[0], 'object');

					return ['items'=>$query->items, 'total'=>$query->total];
				}else{
					return $handler->query_items(...$args);
				}
			}elseif(strtolower($method) == 'query'){
				if($args){
					return $handler->query($args[0], 'object');
				}else{
					return $handler;
				}
			}
		}

		$map	= [
			'list'		=> 'query_items',
			'get_ids'	=> 'get_by_ids',
			'get_all'	=> 'get_results'
		];

		$method	= $map[$method] ?? $method;

		if(method_exists($handler, $method) || method_exists($handler, '__call')){
			// WPJAM_DB 可能因为 cache 设置为 false
			// 不能直接调用 WPJAM_DB 的 cache_xxx 方法
			if(in_array($method, ['cache_get', 'cache_set', 'cache_add', 'cache_delete'])){
				$method	.= '_force';
			}

			return call_user_func_array([$handler, $method], $args);
		}

		return new WP_Error('undefined_method', [$method]);
	}

	public static function __callStatic($method, $args){
		return static::call_handler($method, ...$args);
	}
}

class WPJAM_DB extends WPJAM_Args{
	protected $meta_query	= null;
	protected $query_vars	= [];
	protected $where		= [];

	public function __construct($table, $args=[]){
		$this->args	= wp_parse_args($args, [
			'wpdb'				=> $GLOBALS['wpdb'],
			'table'				=> $table,
			'primary_key'		=> 'id',
			'meta_type'			=> '',
			'cache'				=> true,
			'cache_key'			=> '',
			'cache_prefix'		=> '',
			'cache_group'		=> $table,
			'cache_time'		=> DAY_IN_SECONDS,
			'group_cache_key'	=> [],
			'field_types'		=> [],
			'searchable_fields'	=> [],
			'filterable_fields'	=> []
		]);

		$this->group_cache_key	= (array)$this->group_cache_key;

		if($this->cache_key	== $this->primary_key){
			$this->cache_key	= '';
		}

		if(is_array($this->cache_group)){
			$group	= $this->cache_group[0];

			if(!empty($this->cache_group[1])){
				wp_cache_add_global_groups($group);
			}

			$this->cache_group	= $group;
		}

		$this->clear();
	}

	public function __get($key){
		if(isset($this->query_vars[$key])){
			return $this->query_vars[$key];
		}

		return parent::__get($key);
	}

	public function __call($method, $args){
		if($method == 'get_operators'){
			return [
				'not'		=> '!=',
				'lt'		=> '<',
				'lte'		=> '<=',
				'gt'		=> '>',
				'gte'		=> '>=',
				'in'		=> 'IN',
				'not_in'	=> 'NOT IN',
				'like'		=> 'LIKE',
				'not_like'	=> 'NOT LIKE',
			];
		}elseif(str_starts_with($method, 'where_')){
			$type	= wpjam_remove_prefix($method, 'where_');

			if(in_array($type, ['any', 'all'])){
				$data		= $args[0];
				$output		= $args[1] ?? 'object';
				$fragment	= '';

				if($data && is_array($data)){
					$where		= array_map(function($c, $v){ return $this->where($c, $v, 'value'); }, array_keys($data), $data);
					$type		= $type == 'any' ? 'OR' : 'AND';
					$fragment	= $this->parse_where($where, $type);
				}

				if($output != 'object'){
					return $fragment ?: '';
				}

				$type		= 'fragment';
				$args[0]	= $fragment;
			}

			if($type == 'fragment'){
				if($args[0]){
					$this->where[] = ['compare'=>'fragment', 'fragment'=>' ( '.$args[0].' ) '];
				}
			}elseif(isset($args[1])){
				$operators	= $this->get_operators();
				$compare	= $operators[$type] ?? '';

				if($compare){
					$this->where[]	= ['column'=>$args[0], 'value'=>$args[1], 'compare'=>$compare];
				}
			}

			return $this;
		}elseif(in_array($method, [
			'found_rows',
			'limit',
			'offset',
			'orderby',
			'order',
			'groupby',
			'having',
			'search',
			'order_by',
			'group_by',
		])){
			$map	= [
				'search'	=> 'search_term',
				'order_by'	=> 'orderby',
				'group_by'	=> 'groupby',
			];

			$key	= $map[$method] ?? $method;

			if($key == 'order'){
				$value	= $args[0] ?? 'DESC';
			}elseif($key == 'found_rows'){
				$value	= $args[0] ?? true;
				$value	= (bool)$value;
			}else{
				$value	= $args[0] ?? null;
			}

			if(!is_null($value)){
				if(in_array($key, ['limit', 'offset'])){
					$value	= (int)$value;
				}elseif($key == 'order'){
					$value	= (strtoupper($value) == 'ASC') ? 'ASC' : 'DESC';
				}

				$this->query_vars[$key]	= $value;
			}

			return $this;
		}elseif(in_array($method, [
			'get_col',
			'get_var',
			'get_row',
		])){
			if($method != 'get_col'){
				$this->limit(1);
			}

			$field	= $args[0] ?? '';
			$args	= [$this->get_sql($field)];

			if($method == 'get_row'){
				$args[]	= ARRAY_A;
			}

			return call_user_func_array([$this->wpdb, $method], $args);
		}elseif(in_array($method, [
			'get_table',
			'get_primary_key',
			'get_meta_type',
			'get_cache_group',
			'get_cache_prefix',
			'get_searchable_fields',
			'get_filterable_fields'
		])){
			return $this->{substr($method, 4)};
		}elseif(in_array($method, [
			'set_searchable_fields',
			'set_filterable_fields'
		])){
			$this->{substr($method, 4)}	= $args[0];
		}elseif(str_starts_with($method, 'cache_')){
			$key	= array_shift($args);

			if(!is_scalar($key)){
				trigger_error(var_export($key, true));
				return false;
			}

			if($method == 'cache_key'){
				$primary	= array_shift($args);
				$prefix		= $this->cache_prefix;

				if(!$primary && $this->cache_key){
					$key	= $this->cache_key.':'.$key;
				}

				return $prefix ? $prefix.':'.$key : $key;
			}

			if(str_ends_with($method, '_force')){
				$method		= wpjam_remove_postfix($method, '_force');
			}else{
				if(!$this->cache){
					return false;
				}
			}

			$primary	= str_ends_with($method, '_by_primary_key');

			if($primary){
				$method	= wpjam_remove_postfix($method, '_by_primary_key');
			}

			$key	= $this->cache_key($key, $primary);
			$group	= $this->cache_group;

			if(in_array($method, ['cache_get', 'cache_delete'])){
				return call_user_func('wp_'.$method, $key, $group);
			}else{
				$data	= array_shift($args);
				$time	= array_shift($args);
				$time	= $time ? (int)$time : $this->cache_time;

				return call_user_func('wp_'.$method, $key, $data, $group, $time);
			}
		}elseif(in_array($method, [
			'get_meta',
			'add_meta',
			'update_meta',
			'delete_meta',
			'delete_orphan_meta',
			'lazyload_meta',
			'delete_meta_by_key',
			'delete_meta_by_mid',
			'delete_meta_by_id',
			'update_meta_cache',
			'create_meta_table',
			'get_meta_table',
			'get_meta_column',
		])){
			$object	= wpjam_get_meta_type_object($this->meta_type);

			if($object){
				return call_user_func_array([$object, $method], $args);
			}
		}elseif(in_array($method, [
			'get_last_changed',
			'delete_last_changed',
		])){
			$key	= 'last_changed';
			$group	= $this->cache_group;

			$query_vars	= array_shift($args);

			if($query_vars && is_array($query_vars) && $this->group_cache_key){
				$query_vars	= wp_array_slice_assoc($query_vars, $this->group_cache_key);

				if($query_vars && count($query_vars) == 1){
					$group_key	= array_key_first($query_vars);
					$query_var	= current($query_vars);

					if(!is_array($query_var)){
						$key	.= ':'.$group_key.':'.$query_var;
					}
				}
			}

			if($method == 'get_last_changed'){
				$value	= wp_cache_get($key, $group);

				if(!$value){
					$value	= microtime();

					wp_cache_set($key, $value, $group);
				}

				return $value;
			}else{
				return wp_cache_delete($key, $group);
			}
		}

		return new WP_Error('undefined_method', [$method]);
	}

	public function clear(){
		$this->query_vars	= [
			'found_rows'	=> false,
			'limit'			=> 0,
			'offset'		=> 0,
			'orderby'		=> null,
			'order'			=> null,
			'groupby'		=> null,
			'having'		=> null,
			'search_term'	=> null,
		];

		$this->meta_query	= null;
		$this->where		= [];
	}

	public function find_by($field, $value, $order='ASC', $method='get_results'){
		$value	= $this->format($value, $field);
		$sql	= "SELECT * FROM `{$this->table}` WHERE `{$field}` = {$value}";
		$sql	.= $order ? " ORDER BY `{$this->primary_key}` {$order}" : '';

		return call_user_func([$this->wpdb, $method], $sql, ARRAY_A);
	}

	public function find_one_by($field, $value, $order=''){
		return $this->find_by($field, $value, $order, 'get_row');
	}

	public function find_one($id){
		return $this->find_one_by($this->primary_key, $id);
	}

	public function get($id){
		$result	= $this->cache ? $this->cache_get_by_primary_key($id) : false;

		if($result === false){
			$result	= $this->find_one($id);

			if($this->cache){
				$time	= $result ? $this->cache_time : MINUTE_IN_SECONDS;

				$this->cache_set_by_primary_key($id, $result, $time);
			}
		}

		return $result;
	}

	public function get_by($field, $value, $order='ASC'){
		if($this->cache && $field == $this->primary_key){
			return $this->get($value);
		}

		$cache	= $this->cache && $field == $this->cache_key;
		$result	= $cache ? $this->cache_get($value) : false;

		if($result === false){
			$result	= $this->find_by($field, $value, $order);

			if($cache){
				$time	= $result ? $this->cache_time : MINUTE_IN_SECONDS;

				$this->cache_set($value, $result, $time);
			}
		}

		return $result;
	}

	public function update_caches($keys, $primary=false){
		$keys	= wp_parse_list($keys);
		$keys	= array_filter($keys);
		$keys	= array_unique($keys);
		$data	= [];

		if(!$keys){
			return $data;
		}

		$primary	= $primary || !$this->cache_key;

		if($this->cache){
			$cache_keys		= $this->map($keys, 'cache_key', $primary);
			$cache_map		= array_combine($cache_keys, $keys);
			$cache_values	= wp_cache_get_multiple($cache_keys, $this->cache_group);

			foreach($cache_values as $cache_key => $cache_value){
				if($cache_value !== false){
					$key	= $cache_map[$cache_key];

					$data[$key]	= $cache_value;
				}
			}
		}

		if(count($data) != count($keys)){
			$data	= [];
			$field	= $primary ? $this->primary_key : $this->cache_key;
			$result = $this->wpdb->get_results($this->where_in($field, $keys)->get_sql(), ARRAY_A);

			if($result){
				if($primary){
					$data	= array_combine(array_column($result, $this->primary_key), $result);
				}else{
					foreach($keys as $key){
						$data[$key]	= array_values(wp_list_filter($result, [$field => $key]));
					}
				}
			}

			if($this->cache){
				foreach($cache_map as $cache_key => $key){
					$value	= $data[$key] ?? [];
					$time	= $value ? $this->cache_time : MINUTE_IN_SECONDS;

					wp_cache_set($cache_key, $value, $this->cache_group, $time);
				}
			}
		}

		if($this->meta_type){
			$ids	= [];

			if($primary){
				foreach($data as $id => $item){
					if($item){
						$ids[]	= $id;
					}
				}
			}else{
				foreach($data as $items){
					if($items){
						$ids	= array_merge($ids, array_column($items, $this->primary_key));
					}
				}
			}

			$this->lazyload_meta($ids);
		}

		return $data;
	}

	public function get_ids($ids){
		return self::update_caches($ids, true);
	}

	public function get_by_ids($ids){
		return self::get_ids($ids);
	}

	public function get_clauses($fields=[]){
		$distinct	= '';
		$where		= '';
		$join		= '';
		$groupby	= $this->groupby ?: '';

		if($this->meta_query){
			$sql	= $this->meta_query->get_sql($this->meta_type, $this->table, $this->primary_key, $this);
			$where	= $sql['where'];
			$join	= $sql['join'];

			$groupby	= $groupby ?: $this->table.'.'.$this->primary_key;
			$fields		= $fields ?: $this->table.'.*';
		}

		if($fields){
			if(is_array($fields)){
				$fields	= '`'.implode( '`, `', $fields ).'`';
				$fields	= esc_sql($fields);
			}
		}else{
			$fields	= '*';
		}

		if($groupby){
			if(!str_contains($groupby, ',') && !str_contains($groupby, '(') && !str_contains($groupby, '.')){
				$groupby	= '`'.$groupby.'`';
			}

			$groupby	= ' GROUP BY '.$groupby;
		}

		$having		= $this->having ? ' HAVING '.$having : '';
		$orderby	= $this->orderby;

		if(is_null($orderby) && !$groupby && !$having){
			$orderby	= $this->primary_key;
		}

		if($orderby){
			if(is_array($orderby)){
				$parsed		= array_map([$this, 'parse_orderby'], array_keys($orderby), $orderby);
				$parsed		= array_filter($parsed);
				$orderby	= $parsed ? implode(', ', $parsed) : '';
			}elseif(str_contains($orderby, ',') || (str_contains($orderby, '(') && str_contains($orderby, ')'))){
				$orderby	= esc_sql($orderby);
			}else{
				$orderby	= $this->parse_orderby($orderby, $this->order);
			}

			$orderby	= $orderby ? ' ORDER BY '.$orderby : '';
		}else{
			$orderby	= '';
		}

		$limits		= $this->limit ? ' LIMIT '.$this->limit : '';
		$limits		.= $this->offset ? ' OFFSET '.$this->offset : '';
		$found_rows	= ($limits && $this->found_rows) ? 'SQL_CALC_FOUND_ROWS' : '';
		$conditions	= $this->get_conditions();

		if(!$conditions && $where){
			$where	= 'WHERE 1=1 '.$where;
		}else{
			$where	= $conditions.$where;
			$where	= $where ? ' WHERE '.$where : '';
		}

		return compact('found_rows', 'distinct', 'fields', 'join', 'where', 'groupby', 'having', 'orderby', 'limits');
	}

	public function get_request($clauses=null){
		$clauses	= $clauses ?: $this->get_clauses();

		return sprintf("SELECT %s %s %s FROM `{$this->table}` %s %s %s %s %s %s", ...array_values($clauses));
	}

	public function get_sql($fields=[]){
		return $this->get_request($this->get_clauses($fields));
	}

	public function get_results($fields=[]){
		$clauses	= $this->get_clauses($fields);
		$sql		= $this->get_request($clauses);
		$results	= $this->wpdb->get_results($sql, ARRAY_A);

		return $this->filter_results($results, $clauses['fields']);
	}

	protected function filter_results($results, $fields){
		if($results && in_array($fields, ['*', $this->table.'.*'])){
			$ids	= [];

			foreach($results as $result){
				if(!empty($result[$this->primary_key])){
					$id		= $result[$this->primary_key];
					$ids[]	= $id;

					$this->cache_set_by_primary_key($id, $result);
				}
			}

			if($ids){
				if($this->lazyload_callback){
					call_user_func($this->lazyload_callback, $ids, $results);
				}

				if($this->meta_type){
					$this->lazyload_meta($ids);
				}
			}
		}

		return $results;
	}

	public function find($fields=[]){
		return $this->get_results($fields);
	}

	public function find_total(){
		return $this->wpdb->get_var("SELECT FOUND_ROWS();");
	}

	protected function parse_orderby($orderby, $order){
		if($orderby == 'rand'){
			return 'RAND()';
		}elseif(preg_match('/RAND\(([0-9]+)\)/i', $orderby, $matches)){
			return sprintf('RAND(%s)', (int)$matches[1]);
		}elseif(str_ends_with($orderby, '__in')){
			return '';
			// $field	= str_replace('__in', '', $orderby);
		}

		$order	= (is_string($order) && 'ASC' === strtoupper($order)) ? 'ASC' : 'DESC';

		if($this->meta_query){
			$meta_clauses		= $this->meta_query->get_clauses();
			$primary_meta_query	= reset($meta_clauses);
			$primary_meta_key	= $primary_meta_query['key'] ?? '';

			if($orderby == $primary_meta_key || $orderby == 'meta_value'){
				if(!empty($primary_meta_query['type'])){
					return "CAST({$primary_meta_query['alias']}.meta_value AS {$primary_meta_query['cast']}) ".$order;
				}else{
					return "{$primary_meta_query['alias']}.meta_value ".$order;
				}
			}elseif($orderby == 'meta_value_num'){
				return "{$primary_meta_query['alias']}.meta_value+0 ".$order;
			}elseif(array_key_exists($orderby, $meta_clauses)){
				$meta_clause	= $meta_clauses[$orderby];

				return "CAST({$meta_clause['alias']}.meta_value AS {$meta_clause['cast']}) ".$order;
			}
		}

		if($orderby == 'meta_value_num' || $orderby == 'meta_value'){
			return '';
		}

		return '`'.$orderby.'` '.$order;
	}

	public function insert_multi($datas){	// 使用该方法，自增的情况可能无法无法删除缓存，请注意
		if(empty($datas)){
			return 0;
		}

		$datas		= array_values($datas);

		$this->delete_last_changed();
		$this->cache_delete_by_conditions([], $datas);

		$data		= current($datas);
		$values		= [];
		$fields		= '`'.implode('`, `', array_keys($data)).'`';
		$updates	= implode(', ', array_map(function($field){ return "`$field` = VALUES(`$field`)"; }, array_keys($data)));

		foreach(array_filter($datas) as $data){
			foreach($data as $k => $v){
				if(is_array($v)){
					trigger_error($k.'的值是数组：'.var_export($data, true));
					continue;
				}
			}

			$values[]	= $this->format($data);
		}

		$values	= implode(',', $values);
		$sql	= "INSERT INTO `$this->table` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
		$result	= $this->wpdb->query($sql);

		return (false === $result) ? new WP_Error('insert_error', $this->wpdb->last_error) : $result;
	}

	public function insert($data){
		$this->delete_last_changed();
		$this->cache_delete_by_conditions([], $data);

		$id	= $data[$this->primary_key] ?? null;

		if($id){
			$this->wpdb->check_current_query = false;

			$data		= array_filter($data, 'is_exists');
			$fields		= implode(', ', array_keys($data));
			$values		= $this->format($data);
			$updates	= implode(', ', array_map(function($field){ return "`$field` = VALUES(`$field`)"; }, array_keys($data)));
			$sql		= "INSERT INTO `$this->table` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
			$result		= $this->wpdb->query($sql);
		}else{
			$result		= $this->wpdb->insert($this->table, $data, $this->get_format($data));
		}

		if($result === false){
			return new WP_Error('insert_error', $this->wpdb->last_error);
		}

		$id	= $id ?: $this->wpdb->insert_id;

		$this->cache_delete_by_primary_key($id);

		return $id;
	}

	/*
	用法：
	update($id, $data);
	update($data, $where);
	update($data); // $where各种 参数通过 where() 方法事先传递
	*/
	public function update(...$args){
		$this->delete_last_changed();

		if(count($args) == 2){
			if(is_array($args[0])){
				$data	= $args[0];
				$where	= $args[1];

				$conditions	= $this->where_all($where, 'fragment');
			}else{
				$id		= $args[0];
				$data	= $args[1];
				$where	= $conditions = [$this->primary_key => $id];

				$this->cache_delete_by_primary_key($id);
			}

			$this->cache_delete_by_conditions($conditions, $data);

			$result	= $this->wpdb->update($this->table, $data, $where, $this->get_format($data), $this->get_format($where));

			return $result === false ? new WP_Error('update_error', $this->wpdb->last_error) : $result;
		}elseif(count($args) == 1){	// 如果为空，则需要事先通过各种 where 方法传递进去
			$data	= $args[0];
			$where	= $this->get_conditions();

			if($data && $where){
				$this->cache_delete_by_conditions($where, $data);

				$fields	= implode(', ', array_map(function($field, $value){
					return "`$field` = ".(is_null($value) ? 'NULL' : $this->format($value, $field));
				}, array_keys($data), $data));

				return $this->wpdb->query("UPDATE `{$this->table}` SET {$fields} WHERE {$where}");
			}

			return 0;
		}
	}

	/*
	用法：
	delete($where);
	delete($id);
	delete(); // $where 参数通过各种 where() 方法事先传递
	*/
	public function delete($where = ''){
		$this->delete_last_changed();

		$id	= null;

		if($where){	// 如果传递进来字符串或者数字，认为根据主键删除，否则传递进来数组，使用 wpdb 默认方式
			if(is_array($where)){
				$this->cache_delete_by_conditions($this->where_all($where, 'fragment'));
			}else{
				$id		= $where;
				$where	= [$this->primary_key => $id];

				$this->cache_delete_by_primary_key($id);
				$this->cache_delete_by_conditions($where);
			}

			$result	= $this->wpdb->delete($this->table, $where, $this->get_format($where));
		}else{	// 如果为空，则 $where 参数通过各种 where() 方法事先传递
			$where	= $this->get_conditions();

			if(!$where){
				return 0;
			}

			$this->cache_delete_by_conditions($where);

			$result = $this->wpdb->query("DELETE FROM `{$this->table}` WHERE {$where}");
		}

		if(false === $result){
			return new WP_Error('delete_error', $this->wpdb->last_error);
		}

		if($id){
			$this->delete_meta_by_id($id);
		}else{
			$this->delete_orphan_meta($this->table, $this->primary_key);
		}

		return $result;
	}

	public function delete_by($field, $value){
		return $this->delete([$field => $value]);
	}

	public function delete_multi($ids){
		if(empty($ids)){
			return 0;
		}

		$this->delete_last_changed();
		$this->cache_delete_by_conditions([$this->primary_key => $ids]);

		array_walk($ids, [$this, 'cache_delete_by_primary_key']);

		$values	= $this->map($ids, 'format', $this->primary_key);
		$where	= 'WHERE `'.$this->primary_key.'` IN ('.implode(',', $values).') ';
		$sql	= "DELETE FROM `{$this->table}` {$where}";
		$result = $this->wpdb->query($sql);

		if(false === $result ){
			return new WP_Error('delete_error', $this->wpdb->last_error);
		}

		return $result ;
	}

	protected function cache_delete_by_conditions($conditions, $data=[]){
		if($this->cache || $this->group_cache_key){
			if($data){
				$conditions	= $conditions ? (array)$conditions : [];
				$datas		= wp_is_numeric_array($data) ? $data : [$data];

				foreach($datas as $data){
					foreach(['primary_key', 'cache_key'] as $k){
						$key	= $this->$k;

						if($k == 'primary_key'){
							if(empty($data[$key])){
								continue;
							}

							$this->cache_delete_by_primary_key($data[$key]);
						}else{
							if(!$this->cache_key || !isset($data[$key])){
								continue;
							}

							$this->cache_delete($data[$key]);
						}

						$conditions[$key]	= isset($conditions[$key]) ? (array)$conditions[$key] : [];
						$conditions[$key][]	= $data[$key];
					}

					foreach($this->group_cache_key as $group_cache_key){
						if(isset($data[$group_cache_key])){
							$this->delete_last_changed([$group_cache_key => $data[$group_cache_key]]);
						}
					}
				}
			}

			if(is_array($conditions)){
				if(!$this->cache_key && !$this->group_cache_key){
					if(count($conditions) == 1 && isset($conditions[$this->primary_key])){
						$conditions	= [];
					}
				}

				$conditions	= $conditions ? $this->where_any($conditions, 'fragment') : null;
			}

			if($conditions){
				$fields	= [$this->primary_key];

				if($this->cache_key){
					$fields[]	= $this->cache_key;
				}

				$fields		= implode(', ', array_merge($fields, $this->group_cache_key));
				$results	= $this->wpdb->get_results("SELECT {$fields} FROM `{$this->table}` WHERE {$conditions}", ARRAY_A) ?: [];

				foreach($results as $result){
					$this->cache_delete_by_primary_key($result[$this->primary_key]);

					if($this->cache_key){
						$this->cache_delete($result[$this->cache_key]);
					}

					foreach($this->group_cache_key as $group_cache_key){
						$this->delete_last_changed([$group_cache_key => $result[$group_cache_key]]);
					}
				}
			}
		}
	}

	protected function get_conditions(){
		$where	= $this->parse_where($this->where, 'AND');

		if($this->searchable_fields && $this->search_term){
			$search	= array_map(function($field){
				$like	= $this->wpdb->esc_like($this->search_term);

				return "`{$field}` LIKE '%{$like}%'";
			}, $this->searchable_fields);

			$where	.= ($where ? ' AND ' : '').'('.implode(' OR ', $search).')';
		}

		$this->clear();

		return $where;
	}

	public function get_wheres(){	// 以后放弃，目前统计在用
		return $this->get_conditions();
	}

	protected function format($value, $column=''){
		if(is_array($value)){
			$format	= $this->get_format($value);
			$format	= '('.implode(', ', $format).')';
			$value	= array_values($value);
		}else{
			$format	= str_contains($column, '%') ? $column : $this->get_format($column);
		}

		return $this->wpdb->prepare($format, $value);
	}

	protected function get_format($column){
		if(is_array($column)){
			return $this->map(array_keys($column), 'get_format');
		}else{
			return $this->field_types[$column] ?? '%s';
		}
	}

	protected function parse_where($qs=null, $type=''){
		$where	= [];
		$qs		= $qs ?? $this->where;

		foreach($qs as $q){
			if(!$q || empty($q['compare'])){
				continue;
			}

			$compare	= strtoupper($q['compare']);

			if($compare == strtoupper('fragment')){
				$where[]	= $q['fragment'];

				continue;
			}

			$value	= $q['value'];
			$column	= $q['column'];

			if(in_array($compare, ['IN', 'NOT IN'])){
				$value	= is_array($value) ? $value : explode(',', $value);
				$value	= array_values(array_unique($value));
				$value	= $this->map($value, 'format', $column);

				if(count($value) > 1){
					$value		= '('.implode(',', $value).')';
				}else{
					$compare	= $compare == 'IN' ? '=' : '!=';
					$value		= $value ? current($value) : '\'\'';
				}
			}elseif(in_array($compare, ['LIKE', 'NOT LIKE'])){
				$left	= str_starts_with($value, '%');
				$right	= str_ends_with($value, '%');
				$value	= trim($value, '%');
				$value	= ($left ? '%' : '').$this->wpdb->esc_like($value).($right ? '%' : '');
				$value	= $this->format($value, '%s');
			}else{
				$value	= $this->format($value, $column);
			}

			if(!str_contains($column, '(')){
				$column	= '`'.$column.'`';
			}

			$where[]	= $column.' '.$compare.' '.$value;
		}

		return $type ? implode(' '.$type.' ', $where) : $where;
	}

	public function where($column, $value, $output='object'){
		if(is_array($value)){
			if(wp_is_numeric_array($value)){
				$value	= ['value'=>$value];
			}

			if(!isset($value['value'])){
				$value	= [];
			}else{
				if(is_numeric($column) || is_null($column)){
					if(!isset($value['column'])){
						$value = [];
					}
				}else{
					$value['column']	= $column;
				}

				if($value && (!isset($value['compare']) || !in_array(strtoupper($value['compare']), $this->get_operators()))){
					$value['compare']	= is_array($value['value']) ? 'IN' : '=';
				}
			}
		}else{
			if(is_null($value)){
				$value	= [];
			}else{
				if(is_numeric($column) || is_null($column)){
					$value	= ['compare'=>'fragment', 'fragment'=>'( '.$value.' )'];
				}else{
					$value	= ['compare'=>'=', 'column'=>$column, 'value'=>$value];
				}
			}
		}

		if($output != 'object'){
			return $value;
		}else{
			$this->where[]	= $value;

			return $this;
		}
	}

	public function query_items($limit, $offset){
		$this->limit($limit)->offset($offset)->found_rows();

		foreach(['orderby', 'order'] as $key){
			if(is_null($this->$key)){
				call_user_func([$this, $key], wpjam_get_data_parameter($key));
			}
		}

		if($this->searchable_fields && is_null($this->search_term)){
			$this->search(wpjam_get_data_parameter('s'));
		}

		foreach($this->filterable_fields as $key){
			$this->where($key, wpjam_get_data_parameter($key));
		}

		return ['items'=>$this->get_results(), 'total'=>$this->find_total()];
	}

	public function query($query_vars, $output='array'){
		$query_vars	= apply_filters('wpjam_query_vars', $query_vars, $this);

		if(isset($query_vars['groupby'])){
			$query_vars	= array_except($query_vars, ['first', 'cursor']);

			$query_vars['no_found_rows']	= true;
		}else{
			if(!isset($query_vars['number']) && empty($query_vars['no_found_rows'])){
				$query_vars['number']	= 50;
			}
		}

		$qv				= $query_vars;
		$no_found_rows	= $qv['no_found_rows'] ?? false;
		$cache_results	= $qv['cache_results'] ?? true;
		$fields			= $qv['fields'] ?? null;
		$orderby		= $qv['orderby'] ?? $this->primary_key;

		if($cache_results && str_contains(strtoupper($orderby), ' RAND(')){
			$cache_results	= false;
		}

		if($this->meta_type){
			$meta_query	= array_pulls($qv, [
				'meta_key',
				'meta_value',
				'meta_compare',
				'meta_compare_key',
				'meta_type',
				'meta_type_key',
				'meta_query'
			]);

			if($meta_query){
				$this->meta_query	= new WP_Meta_Query();
				$this->meta_query->parse_query_vars($meta_query);
			}
		}

		foreach($qv as $key => $value){
			if(is_null($value) || in_array($key, ['no_found_rows', 'cache_results', 'fields'])){
				continue;
			}

			if($key == 'number'){
				if($value == -1){
					$no_found_rows	= true;
				}else{
					$this->limit($value);
				}
			}elseif($key == 'offset'){
				$this->offset($value);
			}elseif($key == 'orderby'){
				$this->orderby($value);
			}elseif($key == 'order'){
				$this->order($value);
			}elseif($key == 'groupby'){
				$this->groupby($value);
			}elseif($key == 'cursor'){
				if($value > 0){
					$this->where_lt($orderby, $value);
				}
			}elseif($key == 'search' || $key == 's'){
				$this->search($value);
			}else{
				foreach($this->get_operators() as $operator => $compare){
					if(str_ends_with($key, '__'.$operator)){
						$key	= wpjam_remove_postfix($key, '__'.$operator);
						$value	= ['value'=>$value, 'compare'=>$compare];

						break;
					}
				}

				$this->where($key, $value);
			}
		}

		if(!$no_found_rows){
			$this->found_rows(true);
		}

		$clauses	= apply_filters_ref_array('wpjam_clauses', [$this->get_clauses($fields), &$this]);
		$request	= apply_filters_ref_array('wpjam_request', [$this->get_request($clauses), &$this]);
		$result		= false;

		if($cache_results){
			$last_changed	= $this->get_last_changed($query_vars);
			$cache_key		= 'wpjam_query:'.md5(maybe_serialize($query_vars).$request).':'.$last_changed;
			$result			= $this->cache_get_force($cache_key);
		}

		if($result === false || !isset($result['items'])){
			$items	= $this->wpdb->get_results($request, ARRAY_A);
			$items	= $this->filter_results($items, $clauses['fields']);
			$result	= ['items'=>$items];

			if(!$no_found_rows){
				$result['total']	= $this->find_total();
			}

			if($cache_results){
				$this->cache_set_force($cache_key, $result, DAY_IN_SECONDS);
			}
		}

		if(!$no_found_rows){
			$number	= $qv['number'] ?? null;

			if($number && $number != -1){
				$result['max_num_pages']	= ceil($result['total'] / $number);

				if($result['max_num_pages'] > 1){
					$result['next_cursor']	= (int)(end($result['items'])[$orderby]);
				}else{
					$result['next_cursor']	= 0;
				}
			}
		}else{
			$result['total']	= count($result['items']);
		}

		$result['items']		= $result['datas']	= apply_filters_ref_array('wpjam_queried_items', [$result['items'], &$this]);
		$result['found_rows']	= $result['total'];
		$result['request']		= $request;

		return $output == 'object' ? (object)$result : $result;
	}
}

class WPJAM_DBTransaction{
	public static function beginTransaction(){
		return $GLOBALS['wpdb']->query("START TRANSACTION;");
	}

	public static function queryException(){
		$error = $GLOBALS['wpdb']->last_error;

		if($error){
			throw new Exception($error);
		}
	}

	public static function commit(){
		self::queryException();
		return $GLOBALS['wpdb']->query("COMMIT;");
	}

	public static function rollBack(){
		return $GLOBALS['wpdb']->query("ROLLBACK;");
	}
}

class WPJAM_Items extends WPJAM_Args{
	use WPJAM_Instance_Trait;

	public function __construct($args=[]){
		$this->args = wp_parse_args($args, [
			'item_type'		=> 'array',
			'primary_key'	=> 'id',
			'primary_title'	=> 'ID'
		]);

		if($this->item_type != 'array'){
			$this->primary_key	= null;
		}
	}

	public function __call($method, $args){
		if(in_array($method, [
			'insert',
			'add',
			'update',
			'replace',
			'set',
			'delete',
			'remove',
			'empty',
			'move',
			'increment',
			'decrement'
		])){
			if($method == 'decrement'){
				$method		= 'increment';
				$args[1]	= 0 - ($args[1] ?? 1);
			}elseif($method == 'replace'){
				$method		= 'update';
			}elseif($method == 'remove'){
				$method		= 'delete';
			}

			if($this->item_type == 'array' && in_array($method, ['add', 'increment'])){
				return;
			}

			$retry	= $this->retry_times ?: 1;

			if($method == 'add'){
				if(count($args) >= 2){
					$id		= $args[0];
					$item	= $args[1];
				}else{
					$id		= null;
					$item	= $args[0];
				}
			}elseif($method == 'insert'){
				$id		= null;
				$item	= $args[0];
			}elseif($method == 'empty'){
				$item	= $id = null;
			}else{
				$id		= $args[0];
				$item	= $args[1] ?? null;
			}

			try{
				do{
					$retry	-= 1;
					$result	= $this->_action($method, $id, $item);
				}while($result === false && $retry > 0);

				return $result;
			}catch(WPJAM_Exception $e){
				return $e->get_wp_error();
			}
		}elseif(in_array($method, [
			'get_primary_key',
			'get_searchable_fields',
			'get_filterable_fields'
		])){
			return $this->{substr($method, 4)};
		}elseif(in_array($method, [
			'get_items',
			'update_items',
			'delete_items'
		])){
			$callback	= $this->$method ?: ($this->items_model ? [$this->items_model, $method] : null);

			if($callback && is_callable($callback)){
				return call_user_func_array($callback, $args);
			}else{
				return $method == 'get_items' ? [] : true;
			}
		}
	}

	protected function exception($code, $msg, $type=''){
		if($type){
			$code	.= '_'.$this->{$type.'_key'};
			$msg	= $this->{$type.'_title'}.$msg;
		}

		throw new WPJAM_Exception($msg, $code);
	}

	public function query_items($args){
		$items	= $this->parse_items();

		return ['items'=>$items, 'total'=>count($items)];
	}

	public function parse_items($items=null){
		$items	= $items ?? $this->get_items();

		if($items && is_array($items)){
			foreach($items as $id => &$item){
				$item	= $this->parse_item($item, $id);
			}

			return $items;
		}

		return [];
	}

	public function parse_item($item, $id){
		if($this->item_type == 'array'){
			$item	= is_array($item) ? $item : [];

			return array_merge($item, [$this->primary_key => $id]);
		}

		return $item;
	}

	public function get_results(){
		return $this->parse_items();
	}

	public function reset(){
		return $this->delete_items();
	}

	public function exists($value, $type='unique'){
		$items	= $this->get_items();

		if($items){
			if($this->item_type == 'array'){
				if($type == 'unique'){
					return in_array($value, array_column($items, $this->unique_key));
				}else{
					return isset($items[$value]);
				}
			}else{
				return in_array($value, $items);
			}
		}

		return false;
	}

	public function get($id){
		$items	= $this->get_items();
		$item	= $items[$id] ?? false;

		return $item ? $this->parse_item($item, $id) : false;
	}

	protected function validate($action, $item=null, $id=null){
		$items	= $this->get_items();

		if(isset($id)){
			if(isset($items[$id])){
				if(in_array($action, ['add', 'insert'])){
					$this->exception('duplicate', '「'.$id.'」已存在', 'primary');
				}
			}else{
				if(in_array($action, ['update', 'delete'])){
					$this->exception('invalid', '为「'.$id.'」的数据的不存在', 'primary');
				}elseif($action == 'set'){
					$action == 'add';	// set => add
				}
			}

			if(!isset($item)){
				return true;
			}
		}

		if(in_array($action, ['add', 'insert']) && $this->max_items && count($items) >= $this->max_items){
			$this->exception('over_max_items', '最大允许数量：'.$this->max_items);
		}

		if($this->item_type == 'array'){
			if(in_array($this->primary_key, ['option_key', 'id'])){
				if($this->unique_key){
					$value	= $item[$this->unique_key] ?? null;

					if(isset($id) && is_null($value)){
						return $item;
					}

					if(!$value){
						$this->exception('empty', '不能为空', 'unique');
					}

					foreach($items as $_id => $_item){
						if(isset($id) && $id == $_id){
							continue;
						}

						if($_item[$this->unique_key] == $value){
							$this->exception('duplicate', '值重复', 'unique');
						}
					}
				}
			}else{
				if(is_null($id)){
					$id	= $item[$this->primary_key] ?? null;

					if(!$id){
						$this->exception('empty', '不能为空', 'primary');
					}

					if(isset($items[$id])){
						$this->exception('duplicate', '值重复', 'primary');
					}
				}
			}
		}

		return true;
	}

	protected function get_id($item){
		if(in_array($this->primary_key, ['option_key', 'id'])){
			$items	= $this->get_items();

			if($items){
				$ids	= array_keys($items);
				$ids	= array_map(function($id){return (int)(str_replace('option_key_', '', $id)); }, $ids);
				$id		= max($ids);
				$id		= $id+1;
			}else{
				$id		= 1;
			}

			if($this->primary_key == 'option_key'){
				$id		= 'option_key_'.$id;
			}

			return $id;
		}

		return $item[$this->primary_key];
	}

	protected function _action($action, $id, $item){
		$type	= $this->item_type;
		$items	= $this->get_items();

		if($action != 'move'){
			$result	= $this->validate($action, $item, $id);

			if($type == 'array' && isset($item)){
				$item	= filter_deep($item, 'is_exists');

				if(isset($id)){
					$item[$this->primary_key] = $id;
				}
			}
		}

		if($action == 'insert'){
			if($type == 'array'){
				$id	= $this->get_id($item);

				if($this->last){
					$items[$id]	= $item;
				}else{
					$items		= [$id=>$item]+$items;
				}
			}else{
				if($this->last){
					$items[]	= $item;
				}else{
					array_unshift($items, $item);
				}
			}
		}elseif($action == 'add'){
			if(isset($id)){
				$items[$id]	= $item;
			}else{
				$items[]	= $item;
			}
		}elseif($action == 'update'){
			if($type == 'array'){
				$item	= wp_parse_args($item, $items[$id]);
			}

			$items[$id]	= $item;
		}elseif($action == 'set'){
			$items[$id]	= $item;
		}elseif($action == 'empty'){
			$prev	= $items;
			$items	= [];
		}elseif($action == 'delete'){
			$items	= array_except($items, $id);
		}elseif($action == 'move'){
			$ids	= wpjam_try('array_move', array_keys($items), $id, $item);
			$items	= wp_array_slice_assoc($items, $ids);
		}elseif($action == 'increment'){
			if(isset($items[$id])){
				$item	= (int)$items[$id] + $item;
			}

			$items[$id] = $item;
		}

		if($type == 'array' && $items && is_array($items) && in_array($this->primary_key, ['option_key','id'])){
			foreach($items as $id => &$item){
				$item	= array_except($item, $this->primary_key);

				if($this->parent_key){
					$item	= array_except($item, $this->parent_key);
				}
			}
		}

		$result	= $this->update_items($items);

		if($result){
			if($action == 'insert'){
				if($this->item_type == 'array'){
					return ['id'=>$id,	'last'=>(bool)$this->last];
				}
			}elseif($action == 'empty'){
				return $prev;
			}elseif($action == 'increment'){
				return $item;
			}
		}

		return $result;
	}
}

class WPJAM_Option_Items extends WPJAM_Items{
	public function __construct($option_name, $args=[]){
		$args	= is_array($args) ? wp_parse_args($args, ['primary_key'=>'option_key']) : ['primary_key'=>$args];

		parent::__construct(array_merge($args, ['option_name'=>$option_name]));
	}

	public function __call($method, $args){
		if(in_array($method, ['get_items', 'update_items', 'delete_items'])){
			$callback	= str_replace('_items', '_option', $method);
			$result		= call_user_func($callback, $this->option_name, ...$args);

			return $result ?: ($method == 'get_items' ? [] : false);
		}

		return parent::__call($method, $args);
	}

	public static function get_instance(){
		$r	= new ReflectionMethod(get_called_class(), '__construct');

		return $r->getNumberOfParameters() ? null : static::instance();
	}
}

class WPJAM_Meta_Items extends WPJAM_Items{
	public function __construct($meta_type, $object_id, $meta_key, $args=[]){
		parent::__construct(array_merge($args, [
			'meta_type'		=> $meta_type,
			'object_id'		=> $object_id,
			'meta_key'		=> $meta_key,
			'parent_key'	=> $meta_type.'_id',
		]));
	}

	public function __call($method, $args){
		if(in_array($method, ['get_items', 'update_items', 'delete_items'])){
			if($method == 'get_items'){
				$args[]	= true;
			}elseif($method == 'update_items'){
				$args[]	= $this->get_items();
			}

			$callback	= str_replace('_items', '_metadata', $method);
			$result		= call_user_func($callback, $this->meta_type, $this->object_id, $this->meta_key, ...$args);

			return $result ?: ($method == 'get_items' ? [] : false);
		}

		return parent::__call($method, $args);
	}
}

class WPJAM_Content_Items extends WPJAM_Items{
	public function __construct($post_id, $args=[]){
		parent::__construct(array_merge($args, ['post_id'=>$post_id, 'parent_key'=>'post_id']));
	}

	public function get_items(){
		$_post	= get_post($this->post_id);

		return ($_post && $_post->post_content) ? maybe_unserialize($_post->post_content) : [];
	}

	public function update_items($items){
		$items	= $items ? maybe_serialize($items) : '';

		return WPJAM_Post::update($this->post_id, ['post_content'=>$items]);
	}

	public function delete_items(){
		return $this->update_items([]);
	}
}

class WPJAM_Cache_Items extends WPJAM_Items{
	public function __construct($key, $args=[]){
		parent::__construct(wp_parse_args($args, [
			'item_type'		=> '',
			'retry_times'	=> 10,
			'key'			=> $key,
			'group'			=> 'list_cache',
		]));

		$this->cache	= is_object($this->group) ? $this->group : wpjam_cache($this->group, $this->get_args());
	}

	public function __call($method, $args){
		if(in_array($method, ['get_items', 'update_items', 'delete_items'])){
			$cache	= $this->cache;
			$items	= $cache->get_with_cas($this->key, $token);

			if(!is_array($items)){
				$cache->set($this->key, []);

				$items	= $cache->get_with_cas($this->key, $token);
			}

			if($method == 'get_items'){
				return $items;
			}elseif($method == 'update_items'){
				return $cache->cas($token, $this->key, ...$args);
			}else{
				return $this->update_items([]);
			}
		}

		return parent::__call($method, $args);
	}
}