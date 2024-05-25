<?php

namespace Shared;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\AbstractConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader as ContainerPhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\Loader\PhpFileLoader as RoutingPhpFileLoader;
use Symfony\Component\Routing\RouteCollection;

abstract class MultiKernel extends BaseKernel
{
    abstract protected function getKernelName(): string;

    abstract protected function getKernelDir(): string;

    protected function getRootDir(): string
    {
        return realpath($this->getKernelDir() . '/../../../');
    }

    public function getSharedDir(): string
    {
        return $this->getRootDir() . '/sites/_shared';
    }

    public function getSharedConfigDir(): string
    {
        return $this->getSharedDir() . '/config';
    }

    public function getProjectDir(): string
    {
        return realpath($this->getKernelDir() . '/../');
    }

    public function getCacheDir(): string
    {
        return $this->getRootDir() . '/var/' . $this->getKernelName() . '/cache/' . $this->environment;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->getRootDir() . '/var/' . $this->getKernelName() . '/log';
    }

    private function getConfigDir(): string
    {
        return $this->getProjectDir() . '/config';
    }

    private function configureContainer(
        ContainerConfigurator $container,
        LoaderInterface $loader,
        ContainerBuilder $builder
    ): void {
        foreach ([$this->getSharedConfigDir(), $this->getConfigDir()] as $configDir) {
            $container->import($configDir . '/{packages}/*.{php,yaml}');
            $container->import($configDir . '/{packages}/' . $this->environment . '/*.{php,yaml}');
        }

        if (is_file($configDir . '/services.yaml')) {
            $container->import($configDir . '/services.yaml');
            $container->import($configDir . '/{services}_' . $this->environment . '.yaml');
        } else {
            $container->import($configDir . '/{services}.php');
            $container->import($configDir . '/{services}_' . $this->environment . '.php');
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        foreach ([$this->getSharedConfigDir(), $this->getConfigDir()] as $configDir) {
            $routes->import($configDir . '/{routes}/' . $this->environment . '/*.{php,yaml}');
            $routes->import($configDir . '/{routes}/*.{php,yaml}');

            if (is_file($configDir . '/routes.yaml')) {
                $routes->import($configDir . '/routes.yaml');
            } else {
                $routes->import($configDir . '/{routes}.php');
            }
        }

        if (false !== ($fileName = (new ReflectionObject($this))->getFileName())) {
            $routes->import($fileName, 'attribute');
        }
    }

    private function getBundlesPath(): string
    {
        return $this->getConfigDir() . '/bundles.php';
    }

    public function registerBundles(): iterable
    {
        $contents = require $this->getBundlesPath();
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container) use ($loader) {
            $container->setParameter('kernel.shared_dir', $this->getSharedDir());
            $container->setParameter('kernel.shared_config_dir', $this->getSharedConfigDir());

            $container->loadFromExtension('framework', [
                'router' => [
                    'resource' => 'kernel::loadRoutes',
                    'type' => 'service',
                ],
            ]);

            $kernelClass = str_contains(static::class, "@anonymous\0") ? parent::class : static::class;

            if (!$container->hasDefinition('kernel')) {
                $container->register('kernel', $kernelClass)
                    ->addTag('controller.service_arguments')
                    ->setAutoconfigured(true)
                    ->setSynthetic(true)
                    ->setPublic(true);
            }

            $kernelDefinition = $container->getDefinition('kernel');
            $kernelDefinition->addTag('routing.route_loader');

            $container->addObjectResource($this);
            $container->fileExists($this->getBundlesPath());

            $configureContainer = new ReflectionMethod($this, 'configureContainer');
            $configuratorClass = $configureContainer->getNumberOfParameters() > 0
            && ($type = $configureContainer->getParameters()[0]->getType()) instanceof \ReflectionNamedType
            && !$type->isBuiltin() ? $type->getName() : null;

            if ($configuratorClass && !is_a(ContainerConfigurator::class, $configuratorClass, true)) {
                $configureContainer->getClosure($this)($container, $loader);

                return;
            }

            $file = (new ReflectionObject($this))->getFileName();
            /* @var ContainerPhpFileLoader $kernelLoader */
            $kernelLoader = $loader->getResolver()->resolve($file);
            $kernelLoader->setCurrentDir(\dirname($file));
            $bind = Closure::bind(fn &() => $this->instanceof, $kernelLoader, $kernelLoader)();
            $instanceof = &$bind;

            $valuePreProcessor = AbstractConfigurator::$valuePreProcessor;
            AbstractConfigurator::$valuePreProcessor = fn($value) => $this === $value ? new Reference(
                'kernel'
            ) : $value;

            try {
                $configureContainer->getClosure($this)(
                    new ContainerConfigurator(
                        $container,
                        $kernelLoader,
                        $instanceof,
                        $file,
                        $file,
                        $this->getEnvironment()
                    ),
                    $loader,
                    $container
                );
            } finally {
                $instanceof = [];
                $kernelLoader->registerAliasesForSinglyImplementedInterfaces();
                AbstractConfigurator::$valuePreProcessor = $valuePreProcessor;
            }

            $container->setAlias($kernelClass, 'kernel')->setPublic(true);
        });
    }

    /**
     * @internal
     */
    public function loadRoutes(LoaderInterface $loader): RouteCollection
    {
        $file = (new ReflectionObject($this))->getFileName();
        /* @var RoutingPhpFileLoader $kernelLoader */
        $kernelLoader = $loader->getResolver()->resolve($file, 'php');
        $kernelLoader->setCurrentDir(\dirname($file));
        $collection = new RouteCollection();

        $configureRoutes = new ReflectionMethod($this, 'configureRoutes');
        $configureRoutes->getClosure($this)(
            new RoutingConfigurator($collection, $kernelLoader, $file, $file, $this->getEnvironment())
        );

        foreach ($collection as $route) {
            $controller = $route->getDefault('_controller');

            if (\is_array($controller) && [0, 1] === array_keys($controller) && $this === $controller[0]) {
                $route->setDefault('_controller', ['kernel', $controller[1]]);
            } elseif ($controller instanceof Closure
                && $this === ($r = new ReflectionFunction($controller))->getClosureThis()
                && !str_contains($r->name, '{closure')) {
                $route->setDefault('_controller', ['kernel', $r->name]);
            }
        }

        return $collection;
    }
}