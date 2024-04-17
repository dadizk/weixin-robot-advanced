<?php
class WPJAM_Admin{
	public static function filter_admin_url($url, $path, $blog_id=null, $scheme='admin'){
		if($path && is_string($path) && str_starts_with($path, 'page=')){
			$url	= get_site_url($blog_id, 'wp-admin/', $scheme);
			$url	.= 'admin.php?'.$path;
		}

		return $url;
	}

	public static function filter_html($html){
		if(WPJAM_Plugin_Page::get_current()){
			$queried	= wpjam_get_items('queried_menu');
			$search		= array_column($queried, 'search');
			$replace	= array_column($queried, 'replace');
		}else{
			$search		= '<hr class="wp-header-end">';
			$replace	= $search.wpautop(get_screen_option('page_summary'));
		}

		return str_replace($search, $replace, $html);
	}

	public static function filter_parent_file($parent_file){
		if($GLOBALS['submenu_file']){
			$parent_files	= wpjam_get_items('parent_files');

			if(isset($parent_files[$GLOBALS['submenu_file']])){
				return $parent_files[$GLOBALS['submenu_file']];
			}
		}

		return $parent_file;
	}

	public static function on_current_screen($screen=null){
		$object	= WPJAM_Plugin_Page::get_current();

		if($object){
			if(wpjam_get_items('queried_menu')){
				add_filter('wpjam_html', [self::class, 'filter_html']);
			}

			$object->load($screen);
		}else{
			if(wpjam_get_items('parent_files')){
				add_filter('parent_file', [self::class, 'filter_parent_file']);
			}

			if($screen){
				WPJAM_Builtin_Page::init($screen);

				if(!wp_doing_ajax() && $screen->get_option('page_summary')){
					add_filter('wpjam_html', [self::class, 'filter_html']);
				}
			}
		}
	}
}

class WPJAM_Admin_Action extends WPJAM_Register{
	protected function parse_submit_button($button, $name=null, $render=true){
		$button	= $button ?: [];
		$button	= is_array($button) ? $button : [$this->name => $button];

		foreach($button as $key => &$item){
			$item	= is_array($item) ? $item : ['text'=>$item];
			$item	= wp_parse_args($item, ['response'=>($this->response ?? $this->name), 'class'=>'primary']);

			if($render){
				$item	= get_submit_button($item['text'], $item['class'], $key, false);
			}
		}

		if($name){
			if(!isset($button[$name])){
				wp_die('无效的提交按钮');
			}

			return $button[$name];
		}else{
			return $render ? implode('', $button) : $button;
		}
	}

	protected function parse_nonce_action($args=[]){
		$prefix	= $GLOBALS['plugin_page'] ?? $GLOBALS['current_screen']->id;
		$key	= $this->name;

		if($args){
			if(!empty($args['bulk'])){
				$key	= 'bulk_'.$key;
			}elseif(!empty($args['id'])){
				$key	= $key.'-'.$args['id'];
			}
		}

		return $prefix.'-'.$key;
	}

	public function create_nonce($args=[]){
		$action	= $this->parse_nonce_action($args);

		return wp_create_nonce($action);
	}

	public function verify_nonce($args=[]){
		$action	= $this->parse_nonce_action($args);

		return check_ajax_referer($action, false, false);
	}
}

class WPJAM_Page_Action extends WPJAM_Admin_Action{
	public function is_allowed($type=''){
		$capability	= $this->capability ?? ($type ? 'manage_options' : '');

		return $capability ? current_user_can($capability, $this->name) : true;
	}

	public function callback($type=''){
		if($type == 'form'){
			$page_title	= wpjam_get_post_parameter('page_title');

			if(!$page_title){
				foreach(['page_title', 'button_text', 'submit_text'] as $key){
					if(!empty($this->$key) && !is_array($this->$key)){
						$page_title	= $this->$key;
						break;
					}
				}
			}

			return [
				'form'			=> $this->get_form(),
				'width'			=> $this->width ?: 720,
				'modal_id'		=> $this->modal_id ?: 'tb_modal',
				'page_title'	=> $page_title
			];
		}

		if(!$this->verify_nonce()){
			wp_die('invalid_nonce');
		}

		if(!$this->is_allowed($type)){
			wp_die('access_denied');
		}

		$callback		= '';
		$submit_name	= null;

		if($type == 'submit'){
			$submit_name	= wpjam_get_post_parameter('submit_name',	['default'=>$this->name]);
			$submit_button	= $this->get_submit_button($submit_name);

			$callback	= $submit_button['callback'] ?? '';
			$response	= $submit_button['response'];
		}else{
			$response	= $this->response ?? $this->name;
		}

		$callback	= $callback ?: $this->callback;

		if(!$callback || !is_callable($callback)){
			wp_die('无效的回调函数');
		}

		$cb_args	= [$this->name, $submit_name];

		if($this->validate){
			$data	= wpjam_get_data_parameter();
			$data	= $this->get_fields('object')->validate($data);

			$cb_args	= array_merge([$data], $cb_args);
		}

		$result		= wpjam_try($callback, ...$cb_args);
		$response	= ['type'=>$response];

		if(is_array($result)){
			$response	= array_merge($response, $result);
		}elseif($result === false || is_null($result)){
			$response	= new WP_Error('invalid_callback', ['返回错误']);
		}elseif($result !== true){
			if($this->response == 'redirect'){
				$response['url']	= $result;
			}else{
				$response['data']	= $result;
			}
		}

		return apply_filters('wpjam_ajax_response', $response);
	}

	public function get_data(){
		$data		= $this->data ?: [];
		$callback	= $this->data_callback;

		if($callback && is_callable($callback)){
			$_data	= wpjam_try($callback, $this->name, $this->get_fields());

			return array_merge($data, $_data);
		}

		return $data;
	}

