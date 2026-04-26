<?php

declare(strict_types=1);

use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsCatalogResource;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsCatalogTool;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsManifestStatusResource;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsPageTool;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsSearchTool;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsSectionTool;
use Rad\SymfonyDocsMateExtension\Command\SymfonyDocsSyncCommand;
use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Rad\SymfonyDocsMateExtension\Service\DocsSourceResolver;
use Rad\SymfonyDocsMateExtension\Service\RstParser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters
        ->set('symfony_docs_mate.docs_root', null)
        ->set('symfony_docs_mate.project_root', '%mate.root_dir%')
        ->set('symfony_docs_mate.cache_dir', '%mate.root_dir%/var/cache/symfony-docs-mate')
        ->set('symfony_docs_mate.docs_ref', null)
        ->set('symfony_docs_mate.archive_url', null)
        ->set('symfony_docs_mate.max_search_hits', 8)
        ->set('symfony_docs_mate.max_excerpt_lines', 80)
        ->set('symfony_docs_mate.max_excerpt_chars', 4000);

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(DocsConfiguration::class)
        ->arg('$docsRoot', '%symfony_docs_mate.docs_root%')
        ->arg('$projectRoot', '%symfony_docs_mate.project_root%')
        ->arg('$cacheDir', '%symfony_docs_mate.cache_dir%')
        ->arg('$docsRef', '%symfony_docs_mate.docs_ref%')
        ->arg('$archiveUrl', '%symfony_docs_mate.archive_url%')
        ->arg('$maxSearchHits', '%symfony_docs_mate.max_search_hits%')
        ->arg('$maxExcerptLines', '%symfony_docs_mate.max_excerpt_lines%')
        ->arg('$maxExcerptChars', '%symfony_docs_mate.max_excerpt_chars%');

    $services->set(DocsSourceResolver::class);
    $services->set(RstParser::class);
    $services->set(DocsRepository::class);

    $services->set(SymfonyDocsCatalogTool::class);
    $services->set(SymfonyDocsSearchTool::class);
    $services->set(SymfonyDocsPageTool::class);
    $services->set(SymfonyDocsSectionTool::class);
    $services->set(SymfonyDocsCatalogResource::class);
    $services->set(SymfonyDocsManifestStatusResource::class);
    $services->set(SymfonyDocsSyncCommand::class)
        ->public();
};
