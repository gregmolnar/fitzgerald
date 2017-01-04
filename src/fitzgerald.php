<?php
// This is the only file you really need! The directory structure of this repo is a suggestion,
// not a requirement. It's your app.


/*  Fitzgerald - a single file PHP framework
 *  (c) 2010 Jim Benton and contributors, released under the MIT license
 *  Version 0.5
 */

class Url {
    private $url;
    private $method;
    private $conditions;

    public $params = array();
    public $match = false;

    public function __construct($httpMethod, $url, $conditions=array(), $mountPoint, $logger) {

        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = str_replace($mountPoint, '', preg_replace('/\?.+/', '', $_SERVER['REQUEST_URI']));
        $requestUri = preg_replace('#/+#', '/', $requestUri);
        $requestUri = rtrim($requestUri, '/');

        if( empty($requestUri) ) {
            $requestUri = '/';
        }
        $this->url = $url;
        $this->method = $httpMethod;
        $this->conditions = $conditions;
        if (strtoupper($httpMethod) == $requestMethod) {
            $logger->info("Router: METHOD MATCHED: {$httpMethod}");
            $paramNames = array();
            $paramValues = array();

            preg_match_all('@:([a-zA-Z_]+)@', $url, $paramNames, PREG_PATTERN_ORDER);                   // get param names
            $paramNames = $paramNames[1];                                                               // we want the set of matches
            $regexedUrl = preg_replace_callback('@:[a-zA-Z_\-]+@', array($this, 'regexValue'), $url);   // replace param with regex capture
            $logger->info("Router: requestUri: {$requestUri}");
            $logger->info("Router: regexedUrl: {$regexedUrl}");
            if (preg_match('@^' . $regexedUrl . '$@', $requestUri, $paramValues)){                      // determine match and get param values
                array_shift($paramValues);                                                              // remove the complete text match
                for ($i=0; $i < count($paramNames); $i++) {
                    $this->params[$paramNames[$i]] = $paramValues[$i];
                }
                $this->match = true;
            }
        }
    }

    private function regexValue($matches) {
        $key = str_replace(':', '', $matches[0]);
        if (array_key_exists($key, $this->conditions)) {
            return '(' . $this->conditions[$key] . ')';
        } else {
            return '([a-zA-Z0-9_\-]+)';
        }
    }

}

class ArrayWrapper {
    private $subject;
    public function __construct(&$subject) {
        $this->subject = $subject;
    }
    public function __get($key) {
        return isset($this->subject[$key]) ? $this->subject[$key] : null;
    }

    public function __set($key, $value) {
        $this->subject[$key] = $value;
        return $value;
    }

    public function __isset($key){
        return isset($this->subject[$key]) && ( is_array($this->subject[$key]) || strlen($this->subject[$key]) > 0  );
    }
    public function getCount(){
        return count($this->subject);
    }
}

class SessionWrapper {
    public function setFlash($msg, $status = 'none') {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_status'] = $status;
    }

    public function getFlash() {
        $msg = '';

        if (isset($_SESSION['flash_msg'])) {
            $msg = $_SESSION['flash_msg'];
            unset($_SESSION['flash_msg']);
            unset($_SESSION['flash_status']);
        }

        return $msg;
    }

    public function hasFlash() {
        return isset($_SESSION['flash_msg']);
    }

    public function flashStatus() {
        $status = 'undefined';

        if (isset($_SESSION['flash_status'])) {
          $status = $_SESSION['flash_status'];
          // it's unset at getFlash as it's related to the flash itself
        }

        return $status;
    }

    public function __get($key) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
    }

    public function __set($key, $value) {
        $_SESSION[$key] = $value;
        return $value;
    }

    public function __isset($key){
        return isset($_SESSION[$key]);
    }
}

class RequestWrapper {
    public function __get($key) {
        return isset($_REQUEST[$key]) ? $_REQUEST[$key] : null;
    }

    public function __set($key, $value) {
        $_REQUEST[$key] = $value;
        return $value;
    }

    public function __isset($key){
        return isset($_REQUEST[$key]);
    }
    public function getCount(){
        return count($_REQUEST);
    }
}