	public function get_button($args=[]){
		if(!$this->is_allowed()){
			return '';
		}

		$this->update_args(array_except($args, 'data'));

		$data	= array_get($args, 'data') ?: [];
		$data	= $this->generate_data_attr(['data'=>$data]);
		$tag	= $this->tag ?: 'a';
		$text	= $this->button_text ?? '保存';
		$class	= $this->class ?? 'button-primary large';
		$attr	= [
			'title'	=> $this->page_title ?: $text,
			'class'	=> array_merge(wp_parse_list($class), ['wpjam-button']),
			'style'	=> $this->style,
			'data'	=> $data
		];

		return wpjam_tag($tag, $attr, $text);
	}

	public function get_form(){
		if(!$this->is_allowed()){
			return '';
		}

		$attr	= [
			'method'	=> 'post',
			'action'	=> '#',
			'id'		=> $this->form_id ?: 'wpjam_form',
			'data'		=> $this->generate_data_attr([], 'form')
		];

		$args	= array_merge($this->args, ['data'=>$this->get_data()]);
		$form	= $this->get_fields('object')->render($args, false)->wrap('form', $attr);
		$button	= $this->get_submit_button();

		if($button){
			$form->append('p', ['submit'], $button);
		}

		return $form;
	}

	protected function get_fields($output=''){
		$fields	= $this->fields;

		if($fields && is_callable($fields)){
			$fields	= wpjam_try($fields, $this->name);
		}

		$fields	= $fields ?: [];

		return $output == 'object' ? wpjam_fields($fields) : $fields;
	}

	protected function get_submit_button($name=null, $render=null){
		$render	= $render ?? is_null($name);

		if(!is_null($this->submit_text)){
			$button	= $this->submit_text;

			if($button && is_callable($button)){
				$button	= wpjam_try($button, $this->name);
			}
		}else{
			$button = wp_strip_all_tags($this->page_title);
		}

		return $this->parse_submit_button($button, $name, $render);
	}

	public function generate_data_attr($args=[], $type='button'){
		$attr	= [
			'action'	=> $this->name,
			'nonce'		=> $this->create_nonce()
		];

		if($type == 'button'){
			$args	= wp_parse_args($args, ['data'=>[]]);
			$data	= $this->data ?: [];

			return array_merge($attr, [
				'title'		=> $this->page_title ?: $this->button_text,
				'data'		=> wp_parse_args($args['data'], $data),
				'direct'	=> $this->direct,
				'confirm'	=> $this->confirm
			]);
		}

		return $attr;
	}
}

class WPJAM_Plugin_Page extends WPJAM_Register{
	protected function include(){
		if(!$this->_included){
			$this->_included	= true;

			$key	= $this->page_type.'_file';
			$file	= $this->$key ?: [];

			foreach((array)$file as $_file){
				include $_file;
			}
		}
	}

	protected function tab_load($screen){
		$tabs	= $this->tabs ?: [];
		$tabs	= is_callable($tabs) ? call_user_func($tabs, $this->name) : $tabs;
		$tabs	= apply_filters(wpjam_get_filter_name($this->name, 'tabs'), $tabs);
		
		array_walk($tabs, 'wpjam_add_tab_page');

		$current_tab	= wp_doing_ajax() ? wpjam_get_post_parameter('current_tab') : wpjam_get_parameter('tab');
		$current_tab	= sanitize_key($current_tab);

		$tabs	= [];

		foreach(WPJAM_Tab_Page::get_registereds() as $name => $tab){
			if($tab->plugin_page && $tab->plugin_page != $this->name){
				continue;
			}

			if($tab->network === false && is_network_admin()){
				continue;
			}

			if($tab->capability){
				if($tab->map_meta_cap && is_callable($tab->map_meta_cap)){
					wpjam_register_capability($tab->capability, $tab->map_meta_cap);
				}

				if(!current_user_can($tab->capability)){
					continue;
				}
			}

			if(!$current_tab){
				$current_tab	= $name;
			}

			if($tab->query_args){
				$query_data	= wpjam_generate_query_data($tab->query_args);

				if($null_queries = array_filter($query_data, 'is_null')){
					if($current_tab == $name){
						wp_die('「'.implode('」,「', array_keys($null_queries)).'」参数无法获取');
					}else{
						continue;
					}
				}else{
					if($current_tab == $name){
						$GLOBALS['current_admin_url']	= add_query_arg($query_data, $GLOBALS['current_admin_url']);
					}
				}

				$tab->query_data	= $query_data;
			}

			$tabs[$name]	= $tab;
		}

		if(!$tabs){
			throw new WPJAM_Exception('Tabs 未设置');
		}

		$this->tabs	= $tabs;

		$GLOBALS['current_tab']			= $current_tab;
		$GLOBALS['current_admin_url']	= $GLOBALS['current_admin_url'].'&tab='.$current_tab;

		$tab_object	= $tabs[$current_tab] ?? null;

		if(!$tab_object){
			throw new WPJAM_Exception('无效的 Tab');
		}elseif(!$tab_object->function){
			throw new WPJAM_Exception('Tab 未设置 function');
		}elseif(!$tab_object->function == 'tab'){
			throw new WPJAM_Exception('Tab 不能嵌套 Tab');
		}

		$tab_object->page_hook	= $this->page_hook;
		$this->tab_object		= $tab_object;

		$tab_object->load($screen);
	}

	protected function append_nav_tab($tag, $tab_object){
		$title	= $tab_object->tab_title ?: $tab_object->title;
		$url	= $this->admin_url.'&tab='.$tab_object->name;
		$class	= ['nav-tab'];

		if($this->tab_object && $this->tab_object->name == $tab_object->name){
			$class[]	= 'nav-tab-active';
		}

		if($tab_object->query_data){
			$url	= add_query_arg($tab_object->query_data, $url);
		}

		return $tag->append('a', ['class'=>$class, 'href'=>$url], $title);
	}

