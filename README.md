**Plinker-RPC - Nginx**
=========

Plinker PHP RPC client/server makes it really easy to link and execute PHP 
component classes on remote systems, while maintaining the feel of a local 
method call.

The aim of this component is to build web forwards/reverse proxy to LXD/LXC 
containers on the host (or external upstreams), not as a `server{}` block configurator.

The component uses nginx as a reverse proxy, it relies on php7-fpm being 
installed and will overwrite `/etc/nginx/nginx.conf`! So if you have already 
nginx installed then dont use this component as it will most likely break your stuff.


## ::Installing::


Bring in the project with composer:

    {
    	"require": {
    		"plinker/nginx": ">=v0.1"
    	}
    }
    
    
Then navigate to `./vendor/plinker/nginx/scripts` and run `bash install.sh`


The webroot for plinker will be `/var/www/html` so plinker should be in there.
The difference being that nginx will listen on port 88 for plinker calls, 
and 80, 443 for the reverse proxy.

See the [organisations page](https://github.com/plinker-rpc) for additional 
components and examples.