class Fitzgerald {

    private $mappings = array(), $before_filters = array(), $after_filters = array(), $engines = array();
    public $default_layout;
    protected $options;
    protected $session;
    protected $request;

    public function __construct($options=array()) {
        $this->app_root = __DIR__.'/../../../../';
        $this->logger = new Katzgrau\KLogger\Logger($this->app_root.'/logs');
        $this->class = get_class($this);
        $this->logger->info("Initialisation {$this->class}");
        $this->logger->info("Processing request {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']}");
        $this->options = new ArrayWrapper($options);
        // if(!file_exists(realpath($this->root() . 'views/' . $this->options->layout . '.php')))
        //     $this->options->layout = $app_root.'themes/default/views/layouts/application.php';
        session_name('fitzgerald_session');
        @session_start();
        $this->session = new SessionWrapper;
        $this->request = new RequestWrapper;
        set_error_handler(array($this, 'handleError'), 2);
    }

    public function handleError($number, $message, $file, $line) {
        header("HTTP/1.0 500 Server Error");
        echo $this->render('500', compact('number', 'message', 'file', 'line'));
        die();
    }

    public function show404() {
        header("HTTP/1.0 404 Not Found");
        echo $this->render('404');
        die();
    }

    public function get($url, $methodName, $conditions = array()) {
       $this->event('get', $url, $methodName, $conditions);
    }

    public function post($url, $methodName, $conditions = array()) {
       $this->event('post', $url, $methodName, $conditions);
    }

    public function put($url, $methodName, $conditions = array()) {
       $this->event('put', $url, $methodName, $conditions);
    }

    public function delete($url, $methodName, $conditions = array()) {
       $this->event('delete', $url, $methodName, $conditions);
    }

    public function before($methodName, $filterName) {
        $this->push_filter($this->before_filters, $methodName, $filterName);
    }

    public function after($methodName, $filterName) {
        $this->push_filter($this->after_filters, $methodName, $filterName);
    }

    public function mount($url, $app){
        array_push($this->engines, array('mount', $url, $app));
    }

    protected function push_filter(&$arr_filter, $methodName, $filterName) {
        if (!is_array($methodName)) {
            $methodName = explode('|', $methodName);
        }

        for ($i = 0; $i < count($methodName); $i++) {
            $method = $methodName[$i];
            if (!isset($arr_filter[$method])) {
                $arr_filter[$method] = array();
            }
            array_push($arr_filter[$method], $filterName);
        }
    }

    protected function run_filter($arr_filter, $methodName) {
        $this->logger->info("{$this->class}: Running filters", array('filters' => $arr_filter, 'method' => $methodName));
        $filters = false;
        if(isset($arr_filter['*'])){
            $filters = $arr_filter['*'];
        }elseif(isset($arr_filter[$methodName])){
            $filters = $arr_filter[$methodName];
        }
        $this->logger->info("Applying filters", array('filters' => $filters));
        if($filters) {
            for ($i=0; $i < count($filters); $i++) {
                $return = call_user_func(array($this, $filters[$i]));

                if(!is_null($return)) {
                    return $return;
                }
            }
        }
    }

    public function run() {
        echo $this->processRequest();
    }

    protected function redirect($path) {
        $protocol = empty($_SERVER['HTTPS']) ? 'http' : 'https';
        $host = (preg_match('%^http://|https://%', $path) > 0) ? '' : "$protocol://" . $_SERVER['HTTP_HOST'];
        if (!empty($this->error)) {
          $this->session->error = $this->error;
        }
        if (!empty($this->success)) {
          $this->session->success = $this->success;
        }
        $this->logger->info("Redirecting to: $host$uri$path");
        header("Location: $host$path");
        return false;
    }