	public function load($screen){
		if($this->function != 'tab'){
			$page_model	= 'WPJAM_Admin_Page';
			$page_name	= null;

			if(!$this->function){
				$this->function	= wpjam_get_filter_name($this->name, 'page');
			}elseif(is_string($this->function)){
				$function	= $this->function == 'list' ? 'list_table' : $this->function;

				if(in_array($function, ['option', 'list_table', 'form', 'dashboard'])){
					$page_model	= 'WPJAM_'.ucwords($function, '_').'_Page';
					$page_name	= $this->{$function.'_name'} ?: $GLOBALS['plugin_page'];
				}
			}

			$args	= wpjam_try([$page_model, 'preprocess'], $page_name, $this);
			$args	= ($args && is_array($args)) ? wpjam_parse_data_type($args) : [];

			if($args){
				$this->update_args($args);
			}

			if($this->data_type){
				$data_type	= $this->data_type;
				$type_value	= $this->$data_type;
				$object		= wpjam_get_data_type_object($data_type);
				$meta_type	= $object ? $object->get_meta_type($args) : '';

				$screen->add_option('data_type', $data_type);

				if($meta_type){
					$screen->add_option('meta_type', $meta_type);
				}

				if(in_array($data_type, ['post_type', 'taxonomy']) && $type_value && !$screen->$data_type){
					$screen->$data_type	= $type_value;
				}
			}
		}

		do_action('wpjam_plugin_page_load', $GLOBALS['plugin_page'], $this->load_arg);

		wpjam_admin_load($GLOBALS['plugin_page'], $this->load_arg);

		// 一般 load_callback 优先于 load_file 执行
		// 如果 load_callback 不存在，尝试优先加载 load_file
		if($this->load_callback){
			$load_callback	= $this->load_callback;

			if(!is_callable($load_callback)){
				$this->include();
			}

			if(is_callable($load_callback)){
				call_user_func($load_callback, $this->name);
			}
		}

		$this->include();

		if($this->chart){
			$this->chart	= WPJAM_Chart_Form::init($this->chart);
		}

		if($this->editor){
			add_action('admin_footer', 'wp_enqueue_editor');
		}

		$this->set_defaults();

		try{
			if($this->function == 'tab'){
				return $this->tab_load($screen);
			}

			$object	= wpjam_try([$page_model, 'create'], $page_name, $this);

			if(wp_doing_ajax()){
				return $object->load();
			}

			add_action('load-'.$this->page_hook, [$object, 'load']);

			$this->page_object	= $object;

			if($page_name){
				$this->page_title	= $object->title ?: $this->page_title;
				$this->subtitle		= $object->get_subtitle() ?: $this->subtitle;
				$this->summary		= $this->summary ?: $object->get_summary();
				$this->query_data	= $this->query_data ?: [];
				$this->query_data	+= wpjam_generate_query_data($object->query_args);
			}
		}catch(WPJAM_Exception $e){
			wpjam_add_admin_error($e->get_wp_error());
		}
	}

	public function render(){
		$page_title	= $this->page_title ?? $this->title;
		$summary	= $this->summary;

		if($this->tab_page){
			$tag	= wpjam_tag('h2', [], $page_title.$this->subtitle);
		}else{
			$tag	= wpjam_tag('h1', ['wp-heading-inline'], $page_title)->after($this->subtitle)->after('hr', ['wp-header-end']);
		}

		if($summary){
			if(is_callable($summary)){
				$summary	= call_user_func($summary, $GLOBALS['plugin_page'], $this->load_arg);
			}elseif(is_array($summary)){
				$summ_arr	= $summary;
				$summary	= $summ_arr[0];

				if(!empty($summ_arr[1])){
					$summary	.= '，详细介绍请点击：'.wpjam_tag('a', ['href'=>$summ_arr[1], 'target'=>'_blank'], $this->menu_title);
				}
			}elseif(is_file($summary)){
				$summary	= wpjam_get_file_summary($summary);
			}
		}

		$summary	.= get_screen_option($this->page_type.'_summary');

		if($summary){
			$tag->after($summary, 'p');
		}

		if($this->function == 'tab'){
			$callback	= wpjam_get_filter_name($GLOBALS['plugin_page'], 'page');

			if(is_callable($callback)){
				$tag->after(wpjam_ob_get_contents($callback));	// 所有 Tab 页面都执行的函数
			}

			if(count($this->tabs) > 1){
				$nav	= wpjam_tag('nav', ['nav-tab-wrapper', 'wp-clearfix']);
				$tag->after(array_reduce($this->tabs, [$this, 'append_nav_tab'], $nav));
			}

			if($this->tab_object){
				if($this->chart){
					$this->tab_object->page_object->chart	= $this->chart;
				}

				$tag->after($this->tab_object->render());
			}
		}else{
			$tag->after(wpjam_ob_get_contents([$this->page_object, 'render']));
		}

		if($this->tab_page){
			return $tag;
		}

		echo $tag->wrap('div', ['wrap']);
	}

	public function set_defaults($defaults=[]){
		$this->defaults	= $this->defaults ?: [];
		$this->defaults	= array_merge($this->defaults, $defaults);

		if($this->defaults){
			add_filter('wpjam_parameter_default', [$this, 'filter_parameter_default'], 10, 2);
		}
	}

	protected function preprocess_args($args){
		if(empty($args['tab_page'])){
			$args	= array_merge($args, [
				'page_type'	=> 'page',
				'load_arg'	=> '',
			]);
		}

		return parent::preprocess_args($args);
	}

	public static function get_current(){
		return self::get($GLOBALS['plugin_page']);
	}
}

class WPJAM_Tab_Page extends WPJAM_Plugin_Page{
	protected function preprocess_args($args){
		return parent::preprocess_args(array_merge($args, [
			'page_type'	=> 'tab',
			'tab_page'	=> true,
			'load_arg'	=> $this->name,
		]));
	}

	protected static function get_config($key){
		if($key == 'orderby'){
			return true;
		}elseif($key == 'model'){
			return false;
		}
	}
}

class WPJAM_Admin_Page extends WPJAM_Args{
	public function __call($method, $args){
		if($this->object && method_exists($this->object, $method)){
			return call_user_func_array([$this->object, $method], $args);
		}elseif(in_array($method, ['get_subtitle', 'get_summary'])){
			$key	= wpjam_remove_prefix($method, 'get_');

			return $this->$key;
		}
	}

