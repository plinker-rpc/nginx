<?php
namespace Plinker\Nginx {

    class Manager
    {
        public $config = array();

        public function __construct(array $config = array())
        {
            $this->config = $config;

            // load model
            $this->model = new Model($this->config['database']);
        }

        /**
         *
         */
        public function create(array $params = array())
        {

        }

    }

}
