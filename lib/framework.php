<?php

/* base configuration */
abstract class Hm_Config {

    protected $source = false;
    protected $config = array();

    abstract public function load($source);
    abstract public function dump();
    abstract public function set($name, $value);
    abstract public function get($name, $default=false);
}

/* file based configuration */
class Hm_Config_File extends Hm_Config {

    public function __construct($source) {
        $this->load($source);
    }

    public function load($source) {
        if (is_readable($source)) {
            $data = unserialize(file_get_contents($source));
            if ($data) {
                $this->config = array_merge($this->config, $data);
            }
        }
    }

    public function dump() {
        return $this->config;
    }

    public function set($name, $value) {
        $this->config[$name] = $value;
    }

    public function get($name, $default=false) {
        return isset($this->config[$name]) ? $this->config[$name] : $default;
    }
}

/* handle page processing delegation */
class Hm_Router {

    public $type = false;
    public $sapi = false;
    private $page = 'home';

    public function process_request($config) {
        $filters = $this->load_modules($config);
        $request = new Hm_Request($filters);
        $this->get_page($request, $filters['allowed_pages']);
        $session = $this->setup_session($config);
        $result = $this->merge_response($this->process_page($request, $session, $config), $request, $session);
        $prior_results = $this->forward_redirect_data($session, $request);
        $result = array_merge($result, $prior_results);
        $this->check_for_redirect($request, $session, $result);
        $session->end();
        return $result;
    }

    private function setup_session($config) {
        switch ($config->get('session_type', false)) {
            case 'DB_auth':
                Hm_Debug::add('Using DB Authed session');
                require 'lib/pbkdf2.php';
                $session = new Hm_Session_PHP_DB_Auth();
                break;
            default:
                Hm_Debug::add('No session_type defined, using default PHP sessions');
                $session = new Hm_Session_PHP();
                break;
        }
        return $session;
    }

    private function merge_filters($existing, $new) {
        foreach (array('allowed_get', 'allowed_cookie', 'allowed_post', 'allowed_server', 'allowed_pages') as $v) {
            if (isset($new[$v])) {
                if ($v == 'allowed_pages') {
                    $existing[$v] = array_merge($existing[$v], $new[$v]);
                }
                else {
                    $existing[$v] += $new[$v];
                }
            }
        }
        return $existing;
    }

    private function load_modules($config) {
        $mod_list = explode(',', $config->get('modules', ''));
        $filters = array('allowed_get' => array(), 'allowed_cookie' => array(), 'allowed_post' => array(), 'allowed_server' => array(), 'allowed_pages' => array());
        foreach ($mod_list as $mod) {
            if (preg_match("/^[a-z_]{3,}$/", $mod)) {
                foreach (array('handler_modules', 'output_modules', 'module_map') as $name) {
                    if (is_readable(sprintf("modules/%s/%s.php", $mod, $name))) {
                        if ($name == 'module_map') {
                            $filters = $this->merge_filters($filters, require sprintf("modules/%s/%s.php", $mod, $name));
                        }
                        else {
                            require sprintf("modules/%s/%s.php", $mod, $name);
                        }
                    }
                }
            }
            else {
                Hm_Debug::add(sprintf("Invalid module name: %s", $mod));
            }
        }
        return $filters;
    }

    private function forward_redirect_data($session, $request) {
        $res = $session->get('redirect_result', array());
        $redirect_msgs = $session->get('redirect_messages', array());
        $session->del('redirect_result');
        if (!empty($redirect_msgs)) {
            array_walk($redirect_msgs, function($v) { Hm_Msgs::add($v); });
            $session->del('redirect_messages');
        }
        return $res;
    }

    private function check_for_redirect($request, $session, $result) {
        if (!empty($request->post) && $request->type == 'HTTP' && $session->active) {
            $msgs = Hm_Msgs::get();
            if (!empty($msgs)) {
                $session->set('redirect_messages', $msgs);
            }
            $session->set('redirect_result', $result);
            $session->end();
            $this->redirect($request->server['REQUEST_URI']);
        }
    }

    private function get_page($request, $pages) {
        if ($request->type == 'AJAX' && isset($request->post['hm_ajax_hook']) && in_array($request->post['hm_ajax_hook'], $pages)) {
            $this->page = $request->post['hm_ajax_hook'];
        }
        elseif (isset($request->get['page']) && in_array($request->get['page'], $pages)) {
            $this->page = $request->get['page'];
        }
        elseif (!isset($request->get['page'])) {
            $this->page = 'home';
        }
        else {
            $this->page = 'notfound';
        }
    }

