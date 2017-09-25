**Plinker-RPC - Nginx**
=========

Plinker PHP RPC client/server makes it really easy to link and execute PHP 
component classes on remote systems, while maintaining the feel of a local 
method call.

**WIP:** Dont use!

This component sets up nginx as a reverse proxy, it relies on php7-fpm being 
installed and will overwrite /etc/nginx/nginx.conf! So if you have nginx install 
not as a reverse proxy then dont use this component. It will most likely break 
your stuff.

The sole aim is to route to LXC containers on the host, or external upstreams, 
not as a server{} block configurator.

::Installing::

Ill prob bundle a bash install script, which install nginx and php + modules, 
but in the meantime:

    sudo apt -y install nginx openssl
    sudo apt -y install php7.0-{fpm,mbstring,curl,mcrypt,json,xml,mysql,sqlite}

The webroot for plinker will be `/var/www/html` so plinker should be in there.
The difference being that nginx will listen on port 88 for plinker calls, 
and 80, 443 for the reverse proxy.

**Composer**

    {
    	"require": {
    		"plinker/nginx": ">=v0.1"
    	}
    }



See the [organisations page](https://github.com/plinker-rpc) for additional 
components and examples.
