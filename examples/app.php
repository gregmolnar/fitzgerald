<?php
    // See the accompanying README for how to use Fitzgerald!

    class Application extends Fitzgerald {

        // Define your controller methods, remembering to return a value for the browser!
        public function __construct( $options = array() )
        {
            parent::__construct($options);
        }

        public function get_index()
        {
            return $this->render('index');
        }
    }

    // Define root app path to get your views, only needed when you have
    // Fitzgerald library on another level outside from your app folder.
    // Notice trailing slash for the path
    $params = array('root' => realpath(__DIR__).'/');

    $app = new Application($params);

    // Define your url mappings. Take advantage of placeholders and regexes for safety.
    $app->get('/', 'get_index');

    $app->run();