	public function __get($key){
		if(empty($this->args['object']) || in_array($key, ['object', 'tab_page', 'chart'])){
			return parent::__get($key);
		}else{
			return $this->object->$key;
		}
	}

	public function load(){
	}

	public function render(){
		if($this->chart){
			echo $this->chart->render();
		}

		if(is_callable($this->function)){
			call_user_func($this->function);
		}
	}

	public static function preprocess($name, $menu){
		return [];
	}

	public static function create($name, $menu){
		if(!is_callable($menu->function)){
			return new WP_Error('invalid_menu_page', ['函数', $menu->function]);
		}

		return new self($menu->to_array());
	}
}

class WPJAM_Form_Page extends WPJAM_Admin_Page{
	public function render(){
		try{
			echo $this->get_form();
		}catch(WPJAM_Exception $e){
			wp_die($e->get_wp_error());
		}
	}

	public static function preprocess($name, $menu){
		$object	= WPJAM_Page_Action::get($name);

		if($object){
			return $object->to_array();
		}

		if($menu->form && is_callable($menu->form)){
			$menu->form	= call_user_func($menu->form, $name);
		}

		return $menu->form;
	}

	public static function create($name, $menu){
		$object	= WPJAM_Page_Action::get($name);

		if(!$object){
			$args	= self::preprocess($name, $menu);
			$args	= $args ?: ($menu->callback ? $menu->to_array() : []);

			if(!$args){
				return new WP_Error('invalid_menu_page', ['Page Action', $name]);
			}

			$object	= WPJAM_Page_Action::register($name, $args);
		}

		return new self(array_merge($menu->to_array(), ['object'=>$object]));
	}
}

class WPJAM_Option_Page extends WPJAM_Admin_Page{
	public function __get($key){
		$value	= parent::__get($key);

		return $key == 'object' ? $value->get_current() : $value;
	}

	public function load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-option-action',	[$this, 'ajax_response']);
		}else{
			add_action('admin_action_update', [$this, 'register_settings']);

			if(isset($_POST['response_type'])) {
				$message	= $_POST['response_type'] == 'reset' ? '设置已重置。' : '设置已保存。';

				wpjam_add_admin_error($message);
			}

			$this->register_settings();
		}
	}

	public function render(){
		echo $this->render_sections($this->tab_page);
	}

	public static function preprocess($name, $menu){
		$object	= WPJAM_Option_Setting::get($name);

		if($object){
			return $object->to_array();
		}

		if($menu->option && is_callable($menu->option)){
			$menu->option	= call_user_func($menu->option, $name);
		}

		return $menu->option;
	}

	public static function create($name, $menu){
		$object	= WPJAM_Option_Setting::get($name);

		if(!$object){
			if($menu->model && method_exists($menu->model, 'register_option')){	// 舍弃 ing
				$object	= call_user_func([$menu->model, 'register_option'], $menu->delete_arg('model')->to_array());
			}else{
				$args	= self::preprocess($name, $menu);
				$args	= $args ?: (($menu->sections || $menu->fields) ? $menu->to_array() : []);

				if(!$args){
					$args	= apply_filters(wpjam_get_filter_name($name, 'setting'), []); // 舍弃 ing

					if(!$args){
						return new WP_Error('invalid_menu_page', ['Option', $name]);
					}
				}

				$object	= WPJAM_Option_Setting::create($name, $args);
			}
		}

		return new self(array_merge($menu->to_array(), ['object'=>$object]));
	}
}

class WPJAM_List_Table_Page extends WPJAM_Admin_Page{
	public function load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-list-table-action',	[$this, 'ajax_response']);
		}elseif(wpjam_get_parameter('export_action')){
			$this->export_action();
		}else{
			$result = wpjam_call([$this, 'prepare_items']);

			if(is_wp_error($result)){
				wpjam_add_admin_error($result);
			}
		}
	}

	public function render(){
		if($this->chart){
			echo $this->chart->render();
		}

		$views	= wpjam_ob_get_contents([$this, 'views']);
		$form	= wpjam_ob_get_contents([$this, 'display']);

		if($this->is_searchable()){
			$form	= wpjam_ob_get_contents([$this, 'search_box'], '搜索', 'wpjam').$form;
		}

		$form	= wpjam_tag('form', ['action'=>'#', 'id'=>'list_table_form', 'method'=>'POST'], $form)->before($views);

		if($this->layout == 'left'){
			$form	= $form->wrap('div', ['list-table', 'col-wrap'])->wrap('div', ['id'=>'col-right']);
			$left	= wpjam_tag('div', ['left', 'col-wrap'], $this->get_col_left())->wrap('div', ['id'=>'col-left']);

			echo $form->before($left)->wrap('div', ['id'=>'col-container', 'class'=>'wp-clearfix']);
		}else{
			echo wpjam_tag('div', ['list-table', ($this->layout ? 'layout-'.$this->layout : '')], $form);
		}
	}

	public static function preprocess($name, $menu){
		$args	= wpjam_get_item('list_table', $name) ?: $menu->list_table;

		if($args){
			if(is_string($args) && class_exists($args) && method_exists($args, 'get_list_table')){
				$args	= [$args, 'get_list_table'];
			}

			if(is_callable($args)){
				$args	= call_user_func($args, $name);
			}

			return $menu->list_table = $args;
		}
	}

	public static function create($name, $menu){
		$args	= self::preprocess($name, $menu);

		if($args){
			if(isset($args['defaults'])){
				$menu->set_defaults($args['defaults']);
			}
		}else{
			if($menu->model){
				$args	= array_except($menu->to_array(), 'defaults');
			}else{
				$args	= apply_filters(wpjam_get_filter_name($name, 'list_table'), []);
			}

			if(!$args){
				return new WP_Error('invalid_menu_page', ['List Table', $name]);
			}
		}

		if(empty($args['model']) || !class_exists($args['model'])){
			return new WP_Error('invalid_menu_page', ['List Table 的 Model', $args['model']]);
		}

		foreach(['admin_head', 'admin_footer'] as $admin_hook){
			if(method_exists($args['model'], $admin_hook)){
				add_action($admin_hook,	[$args['model'], $admin_hook]);
			}
		}

		$args	= wp_parse_args($args, ['primary_key'=>'id', 'name'=>$name, 'singular'=>$name, 'plural'=>$name.'s', 'layout'=>'']);

		if($args['layout'] == 'left' || $args['layout'] == '2'){
			$args['layout']	= 'left';

			$object	= new WPJAM_Left_List_Table($args);
		}elseif($args['layout'] == 'calendar'){
			$args['query_args']	= $args['query_args'] ?? [];
			$args['query_args']	= array_merge($args['query_args'], ['year', 'month']);

			$object	= new WPJAM_Calendar_List_Table($args);
		}else{
			$object	= new WPJAM_List_Table($args);
		}

		return new self(array_merge($menu->to_array(), ['object'=>$object]));
	}
}