    private function process_page($request, $session, $config) {
        $response = array();
        $handler = new Hm_Request_Handler();
        $response = $handler->process_request($this->page, $request, $session, $config);
        return $response;
    }

    private function merge_response($response, $request, $session) {
        return array_merge($response, array(
            'router_page_name'    => $this->page,
            'router_request_type' => $request->type,
            'router_sapi_name'    => $request->sapi,
            'router_format_name'  => $request->format,
            'router_login_state'  => $session->active
        ));
    }

    public function redirect($url) {
        header('HTTP/1.1 303 Found');
        header('Location: '.$url);
        exit;
    }
}

/* data request details */
class Hm_Request {

    public $post = array();
    public $get = array();
    public $cookie = array();
    public $server = array(); 
    public $type = false;
    public $sapi = false;
    public $format = false;

    public function __construct($filters) {
        $this->sapi = php_sapi_name();
        $this->get_request_type();

        if ($this->type == 'HTTP' || $this->type == 'AJAX') {
            $this->server = filter_input_array(INPUT_SERVER, $filters['allowed_server'], false);
            $this->post = filter_input_array(INPUT_POST, $filters['allowed_post'], false);
            $this->get = filter_input_array(INPUT_GET, $filters['allowed_get'], false);
            $this->cookie = filter_input_array(INPUT_COOKIE, $filters['allowed_cookie'], false);
        }
        if ($this->type == 'CLI') {
            $this->fetch_cli_vars();
        }
    }

    private function fetch_cli_vars() {
        global $argv;
        if (empty($this->get) && empty($this->post)) {
            if (isset($argv) && !empty($argv)) {
                foreach($argv as $val) {
                    if (strstr($val, '=')) {
                        $arg_parts = explode('=', $val, 2);
                        $this->get[$arg_parts[0]] = $arg_parts[1];
                    }
                }
            }
        }
    }

    private function get_request_type() {
        if ($this->is_cli()) {
            $this->type = 'CLI';
            $this->format = 'Hm_Format_Terminal';
        }
        elseif ($this->is_ajax()) {
            $this->type = 'AJAX';
            $this->format = 'Hm_Format_JSON';
        }
        else {
            $this->type = 'HTTP';
            $this->format = 'Hm_Format_HTML5';
        }
    }

    private function is_cli() {
        return strtolower(php_sapi_name()) == 'cli';
    }

    private function is_ajax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

/* request detail */
class Hm_Request_Handler {

    public $page = false;
    public $request = false;
    public $session = false;
    public $config = false;
    public $response = array();
    private $modules = array();

    public function process_request($page, $request, $session, $config) {
        $this->page = $page;
        $this->request = $request;
        $this->session = $session;
        $this->config = $config;
        $this->modules = Hm_Handler_Modules::get_for_page($page);
        $this->run_modules();
        $this->default_language();
        return $this->response;

    }

    private function default_language() {
        if (!isset($this->response['language'])) {
            $default_lang = $this->config->get('default_language', false);
            if ($default_lang) {
                $this->response['language'] = $default_lang;
            }
        }
    }

    protected function run_modules() {
        foreach ($this->modules as $name => $args) {
            $input = false;
            $name = "Hm_Handler_$name";
            if (class_exists($name)) {
                if (!$args['logged_in'] || ($args['logged_in'] && $this->session->active)) {
                    $mod = new $name( $this, $args['logged_in'], $args['args'] );
                    $input = $mod->process($this->response);
                }
            }
            else {
                Hm_Debug::add(sprintf('Handler module %s activated but not found', $name));
            }
            if ($input) {
                $this->response = $input;
            }
        }
    }
}

/* base class for output formatting */
abstract class HM_Format {

    protected $modules = false;

    abstract protected function content($input, $lang_str);

    public function format_content($input) {
        $lang_strings = array();
        if (isset($input['language'])) {
            $lang_strings = $this->get_language($input['language']);
        }
        $this->modules = Hm_Output_Modules::get_for_page($input['router_page_name']);
        $formatted = $this->content($input, $lang_strings);
        return $formatted;
    }

    private function get_language($lang) {
        $strings = array();
        if (file_exists('language/'.$lang.'.php')) {
            $strings = require 'language/'.$lang.'.php';
        }
        return $strings;
    }
    protected function run_modules($input, $format, $lang_str) {
        $mod_output = array();
        foreach ($this->modules as $name => $args) {
            $name = "Hm_Output_$name";
            if (class_exists($name)) {
                if (!$args['logged_in'] || ($args['logged_in'] && $input['router_login_state'])) {
                    $mod = new $name();
                    if ($format == 'JSON') {
                        $mod_output = $mod->output_content($input, $format, $lang_str);
                        if ($mod_output) {
                            $input = $mod_output;
                        }
                    }
                    else {
                        $mod_output[] = $mod->output_content($input, $format, $lang_str);
                    }
                }
            }
            else {
                Hm_Debug::add(sprintf('Output module %s activated but not found', $name));
            }
        }
        return $mod_output;
    }
}

/* JSON output format */
class Hm_Format_JSON extends HM_Format {

