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
 
namespace Plinker\Nginx {

    use Plinker\Tasks\Tasks as TasksManager;
    use Plinker\Redbean\RedBean as Model;

    /**
     * Plinker Nginx Manager class
     *
     * @example
     * <code>
        <?php
        $config = [
            // plinker connection
            'plinker' => [
                'endpoint' => 'http://127.0.0.1:88',
                'public_key'  => 'makeSomethingUp',
                'private_key' => 'againMakeSomethingUp'
            ],

            // database connection
            'database' => [
                'dsn'      => 'sqlite:./.plinker/database.db',
                'host'     => '',
                'name'     => '',
                'username' => '',
                'password' => '',
                'freeze'   => false,
                'debug'    => false,
            ]
        ];

        // init plinker endpoint client
        $nginx = new \Plinker\Core\Client(
            // where is the plinker server
            $config['plinker']['endpoint'],

            // component namespace to interface to
            'Nginx\Manager',

            // keys
            $config['plinker']['public_key'],
            $config['plinker']['private_key'],

            // construct values which you pass to the component
            $config
        );
       </code>
     *
     * @package Plinker\Nginx
     */
    class Nginx
    {
        /**
         * @var array
         */
        public $config = array();

        /**
         * @param array $config config array passed from \Plinker\Core\Client
         */
        public function __construct(array $config = array())
        {
            $this->config = $config;

            // load models
            $this->model = new Model($this->config['database']);
            $this->tasks = new TasksManager($this->config);
        }

        /**
         * Sets up tasks into \Plinker\Tasks
         *
         * @example
         * <code>
            <?php
            $nginx->setup([
                'build_sleep' => 5,
                'reconcile_sleep' => 5,
            ])
           </code>
         *
         * @param array $params
         * @return array
         */
        public function setup(array $params = array())
        {
            if (!file_exists('/etc/nginx')) {
                return [
                    'status' => 'error',
                    'errors' => [
                        'global' => 'Nginx not installed! - Read the README.md for setup instructions.'
                    ]
                ];
            }

            try {

                // create setup task
                if ($this->model->count(['tasks', 'name = "nginx.setup" AND run_count > 0']) > 0) {
                    $this->model->exec(['DELETE from tasks WHERE name = "nginx.setup" AND run_count > 0']);
                }
                // add task
                $task['nginx.setup'] = $this->tasks->create(
                    // name
                    'nginx.setup',
                    // source
                    file_get_contents(__DIR__.'/tasks/setup.php'),
                    // type
                    'php',
                    // description
                    'Configures nginx module.',
                    // default params
                    $params
                );
                // queue task
                $this->tasks->run('nginx.setup', [], 0);

                // create build task
                if ($this->model->count(['tasks', 'name = "nginx.build" AND run_count > 0']) > 0) {
                    $this->model->exec(['DELETE from tasks WHERE name = "nginx.build" AND run_count > 0']);
                }
                
                // add task
                $task['nginx.build'] = $this->tasks->create(
                    // name
                    'nginx.build',
                    // source
                    file_get_contents(__DIR__.'/tasks/build.php'),
                    // type
                    'php',
                    // description
                    'Builds nginx configuration files.',
                    // default params
                    $params
                );
                // queue task
                $this->tasks->run(
                    'nginx.build',
                    $params,
                    ($params['build_sleep'] ? (int) $params['build_sleep'] : 5)
                );

                // create reconcile task
                if ($this->model->count(['tasks', 'name = "nginx.reconcile" AND run_count > 0']) > 0) {
                    $this->model->exec(['DELETE from tasks WHERE name = "nginx.reconcile" AND run_count > 0']);
                }
                // add task
                $task['nginx.reconcile'] = $this->tasks->create(
                    // name
                    'nginx.reconcile',
                    // source
                    file_get_contents(__DIR__.'/tasks/reconcile.php'),
                    // type
                    'php',
                    // description
                    'Reconciles nginx configuration and database.',
                    // default params
                    $params
                );
                // queue task
                $this->tasks->run(
                    'nginx.reconcile',
                    $params,
                    ($params['reconcile_sleep'] ? (int) $params['reconcile_sleep'] : 5)
                );

                // create nginx reload task
                if ($this->model->count(['tasks', 'name = "nginx.reload" AND run_count > 0']) > 0) {
                    $this->model->exec(['DELETE from tasks WHERE name = "nginx.reload" AND run_count > 0']);
                }
                // add task
                $task['nginx.reload'] = $this->tasks->create(
                    // name
                    'nginx.reload',
                    // source
                    "#!/bin/bash\nnginx -s reload",
                    // type
                    'bash',
                    // description
                    'Reloads nginx server.',
                    // default params
                    $params
                );

                // create composer update task
                if ($this->model->count(['tasks', 'name = "nginx.auto_update" AND run_count > 0']) > 0) {
                    $this->model->exec(['DELETE from tasks WHERE name = "nginx.auto_update" AND run_count > 0']);
                }
                // add
                $task['nginx.auto_update'] = $this->tasks->create(
                    // name
                    'nginx.auto_update',
                    // source
                    "#!/bin/bash\ncomposer update plinker/nginx",
                    // type
                    'bash',
                    // description
                    'Auto update nginx module code.',
                    // default params
                    $params
                );
                // queue task to run every second
                $this->tasks->run(
                    'nginx.auto_update',
                    $params,
                    86400
                 );
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'errors' => [
                        'global' => $e->getMessage()
                    ]
                ];
            }

