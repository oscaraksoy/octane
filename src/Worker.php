<?php

namespace Laravel\Octane;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Contracts\ServesStaticFiles;
use Laravel\Octane\Contracts\Worker as WorkerContract;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use RuntimeException;
use Throwable;

class Worker implements WorkerContract
{
    use DispatchesEvents;

    protected $requestHandledCallbacks = [];

    /**
     * The root application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    public function __construct(
        protected ApplicationFactory $appFactory,
        protected Client $client
    ) {
    }

    /**
     * Boot / initialize the Octane worker.
     *
     * @param  array  $initialInstances
     * @return void
     */
    public function boot(array $initialInstances = []): void
    {
        // First we will create an instance of the Laravel application that can serve as
        // the base container instance we will clone from on every request. This will
        // also perform the initial bootstrapping that's required by the framework.
        $this->app = $app = $this->appFactory->createApplication(
            array_merge(
                $initialInstances,
                [Client::class => $this->client],
            )
        );

        $this->dispatchEvent($app, new WorkerStarting($app));
    }

    /**
     * Handle an incoming request and send the response to the client.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Laravel\Octane\RequestContext  $context
     * @return void
     */
    public function handle(Request $request, RequestContext $context): void
    {
        if ($this->client instanceof ServesStaticFiles &&
            $this->client->canServeRequestAsStaticFile($request, $context)) {
            $this->client->serveStaticFile($request, $context);

            return;
        }

        // We will clone the application instance so that we have a clean copy to switch
        // back to once the request has been handled. This allows us to easily delete
        // certain instances that got resolved / mutated during a previous request.
        CurrentApplication::set($sandbox = clone $this->app);

        $gateway = new ApplicationGateway($this->app, $sandbox);

        try {
            $responded = false;

            // Here we will actually hand the incoming request to the Laravel application so
            // it can generate a response. We'll send this response back to the client so
            // it can be returned to a browser. This gateway will also dispatch events.
            $this->client->respond(
                $context,
                $response = $gateway->handle($request),
            );

            $responded = true;

            $this->invokeRequestHandledCallbacks($request, $response, $sandbox);

            $gateway->terminate($request, $response);
        } catch (Throwable $e) {
            $this->handleWorkerError($e, $sandbox, $request, $context, $responded);
        } finally {
            // After the request handling process has completed we will unset some variables
            // plus reset the current application state back to its original state before
            // it was cloned. Then we will be ready for the next worker iteration loop.
            unset($gateway, $sandbox, $request, $response);

            CurrentApplication::set($this->app);
        }
    }

    /**
     * Handle an incoming task.
     *
     * @param  mixed  $data
     * @return mixed
     */
    public function handleTask($data)
    {
        $result = false;

        // We will clone the application instance so that we have a clean copy to switch
        // back to once the request has been handled. This allows us to easily delete
        // certain instances that got resolved / mutated during a previous request.
        CurrentApplication::set($sandbox = clone $this->app);

        try {
            $this->dispatchEvent($sandbox, new TaskReceived($this->app, $sandbox, $data));

            $result = $data();

            $this->dispatchEvent($sandbox, new TaskTerminated($this->app, $sandbox, $data, $result));
        } catch (Throwable $e) {
            $this->dispatchEvent($sandbox, new WorkerErrorOccurred($e, $sandbox));
        } finally {
            // After the request handling process has completed we will unset some variables
            // plus reset the current application state back to its original state before
            // it wax cloned. Then we will be ready for the next worker iteration loop.
            unset($sandbox);

            CurrentApplication::set($this->app);
        }

        return $result;
    }

    /**
     * Handle an incoming tick.
     *
     * @return void
     */
    public function handleTick()
    {
        CurrentApplication::set($sandbox = clone $this->app);

        try {
            $this->dispatchEvent($sandbox, new TickReceived($this->app, $sandbox));
            $this->dispatchEvent($sandbox, new TickTerminated($this->app, $sandbox));
        } catch (Throwable $e) {
            $this->dispatchEvent($sandbox, new WorkerErrorOccurred($e, $sandbox));
        } finally {
            unset($sandbox);

            CurrentApplication::set($this->app);
        }
    }

    /**
     * Handle an uncaught exception from the worker.
     *
     * @param  \Throwable  $e
     * @param  \Illuminate\Foundation\Application  $app
     * @param  \Illuminate\Http\Request  $request
     * @param  \Laravel\Octane\RequestContext  $context
     * @param  bool  $hasResponded
     * @return void
     */
    protected function handleWorkerError(
        Throwable $e,
        Application $app,
        Request $request,
        RequestContext $context,
        bool $hasResponded
    ): void {
        if (! $hasResponded) {
            $this->client->error($e, $app, $request, $context);
        }

        $this->dispatchEvent($app, new WorkerErrorOccurred($e, $app));
    }

    /**
     * Invoke the request handled callbacks.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    protected function invokeRequestHandledCallbacks($request, $response, $sandbox)
    {
        foreach ($this->requestHandledCallbacks as $callback) {
            $callback($request, $response, $sandbox);
        }
    }

    /**
     * Register a closure to be invoked when requests are handled.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function onRequestHandled(Closure $callback)
    {
        $this->requestHandledCallbacks[] = $callback;

        return $this;
    }

    /**
     * Get the application instance being used by the worker.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function application(): Application
    {
        if (! $this->app) {
            throw new RuntimeException('Worker has not booted. Unable to access application.');
        }

        return $this->app;
    }

    /**
     * Terminate the worker.
     *
     * @return void
     */
    public function terminate(): void
    {
        $this->dispatchEvent($this->app, new WorkerStopping($this->app));
    }
}