    public function content($input, $lang_str) {
        $input['router_user_msgs'] = Hm_Msgs::get();
        $output = $this->run_modules($input, 'JSON', $lang_str);
        return json_encode($output, JSON_FORCE_OBJECT);
    }
}

/* HTML5 output format */
class Hm_Format_HTML5 extends HM_Format {

    public function content($input, $lang_str) {
        $output = $this->run_modules($input, 'HTML5', $lang_str);
        return implode('', $output);
    }
}

/* CLI compatible output format */
class Hm_Format_Terminal extends HM_Format {

    public function content($input, $lang_str) {
        return implode('', $this->run_modules($input, 'CLI', $lang_str));
    }
}

/* base output class */
abstract class Hm_Output {

    abstract protected function output_content($content);

    public function send_response($response, $input=array()) {
        if (isset($input['http_headers'])) {
            $this->output_content($response, $input['http_headers']);
        }
        else {
            $this->output_content($response);
        }
    }

}

/* HTTP output class */
class Hm_Output_HTTP extends Hm_Output {

    protected function output_headers($headers) {
        foreach ($headers as $header) {
            header($header);
        }
    }

    protected function output_content($content, $headers=array()) {
        $this->output_headers($headers);
        //ob_end_clean();
        echo $content;
    }
}

/* STDOUT output class */
class Hm_Output_STDOUT extends Hm_Output {

    protected function output_content($content) {
        $stdout = fopen('php://stdout', 'w');
        fwrite($stdout, $content);
        fclose($stdout);
    }
}

/* file output class */
class Hm_Output_File extends Hm_Output {

    public $filename = 'test.out';

    protected function output_content($content) {
        $fh = fopen($this->filename, 'a');
        fwrite($fh, $content);
        fclose($fh);
    }
}

/* output sanitizing */
trait Hm_Sanitize {

