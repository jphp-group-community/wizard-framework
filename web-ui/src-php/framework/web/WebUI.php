<?php
namespace framework\web;

use framework\core\Annotations;
use framework\core\Event;
use framework\core\Logger;
use framework\core\Module;
use php\format\JsonProcessor;
use php\http\HttpRedirectHandler;
use php\http\HttpResourceHandler;
use php\http\HttpServerRequest;
use php\http\HttpServerResponse;
use php\http\WebSocketSession;
use php\io\ResourceStream;
use php\lang\System;
use php\lib\fs;
use php\lib\str;


include "res://.inc/ui-functions.php";

class WebUI extends Module
{
    /**
     * @var array
     */
    protected $isolatedSessionInstances = [];

    /**
     * @var array
     */
    protected $uiClasses = [];

    /**
     * @var string
     */
    protected $dnextJsFile = null;

    /**
     * @var string
     */
    protected $dnextCssFile = null;

    /**
     * @var WebApplication
     */
    protected $app;

    public function __construct()
    {
        $this->on('inject', function (Event $event) {
            if ($event->context instanceof WebApplication) {
                $this->app = $event->context;
                $this->initializeWebLib($event->context);
            } else {
                throw new \Exception("WebUI module only for Web Applications");
            }
        });

        $this->on('redeploy', function (Event $event) {
            foreach ($this->isolatedSessionInstances as $sid => $instances) {
                foreach ($this->uiClasses as $class => $reflection) {
                    /** @var UI $ui */
                    $ui = $instances[$class];

                    if ($ui) {
                        $ui->sendMessage('ui-reload', []);
                    }
                }
            }
        });

        $this->on('shutdown', function (Event $event) {
            foreach ($this->isolatedSessionInstances as $sid => $instances) {
                /** @var UISocket $socket */
                if ($socket = $instances[UISocket::class]) {
                    $socket->shutdown();
                }
            }
        });
    }

    /**
     * Enable rich user interface.
     * @param string $jsFile
     * @param string $cssFile
     * @return $this
     */
    public function setupResources(string $jsFile = '', string $cssFile = '')
    {
        $this->dnextCssFile = $cssFile;
        $this->dnextJsFile = $jsFile;

        return $this;
    }

    /**
     * @param string $uiClass
     * @return $this
     */
    public function addUI(string $uiClass)
    {
        $reflectionClass = $this->uiClasses[$uiClass] = new \ReflectionClass($uiClass);

        $path = Annotations::getOfClass('path', $reflectionClass);

        if ($path === '/') {
            $path = '';
        }

        Logger::info("Add UI ({0})", $uiClass);

        $route = function ($path, callable $handler) use ($uiClass) {
            $this->app->server()->get($path, function (HttpServerRequest $request, HttpServerResponse $response) use ($uiClass, $handler) {
                $this->app->setupRequestAndResponse($request, $response);

                /** @var UI $instance */
                $instance = $this->app->getInstance($uiClass);
                $instance->trigger(new Event('beforeRequest', $instance, $this));

                $handler($instance, $request, $response);

                $instance->trigger(new Event('afterRequest', $instance, $this));

                UI::setup(null);
            });

            Logger::info("\t-> GET {0}", $path);
        };

        $this->app->server()->addWebSocket("$path/@ws/", [
            'onConnect' => function (WebSocketSession $session) {
            },

            'onMessage' => function (WebSocketSession $session, $text) use ($uiClass) {
                $message = (new JsonProcessor(JsonProcessor::DESERIALIZE_AS_ARRAYS))->parse($text);
                $type = $message['type'];

                /** @var UISocket $socket */
                $sessionId = $message['sessionId'] . '_' . $message['sessionIdUuid'];

                if (!($socket = $this->isolatedSessionInstances[$sessionId][UISocket::class])) {
                    $this->isolatedSessionInstances[$sessionId][UISocket::class] = $socket = new UISocket();
                }

                /** @var UI $ui */
                if (!($ui = $this->isolatedSessionInstances[$sessionId][$uiClass])) {
                    $this->isolatedSessionInstances[$sessionId][$uiClass] = $ui = new $uiClass($socket);
                }

                $ui->linkSocket($socket);

                Logger::trace("New UI socket message, (type = {0}, sessionId = {1})", $type, $sessionId);

                switch ($type) {
                    case 'initialize':
                        $socket->initialize($uiClass, $session, $message);
                        break;

                    case 'activate':
                        $socket->activate($uiClass, $message);
                        break;

                    default:
                        try {
                            $socket->receiveMessage($uiClass, new SocketMessage($message));
                        } catch (\Throwable $e) {
                            $errId = str::uuid();

                            Logger::error("{0}, {1}", $e->getMessage(), $errId);
                            Logger::error("\n{0}\n\t-> at {1} on line {2}", $e->getTraceAsString(), $e->getFile(), $e->getLine());
                        } finally {
                            UI::setup(null);
                        }

                        break;
                }
            },

            'onClose' => function (WebSocketSession $session) use ($uiClass) {
                /** @var UISocket $socket */
                //$socket = $this->getInstance(UISocket::class);
                //$socket->close($uiClass);
            }
        ]);

        $this->app->server()->get($path, new HttpRedirectHandler("$path/"));

        $route("$path/**", function (UI $ui, HttpServerRequest $request, HttpServerResponse $response) use ($path) {
            $ui->show($request, $response, $path);
        });

        return $this;
    }

    protected function initializeWebLib(WebApplication $app)
    {
        Logger::info("Initialize Web Library (DNext Engine) with stamp ...");

        $jsResource = new ResourceStream('/dnext-engine.js');
        $cssResource = new ResourceStream('/dnext-engine.min.css');
        $mapResource = new ResourceStream('/dnext-engine.js.map');

        $tempDir = System::getProperty('java.io.tmpdir') . "/dnext-engine/";
        fs::makeDir($tempDir);


        if ($this->dnextJsFile) {
            $jsFile = $this->dnextJsFile;
            $mapFile = $this->dnextJsFile . ".map";

            if (!fs::isFile($mapFile)) $mapFile = null;
        } else {
            fs::copy($jsResource, $jsFile = "$tempDir/engine.js");
            fs::copy($mapResource, $mapFile = "$tempDir/engine.js.map");
        }

        if ($this->dnextCssFile) {
            $cssFile = $this->dnextCssFile;
        } else {
            fs::copy($cssResource, $cssFile = "$tempDir/engine.min.css");
        }

        $server = $app->server();

        $server->get($jsUrl = "/dnext/engine-{$app->getStamp()}.js", new HttpResourceHandler($jsFile));
        $server->get($cssUrl = "/dnext/engine-{$app->getStamp()}.min.css", new HttpResourceHandler($cssFile));

        if ($mapFile) {
            $server->get($mapUrl = "/dnext/engine-{$app->getStamp()}.js.map", new HttpResourceHandler($mapFile));
        }

        Logger::info("Add DNext Engine:");
        Logger::info("\t-> GET {0} {1}", $jsUrl, $jsFile);

        if ($mapUrl) {
            Logger::info("\t-> GET {0} {1}", $mapUrl, $mapFile);
        }

        Logger::info("\t-> GET {0} {1}", $cssUrl, $cssFile);
    }

}