    protected function render($fileName, $variableArray=array()) {
        $variableArray['options'] = $this->options;
        $variableArray['request'] = $this->request;
        $variableArray['session'] = $this->session;
        if(isset($this->error)) {
            $variableArray['error'] = $this->error;
        }
        if(isset($this->success)) {
            $variableArray['success'] = $this->success;
        }
        if(isset($variableArray['layout'])){
            $layout = $variableArray['layout'];
        }else{
            $layout = $this->options->layout;
        }
        if (is_string($layout)) {
            $this->logger->info("{$this->class}: Rendering view: {$fileName}");
            $variableArray['content'] = $this->renderTemplate($fileName, $variableArray);
            $this->logger->info("{$this->class}: Rendering layout: {$layout}");
            return $this->renderTemplate($layout, $variableArray);
        } else {
            return $this->renderTemplate($fileName, $variableArray);
        }
    }

    protected function renderTemplate($fileName, $locals = array())
    {
        extract($locals);
        ob_start();
        $this->logger->info("{$this->class}: Rendering file: ".$this->root() . 'views/' . $fileName . ".php");
        if(!$path = realpath($this->root() . 'views/' . $fileName . '.php')){
            $path = realpath($this->app_root . '/themes/default/views/' . $fileName . '.php');
        }
        include($path);
        return ob_get_clean();
    }

    protected function renderPartial($fileName, $locals = array())
    {
        echo  $this->renderTemplate($fileName, $locals);
    }

    protected function sendFile($filename, $contentType, $path) {
        header("Content-type: $contentType");
        header("Content-Disposition: attachment; filename=$filename");
        return readfile($path);
    }

    protected function sendDownload($filename, $path) {
        header("Content-Type: application/force-download");
        header("Content-Type: application/octet-stream");
        header("Content-Type: application/download");
        header("Content-Description: File Transfer");
        header("Content-Disposition: attachment; filename=$filename".";");
        header("Content-Transfer-Encoding: binary");
        return readfile($path);
    }

    protected function execute($methodName, $params) {
        $this->logger->info("{$this->class}: Executing method: {$methodName}");
        $return = $this->run_filter($this->before_filters, $methodName);
        if (!is_null($return)) {
          return $return;
        }

        if ($this->session->error) {
            $this->error = $this->session->error;
            $this->session->error = null;
        }

        if ($this->session->success) {
            $this->success = $this->session->success;
            $this->session->success = null;
        }

        $reflection = new ReflectionMethod(get_class($this), $methodName);
        $args = array();

        foreach($reflection->getParameters() as $param) {
            if(isset($params[$param->name])) {
                $args[$param->name] = $params[$param->name];
            }
            else if($param->isDefaultValueAvailable()) {
                $args[$param->name] = $param->getDefaultValue();
            }
        }

        $response = $reflection->invokeArgs($this, $args);

        $return = $this->run_filter($this->after_filters, $methodName);
        if (!is_null($return)) {
          return $return;
        }

        return $response;
    }

    protected function event($httpMethod, $url, $methodName, $conditions=array()) {
        if (method_exists($this, $methodName)) {
            array_push($this->mappings, array($httpMethod, $url, $methodName, $conditions));
        }
    }

    protected function root() {
        if($root = $this->options->root)
          return $root;
        else
          return dirname(__FILE__) . '/../';
    }

    protected function path($path) {
        return $this->root() . $path;
    }

    protected function processRequest() {
        foreach($this->engines as $engine){
            $url = new Url('mount', $engine[1], array(), $engine[2]->options->mountPoint, $this->logger);
            $requestUri = str_replace($this->options->mountPoint, '', preg_replace('/\?.+/', '', $_SERVER['REQUEST_URI']));
            $rule = "@^{$engine[1]}|^{$engine[1]}/(.*)@";
            if(preg_match($rule, $requestUri)){
                $this->logger->info("{$this->class}: Running engine: {$engine['1']}");
                return $engine[2]->run();
            }
        }
        $charset = (is_string($this->options->charset)) ? ";charset={$this->options->charset}" : "";
        header("Content-type: text/html" . $charset);

        for($i = 0; $i < count($this->mappings); $i++) {
            $mapping = $this->mappings[$i];
            $mountPoint = is_string($this->options->mountPoint) ? $this->options->mountPoint : '';
            $url = new Url($mapping[0], $mapping[1], $mapping[3], $mountPoint, $this->logger);

            if($url->match) {
                return $this->execute($mapping[2], $url->params);
            }
        }

        return $this->show404();
    }
}
