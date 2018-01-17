<?php
if (!class_exists('ComposerUpdateNginx')) {
    /**
     *
     */
    class ComposerUpdateNginx
    {
        /**
         *
         */
        public function __construct($task)
        {
            $this->task = $task;
        }
        
        /**
         *
         */
        public function run()
        {
            `composer update plinker/nginx`;
        }
    }
}

$composerUpdate = new ComposerUpdateNginx($this);
$composerUpdate->run();
