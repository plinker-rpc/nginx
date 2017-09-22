<?php
namespace Plinker\Nginx {

    use Plinker\Tasks;

    class Manager
    {
        public $config = array();

        public function __construct(array $config = array())
        {
            $this->config = $config;

            // load model
            $this->model = new Model($this->config['database']);

            $this->tasks = new Tasks\Manager($this->config);
        }

        /**
         *
         */
        public function setup(array $params = array())
        {
            if (!file_exists('/etc/nginx')) {
                //return 'Nginx not installed! - Read the README.md for setup instructions.';
            }

            try {

                // create setup task
                $src = file_get_contents(__DIR__.'/tasks/setup.php');
                $task['nginx.setup'] = $this->tasks->create([
                    // name
                    'nginx.setup',
                    // source
                    $src,
                    // type
                    'php',
                    // description
                    'Sets up nginx for plinker',
                    // default params
                    []
                ]);
                
                $this->tasks->run(['nginx.setup', [], 1]);

                $src = file_get_contents(__DIR__.'/tasks/build.php');
                $task['nginx.build'] = $this->tasks->create([
                    // name
                    'nginx.build',
                    // source
                    $src,
                    // type
                    'php',
                    // description
                    'Builds nginx',
                    // default params
                    []
                ]);

                // create nginx reload task
                $task['nginx.reload'] = $this->tasks->create([
                    // name
                    'nginx.reload',
                    // source
                    "#!/bin/bash\nnginx -s reload",
                    // type
                    'bash',
                    // description
                    'Reloads nginx',
                    // default params
                    []
                ]);
                
                $this->tasks->run(['nginx.build', [], 1]);
            } catch (\Exception $e) {
                return $e->getMessage();
            }

            //return $this->tasks->runNow(['Setup Nginx']);

            return $task;
        }
        
        /**
         * Fetch routes, domains and upstream:
         * @usage:
         *  all            - $nginx->fetch('route');
         *  routeById(1)   - $nginx->fetch('route', 'id = ? ', [1]);
         *  routeByName(1) - $nginx->fetch('route', 'name = ? ', ['foobar'])
         *
         * @return array
         */
        public function fetch(array $params = array())
        {
            if ($params[1] !== null && $params[2] !== null) {
                $result = $this->model->findAll($params[0], $params[1], $params[2]);
            } elseif ($params[0] !== null && $params === null) {
                $result = $this->model->findAll($params[0], $params[1]);
            } else {
                $result = $this->model->findAll($params[0]);
            }
            
            $return = [];
            foreach ($result as $row) {
                $return[] = $this->model->export($row)[0];
            }
            return $return;
        }

        /**
         *
         */
        public function reset(array $params = array())
        {
            $this->model->nuke();

            return true;
        }