class WPJAM_Dashboard_Page extends WPJAM_Admin_Page{
	public function load(){
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		// wp_dashboard_setup();

		wp_enqueue_script('dashboard');

		if(wp_is_mobile()){
			wp_enqueue_script('jquery-touch-punch');
		}

		$widgets	= $this->widgets ?: [];
		$widgets	= is_callable($widgets) ? call_user_func($widgets, $this->name) : $widgets;

		wpjam_dashboard_widget($this->name, $widgets);
	}

	public function render(){
		$tag	= wpjam_tag('div', ['id'=>'dashboard-widgets-wrap'], wpjam_ob_get_contents('wp_dashboard'));

		if($this->welcome_panel && is_callable($this->welcome_panel)){
			$welcome_panel	= wpjam_ob_get_contents($this->welcome_panel, $this->name);

			$tag->before('div', ['id'=>'welcome-panel', 'class'=>'welcome-panel wpjam-welcome-panel'], $welcome_panel);
		}

		echo $tag;
	}

	public static function preprocess($name, $menu){
		return wpjam_get_item('dashboard', $name) ?: $menu->dashboard;
	}

	public static function create($name, $menu){
		$args	= self::preprocess($name, $menu);
		$args	= $args ?: ($menu->widgets ? $menu->to_array() : []);

		if(!$args){
			return new WP_Error('invalid_menu_page', ['Dashboard', $name]);
		}

		return new self(array_merge($args, ['name'=>$name]));
	}
}

class WPJAM_Builtin_Page{
	protected $screen;

	protected function __construct($screen){
		$this->screen	= $screen;
	}

	public function __get($key){
		$screen	= $this->screen;

		if(isset($screen->$key)){
			return $screen->$key;
		}else{
			$object	= $screen->get_option('object');

			return $object ? $object->$key : null;
		}
	}

	public function __call($method, $args){
		$object	= $this->screen->get_option('object');

		if($object){
			return call_user_func_array([$object, $method], $args);
		}
	}

