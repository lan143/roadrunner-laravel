<?php

namespace updg\roadrunner\laravel;

use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

class Bridge
{
    /**
     * Stores the application
     *
     * @var \Illuminate\Foundation\Application|null
     */
    private $_app;

    /**
     * Stores the kernel
     *
     * @var \Illuminate\Contracts\Http\Kernel|\Laravel\Lumen\Application
     */
    private $_kernel;

    /**
     * Laravel Application->register() parameter count
     *
     * @var int
     */
    private $appRegisterParameters;

    /**
     * @var string[]
     */
    private $resetProviders = [];


    private function prepareKernel()
    {
        // Laravel 5 / Lumen
        $isLaravel = true;
        if (file_exists('bootstrap/app.php')) {
            $this->_app = require_once 'bootstrap/app.php';
            if (substr($this->_app->version(), 0, 5) === 'Lumen') {
                $isLaravel = false;
            }
        }
        // Laravel 4
        if (file_exists('bootstrap/start.php')) {
            $this->_app = require_once 'bootstrap/start.php';
            $this->_app->boot();
            return $this->_app;
        }
        if (!$this->_app) {
            throw new \RuntimeException('Laravel bootstrap file not found');
        }
        $kernel = $this->_app->make($isLaravel ? 'Illuminate\Contracts\Http\Kernel' : 'Laravel\Lumen\Application');
        $this->_app->afterResolving('auth', function ($auth) {
            $auth->extend('session', function ($app, $name, $config) {
                $provider = $app['auth']->createUserProvider($config['provider']);
                $guard = new SessionGuard($name, $provider, $app['session.store'], null, $app);
                if (method_exists($guard, 'setCookieJar')) {
                    $guard->setCookieJar($this->_app['cookie']);
                }
                if (method_exists($guard, 'setDispatcher')) {
                    $guard->setDispatcher($this->_app['events']);
                }
                if (method_exists($guard, 'setRequest')) {
                    $guard->setRequest($this->_app->refresh('request', $guard, 'setRequest'));
                }
                return $guard;
            });
        });
        $app = $this->_app;
        $this->_app->extend('session.store', function () use ($app) {
            $manager = $app['session'];
            return $manager->driver();
        });


        $this->_kernel = $kernel;
    }

    public function start()
    {
        $this->prepareKernel();

        $relay = new \Spiral\Goridge\StreamRelay(STDIN, STDOUT);
        $psr7 = new \Spiral\RoadRunner\PSR7Client(new \Spiral\RoadRunner\Worker($relay));
        $httpFoundationFactory = new HttpFoundationFactory();

        while ($req = $psr7->acceptRequest()) {

            $symfonyRequest = $httpFoundationFactory->createRequest($req);
            $request = \Illuminate\Http\Request::createFromBase($symfonyRequest);

            $response = $this->_kernel->handle($request);

            $psr7factory = new DiactorosFactory();
            $psr7response = $psr7factory->createResponse($response);
            $psr7->respond($psr7response);

            if (method_exists($this->_kernel, 'terminate')) {
                $this->_kernel->terminate($request, $response);
            } else {
                $method = new \ReflectionMethod(get_class($this->_kernel), 'callTerminableMiddleware');
                $method->setAccessible(true);
                $method->invoke($this->_kernel, $response);
                $method->setAccessible(false);
            }

            foreach ($this->resetProviders as $provider) {
                $this->resetProvider($provider);
            }

            if (method_exists($this->_app, 'getProvider')) {
                //reset debugbar if available
                $this->resetProvider('\Illuminate\Redis\RedisServiceProvider');
                $this->resetProvider('\Illuminate\Cookie\CookieServiceProvider');
                $this->resetProvider('\Illuminate\Session\SessionServiceProvider');
            }
        }
    }

    protected function resetProvider($providerName)
    {
        if (!$this->_app->getProvider($providerName)) {
            return;
        }
        $this->appRegister($providerName, true);
    }

    /**
     * Register application provider
     * Workaround for BC break in https://github.com/laravel/framework/pull/25028
     * @param string $providerName
     * @param bool $force
     * @throws \ReflectionException
     */
    protected function appRegister($providerName, $force = false)
    {
        if (!$this->appRegisterParameters) {
            $method = new \ReflectionMethod(get_class($this->_app), 'register');
            $this->appRegisterParameters = count($method->getParameters());
        }
        if ($this->appRegisterParameters == 3) {
            $this->_app->register($providerName, [], $force);
        } else {
            $this->_app->register($providerName, $force);
        }
    }

    /**
     * @param string $serviceProviderClass
     * @return Bridge
     */
    public function addProviderToReset($serviceProviderClass)
    {
        $this->resetProviders[] = $serviceProviderClass;

        return $this;
    }
}
