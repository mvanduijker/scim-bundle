<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ScimBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('token')
                    ->info('Bearer token the customer enters as "Secret token" in Entra. Omit when you provide your own ScimTokenProvider.')
                    ->defaultNull()
                ->end()
                ->arrayNode('delegates')
                    ->info('Other apps running this bundle; every change is forwarded to them before it is saved here. Omit when you provide your own ScimDelegateProvider.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')->info('e.g. https://app2.example.com/scim/v2')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('token')->info('That app\'s SCIM token')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set(ScimController::class)
            ->args([
                service(ScimUserStore::class),
                service(ScimTokenProvider::class),
                service(ScimDelegateProvider::class)->nullOnInvalid(),
            ])
            ->tag('controller.service_arguments')
            ->public();

        // Defaults so the bundle installs before the app implements anything; the app's own aliases replace them
        $services->set(NullUserStore::class);
        $services->alias(ScimUserStore::class, NullUserStore::class);
        $services->set(StaticTokenProvider::class)->args([$config['token'] ?? '']); // '' never matches: SCIM stays off
        $services->alias(ScimTokenProvider::class, StaticTokenProvider::class);

        if ([] !== $config['delegates']) {
            $services->set(StaticDelegateProvider::class)->args([array_map(
                static fn (array $d) => inline_service(ScimDelegate::class)->args([service('http_client'), $d['url'], $d['token']]),
                $config['delegates'],
            )]);
            $services->alias(ScimDelegateProvider::class, StaticDelegateProvider::class);
        }
    }
}
