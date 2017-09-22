<?php
/**
 * Task Build NGINX
 */

if (!defined('DEBUG')) {
    define('DEBUG', true);
}

if (!class_exists('LetsEncrypt')) {
    class LetsEncrypt
    {
        public $ca = 'https://acme-v01.api.letsencrypt.org';
        //public $ca = 'https://acme-staging.api.letsencrypt.org'; // sandbox

        public $license = 'https://letsencrypt.org/documents/LE-SA-v1.1.1-August-1-2016.pdf';
        public $countryCode = 'GB';
        public $state = "Great Britain";
        public $challenge = 'http-01'; // http-01 challange only
        public $contact = array();
        public $last_result = '';
        private $certificatesDir;
        private $webRootDir;
        private $runner;
        private $client;
        private $accountKeyPath;

        /**
    *
    */
        public function __construct($certificatesDir, $webRootDir, ClientInterface $client = null)
        {
            $this->certificatesDir = $certificatesDir;
            $this->webRootDir = $webRootDir;
            $this->client = $client ? $client : new Client($this->ca);
            $this->accountKeyPath = $certificatesDir . '/_account/private.pem';
        }

        /**
    *
    */
        public function initAccount($force = false)
        {
            if (!is_file($this->accountKeyPath) || $force) {
                $this->log('Starting new account registration');
                $this->generateKey(dirname($this->accountKeyPath));
                $this->postNewReg();
                $this->log('New account certificate registered');
            } else {
                $this->log('Account already registered. Continuing.');
            }
        }

        /**
    *
    */
        public function signDomains(array $domains, $reuseCsr = false)
        {
            startDomainSigning:

            $this->log('Starting certificate generation process for domains');

            $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
            $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

            foreach ($domains as $domain) {
                $this->log("Requesting challenge for $domain");

                $response = $this->signedRequest(
                    "/acme/new-authz",
                    array("resource" => "new-authz", "identifier" => array("type" => "dns", "value" => $domain))
                );

                $response = json_decode($response, true);

                if (empty($response['challenges'])) {

                    // check expired letsencrypt registration
                    if ($response['detail'] == 'No registration exists matching provided key' && $response['status'] == 403) {
                        $this->log('Account expired due to 403 response from LetsEncrypt. Attempting to create new account!');
                        sleep(5);
                        $this->initAccount(true);
                        goto startDomainSigning;
                    }

                    throw new \RuntimeException("1. HTTP Challenge for $domain is not available. Whole response: " . print_r($response, true));
                }

                $self = $this;
                $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self) {
                    return $v ? $v : ($w['type'] == $self->challenge ? $w : false);
                });

                if (!$challenge) {
                    throw new \RuntimeException("2. HTTP Challenge for $domain is not available. Whole response: ".print_r($response, true));
                }

                $this->log("Got challenge token for $domain");
                $location = $this->client->getLastLocation();

                $directory = $this->webRootDir . '/.well-known/acme-challenge';
                $tokenPath = $directory . '/' . $challenge['token'];

                if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                    throw new \RuntimeException("Couldn't create directory to expose challenge: ${tokenPath}");
                }

                $header = array(
                    // need to be in precise order!
                    "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                    "kty" => "RSA",
                    "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

                );
                $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

                file_put_contents($tokenPath, $payload);
                chmod($tokenPath, 0644);

                // 3. verification process itself
                $uri = "http://${domain}/.well-known/acme-challenge/${challenge['token']}";

                $this->log("Token for $domain saved at $tokenPath and should be available at $uri");

                // simple self check
                if ($payload !== trim(@file_get_contents($uri))) {
                    throw new \RuntimeException("Please check $uri - token not available");
                }

                $this->log("Sending request to challenge");

                // send request to challenge
                $result = $this->signedRequest(
                    $challenge['uri'],
                    array(
                        "resource" => "challenge",
                        "type" => $this->challenge,
                        "keyAuthorization" => $payload,
                        "token" => $challenge['token']
                    )
                );

                $result = json_decode($result, true);

                // waiting loop
                do {
                    if (empty($result['status']) || $result['status'] == "invalid") {
                        throw new \RuntimeException("Verification ended with error: " . print_r($result, true));
                    }
                    $ended = !($result['status'] === "pending");

                    if (!$ended) {
                        $this->log("Verification pending, sleeping 1s");
                        sleep(1);
                    }

                    $result = $this->client->get($location);

                    $result = json_decode($result, true);
                } while (!$ended);

                $this->log("Verification ended with status: ".$result['status']);
                @unlink($tokenPath);
            }

            // requesting certificate
            $domainPath = $this->getDomainPath(reset($domains));

            // generate private key for domain if not exist
            if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
                $this->generateKey($domainPath);
            }

            // load domain key
            $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

            $this->client->getLastLinks();

            $csr = ($reuseCsr && is_file($domainPath . "/last.csr")) ?
                $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, $domains);

            // request certificates creation
            $result = $this->signedRequest(
                "/acme/new-cert",
                array('resource' => 'new-cert', 'csr' => $csr)
            );

            if ($this->client->getLastCode() !== 201) {
                $this->last_result = json_encode($result);
                throw new \RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($result));
            }
            $location = $this->client->getLastLocation();

            // waiting loop
            $certificates = array();
            while (1) {
                $this->client->getLastLinks();

                $result = $this->client->get($location);

                if ($this->client->getLastCode() == 202) {
                    $this->log("Certificate generation pending, sleeping 1s");
                    sleep(1);
                } elseif ($this->client->getLastCode() == 200) {
                    $this->log("Got certificate! YAY!");
                    $certificates[] = $this->parsePemFromBody($result);

                    foreach ($this->client->getLastLinks() as $link) {
                        $this->log("Requesting chained cert at $link");
                        $result = $this->client->get($link);
                        $certificates[] = $this->parsePemFromBody($result);
                    }

                    break;
                } else {
                    throw new \RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());
                }
            }

            if (empty($certificates)) {
                throw new \RuntimeException('No certificates generated');
            }

            $this->log("Saving fullchain.pem");
            file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

            $this->log("Saving cert.pem");
            file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

            $this->log("Saving chain.pem");
            file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

            $this->log("Done!");
        }

        /**
    *
    */
        private function readPrivateKey($path)
        {
            if (($key = openssl_pkey_get_private('file://' . $path)) === false) {
                throw new \RuntimeException(openssl_error_string());
            }

            return $key;
        }

        /**
    *
    */
        private function parsePemFromBody($body)
        {
            $pem = chunk_split(base64_encode($body), 64, "\n");
            return "-----BEGIN CERTIFICATE-----\n" . $pem . "-----END CERTIFICATE-----\n";
        }

        /**
    *
    */
        private function getDomainPath($domain)
        {
            return $this->certificatesDir . '/' . $domain . '/';
        }

        /**
    *
    */
        private function postNewReg()
        {
            $this->log('Sending registration to letsencrypt server');

            $data = array('resource' => 'new-reg', 'agreement' => $this->license);
            if (!$this->contact) {
                $data['contact'] = $this->contact;
            }

            return $this->signedRequest(
                '/acme/new-reg',
                $data
            );
        }

        /**
    *
    */
        private function generateCSR($privateKey, array $domains)
        {
            $domain = reset($domains);
            $san = implode(",", array_map(function ($dns) {
                return "DNS:" . $dns;
            }, $domains));
            $tmpConf = tmpfile();
            $tmpConfMeta = stream_get_meta_data($tmpConf);
            $tmpConfPath = $tmpConfMeta["uri"];

            // workaround to get SAN working
            fwrite(
                $tmpConf,
                   'HOME = .
RANDFILE = $ENV::HOME/.rnd
[ req ]
default_bits = 2048
default_keyfile = privkey.pem
distinguished_name = req_distinguished_name
req_extensions = v3_req
[ req_distinguished_name ]
countryName = Country Name (2 letter code)
[ v3_req ]
basicConstraints = CA:FALSE
subjectAltName = ' . $san . '
keyUsage = nonRepudiation, digitalSignature, keyEncipherment'
            );

            $csr = openssl_csr_new(
                array(
                    "CN" => $domain,
                    "ST" => $this->state,
                    "C" => $this->countryCode,
                    "O" => "Unknown",
                ),
                $privateKey,
                array(
                    "config" => $tmpConfPath,
                    "digest_alg" => "sha256"
                )
            );

            if (!$csr) {
                throw new \RuntimeException("CSR couldn't be generated! " . openssl_error_string());
            }

            openssl_csr_export($csr, $csr);
            fclose($tmpConf);

            $csrPath = $this->getDomainPath($domain) . "/last.csr";
            file_put_contents($csrPath, $csr);

            return $this->getCsrContent($csrPath);
        }

        /**
    *
    */
        private function getCsrContent($csrPath)
        {
            $csr = file_get_contents($csrPath);

            preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

            return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
        }

        /**
    *
    */
        private function generateKey($outputDirectory)
        {
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
        }

        /**
    *
    */
        private function signedRequest($uri, array $payload)
        {
            $privateKey = $this->readPrivateKey($this->accountKeyPath);
            $details = openssl_pkey_get_details($privateKey);

            $header = array(
                "alg" => "RS256",
                "jwk" => array(
                    "kty" => "RSA",
                    "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                    "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
                )
            );

            $protected = $header;
            $protected["nonce"] = $this->client->getLastNonce();


            $payload64 = Base64UrlSafeEncoder::encode(str_replace('\\/', '/', json_encode($payload)));
            $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

            openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

            $signed64 = Base64UrlSafeEncoder::encode($signed);

            $data = array(
                'header' => $header,
                'protected' => $protected64,
                'payload' => $payload64,
                'signature' => $signed64
            );

            $this->log("Sending signed request to $uri");

            return $this->client->post($uri, json_encode($data));
        }

        /**
    *
    */
        protected function log($message)
        {
            echo $message."\n";
        }
    }

    interface ClientInterface
    {
        public function __construct($base);
        public function post($url, $data);
        public function get($url);
        public function getLastNonce();
        public function getLastLocation();
        public function getLastCode();
        public function getLastLinks();
    }

    class Client implements ClientInterface
    {
        private $lastCode;
        private $lastHeader;

        private $base;

        /**
    *
    */
        public function __construct($base)
        {
            $this->base = $base;
        }

        /**
    *
    */
        private function curl($method, $url, $data = null)
        {
            $headers = array('Accept: application/json', 'Content-Type: application/json');
            $handle = curl_init();
            curl_setopt($handle, CURLOPT_URL, preg_match('~^http~', $url) ? $url : $this->base.$url);
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_HEADER, true);

            switch ($method) {
                case 'GET':
                    break;
                case 'POST':
                    curl_setopt($handle, CURLOPT_POST, true);
                    curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
                    break;
            }
            $response = curl_exec($handle);

            if (curl_errno($handle)) {
                throw new \RuntimeException('Curl: '.curl_error($handle));
            }

            $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

            $header = substr($response, 0, $header_size);
            $body = substr($response, $header_size);

            $this->lastHeader = $header;
            $this->lastCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

            try {
                $data = $body;
            } catch (Exception $e) {
                echo DEBUG ? " - JSON parse error: ".$e->getMessage()."\n" : null;
            }

            return $data === null ? $body : $data;
        }

        /**
    *
    */
        public function post($url, $data)
        {
            return $this->curl('POST', $url, $data);
        }

        /**
    *
    */
        public function get($url)
        {
            return $this->curl('GET', $url);
        }

        /**
    *
    */
        public function getLastNonce()
        {
            if (preg_match('~Replay\-Nonce: (.+)~i', $this->lastHeader, $matches)) {
                return trim($matches[1]);
            }

            $this->curl('GET', '/directory');
            return $this->getLastNonce();
        }

        /**
    *
    */
        public function getLastLocation()
        {
            if (preg_match('~Location: (.+)~i', $this->lastHeader, $matches)) {
                return trim($matches[1]);
            }
            return null;
        }

        /**
    *
    */
        public function getLastCode()
        {
            return $this->lastCode;
        }

        /**
    *
    */
        public function getLastLinks()
        {
            preg_match_all('~Link: <(.+)>;rel="up"~', $this->lastHeader, $matches);
            return $matches[1];
        }
    }

    class Base64UrlSafeEncoder
    {
        /**
    *
    */
        public static function encode($input)
        {
            return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
        }

        /**
    *
    */
        public static function decode($input)
        {
            $remainder = strlen($input) % 4;
            if ($remainder) {
                $padlen = 4 - $remainder;
                $input .= str_repeat('=', $padlen);
            }
            return base64_decode(strtr($input, '-_', '+/'));
        }
    }
}

