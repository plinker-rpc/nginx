<?php
/*
 +------------------------------------------------------------------------+
 | Plinker-RPC PHP                                                        |
 +------------------------------------------------------------------------+
 | Copyright (c)2017-2018 (https://github.com/plinker-rpc/core)           |
 +------------------------------------------------------------------------+
 | This source file is subject to MIT License                             |
 | that is bundled with this package in the file LICENSE.                 |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@cherone.co.uk so we can send you a copy immediately.        |
 +------------------------------------------------------------------------+
 | Authors: Lawrence Cherone <lawrence@cherone.co.uk>                     |
 +------------------------------------------------------------------------+
 */
 
if (!class_exists('Reconcile')) {
    /**
     *
     */
    class Reconcile
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
            $this->routes();
        }
        
        /**
         *
         */
        public function routes()
        {
            $routes = $this->task->find('route');
    
            /*
             * Remove inactive route configs
             */
            // get current routes
            $current = [];
            foreach ($routes as $route) {
                $current[] = $route->name;
            }
            
            // get active routes
            $it = new \DirectoryIterator('/etc/nginx/proxied/servers');
            $active = [];
            foreach ($it as $dir) {
                if ($dir->isDir() && !$dir->isDot()) {
                    $active[] = $dir->getFilename();
                }
            }
            
            // remove inactive routes
            $restart = false;
            foreach (array_diff($active, $current) as $folder) {
                $restart = true;
                `rm /etc/nginx/proxied/servers/$folder -R`;
            }
            
            // reload nginx config
            if ($restart) {
                `nginx -s reload`;
            }
            
            /*
             * Remove inactive route logs
             */
            //
            $active = [];
            foreach (new \DirectoryIterator('/var/log/nginx') as $it) {
                if ($it->isFile() && !in_array($it->getExtension(), ['gz'])) {
                    // get real filename
                    list($filename, $ext) = explode('.', $it->getFilename(), 2);
                    // skip main error logs
                    if (in_array($filename, ['error', 'access'])) {
                        continue;
                    }
                    //
                    $active[] = $filename;
                }
            }

            // remove logs
            foreach (array_diff($active, $current) as $file) {
                // remove file, avoid error output
                `rm -f /var/log/nginx/$file.* ||:`;
            }
        }
    }
}

$reconcile = new Reconcile($this);
$reconcile->run();
