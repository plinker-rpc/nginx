<?php
/*
 +------------------------------------------------------------------------+
 | Plinker-RPC PHP                                                        |
 +------------------------------------------------------------------------+
 | Copyright (c)2017-2020 (https://github.com/plinker-rpc/core)           |
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
 
/**
 * Task Build NGINX
 */

if (!defined('DEBUG')) {
    // define('DEBUG', !empty($this->task->config['debug']));
	define('DEBUG', true);
}

if (!defined('TMP_DIR')) {
    define('TMP_DIR', (!empty($this->task->config['tmp_dir']) ? $this->task->config['tmp_dir'] : './.plinker'));
}

if (!defined('LOG')) {
    define('LOG', !empty($this->task->config['log']));
}

// ACHTUNG! change this
if (!defined('LETS_ENCRYPT_CONTACT_EMAIL')) {
    define('LETS_ENCRYPT_CONTACT_EMAIL', 'lawrence@cherone.co.uk');
}

if (!defined('LETS_ENCRYPT_CERTS_PATH')) {
    define('LETS_ENCRYPT_CERTS_PATH', '/etc/letsencrypt/live');
}

//
if (!class_exists('Nginx')) {
    class Nginx
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
        private function log($message)
        {
			echo DEBUG ? " - ".$message."\n" : null;
            if (LOG) {
                if (!file_exists(TMP_DIR.'/logs')) {
                    mkdir(TMP_DIR.'/logs', 0755, true);
                }
                $log  = '['.date("c").'] '.$message.PHP_EOL;
                file_put_contents(TMP_DIR.'/logs/nginx.'.date("d-m-Y").'.txt', $log, FILE_APPEND);
                
                shell_exec('chown www-data:www-data '.TMP_DIR.'/logs -R');
            }
        }

        /**
         *
         */
        public function build($routes)
        {
            // trigger restart of nginx service
            $restart_nginx = false;

            // loop over each server and build config file
            foreach ($routes as $row) {
                echo DEBUG ? $this->log($row['name'].": ".$row['label']) : null;

                $restart_nginx = true;

                $this->path = "/etc/nginx/proxied/servers/".basename($row['name']);
				
				echo DEBUG ? $this->log('NGINX path: '.$this->path) : null;

                // check for config folder
                if (!file_exists($this->path)) {
                    echo DEBUG ? $this->log('Create config directory (CREATE): '.$this->path) : null;
                    mkdir($this->path, 0755, true);
                } else {
					echo DEBUG ? $this->log('Create config directory (EXISTS): '.$this->path) : null;
				}

                // build nginx configs
                if (!$this->config_upstream($row)) {
                    echo DEBUG ? $this->log('NGINX was not reloaded - upstream.conf - check log for error!') : null;
                    $restart_nginx = false;
                    $row->has_error = true;
                    $this->task->store($row);
                }
                
                if (!$this->config_http($row)) {
                    echo DEBUG ? $this->log('NGINX was not reloaded - http.conf - check log for error!') : null;
                    $restart_nginx = false;
                    $row->has_error = true;
                    $this->task->store($row);
                }
                
                if (!$this->config_https($row)) {
                    echo DEBUG ? $this->log('NGINX was not reloaded - https.conf - check log for error!') : null;
                    $restart_nginx = false;
                    $row->has_error = true;
                    $this->task->store($row);
                }
                
                $row->has_change = 0;
                $this->task->store($row);
            }

            // reload nginx if config test passed
            if ($restart_nginx === true) {
                if ($this->config_test_nginx()) {
                    $this->reload_nginx();
                } else {
                    echo DEBUG ? $this->log('NGINX was not reloaded.') : null;
                }
                
                echo DEBUG ? $this->log(str_repeat('-', 40)) : null;
            }

            return;
        }

        /**
         *
         */
        public function config_test_nginx()
        {
            exec('/usr/sbin/nginx -t 2>&1', $out, $exit_code);
            echo DEBUG ? $this->log('Testing NGINX config: [exit code: '.$exit_code.']: '.print_r($out, true)) : null;
            return ($exit_code == 0);
        }
		
		/**
		*
		*/
		public function reload_nginx()
		{
			exec('/usr/sbin/nginx -s reload 2>&1', $out, $exit_code);
                    
            echo DEBUG ? $this->log('Reloading NGINX: [exit code: '.$exit_code.']: '.print_r($out, true)) : null;

            // out is not empty
            if (!empty($out)) {
                echo DEBUG ? $this->log('NGINX was reloaded with warnings! Some routes may be effected.') : null;
            }
            // all is good
            else {
                echo DEBUG ? $this->log('NGINX was reloaded.') : null;
            }
		}

        /**
         *
         */
        public function config_http($row)
        {
            echo DEBUG ? $this->log('Building NGINX HTTP server config.') : null;

            $domains = [];
            foreach ($row->with("ORDER BY LENGTH(name) ASC")->ownDomain as $domain) {
                $domains[] = $domain->name;
            }
			
			echo DEBUG ? $this->log('Domains: '.print_r($domains, true)) : null;

            $bytes_written = file_put_contents(
                $this->path.'/http.conf',
                '# Auto generated on '.date_create()->format('Y-m-d H:i:s').'
# Do not edit this file as any changes will be overwritten!

server {
    client_max_body_size 256M;
	
    listen       80;
    server_name '.implode(' ', $domains).';

    # logs
    access_log  /var/log/nginx/'.$row['name'].'.access.log;
    error_log  /var/log/nginx/'.$row['name'].'.error.log;

    root   /usr/share/nginx/html;
    index  index.html index.htm;

    # redirect server error pages
    # include /etc/nginx/proxied/includes/error_pages.conf;

    # send request back to backend
    location / {
	    client_max_body_size       256M;
        client_body_buffer_size    512k;
		
	    '.(!empty($row['forcessl']) ? '# force SSL - 301 redirect' : '').'
        '.(!empty($row['forcessl']) ? 'return 301 https://$host$request_uri;' : '').'
        #
        proxy_bind $server_addr;
        
        # change to upstream directive e.g http://backend
        proxy_pass  http://'.$row['name'].';

        include /etc/nginx/proxied/includes/proxy.conf;
    }

    # Lets Encrypt
    location ^~ /.well-known/ {
        root /usr/share/nginx/html/letsencrypt/'.$domains[0].';
        index index.html index.htm;

        try_files $uri $uri/ =404;
    }
}
'
            );
			
			if ($bytes_written && $this->config_test_nginx()) {
                $this->reload_nginx();
				sleep(1);
            }
				
			return $bytes_written;
        }

        /**
         *
         */
        public function config_https($row)
        {
            // no ssl
            if (empty($row['ssl_type'])) {
                //remove https.conf if its there
                if (file_exists($this->path.'/https.conf')) {
                    echo DEBUG ? $this->log('Removing unused https.sh config.') : null;
                    unlink($this->path.'/https.conf');
                }
                return true;
            }
            
            echo DEBUG ? $this->log('Building NGINX HTTPS server config.') : null;

            $domains = [];
            foreach ($row->with("ORDER BY LENGTH(name) ASC")->ownDomain as $domain) {
                $domains[] = $domain->name;
            }
			
			echo DEBUG ? $this->log('Domains: '.print_r($domains, true)) : null;

            // check certs for letsencrypt
            if (isset($row['ssl_type']) && $row['ssl_type'] == 'letsencrypt') {
                date_default_timezone_set("UTC");
                echo DEBUG ? $this->log('Using LetsEncrypt 2.0 certificate.') : null;

                if (!file_exists(LETS_ENCRYPT_CERTS_PATH)) {
                    mkdir(LETS_ENCRYPT_CERTS_PATH, 0755, true);
                }

                echo DEBUG ? $this->log('Certificate will be written to: '.LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0]) : null;

                // make sure our cert location exists
                if (!is_dir(LETS_ENCRYPT_CERTS_PATH)) {
                    echo DEBUG ? $this->log('Certs path is not a directory.') : null;
                    // Make sure nothing is already there.
                    if (file_exists(LETS_ENCRYPT_CERTS_PATH)) {
                        echo DEBUG ? $this->log('Removing existing path contents ready for certificate.') : null;
                        array_map('unlink', glob(LETS_ENCRYPT_CERTS_PATH."/*.*"));
                        rmdir(LETS_ENCRYPT_CERTS_PATH);
                    }
                    echo DEBUG ? $this->log('Certificates directory created.') : null;
                    mkdir(LETS_ENCRYPT_CERTS_PATH);
                }

                // do we need to create or upgrade our cert? Assume no to start with.
                $needsgen = false;

                // display number of domains in cert
                echo DEBUG ? $this->log(count($domains).' domains for certificate') : null;

                // the first domain is the main domain used
                $certfile = LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/fullchain.pem';
                echo DEBUG ? $this->log('Certificate: '.$certfile) : null;

                //
                if (!file_exists($certfile)) {
                    echo DEBUG ? $this->log('Certificate: fullchain.pem - no exsiting certificate found, triggering initial generation.') : null;
                    // we don't have a cert, so we need to request one.
                    $needsgen = true;
                } else {
                    
                    echo DEBUG ? $this->log('Certificate: fullchain.pem - found exsiting certificate, checking validity.') : null;

                    // varify certificate date.
                    $certdata = openssl_x509_parse(file_get_contents($certfile));
                    echo DEBUG ? $this->log('Certificate: fullchain.pem - domain expires '.date('d/m/Y h:i:s', $certdata['validTo_time_t'])) : null;

                    // if it expires in less than a month, we want to renew it.
                    $renewafter = $certdata['validTo_time_t']-(86400*30);

                    // update control host with the certificates expiry date
                    echo DEBUG ? $this->log('Notifying control host with the certificates expiry date.') : null;
                    $row->certificate_expiry = $certdata['validTo_time_t'];
                    $this->task->store($row);

                    if (time() > $renewafter) {
                        echo DEBUG ? $this->log('Certificate: cert.pem - renewing certificate.') : null;
                        // less than a month left, we need to renew.
                        $needsgen = true;
                    } else {
                        echo DEBUG ? $this->log('Certificate: cert.pem - skipping renewal.') : null;
                    }
                }

                // we need to generate a certificate
                if ($needsgen) {
                    $error = null;

                    $generateRSAKeys = function ($outputDirectory) {
                        $res = openssl_pkey_new(array(
                            "private_key_type" => OPENSSL_KEYTYPE_RSA,
                            "private_key_bits" => 4096,
                        ));

                        if (!openssl_pkey_export($res, $privateKey)) {
                            throw new \RuntimeException("Key export failed!");
                        }

                        $details = openssl_pkey_get_details($res);

                        if (!is_dir($outputDirectory)) {
                            @mkdir($outputDirectory, 0700, true);
                        }
                        if (!is_dir($outputDirectory)) {
                            throw new \RuntimeException("Cant't create directory $outputDirectory");
                        }

                        file_put_contents($outputDirectory.'/private.pem', $privateKey);
                        file_put_contents($outputDirectory.'/public.pem', $details['key']);
                    };

                    try {
                        //
                        $ac = new ACMECert();

                        if (!is_file(LETS_ENCRYPT_CERTS_PATH.'/_account/private.pem')) {
                            echo DEBUG ? $this->log('Starting new account registration.') : null;

                            // gen key
                            $generateRSAKeys(LETS_ENCRYPT_CERTS_PATH.'/_account');

                            // register
                            $ac->loadAccountKey('file://'.LETS_ENCRYPT_CERTS_PATH.'/_account/private.pem');
                            $ret = $ac->register(true, LETS_ENCRYPT_CONTACT_EMAIL);

                            // result
                            echo DEBUG ? $this->log(print_r($ret, true)) : null;
                        } else {
                            echo DEBUG ? $this->log('Account already registered. Continuing.') : null;
                        }

                        $ac->loadAccountKey('file://'.LETS_ENCRYPT_CERTS_PATH.'/_account/private.pem');

                        // create domains config from domains
                        $domain_config = [];
                        foreach ($domains as $key => $value) {
                            $domain_config[$value] = [
                                'challenge' => 'http-01', 'docroot' => '/usr/share/nginx/html/letsencrypt/'.$domains[0]
                            ];
                        }

                        $handler = function($opts) {
                            $fn = $opts['config']['docroot'].$opts['key'];
                            @mkdir(dirname($fn),0777, true);
                            file_put_contents($fn,$opts['value']);
                            return function ($opts) {
                                unlink($opts['config']['docroot'].$opts['key']);
                            };
                        };

                        $readPrivateKey = function($path){
                            if (($key = openssl_pkey_get_private('file://' . $path)) === false) {
                                throw new \RuntimeException(openssl_error_string());
                            }
                            return $key;
                        };

                        //
                        $domainPath = LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0];

                        // generate private key for domain if not exist
                        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
                            $generateRSAKeys($domainPath);
                        }

                        $fullchain = $ac->getCertificateChain('file://'.$domainPath . '/private.pem', $domain_config, $handler);
                        file_put_contents($domainPath.'/fullchain.pem', $fullchain);
                    } catch (\Exception $e) {
                        $row->has_error = 1;
                        $row->error = json_encode($e);
                        $this->task->store($row);

                        echo DEBUG ? $this->log('Certificate error: '.print_r($e->getMessage(), true)) : null;
                        return;
                    }

                    if (empty($error)) {
                        // concat certs into single domain.tld.pem
                        file_put_contents(
                            LETS_ENCRYPT_CERTS_PATH."/{$domains[0]}/{$domains[0]}.pem",
                            file_get_contents(LETS_ENCRYPT_CERTS_PATH."/{$domains[0]}/fullchain.pem")."\n".
                            file_get_contents(LETS_ENCRYPT_CERTS_PATH."/{$domains[0]}/private.pem")
                        );

                        echo DEBUG ? $this->log('Certificate: '.PHP_EOL.file_get_contents(LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/'.$domains[0].'.pem')) : null;

                        // varify certificate date.
                        $certdata = openssl_x509_parse(file_get_contents(LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/cert.pem'));
                        
                        echo DEBUG ? $this->log('Certificate: cert.pem - domain expires on '.date('d/m/Y h:i:s', $certdata['validTo_time_t'])): null;

                        // update control host with the certificates expiry date
                        echo DEBUG ? $this->log('Notifying control host with the certificates expiry date.') : null;

                        $row->certificate_expiry = $certdata['validTo_time_t'];
                        $this->task->store($row);
                    }
                }
            }

            // check certs for manual ssl
            if (isset($row['ssl_type']) && $row['ssl_type'] == 'manual') {
                echo DEBUG ? $this->log('SSL is type manual.') : null;
            }

            // check certs for manual ssl
            if (isset($row['ssl_type']) && $row['ssl_type'] == 'selfsigned') {
                echo DEBUG ? $this->log('SSL is type selfsigned.') : null;
            }

            if (empty(LETS_ENCRYPT_CERTS_PATH) ||
                !file_exists(LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/fullchain.pem') ||
                !file_exists(LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/private.pem')
               ) {
                echo DEBUG ? $this->log('No SSL to setup.') : null;
                return true;
            }

            echo DEBUG ? $this->log('Building https.conf') : null;

            return file_put_contents(
                $this->path.'/https.conf',
                '# Auto generated on '.date_create()->format('Y-m-d H:i:s').'
# Do not edit this file as any changes will be overwritten!

server {
    client_max_body_size       256M;
    listen       443 ssl;
    server_name  '.implode(' ', $domains).';

    # change log names
    access_log  /var/log/nginx/'.$row['name'].'.access.log;
    error_log  /var/log/nginx/'.$row['name'].'.error.log;

    root   /usr/share/nginx/html;
    index  index.html index.htm;

    # redirect server error pages
    # include /etc/nginx/proxied/includes/error_pages.conf;

    # add ssl certs
    ssl_certificate      '.LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/fullchain.pem;
    ssl_certificate_key  '.LETS_ENCRYPT_CERTS_PATH.'/'.$domains[0].'/private.pem;

    # add ssl seetings
    include /etc/nginx/proxied/includes/ssl.conf;

    ## send request back to backend ##
    location / {
	    client_max_body_size       256M;
        client_body_buffer_size    512k;

        #
        proxy_bind $server_addr;
        
        # change to upstream directive e.g http://backend
        proxy_pass  http://'.$row['name'].';

        include /etc/nginx/proxied/includes/proxy.conf;
    }
}'
            );
        }

        /**
         *
         */
        public function config_upstream($row)
        {
            echo DEBUG ? $this->log('Building NGINX upstream config.') : null;

            $upstreams = [];
            foreach ($row['ownUpstream'] as $upstream) {
                $upstreams[] = [
                    'ip' => $upstream['ip'],
                    'port' => $upstream['port'],
                ];
            }

            if (!empty($upstreams[0]['ip'])) {
                echo DEBUG ? "   - Upstream IP: ".$upstreams[0]['ip'].":".$upstreams[0]['port']."\n" : null;
                $data = null;
                $data .= '# Auto generated on '.date_create()->format('Y-m-d H:i:s').PHP_EOL;
                $data .= '# Do not edit this file as any changes will be overwritten!'.PHP_EOL.PHP_EOL;
                $data .= 'upstream '.$row['name'].' {'.PHP_EOL;
                foreach ($upstreams as $upstream) {
                    $data .= '    server '.$upstream['ip'].':'.(!empty($upstream['port']) ? $upstream['port'] : '80').';'.PHP_EOL;
                }
                $data .= '}'.PHP_EOL;

                return file_put_contents($this->path.'/upstream.conf', $data);
            }
        }
    }
}

if (!class_exists('ACMECert')) {
    
    /*
    MIT License

    Copyright (c) 2018 Stefan Körfgen

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.
    */

    // https://github.com/skoerfgen/ACMECert

    class ACMEv2 { // Communication with Let's Encrypt via ACME v2 protocol

        protected
            $directories=array(
                'live'=>'https://acme-v02.api.letsencrypt.org/directory',
                'staging'=>'https://acme-staging-v02.api.letsencrypt.org/directory'
            ),$ch=null,$bits,$sha_bits,$directory,$resources,$jwk_header,$kid_header,$account_key,$thumbprint,$nonce,$mode;

        public function __construct($live=true){
            $this->directory=$this->directories[$this->mode=($live?'live':'staging')];
        }

        public function __destruct(){
            if ($this->account_key) openssl_pkey_free($this->account_key);
            if ($this->ch) curl_close($this->ch);
        }

        public function loadAccountKey($account_key_pem){
            if ($this->account_key) openssl_pkey_free($this->account_key);
            if (false===($this->account_key=openssl_pkey_get_private($account_key_pem))){
                throw new Exception('Could not load account key: '.$account_key_pem.' ('.$this->get_openssl_error().')');
            }

            if (false===($details=openssl_pkey_get_details($this->account_key))){
                throw new Exception('Could not get account key details: '.$account_key_pem.' ('.$this->get_openssl_error().')');
            }

            $this->bits=$details['bits'];
            switch($details['type']){
                case OPENSSL_KEYTYPE_EC:
                    if (version_compare(PHP_VERSION,'7.1.0')<0) throw new Exception('PHP >= 7.1.0 required for EC keys !');
                    $this->sha_bits=($this->bits==521?512:$this->bits);
                    $this->jwk_header=array( // JOSE Header - RFC7515
                        'alg'=>'ES'.$this->sha_bits,
                        'jwk'=>array( // JSON Web Key
                            'crv'=>'P-'.$details['bits'],
                            'kty'=>'EC',
                            'x'=>$this->base64url(str_pad($details['ec']['x'],ceil($this->bits/8),"\x00",STR_PAD_LEFT)),
                            'y'=>$this->base64url(str_pad($details['ec']['y'],ceil($this->bits/8),"\x00",STR_PAD_LEFT))
                        )
                    );
                break;
                case OPENSSL_KEYTYPE_RSA:
                    $this->sha_bits=256;
                    $this->jwk_header=array( // JOSE Header - RFC7515
                        'alg'=>'RS256',
                        'jwk'=>array( // JSON Web Key
                            'e'=>$this->base64url($details['rsa']['e']), // public exponent
                            'kty'=>'RSA',
                            'n'=>$this->base64url($details['rsa']['n']) // public modulus
                        )
                    );
                break;
                default:
                    throw new Exception('Unsupported key type! Must be RSA or EC key.');
                break;
            }

            $this->kid_header=array(
                'alg'=>$this->jwk_header['alg'],
                'kid'=>null
            );

            $this->thumbprint=$this->base64url( // JSON Web Key (JWK) Thumbprint - RFC7638
                hash(
                    'sha256',
                    json_encode($this->jwk_header['jwk']),
                    true
                )
            );
        }

        public function getAccountID(){
            if (!$this->kid_header['kid']) self::getAccount();
            return $this->kid_header['kid'];
        }

        public function log($message){
            echo DEBUG ? " - ".$message."\n" : null;
            if (LOG) {
                if (!file_exists(TMP_DIR.'/logs')) {
                    mkdir(TMP_DIR.'/logs', 0755, true);
                }
                $log  = '['.date("c").'] '.$message.PHP_EOL;
                file_put_contents(TMP_DIR.'/logs/nginx.'.date("d-m-Y").'.txt', $log, FILE_APPEND);
                
                shell_exec('chown www-data:www-data '.TMP_DIR.'/logs -R');
            }
        }

        protected function get_openssl_error(){
            $out=array();
            $arr=error_get_last();
            if (is_array($arr)){
                $out[]=$arr['message'];
            }
            $out[]=openssl_error_string();
            return implode(' | ',$out);
        }
        
        protected function getAccount(){
            $this->log('Getting account info');
            $ret=$this->request('newAccount',array('onlyReturnExisting'=>true));
            $this->log('Account info retrieved');
            return $ret;
        }

        protected function keyAuthorization($token){
            return $token.'.'.$this->thumbprint;
        }

        protected function request($type,$payload='',$retry=false){
            if (!$this->jwk_header) {
                throw new Exception('use loadAccountKey to load an account key');
            }

            if (!$this->resources){
                $this->log('Initializing ACME v2 '.$this->mode.' environment');
                $ret=$this->http_request($this->directory); // Read ACME Directory
                if (!is_array($ret['body'])) {
                    throw new Exception('Failed to read directory: '.$this->directory);
                }
                $this->resources=$ret['body']; // store resources for later use
                $this->log('Initialized');
            }

            if (0===stripos($type,'http')) {
                $this->resources['_tmp']=$type;
                $type='_tmp';
            }

            try {
                $ret=$this->http_request($this->resources[$type],json_encode(
                    $this->jws_encapsulate($type,$payload)
                ));
            }catch(ACME_Exception $e){ // retry previous request once, if replay-nonce expired/failed
                if (!$retry && $e->getType()==='urn:ietf:params:acme:error:badNonce') {
                    $this->log('Replay-Nonce expired, retrying previous request');
                    return $this->request($type,$payload,true);
                }
                throw $e; // rethrow all other exceptions
            }

            if (!$this->kid_header['kid'] && $type==='newAccount'){
                $this->kid_header['kid']=$ret['headers']['location'];
                $this->log('AccountID: '.$this->kid_header['kid']);
            }

            return $ret;
        }
        
        protected function jws_encapsulate($type,$payload,$is_inner_jws=false){ // RFC7515
            if ($type==='newAccount' || $is_inner_jws) {
                $protected=$this->jwk_header;
            }else{
                $this->getAccountID();
                $protected=$this->kid_header;
            }

            if (!$is_inner_jws) {
                if (!$this->nonce) {
                    $ret=$this->http_request($this->resources['newNonce'],false);
                }
                $protected['nonce']=$this->nonce;
            }

            $protected['url']=$this->resources[$type];

            $protected64=$this->base64url(json_encode($protected));
            $payload64=$this->base64url(is_string($payload)?$payload:json_encode($payload));

            if (false===openssl_sign(
                $protected64.'.'.$payload64,
                $signature,
                $this->account_key,
                'SHA'.$this->sha_bits
            )){
                throw new Exception('Failed to sign payload !'.' ('.$this->get_openssl_error().')');
            }

            return array(
                'protected'=>$protected64,
                'payload'=>$payload64,
                'signature'=>$this->base64url($this->jwk_header['alg'][0]=='R'?$signature:$this->asn2signature($signature,ceil($this->bits/8)))
            );
        }
        
        private function asn2signature($asn,$pad_len){
            if ($asn[0]!=="\x30") throw new Exception('ASN.1 SEQUENCE not found !');
            $asn=substr($asn,$asn[1]==="\x81"?3:2);
            if ($asn[0]!=="\x02") throw new Exception('ASN.1 INTEGER 1 not found !');
            $R=ltrim(substr($asn,2,ord($asn[1])),"\x00");
            $asn=substr($asn,ord($asn[1])+2);
            if ($asn[0]!=="\x02") throw new Exception('ASN.1 INTEGER 2 not found !');
            $S=ltrim(substr($asn,2,ord($asn[1])),"\x00");
            return str_pad($R,$pad_len,"\x00",STR_PAD_LEFT).str_pad($S,$pad_len,"\x00",STR_PAD_LEFT);
        }
        
        protected function base64url($data){ // RFC7515 - Appendix C
            return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
        }
        
        private function json_decode($str){
            $ret=json_decode($str,true);
            if ($ret===null) {
                throw new Exception('Could not parse JSON: '.$str);
            }
            return $ret;
        }

        private function http_request($url,$data=null){
            if ($this->ch===null) {
                if (extension_loaded('curl') && $this->ch=curl_init()) {
                    $this->log('Using cURL');
                }elseif(ini_get('allow_url_fopen')){
                    $this->ch=false;
                    $this->log('Using fopen wrappers');
                }else{
                    throw new Exception('Can not connect, no cURL or fopen wrappers enabled !');
                }
            }
            $method=$data===false?'HEAD':($data===null?'GET':'POST');
            $user_agent='ACMECert v2.6 (+https://github.com/skoerfgen/ACMECert)';
            $header=($data===null||$data===false)?array():array('Content-Type: application/jose+json');
            if ($this->ch) {
                $headers=array();
                curl_setopt_array($this->ch,array(
                    CURLOPT_URL=>$url,
                    CURLOPT_FOLLOWLOCATION=>true,
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_TCP_NODELAY=>true,
                    CURLOPT_NOBODY=>$data===false,
                    CURLOPT_USERAGENT=>$user_agent,
                    CURLOPT_CUSTOMREQUEST=>$method,
                    CURLOPT_HTTPHEADER=>$header,
                    CURLOPT_POSTFIELDS=>$data,
                    CURLOPT_HEADERFUNCTION=>function($ch,$header)use(&$headers){
                        $headers[]=$header;
                        return strlen($header);
                    }
                ));
                $took=microtime(true);
                $body=curl_exec($this->ch);
                $took=round(microtime(true)-$took,2).'s';
                if ($body===false) throw new Exception('HTTP Request Error: '.curl_error($this->ch));
            }else{
                $opts=array(
                    'http'=>array(
                        'header'=>$header,
                        'method'=>$method,
                        'user_agent'=>$user_agent,
                        'ignore_errors'=>true,
                        'timeout'=>60,
                        'content'=>$data
                    )
                );
                $took=microtime(true);
                $body=file_get_contents($url,false,stream_context_create($opts));
                $took=round(microtime(true)-$took,2).'s';
                if ($body===false) throw new Exception('HTTP Request Error: '.$this->get_openssl_error());
                $headers=$http_response_header;
            }
            
            $headers=array_reduce( // parse http response headers into array
                array_filter($headers,function($item){ return trim($item)!=''; }),
                function($carry,$item)use(&$code){
                    $parts=explode(':',$item,2);
                    if (count($parts)===1){
                        list(,$code)=explode(' ',trim($item),3);
                        $carry=array();
                    }else{
                        list($k,$v)=$parts;
                        $carry[strtolower(trim($k))]=trim($v);
                    }
                    return $carry;
                },
                array()
            );
            $this->log('  '.$url.' ['.$code.'] ('.$took.')');

            if (!empty($headers['replay-nonce'])) $this->nonce=$headers['replay-nonce'];

            if (!empty($headers['content-type'])){
                switch($headers['content-type']){
                    case 'application/json':
                        $body=$this->json_decode($body);
                    break;
                    case 'application/problem+json':
                        $body=$this->json_decode($body);
                        throw new ACME_Exception($body['type'],$body['detail'],
                            array_map(function($subproblem){
                                return new ACME_Exception(
                                    $subproblem['type'],
                                    '"'.$subproblem['identifier']['value'].'": '.$subproblem['detail']
                                );
                            },isset($body['subproblems'])?$body['subproblems']:array())
                        );
                    break;
                }
            }

            if ($code[0]!='2') {
                throw new Exception('Invalid HTTP-Status-Code received: '.$code.': '.$url);
            }

            $ret=array(
                'code'=>$code,
                'headers'=>$headers,
                'body'=>$body
            );

            return $ret;
        }
    }

    class ACME_Exception extends Exception {
        private $type,$subproblems;
        function __construct($type,$detail,$subproblems=array()){
            $this->type=$type;
            $this->subproblems=$subproblems;
            parent::__construct($detail.' ('.$type.')');
        }
        function getType(){
            return $this->type;
        }
        function getSubproblems(){
            return $this->subproblems;
        }
    }

    class ACMECert extends ACMEv2 { // ACMECert - PHP client library for Let's Encrypt (ACME v2)

        public function register($termsOfServiceAgreed=false,$contacts=array()){
            $this->log('Registering account');

            $ret=$this->request('newAccount',array(
                'termsOfServiceAgreed'=>(bool)$termsOfServiceAgreed,
                'contact'=>$this->make_contacts_array($contacts)
            ));
            $this->log($ret['code']==201?'Account registered':'Account already registered');
            return $ret['body'];
        }

        public function update($contacts=array()){
            $this->log('Updating account');
            $ret=$this->request($this->getAccountID(),array(
                'contact'=>$this->make_contacts_array($contacts)
            ));
            $this->log('Account updated');
            return $ret['body'];
        }

        public function getAccount(){
            $ret=parent::getAccount();
            return $ret['body'];
        }

        public function deactivateAccount(){
            $this->log('Deactivating account');
            $ret=$this->deactivate($this->getAccountID());
            $this->log('Account deactivated');
            return $ret;
        }

        public function deactivate($url){
            $this->log('Deactivating resource: '.$url);
            $ret=$this->request($url,array('status'=>'deactivated'));
            $this->log('Resource deactivated');
            return $ret['body'];
        }

        public function keyChange($new_account_key_pem){ // account key roll-over
            $ac2=new ACMEv2();
            $ac2->loadAccountKey($new_account_key_pem);
            $account=$this->getAccountID();
            $ac2->resources=$this->resources;

            $this->log('Account Key Roll-Over');

            $ret=$this->request('keyChange',
                $ac2->jws_encapsulate('keyChange',array(
                    'account'=>$account,
                    'oldKey'=>$this->jwk_header['jwk']
                ),true)
            );
            $this->log('Account Key Roll-Over successful');

            $this->loadAccountKey($new_account_key_pem);
            return $ret['body'];
        }

        public function revoke($pem){
            if (false===($res=openssl_x509_read($pem))){
                throw new Exception('Could not load certificate: '.$pem.' ('.$this->get_openssl_error().')');
            }
            if (false===(openssl_x509_export($res,$certificate))){
                throw new Exception('Could not export certificate: '.$pem.' ('.$this->get_openssl_error().')');
            }

            $this->log('Revoking certificate');
            $this->request('revokeCert',array(
                'certificate'=>$this->base64url($this->pem2der($certificate))
            ));
            $this->log('Certificate revoked');
        }

        public function getCertificateChain($pem,$domain_config,$callback){
            $domain_config=array_change_key_case($domain_config,CASE_LOWER);
            $domains=array_keys($domain_config);

            // autodetect if Private Key or CSR is used
            if ($key=openssl_pkey_get_private($pem)){ // Private Key detected
                openssl_free_key($key);
                $this->log('Generating CSR');
                $csr=$this->generateCSR($pem,$domains);
            }elseif(openssl_csr_get_subject($pem)){ // CSR detected
                $this->log('Using provided CSR');
                if (0===strpos($pem,'file://')) {
                    $csr=file_get_contents(substr($pem,7));
                    if (false===$csr) {
                        throw new Exception('Failed to read CSR from '.$pem.' ('.$this->get_openssl_error().')');
                    }
                }else{
                    $csr=$pem;
                }
            }else{
                throw new Exception('Could not load Private Key or CSR ('.$this->get_openssl_error().'): '.$pem);
            }

            $this->getAccountID(); // get account info upfront to avoid mixed up logging order

            // === Order ===
            $this->log('Creating Order');
            $ret=$this->request('newOrder',array(
                'identifiers'=>array_map(
                    function($domain){
                        return array('type'=>'dns','value'=>$domain);
                    },
                    $domains
                )
            ));
            $order=$ret['body'];
            $order_location=$ret['headers']['location'];
            $this->log('Order created: '.$order_location);

            // === Authorization ===
            if ($order['status']==='ready') {
                $this->log('All authorizations already valid, skipping validation altogether');
            }else{
                $groups=array();
                $auth_count=count($order['authorizations']);

                foreach($order['authorizations'] as $idx=>$auth_url){
                    $this->log('Fetching authorization '.($idx+1).' of '.$auth_count);
                    $ret=$this->request($auth_url,'');
                    $authorization=$ret['body'];

                    // wildcard authorization identifiers have no leading *.
                    $domain=( // get domain and add leading *. if wildcard is used
                        isset($authorization['wildcard']) &&
                        $authorization['wildcard'] ?
                        '*.':''
                    ).$authorization['identifier']['value'];

                    if ($authorization['status']==='valid') {
                        $this->log('Authorization of '.$domain.' already valid, skipping validation');
                        continue;
                    }

                    // groups are used to be able to set more than one TXT Record for one subdomain
                    // when using dns-01 before firing the validation to avoid DNS caching problem
                    $groups[
                        $domain_config[$domain]['challenge'].
                        '|'.
                        ltrim($domain,'*.')
                    ][$domain]=array($auth_url,$authorization);
                }

                // make sure dns-01 comes last to avoid DNS problems for other challenges
                krsort($groups);

                foreach($groups as $group){
                    $pending_challenges=array();

                    try { // make sure that pending challenges are cleaned up in case of failure
                        foreach($group as $domain=>$arr){
                            list($auth_url,$authorization)=$arr;

                            $config=$domain_config[$domain];
                            $type=$config['challenge'];

                            $challenge=$this->parse_challenges($authorization,$type,$challenge_url);

                            $opts=array(
                                'domain'=>$domain,
                                'config'=>$config
                            );
                            list($opts['key'],$opts['value'])=$challenge;

                            $this->log('Triggering challenge callback for '.$domain.' using '.$type);
                            $remove_cb=$callback($opts);

                            $pending_challenges[]=array($remove_cb,$opts,$challenge_url,$auth_url);
                        }

                        foreach($pending_challenges as $arr){
                            list($remove_cb,$opts,$challenge_url,$auth_url)=$arr;
                            $this->log('Notifying server for validation of '.$opts['domain']);
                            $this->request($challenge_url,new StdClass);

                            $this->log('Waiting for server challenge validation');
                            sleep(1);

                            if (!$this->poll('pending',$auth_url,$body)) {
                                $this->log('Validation failed: '.$opts['domain']);

                                $ret=array_values(array_filter($body['challenges'],function($item){
                                    return isset($item['error']);
                                }));

                                $error=$ret[0]['error'];
                                throw new ACME_Exception($error['type'],'Challenge validation failed: '.$error['detail']);
                            }else{
                                $this->log('Validation successful: '.$opts['domain']);
                            }
                        }

                    }finally{ // cleanup pending challenges
                        foreach($pending_challenges as $arr){
                            list($remove_cb,$opts)=$arr;
                            if ($remove_cb) {
                                $this->log('Triggering remove callback for '.$opts['domain']);
                                $remove_cb($opts);
                            }
                        }
                    }
                }
            }

            $this->log('Finalizing Order');

            $ret=$this->request($order['finalize'],array(
                'csr'=>$this->base64url($this->pem2der($csr))
            ));
            $ret=$ret['body'];

            if (isset($ret['certificate'])) {
                return $this->request_certificate($ret);
            }

            if ($this->poll('processing',$order_location,$ret)) {
                return $this->request_certificate($ret);
            }

            throw new Exception('Order failed');
        }

        public function generateCSR($domain_key_pem,$domains){
            if (false===($domain_key=openssl_pkey_get_private($domain_key_pem))){
                throw new Exception('Could not load domain key: '.$domain_key_pem.' ('.$this->get_openssl_error().')');
            }

            $fn=$this->tmp_ssl_cnf($domains);
            $dn=array('commonName'=>reset($domains));
            $csr=openssl_csr_new($dn,$domain_key,array(
                'config'=>$fn,
                'req_extensions'=>'SAN',
                'digest_alg'=>'sha512'
            ));
            unlink($fn);
            openssl_free_key($domain_key);

            if (false===$csr) {
                throw new Exception('Could not generate CSR ! ('.$this->get_openssl_error().')');
            }
            if (false===openssl_csr_export($csr,$out)){
                throw new Exception('Could not export CSR ! ('.$this->get_openssl_error().')');
            }

            return $out;
        }

        private function generateKey($opts){
            $fn=$this->tmp_ssl_cnf();
            $config=array('config'=>$fn)+$opts;
            if (false===($key=openssl_pkey_new($config))){
                throw new Exception('Could not generate new private key ! ('.$this->get_openssl_error().')');
            }
            if (false===openssl_pkey_export($key,$pem,null,$config)){
                throw new Exception('Could not export private key ! ('.$this->get_openssl_error().')');
            }
            unlink($fn);
            openssl_free_key($key);
            return $pem;
        }
        
        public function generateRSAKey($bits=2048){
            return $this->generateKey(array(
                'private_key_bits'=>$bits,
                'private_key_type'=>OPENSSL_KEYTYPE_RSA
            ));
        }
        
        public function generateECKey($curve_name='P-384'){
            if (version_compare(PHP_VERSION,'7.1.0')<0) throw new Exception('PHP >= 7.1.0 required for EC keys !');
            $map=array('P-256'=>'prime256v1','P-384'=>'secp384r1','P-521'=>'secp521r1');
            if (isset($map[$curve_name])) $curve_name=$map[$curve_name];
            return $this->generateKey(array(
                'curve_name'=>$curve_name,
                'private_key_type'=>OPENSSL_KEYTYPE_EC
            ));
        }
        
        public function parseCertificate($cert_pem){
            if (false===($ret=openssl_x509_read($cert_pem))) {
                throw new Exception('Could not load certificate: '.$cert_pem.' ('.$this->get_openssl_error().')');
            }
            if (!is_array($ret=openssl_x509_parse($ret,true))) {
                throw new Exception('Could not parse certificate ('.$this->get_openssl_error().')');
            }
            return $ret;
        }

        public function getRemainingDays($cert_pem){
            $ret=$this->parseCertificate($cert_pem);
            return ($ret['validTo_time_t']-time())/86400;
        }

        public function generateALPNCertificate($domain_key_pem,$domain,$token){
            $domains=array($domain);
            $csr=$this->generateCSR($domain_key_pem,$domains);

            $fn=$this->tmp_ssl_cnf($domains,'1.3.6.1.5.5.7.1.31=critical,DER:0420'.$token."\n");
            $config=array(
                'config'=>$fn,
                'x509_extensions'=>'SAN',
                'digest_alg'=>'sha512'
            );
            $cert=openssl_csr_sign($csr,null,$domain_key_pem,1,$config);
            unlink($fn);
            if (false===$cert) {
                throw new Exception('Could not generate self signed certificate ! ('.$this->get_openssl_error().')');
            }
            if (false===openssl_x509_export($cert,$out)){
                throw new Exception('Could not export self signed certificate ! ('.$this->get_openssl_error().')');
            }
            return $out;
        }

        private function parse_challenges($authorization,$type,&$url){
            foreach($authorization['challenges'] as $challenge){
                if ($challenge['type']!=$type) continue;

                $url=$challenge['url'];

                switch($challenge['type']){
                    case 'dns-01':
                        return array(
                            '_acme-challenge.'.$authorization['identifier']['value'],
                            $this->base64url(hash('sha256',$this->keyAuthorization($challenge['token']),true))
                        );
                    break;
                    case 'http-01':
                        return array(
                            '/.well-known/acme-challenge/'.$challenge['token'],
                            $this->keyAuthorization($challenge['token'])
                        );
                    break;
                    case 'tls-alpn-01':
                        return array(null,hash('sha256',$this->keyAuthorization($challenge['token'])));
                    break;
                }
            }
            throw new Exception('Challenge type: "'.$type.'" not available');
        }

        private function poll($initial,$type,&$ret){
            $max_tries=8;
            for($i=0;$i<$max_tries;$i++){
                $ret=$this->request($type);
                $ret=$ret['body'];
                if ($ret['status']!==$initial) return $ret['status']==='valid';
                $s=pow(2,min($i,6));
                if ($i!==$max_tries-1){
                    $this->log('Retrying in '.($s).'s');
                    sleep($s);
                }
            }
            throw new Exception('Aborted after '.$max_tries.' tries');
        }

        private function request_certificate($ret){
            $this->log('Requesting certificate-chain');
            $ret=$this->request($ret['certificate'],'');
            if ($ret['headers']['content-type']!=='application/pem-certificate-chain'){
                throw new Exception('Unexpected content-type: '.$ret['headers']['content-type']);
            }
            $this->log('Certificate-chain retrieved');
            return $ret['body'];
        }

        private function tmp_ssl_cnf($domains=null,$extension=''){
            if (false===($fn=tempnam(sys_get_temp_dir(), "CNF_"))){
                throw new Exception('Failed to create temp file !');
            }
            if (false===@file_put_contents($fn,
                'HOME = .'."\n".
                'RANDFILE=$ENV::HOME/.rnd'."\n".
                '[v3_ca]'."\n".
                '[req]'."\n".
                'default_bits=2048'."\n".
                ($domains?
                    'distinguished_name=req_distinguished_name'."\n".
                    '[req_distinguished_name]'."\n".
                    '[v3_req]'."\n".
                    '[SAN]'."\n".
                    'subjectAltName='.
                    implode(',',array_map(function($domain){
                        return 'DNS:'.$domain;
                    },$domains))."\n"
                :
                    ''
                ).$extension
            )){
                throw new Exception('Failed to write tmp file: '.$fn);
            }
            return $fn;
        }

        private function pem2der($pem) {
            return base64_decode(implode('',array_slice(
                array_map('trim',explode("\n",trim($pem))),1,-1
            )));
        }

        private function make_contacts_array($contacts){
            if (!is_array($contacts)) {
                $contacts=$contacts?array($contacts):array();
            }
            return array_map(function($contact){
                return 'mailto:'.$contact;
            },$contacts);
        }
    }
}

$nginx = new Nginx($this);

$routes = $this->find('route', 'has_change = 1');

$nginx->build($routes);

if (LOG && file_exists(TMP_DIR.'/logs/nginx.'.date("d-m-Y").'.txt')) {
	echo file_get_contents(TMP_DIR.'/logs/nginx.'.date("d-m-Y").'.txt');
}