        /**
         *
         */
        public function add(array $params = array())
        {
            $data = $params[0];
            
            $errors = [];

            // validate server id
            $data['serverid'] = trim($data['serverid']);
            if (empty($data['serverid'])) {
                $errors['serverid'] = 'Server ID cannot be empty';
            }

            // validate machine id
            $data['machineid'] = trim($data['machineid']);
            if (empty($data['machineid'])) {
                $errors['machineid'] = 'Machine ID cannot be empty'; // check agent auth key
            }

            // validate container
            $data['container'] = trim($data['container']);
            if (empty($data['container'])) {
                $errors['container'] = 'Container name is a required field';
            }

            // validate user id
            $data['user_id'] = trim($data['user_id']);
            if (empty($data['user_id'])) {
                $errors['user_id'] = 'User id cannot be empty';
            }

            // validate name
            $data['name'] = trim($data['name']);
            if (empty($data['name'])) {
                $errors['name'] = 'Name is a required field';
            }

            if (!empty($data['name']) && strlen($data['name']) > 50) {
                $errors['name'] = 'Name cannot be greater then 50 characters';
            }

            if (!empty($data['name']) && strlen($data['name']) < 3) {
                $errors['name'] = 'Name cannot be less then 3 characters';
            }

            // if no previous errors for name, check if route already added
            // in future i may prepend the route with user association
            if (empty($errors['name'])) {
                if ($this->model->count('route', 'name = ? AND machineid = ?', [$data['name'], $data['machineid']]) > 0) {
                    $errors['name'] = 'Name already in use';
                }
            }

            // validate ip - needs to be change to accept an array
            if (isset($data['ip'])) {
                $data['ip'] = trim($data['ip']);
                if (empty($data['ip'])) {
                    $errors['ip'] = 'Leave blank or enter a correct IP address to use this option';
                }
                //if (!filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                //	$errors['ip'] = 'Invalid IP address';
                //}
            }

            // validate port - needs to be change to accept an array
            if (isset($data['port'])) {
                $data['port'] = trim($data['port']);
                if (empty($data['port'])) {
                    $errors['port'] = 'Leave blank or enter a numeric port number to use this option';
                }
                if (!empty($data['port']) && !is_numeric($data['port'])) {
                    $errors['port'] = 'Invalid port number!';
                }
                if (!empty($data['port']) && is_numeric($data['port']) && $data['port'] > 65535) {
                    $errors['port'] = 'Invalid port number!';
                }
                if (!empty($data['port']) && is_numeric($data['port']) && $data['port'] == 0) {
                    $errors['port'] = 'Invalid port number!';
                }
            }

            // check ssl letsencrypt
            if (!empty($data['letsencrypt'])) {
                $data['ssl_type'] = 'letsencrypt';
            } else {
                $data['ssl_type'] = '';
            }

            // validate domains
            foreach ((array) $data['domains'] as $key => $row) {
                // filter
                if (stripos($row, 'http') === 0) {
                    $row = substr($row, 4);
                }
                if (stripos($row, 's://') === 0) {
                    $row = substr($row, 4);
                }
                if (stripos($row, '://') === 0) {
                    $row = substr($row, 3);
                }
                if (stripos($row, '//') === 0) {
                    $row = substr($row, 2);
                }

                // check for no dots
                if (!substr_count($row, '.')) {
                    $errors['domains'][$key] = 'Invalid domain name';
                }

                // has last dot
                if (substr($row, -1) == '.') {
                    $errors['domains'][$key] = 'Invalid domain name';
                }

                // validate url
                if (!filter_var('http://' . $row, FILTER_VALIDATE_URL)) {
                    $errors['domains'][$key] = 'Invalid domain name';
                }

                /*

                    // domain already in use by another route
                    if (R::count('domain', 'name = ? AND machineid = ?', [$row, $data['machineid']]) > 0) {
                        //$errors['domains'][$key] = 'Domain already in use by another route';
                    }

                    // domain not pointing to host
                    if (R::count('agent', 'last_ip = ? AND machineid = ?', [gethostbyname($row), $data['machineid']]) > 0) {
                        //$errors['domains'][$key] = 'Domain does not point to the host';
                    }

                    */
            }

            // validate upstream
            foreach ((array) $data['upstreams'] as $key => $row) {
                // validate ip
                if (!filter_var($row['ip'], FILTER_VALIDATE_IP)) {
                    $errors['upstreams'][$key] = 'Invalid IP address';
                }
                if (empty($row['port']) || !is_numeric($row['port'])) {
                    $errors['upstreams'][$key] = 'Invalid port';
                } else {
                    if ($row['port'] < 1 || $row['port'] > 65535) {
                        $errors['upstreams'][$key] = 'Invalid port';
                    }
                }
            }

            /* has errors return */
            if (!empty($errors)) {
                return [
                    'status' => false,
                    'errors' => $errors
                ];
            }

            // build and store routes bean
            $route = $this->model->create(
                [
                    'route',
                    'label' => (!empty($data['label']) ? $data['label'] : '')
                ]
            );

            $route->machineid = !empty($data['machineid']) ? preg_replace('/[^a-z0-9]/i', '', $data['machineid']) : '';
            $route->name = !empty($data['name']) ? trim(preg_replace('/[^a-z-0-9]/i', '-', $data['name']), '-') : '-';
            $route->ssl_type = !empty($data['ssl_type']) ? preg_replace('/[^a-z]/i', '', $data['ssl_type']) : '';
            $route->added = date_create();
            $route->updated = date_create();
            $route->has_change = 1;
            $route->has_error = 0;
            $route->delete = 0;
            $route->enabled = !empty($data['enabled']);
            $route->serverid = (!empty($data['serverid']) ? $data['serverid'] : 0);
            $route->user_id = (!empty($data['user_id']) ? $data['user_id'] : 0);
            $route->container = (!empty($data['container']) ? $data['container'] : '');
            $route->update_ip = (!empty($data['update_ip']) ? 1 : 0);

            // domains
            $domains = [];
            foreach ((array) $data['domains'] as $row) {
                $domain = $this->model->create(
                    [
                        'domain',
                        'name' => str_replace(['http://', 'https://', '//'], '', $row)
                    ]
                );
                $domain->machineid = !empty($data['machineid']) ? preg_replace('/[^a-z0-9]/i', '', $data['machineid']) : '';
                $domain->serverid = (!empty($data['serverid']) ? $data['serverid'] : 0);
                $domain->user_id = (!empty($data['user_id']) ? $data['user_id'] : 0);
                $domain->container = (!empty($data['container']) ? $data['container'] : '');

                $domains[] = $domain;
            }
            $route['xownDomainList'] = $domains;

            // upstreams
            $upstreams = [];

            // set first ip back into route
            if (isset($data['upstreams'][0]['ip'])) {
                $route->ip = $data['upstreams'][0]['ip'];
            } else {
                $route->ip = !empty($data['ip']) ? $data['ip'] : '';
            }

            // set first port back into route
            if (isset($data['upstreams'][0]['port'])) {
                $route->port = (int) $data['upstreams'][0]['port'];
            } else {
                $route->port = !empty($data['port']) ? preg_replace('/[^0-9]/', '', $data['port']) : '';
            }

            // upstreams
            foreach ((array) $data['upstreams'] as $row) {
                $upstream = $this->model->create(
                    [
                        'upstream',
                        'ip' => $row['ip']
                    ]
                );
                $upstream->port = (int) $row['port'];
                $upstreams[] = $upstream;
            }
            $route['xownUpstreamList'] = $upstreams;

            try {
                $this->model->store($route);
            } catch (\Exception $e) {
                return [
                    'status' => false,
                    'errors' => ['name' => $e->getMessage()]
                ];
            }
            return [
                'status' => true,
                'errors' => []
            ];
        }
    }

}
