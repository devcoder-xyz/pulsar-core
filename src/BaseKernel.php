<?php
declare(strict_types=1);

namespace Pulsar\Core;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DevCoder\DotEnv;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Pulsar\Core\ErrorHandler\ErrorHandler;
use Pulsar\Core\ErrorHandler\ExceptionHandler;
use Pulsar\Core\Handler\RequestHandler;
use Pulsar\Core\Http\Exception\HttpExceptionInterface;
use Pulsar\Core\Router\RouterBuilder;
use Throwable;
use function array_filter;
use function array_keys;
use function array_merge;
use function date_default_timezone_set;
use function error_reporting;
use function getenv;
use function implode;
use function in_array;
use function json_encode;
use function sprintf;

/**
 * @package    Pulsar
 * @author    Devcoder.xyz <dev@devcoder.xyz>
 * @license    https://opensource.org/licenses/MIT	MIT License
 * @link    https://www.devcoder.xyz
 */
abstract class BaseKernel
{
    public const VERSION = '1.0.0';
    public const NAME = 'Pulsar';

    private const DEFAULT_ENVIRONMENTS = [
        'dev',
        'prod'
    ];

    protected ContainerInterface $container;

    /**
     * @var array<MiddlewareInterface, string>
     */
    private array $middlewareCollection = [];
    protected ?DateTimeInterface $startTime = null;

    /**
     * BaseKernel constructor.
     */
    public function __construct()
    {
        App::init($this->getConfigDir() . DIRECTORY_SEPARATOR . 'framework.php');
        $this->boot();
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws Throwable
     */
    final public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $requestHandler = new RequestHandler($this->container, $this->middlewareCollection);
            $response = $requestHandler->handle($request);
            if ($this->startTime !== null) {
                $diff = (microtime(true) - $this->startTime) * 1000;
            }
            return $response;
        } catch (Throwable $exception) {
            if (!$exception instanceof HttpExceptionInterface) {
                $this->log($exception);
            }

            $exceptionHandler = $this->container->get(ExceptionHandler::class);
            return $exceptionHandler->render($request, $exception);
        }
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    abstract protected function getProjectDir(): string;

    abstract protected function getCacheDir(): string;

    abstract protected function getLogDir(): string;

    abstract protected function getConfigDir(): string;

    protected function loadParameters(array $parameters): array
    {
        $parameters['pulsar.environment'] = getenv('APP_ENV');
        $parameters['pulsar.debug'] = getenv('APP_ENV') === 'dev';
        $parameters['pulsar.project_dir'] = $this->getProjectDir();
        $parameters['pulsar.cache_dir'] = $this->getCacheDir();
        $parameters['pulsar.logs_dir'] = $this->getLogDir();
        $parameters['pulsar.config_dir'] = $this->getConfigDir();

        return $parameters;
    }

    protected function loadContainer(array $definitions): ContainerInterface
    {
        $containerBuilder = App::createContainerBuilder();
        return $containerBuilder($definitions, ['cache_dir' => $this->getCacheDir()]);
    }

    protected function loadEventDispatcher(array $listeners): Closure
    {
        $eventDispatcherBuilder = App::createEventDispatcherBuilder();
        return $eventDispatcherBuilder($listeners);
    }

    protected function loadRouter(array $routes): Closure
    {
        $routerBuilder = App::createRouterBuilder();
        return $routerBuilder($routes);
    }

    protected function log(Throwable $exception): void
    {
        $data = [
            'date' => (new DateTimeImmutable())->format('c'),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace(),
        ];

        error_log(
            json_encode($data) . PHP_EOL,
            3,
            $this->getLogDir() . DIRECTORY_SEPARATOR . $this->container->get('pulsar.environment') . '.log'
        );
    }

    final private function boot(): void
    {
        (new DotEnv($this->getProjectDir() . DIRECTORY_SEPARATOR . '.env'))->load();
        $environments = self::getAvailableEnvironments();
        if (!in_array(getenv('APP_ENV'), $environments)) {
            throw new InvalidArgumentException(sprintf(
                    'The env "%s" do not exist. Defined environments are: "%s".',
                    getenv('APP_ENV'),
                    implode('", "', $environments))
            );
        }

        date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

        error_reporting(0);
        if (getenv('APP_ENV') === 'dev') {
            $this->startTime = microtime(true);
            ErrorHandler::register();
        }

        $middlewares = (require $this->getConfigDir() . DIRECTORY_SEPARATOR . 'middlewares.php');
        $middlewares = array_filter($middlewares, function ($environments) {
            return in_array(getenv('APP_ENV'), $environments);
        });
        $this->middlewareCollection = array_keys($middlewares);

        list($services, $parameters, $listeners, $routes) = $this->initDependencies();
        $this->container = $this->loadContainer(array_merge(
            $this->loadParameters($parameters),
            $services,
            [
                EventDispatcherInterface::class => $this->loadEventDispatcher($listeners),
                'router' => $this->loadRouter($routes)
            ]
        ));
    }

    final private function initDependencies(): array
    {
        $services = (require $this->getConfigDir() . DIRECTORY_SEPARATOR . 'services.php');
        $parameters = (require $this->getConfigDir() . DIRECTORY_SEPARATOR . 'parameters.php');
        $listeners = (require $this->getConfigDir() . DIRECTORY_SEPARATOR . 'listeners.php');
        $routes = (require $this->getConfigDir() . DIRECTORY_SEPARATOR . 'routes.php');
        foreach ($this->getPackages() as $package) {
            $services = array_merge($package->getDefinitions(), $services);
            $parameters = array_merge($package->getParameters(), $parameters);
            $listeners = array_merge_recursive($package->getListeners(), $listeners);
            $routes = array_merge($package->getRoutes(), $routes);
        }
        return [$services, $parameters, $listeners, $routes];
    }

    final private function getPackages(): array
    {
        $packagesName = (require $this->getConfigDir() . DIRECTORY_SEPARATOR . 'packages.php');
        $packages = [];
        foreach ($packagesName as $packageName => $envs) {
            if (!in_array(getenv('APP_ENV'), $envs)) {
                continue;
            }
            $packages[] = new $packageName();
        }
        return $packages;
    }

    final private static function getAvailableEnvironments(): array
    {
        return array_unique(array_merge(self::DEFAULT_ENVIRONMENTS, App::getCustomEnvironments()));
    }
}