            return [
                'status' => 'success'
            ];
        }

        /**
         * Runs composer update to update package
         *
         * @example
         * <code>
            <?php
            $nginx->update_package()
           </code>
         *
         * @param array $params
         * @return array
         */
        public function update_package()
        {
            // queue nginx.auto_update task
            return $this->tasks->run('nginx.auto_update', [], 0);
        }

        /**
         * Get nginx status $nginx->status();
         *
         * @example
         * <code>
            <?php
            $nginx->status()
           </code>
         *
         * @param array $params
         * @return array
         */
        public function status()
        {
            /*
                Active connections: 2
                server accepts handled requests
                 8904 8904 8907
                Reading: 0 Writing: 2 Waiting: 0
             */
            $return = file_get_contents('http://127.0.0.1/nginx_status');

            $lines = explode(PHP_EOL, $return);

            $return = [];

            //active connections
            $return['active_connections'] = (int) trim(str_replace('Active connections:', '', $lines[0]));

            // break up line for the following
            $columns = array_values(array_filter(explode(' ', $lines[2]), 'strlen'));
            //
            // accepts
            $return['accepts'] = (int) $columns[0];
            //
            // handled
            $return['handled'] = (int) $columns[1];
            //
            //requests
            $return['requests'] = (int) $columns[1];

            // break up line for the following
            $columns = array_values(array_filter(explode(' ', $lines[3]), 'strlen'));
            //
            // reading
            $return['reading'] = (int) $columns[1];

            // writing
            $return['writing'] = (int) $columns[3];

            // waiting
            $return['waiting'] = (int) $columns[5];

            return $return;
        }

        /**
         * Fetch route rules
         *
         * @usage:
         *  all           - $nginx->fetch();
         *  ruleById(1)   - $nginx->fetch('id = ? ', [1]);
         *  ruleByName(1) - $nginx->fetch('name = ? ', ['guidV4-value'])
         *
         * @return array
         */
        public function fetch($placeholder = null, array $values = [])
        {
            $table = 'route';

            if (!empty($placeholder) && !empty($values)) {
                $result = $this->model->findAll([$table, $placeholder, $values]);
            } elseif (!empty($placeholder)) {
                $result = $this->model->findAll([$table, $placeholder]);
            } else {
                $result = $this->model->findAll([$table]);
            }

            $return = [];
            foreach ((array) $result as $row) {
                $return[] = $this->model->export($row)[0];
            }

            return $return;
        }

