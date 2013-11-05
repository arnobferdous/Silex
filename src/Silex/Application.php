<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\EventListener\ResponseListener;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\RouteCollection;
use Silex\Api\BootableProviderInterface;
use Silex\Api\EventListenerProviderInterface;
use Silex\Api\ControllerProviderInterface;
use Silex\Api\ServiceProviderInterface;
use Silex\EventListener\MiddlewareListener;
use Silex\EventListener\ConverterListener;
use Silex\EventListener\StringToResponseListener;
use Silex\Provider\RoutingServiceProvider;

/**
 * The Silex framework class.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Application extends \Pimple implements HttpKernelInterface, TerminableInterface
{
    const VERSION = '2.0.0-DEV';

    const EARLY_EVENT = 512;
    const LATE_EVENT  = -512;

    protected $providers = array();
    protected $booted = false;

    /**
     * Instantiate a new Application.
     *
     * Objects and parameters can be passed as argument to the constructor.
     *
     * @param array $values The parameters or objects.
     */
    public function __construct(array $values = array())
    {
        parent::__construct();

        $app = $this;

        $this['logger'] = null;

        $this['routes'] = $this->share(function () {
            return new RouteCollection();
        });

        $this['controllers'] = $this->share(function () use ($app) {
            return $app['controllers_factory'];
        });

        $this['controllers_factory'] = function () use ($app) {
            return new ControllerCollection($app['route_factory']);
        };

        $this['route_class'] = 'Silex\\Route';
        $this['route_factory'] = function () use ($app) {
            return new $app['route_class']();
        };

        $this['exception_handler'] = $this->share(function () use ($app) {
            return new ExceptionHandler($app['debug']);
        });

        $this['dispatcher_class'] = 'Symfony\\Component\\EventDispatcher\\EventDispatcher';
        $this['dispatcher'] = $this->share(function () use ($app) {
            $dispatcher = new $app['dispatcher_class']();

            if (isset($app['exception_handler'])) {
                $dispatcher->addSubscriber($app['exception_handler']);
            }
            $dispatcher->addSubscriber(new ResponseListener($app['charset']));
            $dispatcher->addSubscriber(new MiddlewareListener($app));
            $dispatcher->addSubscriber(new ConverterListener($app['routes'], $app['callback_resolver']));
            $dispatcher->addSubscriber(new StringToResponseListener());

            return $dispatcher;
        });

        $this['callback_resolver'] = $this->share(function () use ($app) {
            return new CallbackResolver($app);
        });

        $this['resolver'] = $this->share(function () use ($app) {
            return new ControllerResolver($app, $app['logger']);
        });

        $this['kernel'] = $this->share(function () use ($app) {
            return new HttpKernel($app['dispatcher'], $app['resolver'], $app['request_stack']);
        });

        $this['request_stack'] = $this->share(function () use ($app) {
            return new RequestStack();
        });

        $this['request.http_port'] = 80;
        $this['request.https_port'] = 443;
        $this['debug'] = false;
        $this['charset'] = 'UTF-8';

        $this->register(new RoutingServiceProvider());

        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }
    }

    /**
     * Registers a service provider.
     *
     * @param ServiceProviderInterface $provider A ServiceProviderInterface instance
     * @param array                    $values   An array of values that customizes the provider
     *
     * @return Application
     */
    public function register(ServiceProviderInterface $provider, array $values = array())
    {
        $this->providers[] = $provider;

        $provider->register($this);

        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }

        return $this;
    }

    /**
     * Boots all service providers.
     *
     * This method is automatically called by handle(), but you can use it
     * to boot all service providers when not handling a request.
     */
    public function boot()
    {
        if (!$this->booted) {
            $this->booted = true;

            foreach ($this->providers as $provider) {
                if ($provider instanceof EventListenerProviderInterface) {
                    $provider->subscribe($this, $this['dispatcher']);
                }

                if ($provider instanceof BootableProviderInterface) {
                    $provider->boot($this);
                }
            }
        }
    }

    /**
     * Maps a pattern to a callable.
     *
     * You can optionally specify HTTP methods that should be matched.
     *
     * @param string $pattern Matched route pattern
     * @param mixed  $to      Callback that returns the response when matched
     *
     * @return Controller
     */
    public function match($pattern, $to = null)
    {
        return $this['controllers']->match($pattern, $to);
    }

    /**
     * Maps a GET request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed  $to      Callback that returns the response when matched
     *
     * @return Controller
     */
    public function get($pattern, $to = null)
    {
        return $this['controllers']->get($pattern, $to);
    }

    /**
     * Maps a POST request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed  $to      Callback that returns the response when matched
     *
     * @return Controller
     */
    public function post($pattern, $to = null)
    {
        return $this['controllers']->post($pattern, $to);
    }

    /**
     * Maps a PUT request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed  $to      Callback that returns the response when matched
     *
     * @return Controller
     */
    public function put($pattern, $to = null)
    {
        return $this['controllers']->put($pattern, $to);
    }

    /**
     * Maps a DELETE request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed  $to      Callback that returns the response when matched
     *
     * @return Controller
     */
    public function delete($pattern, $to = null)
    {
        return $this['controllers']->delete($pattern, $to);
    }

    /**
     * Adds an event listener that listens on the specified events.
     *
     * @param string   $eventName The event to listen on
     * @param callable $callback  The listener
     * @param integer  $priority  The higher this value, the earlier an event
     *                            listener will be triggered in the chain (defaults to 0)
     */
    public function on($eventName, $callback, $priority = 0)
    {
        if ($this->booted) {
            $this['dispatcher']->addListener($eventName, $callback, $priority);

            return;
        }

        $this['dispatcher'] = $this->share($this->extend('dispatcher', function ($dispatcher, $app) use ($callback, $priority, $eventName) {
            $dispatcher->addListener($eventName, $callback, $priority);

            return $dispatcher;
        }));
    }

    /**
     * Registers a before filter.
     *
     * Before filters are run before any route has been matched.
     *
     * @param mixed   $callback Before filter callback
     * @param integer $priority The higher this value, the earlier an event
     *                          listener will be triggered in the chain (defaults to 0)
     */
    public function before($callback, $priority = 0)
    {
        $this->on(KernelEvents::REQUEST, function (GetResponseEvent $event) use ($callback) {
            if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
                return;
            }

            $ret = call_user_func($callback, $event->getRequest());

            if ($ret instanceof Response) {
                $event->setResponse($ret);
            }
        }, $priority);
    }

    /**
     * Registers an after filter.
     *
     * After filters are run after the controller has been executed.
     *
     * @param mixed   $callback After filter callback
     * @param integer $priority The higher this value, the earlier an event
     *                          listener will be triggered in the chain (defaults to 0)
     */
    public function after($callback, $priority = 0)
    {
        $this->on(KernelEvents::RESPONSE, function (FilterResponseEvent $event) use ($callback) {
            if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
                return;
            }

            call_user_func($callback, $event->getRequest(), $event->getResponse());
        }, $priority);
    }

    /**
     * Registers a finish filter.
     *
     * Finish filters are run after the response has been sent.
     *
     * @param mixed   $callback Finish filter callback
     * @param integer $priority The higher this value, the earlier an event
     *                          listener will be triggered in the chain (defaults to 0)
     */
    public function finish($callback, $priority = 0)
    {
        $this->on(KernelEvents::TERMINATE, function (PostResponseEvent $event) use ($callback) {
            call_user_func($callback, $event->getRequest(), $event->getResponse());
        }, $priority);
    }

    /**
     * Aborts the current request by sending a proper HTTP error.
     *
     * @param integer $statusCode The HTTP status code
     * @param string  $message    The status message
     * @param array   $headers    An array of HTTP headers
     */
    public function abort($statusCode, $message = '', array $headers = array())
    {
        throw new HttpException($statusCode, $message, null, $headers);
    }

    /**
     * Registers an error handler.
     *
     * Error handlers are simple callables which take a single Exception
     * as an argument. If a controller throws an exception, an error handler
     * can return a specific response.
     *
     * When an exception occurs, all handlers will be called, until one returns
     * something (a string or a Response object), at which point that will be
     * returned to the client.
     *
     * For this reason you should add logging handlers before output handlers.
     *
     * @param mixed   $callback Error handler callback, takes an Exception argument
     * @param integer $priority The higher this value, the earlier an event
     *                          listener will be triggered in the chain (defaults to -8)
     */
    public function error($callback, $priority = -8)
    {
        $this->on(KernelEvents::EXCEPTION, new ExceptionListenerWrapper($this, $callback), $priority);
    }

    /**
     * Flushes the controller collection.
     *
     * @param string $prefix The route prefix
     */
    public function flush($prefix = '')
    {
        $this['routes']->addCollection($this['controllers']->flush($prefix));
    }

    /**
     * Redirects the user to another URL.
     *
     * @param string  $url    The URL to redirect to
     * @param integer $status The status code (302 by default)
     *
     * @return RedirectResponse
     */
    public function redirect($url, $status = 302)
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * Creates a streaming response.
     *
     * @param mixed   $callback A valid PHP callback
     * @param integer $status   The response status code
     * @param array   $headers  An array of response headers
     *
     * @return StreamedResponse
     */
    public function stream($callback = null, $status = 200, $headers = array())
    {
        return new StreamedResponse($callback, $status, $headers);
    }

    /**
     * Escapes a text for HTML.
     *
     * @param string  $text         The input text to be escaped
     * @param integer $flags        The flags (@see htmlspecialchars)
     * @param string  $charset      The charset
     * @param Boolean $doubleEncode Whether to try to avoid double escaping or not
     *
     * @return string Escaped text
     */
    public function escape($text, $flags = ENT_COMPAT, $charset = null, $doubleEncode = true)
    {
        return htmlspecialchars($text, $flags, $charset ?: $this['charset'], $doubleEncode);
    }

    /**
     * Convert some data into a JSON response.
     *
     * @param mixed   $data    The response data
     * @param integer $status  The response status code
     * @param array   $headers An array of response headers
     *
     * @return JsonResponse
     */
    public function json($data = array(), $status = 200, $headers = array())
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Sends a file.
     *
     * @param \SplFileInfo|string $file               The file to stream
     * @param integer             $status             The response status code
     * @param array               $headers            An array of response headers
     * @param null|string         $contentDisposition The type of Content-Disposition to set automatically with the filename
     *
     * @return BinaryFileResponse
     */
    public function sendFile($file, $status = 200, $headers = array(), $contentDisposition = null)
    {
        return new BinaryFileResponse($file, $status, $headers, true, $contentDisposition);
    }

    /**
     * Mounts controllers under the given route prefix.
     *
     * @param string                                           $prefix      The route prefix
     * @param ControllerCollection|ControllerProviderInterface $controllers A ControllerCollection or a ControllerProviderInterface instance
     *
     * @return Application
     */
    public function mount($prefix, $controllers)
    {
        if ($controllers instanceof ControllerProviderInterface) {
            $controllers = $controllers->connect($this);
        }

        if (!$controllers instanceof ControllerCollection) {
            throw new \LogicException('The "mount" method takes either a ControllerCollection or a ControllerProviderInterface instance.');
        }

        $this['controllers']->mount($prefix, $controllers);

        return $this;
    }

    /**
     * Handles the request and delivers the response.
     *
     * @param Request|null $request Request to process
     */
    public function run(Request $request = null)
    {
        if (null === $request) {
            $request = Request::createFromGlobals();
        }

        $response = $this->handle($request);
        $response->send();
        $this->terminate($request, $response);
    }

    /**
     * {@inheritdoc}
     *
     * If you call this method directly instead of run(), you must call the
     * terminate() method yourself if you want the finish filters to be run.
     */
    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        if (!$this->booted) {
            $this->boot();
        }

        $this->flush();

        return $this['kernel']->handle($request, $type, $catch);
    }

    /**
     * {@inheritdoc}
     */
    public function terminate(Request $request, Response $response)
    {
        $this['kernel']->terminate($request, $response);
    }
}