	public static function init($screen){
		$admin_url	= set_url_scheme('http://'.$_SERVER['HTTP_HOST'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

		if($GLOBALS['plugin_page']){
			$admin_url	= add_query_arg(['page' => $GLOBALS['plugin_page']], $admin_url);
		}else{
			$args	= [];

			foreach(['taxonomy', 'post_type'] as $key){
				if($screen->$key && isset($_REQUEST[$key])){
					$args[$key]	= $_REQUEST[$key];
				}
			}

			if($args){
				$admin_url	= add_query_arg($args, $admin_url);
			}
		}

		$GLOBALS['current_admin_url']	= $admin_url;

		if(in_array($screen->base, ['edit', 'upload', 'post', 'term', 'edit-tags'])){
			if(in_array($screen->base, ['edit', 'upload', 'post'])){
				$object	= wpjam_get_post_type_object($screen->post_type);
			}elseif(in_array($screen->base, ['term', 'edit-tags'])){
				$object	= wpjam_get_taxonomy_object($screen->taxonomy);
			}

			if(!$object){
				return;
			}

			$screen->add_option('object', $object);
		}

		wpjam_add_admin_load([
			[
				'base'	=> 'post',
				'model'	=> 'WPJAM_Post_Builtin_Page'
			],
			[
				'base'	=> ['edit', 'upload'],
				'model'	=> 'WPJAM_Posts_List_Table'
			],
			[
				'base'	=> 'users',
				'model'	=> 'WPJAM_Users_List_Table'
			],
			[
				'base'	=> ['term', 'edit-tags'],
				'model'	=> 'WPJAM_Term_Builtin_Page'
			],
			[
				'base'	=> 'edit-tags',
				'model'	=> 'WPJAM_Terms_List_Table'
			],
		]);

		wpjam_admin_load($screen);
	}

	public static function load($screen){
		return new static($screen);
	}
}

class WPJAM_Post_Builtin_Page extends WPJAM_Builtin_Page{
	protected function __construct($screen){
		parent::__construct($screen);

		$edit_form_hook	= $GLOBALS['typenow'] == 'page' ? 'edit_page_form' : 'edit_form_advanced';

		add_action($edit_form_hook,			[$this, 'on_edit_form'], 99);
		add_action('add_meta_boxes',		[$this, 'on_add_meta_boxes']);
		add_action('wp_after_insert_post',	[$this, 'on_after_insert_post'], 999, 2);

		add_filter('post_updated_messages',		[$this, 'filter_updated_messages']);
		add_filter('redirect_post_location',	[$this, 'filter_redirect_location']);
		add_filter('admin_post_thumbnail_html',	[$this, 'filter_admin_thumbnail_html']);
	}

	public function on_edit_form($post){	// 下面代码 copy 自 do_meta_boxes
		$meta_boxes		= $GLOBALS['wp_meta_boxes'][$this->id]['wpjam'] ?? [];
		$tab_title		= wpjam_tag('ul');
		$tab_content	= wpjam_tag('div', ['inside']);
		$tab_count		= 0;

		foreach(wp_array_slice_assoc($meta_boxes, ['high', 'core', 'default', 'low']) as $_meta_boxes){
			foreach((array)$_meta_boxes as $meta_box){
				if(empty($meta_box['id']) || empty($meta_box['title'])){
					continue;
				}

				$tab_count++;

				$meta_id	= 'tab_'.$meta_box['id'];

				$tab_title->append('li', [], wpjam_tag('a', ['class'=>'nav-tab', 'href'=>'#'.$meta_id], $meta_box['title']));
				$tab_content->append('div', ['id'=>$meta_id], wpjam_ob_get_contents($meta_box['callback'], $post, $meta_box));
			}
		}

		if(!$tab_count){
			return;
		}

		if($tab_count == 1){
			$tab_title	= wpjam_tag('h2', ['hndle'], strip_tags($tab_title))->wrap('div', ['postbox-header']);
		}else{
			$tab_title->wrap('h2', ['nav-tab-wrapper']);
		}

		echo $tab_title->after($tab_content)->wrap('div', ['id'=>'wpjam', 'class'=>['postbox','tabs']])->wrap('div', ['id'=>'wpjam-sortables']);
	}

	public function meta_box_cb($post, $meta_box){
		$object	= array_shift($meta_box['args']);
		$id		= $GLOBALS['current_screen']->action == 'add' ? false : $post->ID;
		$type	= $object->context == 'side' ? 'list' : 'table';

		echo $object->summary ? wpautop($object->summary) : '';

		$object->render($id, ['fields_type'=>$type]);
	}

	public function on_add_meta_boxes($post_type){
		$context	= use_block_editor_for_post_type($post_type) ? 'normal' : 'wpjam';

		foreach(wpjam_get_post_options($post_type, ['list_table'=>false]) as $object){
			$context	= $object->context ?: $context;
			$callback	= $object->meta_box_cb ?: [$this, 'meta_box_cb'];

			add_meta_box($object->name, $object->title, $callback, $post_type, $context, $object->priority, [$object]);
		}
	}

	public function on_after_insert_post($post_id, $post){
		// 只有 POST 方法提交才处理，自动草稿、自动保存和预览情况下不处理
		if($_SERVER['REQUEST_METHOD'] != 'POST'
			|| $post->post_status == 'auto-draft'
			|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
			|| (!empty($_POST['wp-preview']) && $_POST['wp-preview'] == 'dopreview')
		){
			return;
		}

		foreach(wpjam_get_post_options($this->post_type, ['list_table'=>false]) as $object){
			$result	= $object->callback($post_id);

			wpjam_die_if_error($result);
		}
	}

	public function filter_updated_messages($messages){
		$key	= $this->hierarchical ? 'page' : 'post';

		if(isset($messages[$key])){
			$search		= $key == 'post' ? '文章':'页面';
			$replace	= $this->labels->name;

			foreach($messages[$key] as &$message){
				$message	= str_replace($search, $replace, $message);
			}
		}

		return $messages;
	}

	public function filter_admin_thumbnail_html($content){
		$size	= $this->thumbnail_size;

		return $content.($size ? wpautop('尺寸：'.$size) : '');
	}

	public function filter_redirect_location($location){
		if(parse_url($location, PHP_URL_FRAGMENT)){
			return $location;
		}

		if($fragment = parse_url(wp_get_referer(), PHP_URL_FRAGMENT)){
			return $location.'#'.$fragment;
		}

		return $location;
	}
}

class WPJAM_Term_Builtin_Page extends WPJAM_Builtin_Page{
	protected function __construct($screen){
		parent::__construct($screen);

		add_filter('term_updated_messages',	[$this, 'filter_updated_messages']);

		if($this->base == 'edit-tags'){
			if(wp_doing_ajax()){
				if($_POST['action'] == 'add-tag'){
					add_filter('pre_insert_term',	[$this, 'filter_pre_insert'], 10, 2);
					add_action('created_term',		[$this, 'on_created'], 10, 3);
				}
			}else{
				add_action('edited_term',	[$this, 'on_edited'], 10, 3);
			}

			add_action($GLOBALS['taxnow'].'_add_form_fields',	[$this, 'on_add_form_fields']);
		}else{
			add_action($GLOBALS['taxnow'].'_edit_form_fields',	[$this, 'on_edit_form_fields']);
		}
	}

	public function get_form_fields($action, $args){
		foreach(wpjam_get_term_options($this->taxonomy, ['action'=>$action, 'list_table'=>false]) as $object){
			$object->render($args['id'], wp_parse_args($args, $object->to_array()));
		}
	}

	protected function update_data($action, $term_id=null){
		foreach(wpjam_get_term_options($this->taxonomy, ['action'=>$action, 'list_table'=>false]) as $object){
			$result	= $term_id ? $object->callback($term_id) : $object->validate();

			wpjam_die_if_error($result);
		}

		return true;
	}

	public function on_add_form_fields($taxonomy){
		$this->get_form_fields('add', [
			'fields_type'	=> 'div',
			'wrap_class'	=> 'form-field',
			'id'			=> false,
		]);
	}

	public function on_edit_form_fields($term){
		$this->get_form_fields('edit', [
			'fields_type'	=> 'tr',
			'wrap_class'	=> 'form-field',
			'id'			=> $term->term_id,
		]);
	}

	public function on_created($term_id, $tt_id, $taxonomy){
		if($taxonomy == $this->taxonomy){
			$this->update_data('add', $term_id);
		}
	}

	public function on_edited($term_id, $tt_id, $taxonomy){
		if($taxonomy == $this->taxonomy){
			$list_table	= wpjam_get_builtin_list_table('WP_Terms_List_Table');

			if($list_table->current_action() == 'editedtag'){
				$this->update_data('edit', $term_id);
			}
		}
	}

	public function filter_pre_insert($term, $taxonomy){
		if($taxonomy == $this->taxonomy){
			$this->update_data('add');
		}

		return $term;
	}

	public function filter_updated_messages($messages){
		if(!in_array($this->taxonomy, ['post_tag', 'category'])){
			$label	= $this->labels->name;

			foreach($messages['_item'] as $key => $message){
				$messages[$this->taxonomy][$key]	= str_replace(['项目', 'Item'], [$label, ucfirst($label)], $message);
			}
		}

		return $messages;
	}
}

class WPJAM_Chart extends WPJAM_Args{
	public function line($args=[], $type='Line'){
		$this->update_args(wp_parse_args($args, [
			'data'			=> [],
			'labels'		=> [],
			'day_key'		=> 'day',
			'day_label'		=> '时间',
			'day_labels'	=> [],
			'show_table'	=> true, 
			'show_sum'		=> true,
			'show_avg'		=> true,
		]));

		$chart_id	= $this->chart_id ?: 'daily-chart';
		$show_table	= $this->show_table;
		$keys		= $labels = [];

		foreach($this->labels as $key => $label){
			if(strpos($label,'%') === false && strpos($label,'#') === false){ // %,# 数据不写入 Chart
				$keys[]		= $key;
				$labels[]	= $label;
			}
		}

		$data	= $total = [];

		if($show_table){ 
			$thead	= wpjam_tag('thead')->append($this->day_row('head'));
			$tbody	= wpjam_tag('tbody');
		}

		foreach($this->data as $day => $counts){
			$counts	= (array)$counts;
			$key	= $this->day_key;
			$day	= $counts[$key] ?? $day;

			if(strpos($day, '%') === false && strpos($day, '#') === false){
				$label	= $this->day_labels[$day] ?? $day;
				$item 	= [$key => $label];

				foreach($keys as $key){
					$count		= $counts[$key] ?? 0;
					$item[$key]	= $count;

					if(is_numeric($count)){
						$total[$key]	= $total[$key] ?? 0;
						$total[$key]	+= $count;
					}
				}

				$data[]	= $item;	
			}

			if($show_table){
				$tbody->append($this->day_row($day, (array)$counts, true));
			}
		}

		$options	= ['xkey'=>$this->day_key, 'ykeys'=>$keys, 'data'=>$data, 'labels'=>$labels];
		$chart		= wpjam_tag('div', ['class'=>'chart', 'id'=>$chart_id, 'data'=>['type'=>$type, 'options'=>$options]]);

		if($show_table && count($this->data) > 1){
			foreach(['sum', 'avg'] as $key){
				if($this->{'show_'.$key}){
					$this->day_row($key, $total)->append_to($tbody);
				}
			}

			$thead->after($tbody)->wrap('table', ['class'=>'wp-list-table widefat striped'])->insert_after($chart);
		}

		echo $chart;
	}

	public function bar($args=[]){

	}

	public function donut($args=[]){
		$this->update_args(wp_parse_args($args, [
			'data'			=> [],
			'total'			=> 0,
			'title'			=> '名称',
			'key'			=> 'type',
			'total_link'	=> $GLOBALS['current_admin_url'],
			'show_table'	=> true,
			'show_line_num'	=> false,
			'show_link'		=> false,
			'labels'		=> []
		]));

		$show_table	= $this->show_table;
		$chart_id	= $this->chart_id ?: 'chart_'.wp_generate_password(6, false, false);
		$summary	= [];

		if($show_table){
			$thead	= wpjam_tag('thead')->append($this->summary_row('head'));
			$tbody	= wpjam_tag('tbody');
		}

		foreach(array_values($this->data) as $i => $count){
			$count	= (array)$count;
			$label 	= $count['label'] ?? '/';
			$link	= $count['link'] ?? '';

			if($this->show_link){
				$value	= $count[$this->key] ?? $label;
				$link	= $this->total_link.'&'.$this->key.'='.$value;
			}else{
				$link	= '';
			}

			$label 		= $this->labels[$label] ?? $label;
			$count		= $count['count'];
			$summary[]	= ['label'=>$label, 'value'=>$count];

			if($show_table){
				$this->summary_row($i+1, $count, $label, $link)->append_to($tbody);
			}
		}

		$chart	= wpjam_tag('div', ['class'=>'chart', 'id'=>$chart_id, 'data'=>['options'=>['data'=>$summary], 'type'=>'Donut']]);

		if($show_table){
			if($this->total){
				$this->summary_row('total')->append_to($tbody);
			}

			$thead->after($tbody)->wrap('table', ['wp-list-table', 'widefat', 'striped'])->insert_after($chart);
		}

		echo $chart->wrap('div', ['class'=>'donut-chart-wrap']);
	}

	protected function day_row($day, $counts=[], $day_row=false){
		if($day_row){
			$type	= '';
			$label	= $this->day_labels[$day] ?? $day;
		}else{
			$type	= $day;

			if($type == 'sum'){
				$label	= '累加';
			}elseif($type == 'avg'){
				$label	= '平均';
				$number	= count($this->data);
			}
		}

		$row	= wpjam_tag('tr');

		if($type == 'head'){
			$row->append($this->day_label, 'th', ['scope'=>'col', 'id'=>$this->day_key, 'class'=>['column-'.$this->day_key, 'column-primary']]);
		}else{
			$toggle	= wpjam_tag('button', ['type'=>'button', 'class'=>'toggle-row'], ['显示详情', 'span', ['screen-reader-text']]);

			$row->append($label.$toggle, 'td', ['class'=>['column-'.$this->day_key, 'column-primary'],  'data-colname'=>$this->day_label]);
		}
		
		foreach($this->labels as $key => $label){ 
			if($type == 'head'){
				$row->append($label, 'th', ['scope'=>'col',	'id'=>$key,	'class'=>['column-'.$this->day_key]]);
			}else{
				$count	= $counts[$key] ?? 0;

				if($type == 'avg'){
					$count	= $count ? round($count/$number) : '';
				}

				$row->append($count, 'td', ['class'=>['column-'.$key], 'data-colname'=>$label]);
			}
		}

		return $row;
	}

	protected function summary_row($i='total', $count=0, $label='', $link=''){
		if(is_numeric($i)){
			if($this->total){
				$rate	= round($count/$this->total*100, 2);
			}
		}elseif($i == 'total'){
			$label	= '所有';
			$link	= $this->show_link ? $this->total_link : '';
			$rate	= 100;
		}

		$row	= wpjam_tag('tr');

		if($this->show_line_num){
			if($i === 'head'){
				$row->append('排名', 'th', ['style'=>'width:40px;']);
			}else{
				$row->append($i, 'td');
			}
		} 

		if($i === 'head'){
			$row->append($this->title, 'th')->append('数量', 'th');
		}else{
			$row->append(($link ? '<a href="'.$link.'">'.$label.'</a>' : $label), 'td')->append($count, 'td');
		}

		if($this->total){
			if($i === 'head'){
				$row->append('比例', 'th');
			}else{
				$row->append($rate.'%', 'td');
			}
		}

		return $row;
	}

	public static function form(){
		echo (WPJAM_Chart_Form::get_instance())->render();
	}

	public static function init($args=[]){
		WPJAM_Chart_Form::init($args);
	}
}

class WPJAM_Chart_Form extends WPJAM_Singleton{
	public function get_parameter($key){
		if(str_contains($key, 'timestamp')){
			$date_key	= str_replace('timestamp', 'date', $key);
			$postfix	= str_starts_with($key, 'end_') ? '23:59:59' : '00:00:00';
			$value		= $this->get_parameter($date_key).' '.$postfix;

			return wpjam_strtotime($value);
		}elseif($key == 'date_format'){
			$date_type	= $this->get_parameter('date_type') ?: '按天';

			return $this->get_date_format($date_type);
		}

		$value	= wpjam_get_post_parameter($key);

		if($value){
			wpjam_set_cookie($key, $value, HOUR_IN_SECONDS);
		}else{
			$value	= $_COOKIE[$key] ?? null;

			if(!$value){
				if($key == 'start_date'){
					$value	= wpjam_date('Y-m-d', time() - DAY_IN_SECONDS*30);
				}elseif($key == 'end_date'){
					$value	= wpjam_date('Y-m-d', time());
				}elseif($key == 'date'){
					$value	= wpjam_date('Y-m-d', time() - DAY_IN_SECONDS);
				}elseif($key == 'start_date_2'){
					$start	= $this->get_parameter('start_timestamp');
					$diff	= $this->get_parameter('end_timestamp') - $start;
					$value	= wpjam_date('Y-m-d', $start - DAY_IN_SECONDS - $diff);
				}elseif($key == 'end_date_2'){
					$start	= $this->get_parameter('start_timestamp');
					$value	= wpjam_date('Y-m-d', $start - DAY_IN_SECONDS);
				}elseif($key == 'date_type'){
					$value	= '按天';
				}elseif($key == 'compare'){
					$value	= 0;
				}
			}
		}

		if($key == 'date_type'){
			$value	= $value == '显示' ? '按天' : $value;
		}

		return $value;
	}

	protected function get_date_format($name=null){
		$formats	= [
			'按分钟'	=> '%Y-%m-%d %H:%i',
			'按小时'	=> '%Y-%m-%d %H:00',
			'按天'	=> '%Y-%m-%d',
			'按周'	=> '%Y%U',
			'按月'	=> '%Y-%m'
		];

		return $name ? array_get($formats, $name) : $formats;
	}

	public function render(){
		if(!$this->show_form){
			return;
		}

		$current	= wpjam_get_parameter('type', ['default'=>-1]);
		$current	= $current == 'all' ? '-1' : $current;
		$fields		= [];

		if($this->show_start_date){
			$fields['date_view']	= ['type'=>'view',	'value'=>'日期：'];
			$fields['start_date']	= ['type'=>'date',	'value'=>$this->get_parameter('start_date')];
			$fields['sep_view']		= ['type'=>'view',	'value'=>'-'];
			$fields['end_date']		= ['type'=>'date',	'value'=>$this->get_parameter('end_date')];
		}else{
			$fields['date']			= ['type'=>'date',	'value'=>$this->get_parameter('date')];
		}

		if($this->show_date_type){
			foreach($this->get_date_format() as $date_type => $date_format){
				$class	= $this->get_parameter('date_type') == $date_type ? 'button button-primary' : 'button';

				$fields['date_type_'.$date_type]	= ['type'=>'submit',	'name'=>'date_type',	'value'=>$date_type,	'class'=>$class];
			}
		}else{
			$fields['date_type']	= ['type'=>'submit', 'name'=>'date_type', 'value'=>'显示', 'class'=>'button button-secondary'];
		}

		if($current !=-1 && $this->show_start_date && $this->show_compare){
			$fields['date_view_2']	= ['type'=>'view',		'value'=>'对比： '];
			$fields['start_date_2']	= ['type'=>'date',		'value'=>$this->get_parameter('start_date_2')];
			$fields['sep_view']		= ['type'=>'view',		'value'=>'-'];
			$fields['end_date_2']	= ['type'=>'date',		'value'=>$this->get_parameter('end_date_2')];
			$fields['compare']		= ['type'=>'checkbox',	'value'=>$this->get_parameter('compare')];
		}

		$fields	= apply_filters('wpjam_chart_fields', $fields);
		$action	= $GLOBALS['current_admin_url'];
		$action	= $current == -1 ? $action : $action.'&type='.$current;

		return wpjam_fields($fields)->render(['fields_type'=>''])
			->wrap('form', ['method'=>'POST', 'action'=>$action, 'target'=>'_self', 'class'=>'chart-form'])
			->after('div', ['clear']);
	}

	public static function init($args=[]){
		$object	= parent::get_instance();
		$args	= $args ?: [];
	
		$object->update_args(wp_parse_args($args, [ 
			'show_form'			=> true,
			'show_date_type'	=> false,
			'show_compare'		=> false,
			'show_start_date'	=> true
		]));

		$offset	= (int)get_option('gmt_offset');
		$offset	= $offset >= 0 ? '+'.$offset.':00' : $offset.':00';

		$GLOBALS['wpdb']->query("SET time_zone = '{$offset}';");

		wp_enqueue_style('morris',		'https://cdn.staticfile.org/morris.js/0.5.1/morris.css');
		wp_enqueue_script('raphael',	'https://cdn.staticfile.org/raphael/2.3.0/raphael.min.js');
		wp_enqueue_script('morris',		'https://cdn.staticfile.org/morris.js/0.5.1/morris.min.js', ['raphael']);

		return $object;
	}
}