        /**
         * Count
         *
         * @example
         * <code>
            $nginx->count();
            $nginx->count('id = ? ', [1]);
            $nginx->count('name = ? ', ['guidV4-value']);
           </code>
         *
         * @param array $params
         * @return array
         */
        public function count($placeholder = null, array $values = [])
        {
            $table = 'route';

            if (!empty($placeholder) && !empty($values)) {
                $result = $this->model->count([$table, $placeholder, $values]);
            } elseif (!empty($placeholder)) {
                $result = $this->model->count([$table, $placeholder]);
            } else {
                $result = $this->model->count([$table]);
            }

            return (int) $result;
        }

        /**
         * Rebuild route/s
         *
         * @example
         * <code>
            <?php
            $nginx->rebuild('id = ? ', [1]);
            $nginx->rebuild('name = ? ', ['guidV4-value']);
           </code>
         *
         * @param array $params
         * @return array
         */
        public function rebuild($placeholder = '', $values = [])
        {
            if (!is_string($placeholder)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'First param must be a string']
                ];
            }

            if (!is_array($values)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'Second param must be an array']
                ];
            }

            $route = $this->model->findOne(['route', $placeholder, $values]);

            if (empty($route)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'Not found']
                ];
            }

            $route->has_change = 1;

            $this->model->store($route);

            return [
                'status' => 'success'
            ];
        }

        /**
         * Remove route/s
         *
         * @example
         * <code>
            <?php
            $nginx->remove('id = ? ', [1]);
            $nginx->remove('name = ? ', ['guidV4-value']);
           </code>
         *
         * @param array $params
         * @return array
         */
        public function remove($placeholder = null, array $values = [])
        {
            if (!is_string($placeholder)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'First param must be a string']
                ];
            }

            if (!is_array($values)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'Second param must be an array']
                ];
            }

            $route = $this->model->findOne(['route', $placeholder, $values]);

            if (empty($route)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'Not found']
                ];
            }

            foreach ($route->ownDomain as $domain) {
                $this->model->trash($domain);
            }

            foreach ($route->ownUpstream as $upstream) {
                $this->model->trash($upstream);
            }

            $this->model->trash($route);

            return [
                'status' => 'success'
            ];
        }

        /**
         * Deletes all route, domain, upstreams and [related tasks]
         *
         * @example
         * <code>
            <?php
            $nginx->reset();     // deletes routes, domains and upstreams
            $nginx->reset(true); // deletes routes, domains, upstreams and tasks
           </code>
         *
         *
         * @param bool $param[0] - remove tasks
         * @return array
         */
        public function reset($purge = false)
        {
            $this->model->exec(['DELETE FROM route']);
            $this->model->exec(['DELETE FROM domain']);
            $this->model->exec(['DELETE FROM upstream']);

            if ($purge) {
                $this->model->exec(['DELETE from tasks WHERE name = "nginx.setup"']);
                $this->model->exec(['DELETE from tasks WHERE name = "nginx.build"']);
                $this->model->exec(['DELETE from tasks WHERE name = "nginx.reconcile"']);
                $this->model->exec(['DELETE from tasks WHERE name = "nginx.reload"']);
                $this->model->exec(['DELETE from tasks WHERE name = "nginx.auto_update"']);
            }

            return [
                'status' => 'success'
            ];
        }

        /**
         * Generate a GUIv4
         *
         * @return string
         */
        private function guidv4()
        {
            if (function_exists('random_bytes') === true) {
                $bytes = random_bytes(16);
            } elseif (function_exists('openssl_random_pseudo_bytes') === true) {
                $bytes = openssl_random_pseudo_bytes(16);
            } elseif (function_exists('mcrypt_create_iv') === true) {
                $bytes = mcrypt_create_iv(16, MCRYPT_DEV_URANDOM);
            } elseif (function_exists('com_create_guid') === true) {
                return trim(com_create_guid(), '{}');
            } else {
                return sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 65535),
                    mt_rand(0, 65535),
                    mt_rand(0, 65535),
                    mt_rand(16384, 20479),
                    mt_rand(32768, 49151),
                    mt_rand(0, 65535),
                    mt_rand(0, 65535),
                    mt_rand(0, 65535)
                );
            }

            $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // set version to 0100
            $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // set bits 6-7 to 10
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        }

        /**
         * Add route, expects structured input and will return with the same
         * structure which allows for rolling state.
         *
         * @example
         * <code>
            <?php
            $form = [
                // form errors
                'errors' => '',

                // form values
                'values' => [
                    'label' => 'Example Route',
                    'domains' => [
                        ['name' => 'example.com'],
                        ['name' => 'www.example.com']
                    ],
                    'upstreams' => [
                        ['ip' => '10.158.250.5', 'port' => '80']
                    ],
                    'letsencrypt' => 0,
                    'enabled' => 1
                ]
            ];

            $nginx->add($form['values']);

           </code>
         *
         * @param array $params
         * @return array
         */
        public function add(array $data = [])
        {
            $errors = [];

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

            // validate domains
            foreach ((array) $data['ownDomain'] as $key => $row) {
                $row = strtolower($row);

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

                // domain already in use by another route
                if ($this->model->count(['domain', 'name = ?', [$row]]) > 0) {
                    $errors['domains'][$key] = 'Domain already in use';
                }
            }

            // validate upstream
            foreach ((array) $data['ownUpstream'] as $key => $row) {
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

            // has error/s
            if (!empty($errors)) {
                return [
                    'status' => 'error',
                    'errors' => $errors,
                    'values' => $data
                ];
            }
            
            // check ssl letsencrypt
            if (!empty($data['letsencrypt'])) {
                $data['ssl_type'] = 'letsencrypt';
            } else {
                $data['ssl_type'] = '';
            }
            
            $data['name'] = $this->guidv4();

            // create route
            $route = $this->model->create([
                'route',
                [
                    'label'      => (!empty($data['label']) ? $data['label'] : '-'),
                    'name'       => $data['name'],
                    'ssl_type'   => (!empty($data['ssl_type']) ? preg_replace('/[^a-z]/i', '', $data['ssl_type']) : ''),
                    'added'      => date_create(),
                    'updated'    => date_create(),
                    'has_change' => 1,
                    'has_error'  => 0,
                    'delete'     => 0,
                    'enabled'    => !empty($data['enabled']),
                    'update_ip'  => (!empty($data['update_ip']) ? 1 : 0)
                ]
            ]);

            // create domains
            $domains = [];
            foreach ((array) $data['ownDomain'] as $row) {
                $row = strtolower($row);
                $domain = $this->model->create([
                    'domain',
                    [
                        'name' => str_replace(['http://', 'https://', '//'], '', $row)
                    ]
                ]);
                $domains[] = $domain;
            }
            $route['xownDomainList'] = $domains;

            // upstreams
            // set first ip back into route
            if (isset($data['ownUpstream'][0]['ip'])) {
                $route->ip = $data['ownUpstream'][0]['ip'];
            } else {
                $route->ip = !empty($data['ip']) ? $data['ip'] : '';
            }

            // set first port back into route
            if (isset($data['ownUpstream'][0]['port'])) {
                $route->port = (int) $data['ownUpstream'][0]['port'];
            } else {
                $route->port = !empty($data['port']) ? preg_replace('/[^0-9]/', '', $data['port']) : '';
            }

            // create upstreams
            $upstreams = [];
            foreach ((array) $data['ownUpstream'] as $row) {
                $upstream = $this->model->create([
                    'upstream',
                    [
                        'ip' => $row['ip']
                    ]
                ]);
                $upstream->port = (int) $row['port'];
                $upstreams[] = $upstream;
            }
            $route['xownUpstreamList'] = $upstreams;

            try {
                $this->model->store($route);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => $e->getMessage()],
                    'values' => $data
                ];
            }

            $route = $this->model->export($route)[0];

            return [
                'status' => 'success',
                'values' => $route
            ];
        }

        /**
         * Update route,
         *  - Treat as findOne with additional param for data.
         *  - Expects structured input and will return with the same structure
         *    which allows for rolling state.
         *
         * @example
         * <code>
            <?php
            $form = [
                // form errors
                'errors' => '',

                // form values
                'values' => [
                    'label' => 'Updated Example Route',
                    'domains' => [
                        ['name' => 'example.com'],
                        ['name' => 'www.example.com']
                    ],
                    'upstreams' => [
                        ['ip' => '10.158.250.1', 'port' => '80']
                    ],
                    'letsencrypt' => 0,
                    'enabled' => 1
                ]
            ];

            // update by id
            $nginx->update('id = ?', [1], $form['values']);

            // update by name
            $nginx->update('name = ?', ['0e5391ac-a37f-41cf-a36b-369df19e592f'], $form['values']);

            // update by id and name
            $nginx->update('id = ? AND name = ?', [23, '0e5391ac-a37f-41cf-a36b-369df19e592f'], $form['values']);

           </code>
         *
         * @param array $params
         * @return array
         */
        public function update($placeholder = '', $values = [], $data = [])
        {
            $errors = [];

            $route = $this->model->findOne(['route', $placeholder, $values]);

            // check found
            if (empty($route->name)) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => 'Route not found'],
                    'values' => $data
                ];
            }

            // dont allow name change
            if (isset($data['name']) && $data['name'] != $route->name) {
                return [
                    'status' => 'error',
                    'errors' => ['name' => 'Name cannot be changed'],
                    'values' => $data
                ];
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
            if (isset($data['letsencrypt'])) {
                if (!empty($data['letsencrypt'])) {
                    $data['ssl_type'] = 'letsencrypt';
                } else {
                    $data['ssl_type'] = '';
                }
            }

            // validate domains
            if (isset($data['ownDomain'])) {
                foreach ((array) $data['ownDomain'] as $key => $row) {
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

                    // domain already in use by another route
                    if ($this->model->count(['domain', 'name = ? AND route_id != ?', [$row, $route->id]]) > 0) {
                        $errors['domains'][$key] = 'Domain already in use';
                    }
                }
            }

            // validate upstream
            if (isset($data['ownUpstream'])) {
                foreach ((array) $data['ownUpstream'] as $key => $row) {
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
            }

            // has error/s
            if (!empty($errors)) {
                return [
                    'status' => 'error',
                    'errors' => $errors,
                    'values' => $data
                ];
            }

            // update route
            if (isset($data['label'])) {
                $route->label = $data['label'];
            }
            if (isset($data['ssl_type'])) {
                $route->ssl_type = preg_replace('/[^a-z]/i', '', $data['ssl_type']);
            }
            if (isset($data['enabled'])) {
                $route->enabled = !empty($data['enabled']);
            }

            $route->updated = date_create();
            $route->has_change = 1;

            // create domains
            if (isset($data['ownDomain'])) {
                $route->xownDomainList = [];
                $domains = [];
                foreach ((array) $data['ownDomain'] as $row) {
                    $domain = $this->model->create([
                        'domain',
                        [
                            'name' => str_replace(['http://', 'https://', '//'], '', $row)
                        ]
                    ]);
                    $domains[] = $domain;
                }
                $route->xownDomainList = $domains;
            }

            // upstreams
            // set first ip back into route
            if (isset($data['ownUpstream'])) {
                if (isset($data['ownUpstream'][0]['ip'])) {
                    $route->ip = $data['ownUpstream'][0]['ip'];
                } else {
                    $route->ip = !empty($data['ip']) ? $data['ip'] : '';
                }

                // set first port back into route
                if (isset($data['ownUpstream'][0]['port'])) {
                    $route->port = (int) $data['ownUpstream'][0]['port'];
                } else {
                    $route->port = !empty($data['port']) ? preg_replace('/[^0-9]/', '', $data['port']) : '';
                }

                // create upstreams
                $route->xownUpstreamList = [];
                $upstreams = [];
                foreach ((array) $data['ownUpstream'] as $row) {
                    $upstream = $this->model->create([
                        'upstream',
                        [
                            'ip' => $row['ip']
                        ]
                    ]);
                    $upstream->port = (int) $row['port'];
                    $upstreams[] = $upstream;
                }
                $route->xownUpstreamList = $upstreams;
            }

            try {
                $this->model->store($route);
            } catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'errors' => ['global' => $e->getMessage()],
                    'values' => $data
                ];
            }

            $route = $this->model->export($route)[0];

            return [
                'status' => 'success',
                'values' => $route
            ];
        }
    }
}