if (!class_exists('Nginx')) {
    class Nginx
    {
        public function __construct($task)
        {
            $this->task = $task;
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
                echo DEBUG ? "   - ".$row['name'].".\n" : null;

                $restart_nginx = true;

                $this->path = "/etc/nginx/proxied/servers/".basename($row['name']);

                // the server has a delete flag
                if (!empty($row['delete'])) {
                    echo DEBUG ? "   - Request to remove route.\n" : null;
                    if ($this->path != "/etc/nginx/proxied/servers/" && file_exists($this->path)) {
                        echo DEBUG ? "   - Removing route for {$row['name']}\n" : null;

                        // //
                        // $this->runner->log([
                        //     'title' => 'Removing route '.$row['name'],
                        //     'stage' => 'delete',
                        //     'state' => 'success',
                        //     'route' => $row['name']
                        // ]);

                        // remove nginx configs
                        echo DEBUG ? "   - Deleting nginx configs for {$row['name']}\n" : null;
                        system("rm -rf ".escapeshellarg($this->path));
                    }

                    $this->task->trash($row);

                    continue;
                }

                // the server has a rename flag
                if (!empty($row['rename'])) {
                    echo DEBUG ? "   - Request to rename route\n" : null;

                    if (file_exists('/etc/nginx/proxied/servers/'.$row['rename'].'/')) {
                        rename(
                            "/etc/nginx/proxied/servers/".$row['rename'].'/',
                            $this->path.'/'
                        );
                        echo DEBUG ? "   - Route directory renamed\n" : null;
                    } else {
                        echo DEBUG ? "   - Route directory not found, skipping..\n" : null;
                    }

                    $row->rename = '';
                    $this->task->store($row);
                }

                // check for config folder
                if (!file_exists($this->path)) {
                    echo DEBUG ? "   - Create config directory {$this->path}\n" : null;
                    mkdir($this->path, 0755, true);
                }

                $has_error = false;

                // build nginx configs
                if (!$this->config_upstream($row) && !$has_error) {
                    echo DEBUG ? "   - \e[1;31mNGINX was not reloaded - upstream.conf - check log for error!\e[0m\n" : null;
                    $row->has_error = 1;
                    $this->task->store($row);
                }
                if (!$this->config_http($row) && !$has_error) {
                    echo DEBUG ? "   - \e[1;31mNGINX was not reloaded - http.conf - check log for error!\e[0m\n" : null;
                    $row->has_error = 1;
                    $this->task->store($row);
                }
                if (!$this->config_https($row) && !$has_error) {
                    echo DEBUG ? "   - \e[1;31mNGINX was not reloaded - https.conf - check log for error!\e[0m\n" : null;
                    $row->has_error = 1;
                    $this->task->store($row);
                }
                
                $row->has_change = 0;
                $this->task->store($row);
            }

            // reload nginx if config test passed
            if ($restart_nginx === true) {
                if ($this->config_test_nginx()) {
                    exec('/usr/sbin/nginx -s reload 2>&1', $out, $exit_code);

                    echo DEBUG ? "   - Reloading NGINX\n" : null;

                    // out is not empty - when is should be on success
                    if (!empty($out)) {
                        echo DEBUG ? "   - \e[1;33mNGINX was reloaded with warnings! Some routes may be effected.\e[0m\n" : null;
                    }
                    // all is good
                    else {
                        echo DEBUG ? "   - \e[1;32mNGINX was reloaded\e[0m\n" : null;
                    }
                } else {
                    echo DEBUG ? "   - \e[1;31mNGINX was not reloaded\e[0m\n" : null;
                }
            }

            return;
        }

        /**
     *
     */
        public function config_test_nginx()
        {
            echo DEBUG ? "   - Testing NGINX config\n" : null;
            exec('/usr/sbin/nginx -t 2>&1', $out, $exit_code);

            $this->config_test_result = $out;

            return ($exit_code == 0);
        }

        /**
     *
     */
        public function config_http($row)
        {
            echo DEBUG ? "   - Building NGINX HTTP server config\n" : null;

            $domains = [];
            foreach ($row->ownDomain as $domain) {
                $domains[] = $domain->name;
            }

            return file_put_contents(
                $this->path.'/http.conf',
                '# Auto generated on '.date_create()->format('Y-m-d H:i:s').'
# Do not edit this file as any changes will be overwritten!

server {
    listen       80;
    server_name '.implode(' ', $domains).';

    # logs
    access_log  /var/log/nginx/'.$row['name'].'.access.log;
    error_log  /var/log/nginx/'.$row['name'].'.error.log;

    root   /usr/share/nginx/html;
    index  index.html index.htm;

    # redirect server error pages
    include /etc/nginx/proxied/includes/error_pages.conf;

    # send request back to backend
    location / {
        # change to upstream directive e.g http://backend
        proxy_pass  http://'.$row['name'].';

        include /etc/nginx/proxied/includes/proxy.conf;
    }

    # Lets Encrypt
    location ^~ /.well-known/ {
        root /usr/share/nginx/html/letsencrypt;
        index index.html index.htm;

        try_files $uri $uri/ =404;
    }
}
'
            );
        }

        /**
     *
     */
        public function config_https($row)
        {
            echo DEBUG ? "   - Building NGINX HTTPS server config\n" : null;

            //no ssl
            if (empty($row['ssl_type'])) {
                echo DEBUG ? "   - Removing unused SSL configs\n" : null;
                $logit = false;

                //remove https.conf if its there
                if (file_exists($this->path.'/https.conf')) {
                    $logit = true;
                    echo DEBUG ? "   - https.sh removed\n" : null;
                    unlink($this->path.'/https.conf');
                }

                return true;
            }

            $sslPath = null;

            $domains = [];
            foreach ($row->ownDomain as $domain) {
                $domains[] = $domain->name;
            }

            // check certs for letsencrypt
            if (isset($row['ssl_type']) && $row['ssl_type'] == 'letsencrypt') {
                date_default_timezone_set("UTC");
                echo DEBUG ? "   - Using LetsEncrypt certificate\n" : null;

                $sslPath = '/etc/letsencrypt/live';

                echo DEBUG ? "   - Certificate will be written to: {$sslPath}/{$domains[0]}\n" : null;

                // make sure our cert location exists
                if (!is_dir($sslPath)) {
                    echo DEBUG ? "   - Certs path is not a directory\n" : null;
                    // Make sure nothing is already there.
                    if (file_exists($sslPath)) {
                        echo DEBUG ? "   - Removing existing path contents ready for certificate\n" : null;
                        array_map('unlink', glob("$sslPath/*.*"));
                        rmdir($sslPath);
                    }
                    echo DEBUG ? "   - Certificates directory created\n" : null;
                    mkdir($sslPath);
                }

                // do we need to create or upgrade our cert? Assume no to start with.
                $needsgen = false;

                // display number of domains in cert
                echo DEBUG ? "   - ".count($domains)." domains for certificate\n" : null;

                // the first domain is the main domain used
                $certfile = $sslPath.'/'.$domains[0].'/cert.pem';
                echo DEBUG ? "   - Certificate: $certfile\n" : null;

                //
                if (!file_exists($certfile)) {
                    echo DEBUG ? "   - Certificate: cert.pem - no exsiting certificate found, triggering initial generation\n" : null;
                    // we don't have a cert, so we need to request one.
                    $needsgen = true;
                } else {
                    echo DEBUG ? "   - Certificate: cert.pem - found exsiting certificate, checking validity\n" : null;

                    // varify certificate date.
                    $certdata = openssl_x509_parse(file_get_contents($certfile));
                    echo DEBUG ? "   - Certificate: cert.pem - domain expires on ".date('d/m/Y h:i:s', $certdata['validTo_time_t'])."\n" : null;

                    // if it expires in less than a month, we want to renew it.
                    $renewafter = $certdata['validTo_time_t']-(86400*30);

                    // update control host with the certificates expiry date
                    echo DEBUG ? "   - Notifying control host with the certificates expiry date\n" : null;
                    $row->certificate_expiry = $certdata['validTo_time_t'];
                    $this->task->store($row);

                    if (time() > $renewafter) {
                        echo DEBUG ? "   - Certificate: cert.pem - renewing certificate\n" : null;
                        // less than a month left, we need to renew.
                        $needsgen = true;
                    } else {
                        echo DEBUG ? "   - Certificate: cert.pem - skipping renewal\n" : null;
                    }
                }

                // we need to generate a certificate
                if ($needsgen) {
                    $error = null;
                    try {
                        $le = new LetsEncrypt($sslPath, '/usr/share/nginx/html/letsencrypt');
                        $le->initAccount();
                        $le->signDomains($domains);
                    } catch (\Exception $e) {
                        $row->has_error = 1;
                        $row->error = json_encode($e);
                        $this->task->store($row);

                        if (DEBUG) {
                            echo "   - Certificate error: \n";
                            print_r($le->last_result);
                            print_r($e);
                        }
                        return;
                    }

                    if (empty($error)) {
                        // concat certs into single domain.tld.pem
                        file_put_contents(
                            "$sslPath/{$domains[0]}/{$domains[0]}.pem",
                            file_get_contents("$sslPath/{$domains[0]}/fullchain.pem")."\n".
                            file_get_contents("$sslPath/{$domains[0]}/private.pem")
                        );

                        if (DEBUG) {
                            echo file_get_contents($sslPath.'/'.$domains[0].'/cert.pem');
                            echo file_get_contents($sslPath.'/'.$domains[0].'/chain.pem');
                            echo file_get_contents($sslPath.'/'.$domains[0].'/fullchain.pem');
                            echo file_get_contents($sslPath.'/'.$domains[0].'/private.pem');
                            echo file_get_contents($sslPath.'/'.$domains[0].'/public.pem');
                            echo file_get_contents($sslPath.'/'.$domains[0].'/'.$domains[0].'.pem');
                        }

                        // varify certificate date.
                        $certdata = openssl_x509_parse(file_get_contents($sslPath.'/'.$domains[0].'/cert.pem'));
                        echo DEBUG ? "   - Certificate: cert.pem - domain expires on ".date('d/m/Y h:i:s', $certdata['validTo_time_t'])."\n" : null;

                        // update control host with the certificates expiry date
                        echo DEBUG ? "   - Notifying control host with the certificates expiry date\n" : null;

                        $row->certificate_expiry = $certdata['validTo_time_t'];
                        $this->task->store($row);
                    }
                }
            }

            // check certs for manual ssl
            if (isset($row['ssl_type']) && $row['ssl_type'] == 'manual') {
                echo DEBUG ? "   - SSL is type manual\n" : null;
                $sslPath = '/etc/ssl/live/'.$domains[0];
            }

            // check certs for manual ssl
            if (isset($row['ssl_type']) && $row['ssl_type'] == 'selfsigned') {
                echo DEBUG ? "   - SSL is type selfsigned\n" : null;
                $sslPath = '/etc/ssl/selfsigned';
            }

            if (empty($sslPath) ||
                !file_exists($sslPath.'/'.$domains[0].'/fullchain.pem') ||
                !file_exists($sslPath.'/'.$domains[0].'/private.pem')
               ) {
                echo DEBUG ? "  - No SSL to setup\n" : null;
                return true;
            }

            echo DEBUG ? "   - Building https.conf\n" : null;

            return file_put_contents(
                $this->path.'/https.conf',
                '# Auto generated on '.date_create()->format('Y-m-d H:i:s').'
# Do not edit this file as any changes will be overwritten!

server {
    listen       443;
    server_name  '.implode(' ', $domains).';

    # change log names
    access_log  /var/log/nginx/'.$row['name'].'.access.log;
    error_log  /var/log/nginx/'.$row['name'].'.error.log;

    root   /usr/share/nginx/html;
    index  index.html index.htm;

    # redirect server error pages
    include /etc/nginx/proxied/includes/error_pages.conf;

    # add ssl certs
    ssl_certificate      '.$sslPath.'/'.$domains[0].'/fullchain.pem;
    ssl_certificate_key  '.$sslPath.'/'.$domains[0].'/private.pem;

    # add ssl seetings
    include /etc/nginx/proxied/includes/ssl.conf;

    ## send request back to backend ##
    location / {
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
            echo DEBUG ? "\n   - Building NGINX upstream config\n" : null;

            $upstreams = [];
            foreach ($row['ownUpstream'] as $upstream) {
                $upstreams[] = [
                    'ip' => $upstream['ip'],
                    'port' => $upstream['port'],
                ];
            }

            $data = null;
            // custom ip
            if (!empty($row['ip']) || !empty($upstreams[0]['ip'])) {
                echo DEBUG ? "   - Upstream IP: ".$upstreams[0]['ip'].":".$upstreams[0]['port']."\n" : null;

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

$nginx = new Nginx($this);

$routes = $this->find('route', 'has_change = 1');

$nginx->build($routes);
