<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleExtension;

class OnServerStart
{
    public function __construct(
        protected ServerStateFile $serverStateFile,
        protected SwooleExtension $extension,
        protected string $appName,
        protected bool $shouldTick = true,
        protected bool $shouldSetProcessName = true
    ) {
    }

    /**
     * Handle the "start" Swoole event.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    public function __invoke($server)
    {
        if ($this->shouldTick) {
            $server->tick(1000, function () use ($server) {
                $server->task('octane-tick');
            });
        }

        $this->serverStateFile->writeProcessIds(
            $server->master_pid,
            $server->manager_pid
        );

        if ($this->shouldSetProcessName) {
            $this->extension->setProcessName($this->appName, 'master process');
        }
    }
}