    protected function html_safe($string) {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/* interface and debug mssages */
trait Hm_List {

    private static $msgs = array();

    public static function add($string) {
        self::$msgs[] = $string;
    }

    public static function get() {
        return self::$msgs;
    }

    public static function show($log=false) {
        if ($log) {
            error_log(str_replace(array("\n", "\t", "  "), array(' '), print_r(self::$msgs, true)));
        }
        else {
            print_r(self::$msgs);
        }
    }
}
class Hm_Msgs { use Hm_List; }
class Hm_Debug { use Hm_List;

    public static function load_page_stats() {
        self::add(sprintf("PHP version %s", phpversion()));
        self::add(sprintf("Zend version %s", zend_version()));
        self::add(sprintf("Peak Memory: %d", (memory_get_peak_usage(true)/1024)));
        self::add(sprintf("PID: %d", getmypid()));
        self::add(sprintf("Included files: %d", count(get_included_files())));
    }

}

/* base handler module */
abstract class Hm_Handler_Module {

    protected $session = false;
    protected $request = false;
    protected $config = false;
    protected $page = false;

    public function __construct($parent, $logged_in, $args) {
        $this->session = $parent->session;
        $this->request = $parent->request;
        $this->config = $parent->config;
        $this->page = $parent->page;
    }

    protected function process_form($form) {
        $post = $this->request->post;
        $success = false;
        $new_form = array();
        foreach($form as $name) {
            if (isset($post[$name]) && (trim($post[$name]) || $post[$name] === 0)) {
                $new_form[$name] = $post[$name];
            }
        }
        if (count($form) == count($new_form)) {
            $success = true;
        }
        return array($success, $new_form);
    }

    abstract public function process($data);
}

/* base output module */
abstract class Hm_Output_Module {

    use Hm_Sanitize;

    protected $lstr = array();
    protected $lang = false;

    abstract protected function output($input, $format);

    protected function trans($string) {
        if (isset($this->lstr[$string])) {
            if ($this->lstr[$string] === false) {
                return $string;
            }
            else {
                return $this->lstr[$string];
            }
        }
        else {
            Hm_Debug::add(sprintf('No translation found: %s', $string));
        }
        return $string;
    }

    public function output_content($input, $format, $lang_str) {
        $this->lstr = $lang_str;
        if (isset($lang_str['interface_lang'])) {
            $this->lang = $lang_str['interface_lang'];
        }
        return $this->output($input, $format);
    }
}

/* module managers */
trait Hm_Modules {

    private static $module_list = array();

    public static function add($page, $module, $logged_in, $marker=false, $placement='after', $module_args=array()) {
        $inserted = false;
        if (!isset(self::$module_list[$page])) {
            self::$module_list[$page] = array();
        }
        if (isset(self::$module_list[$page][$module])) {
            Hm_Debug::add(sprintf("Already registered module re-attempted: %s", $module));
            return;
        }
        if ($marker) {
            $mods = array_keys(self::$module_list[$page]);
            $index = array_search($marker, $mods);
            if ($index !== false) {
                if ($placement == 'after') {
                    $index++;
                }
                $list = self::$module_list[$page];
                self::$module_list[$page] = array_merge(array_slice($list, 0, $index), 
                    array($module => array('logged_in' => $logged_in, 'args' => $module_args)),
                    array_slice($list, $index));
                $inserted = true;
            }
        }
        else {
            $inserted = true;
            self::$module_list[$page][$module] = array('logged_in' => $logged_in, 'args' => $module_args);
        }
        if (!$inserted) {
            Hm_Msgs::add(sprintf('failed to insert module %s', $module));
        }
    }

    public static function del($page, $module) {
        if (isset(self::$module_list[$page][$module])) {
            unset(self::$module_list[$page][$module]);
        }
    }

    public static function get_for_page($page) {
        $res = array();
        if (isset(self::$module_list[$page])) {
            $res = array_merge($res, self::$module_list[$page]);
        }
        return $res;
    }
}
class Hm_Handler_Modules { use Hm_Modules; }
class Hm_Output_Modules { use Hm_Modules; }

/* wrapper around multiple imap connections */
class Hm_IMAP_List {

    private static $imap_list = array();

    public static function connect( $id, $cache=false, $user=false, $pass=false, $save_credentials=false) {
        if (isset(self::$imap_list[$id])) {
            $imap = self::$imap_list[$id];
            if ($imap['object']) {
                return $imap['object'];
            }
            else {
                if ((!$user || !$pass) && (!isset($imap['user']) || !isset($imap['pass']))) {
                    return false;
                }
                elseif (isset($imap['user']) && isset($imap['pass'])) {
                    $user = $imap['user'];
                    $pass = $imap['pass'];
                }
                if ($user && $pass) {
                    self::$imap_list[$id]['object'] = new Hm_IMAP();
                    if ($cache) {
                        self::$imap_list[$id]['object']->load_cache($cache, 'gzip');
                    }
                    $res = self::$imap_list[$id]['object']->connect(array(
                        'server' => $imap['server'],
                        'port' => $imap['port'],
                        'tls' => $imap['tls'],
                        'username' => $user,
                        'password' => $pass
                    ));
                    if ($res) {
                        self::$imap_list[$id]['connected'] = true;
                        if ($save_credentials) {
                            self::$imap_list[$id]['user'] = $user;
                            self::$imap_list[$id]['pass'] = $pass;
                        }
                    }
                    return self::$imap_list[$id]['object'];
                }
            }
        }
        return false;
    }

    public static function forget_credentials( $id ) {
        if (isset(self::$imap_list[$id])) {
            unset(self::$imap_list[$id]['user']);
            unset(self::$imap_list[$id]['pass']);
        }
    }

    public static function add( $atts, $id=false ) {
        $atts['object'] = false;
        $atts['connected'] = false;
        if ($id) {
            self::$imap_list[$id] = $atts;
        }
        else {
            self::$imap_list[] = $atts;
        }
    }

    public static function del( $id ) {
        if (isset(self::$imap_list[$id])) {
            unset(self::$imap_list[$id]);
            return true;
        }
        return false;
    }

    public static function dump( $id=false, $full=false ) {
        $list = array();
        foreach (self::$imap_list as $index => $server) {
            if ($id !== false && $index != $id) {
                continue;
            }
            if ($full) {
                $list[$index] = $server;
            }
            else {
                $list[$index] = array(
                    'server' => $server['server'],
                    'port' => $server['port'],
                    'tls' => $server['tls']
                );
                if (isset($server['user'])) {
                    $list[$index]['user'] = $server['user'];
                }
                if (isset($server['pass'])) {
                    $list[$index]['pass'] = $server['pass'];
                }
            }
            if ($id !== false) {
                return $list[$index];
            }
        }
        return $list;
    }

    public static function clean_up( $id=false ) {
        foreach (self::$imap_list as $index => $server) {
            if ($id !== false && $id != $index) {
                continue;
            }
            if ($server['connected'] && $server['object']) {
                self::$imap_list[$index]['object']->disconnect();
                self::$imap_list[$index]['connected'] = false;
            }
        }
    }
}
?>
