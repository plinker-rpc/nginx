<?php

/**
 * Begin filesystem reconcile
 *
 * Get all routes
 * Get all filesystem directorys
 * Remove nginx/servers directorys
 * Reload nginx
 */

$routes = $this->find('route');

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
