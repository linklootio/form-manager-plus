<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

use SchefferWebdesign\FormManagerPlus\Compatibility\CurrentFormSource;
use SchefferWebdesign\FormManagerPlus\Compatibility\FormSourceInterface;
use SchefferWebdesign\FormManagerPlus\Compatibility\ModernFormSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Information\Typo3Version;

return static function (ContainerConfigurator $container): void {
    $major = (new Typo3Version())->getMajorVersion();
    $source = $major === 13 ? CurrentFormSource::class : ModernFormSource::class;
    $container->services()->set($source)->autowire()->autoconfigure();
    $container->services()->alias(FormSourceInterface::class, $source);
    $persistence = $major === 13 ? \SchefferWebdesign\FormManagerPlus\Compatibility\Persistence13::class : \SchefferWebdesign\FormManagerPlus\Compatibility\Persistence14::class;
    $container->services()->set($persistence)->autowire()->autoconfigure();
    $container->services()->alias(\SchefferWebdesign\FormManagerPlus\Compatibility\PersistenceAdapter::class, $persistence);

    if ($major >= 13) {
        $container->services()->set(\SchefferWebdesign\FormManagerPlus\Controller\ToolsController::class)->autowire()->autoconfigure()->public();
        $container->services()->set(\SchefferWebdesign\FormManagerPlus\EventListener\WorkbenchButtons::class)
            ->autowire()->autoconfigure()->tag('event.listener', [
                'identifier' => 'form-manager-plus/workbench-buttons',
                'event' => \TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent::class,
            ]);
        $container->services()->set($major >= 14
            ? \SchefferWebdesign\FormManagerPlus\Compatibility\DeferredFormManager14::class
            : \SchefferWebdesign\FormManagerPlus\Compatibility\DeferredFormManager13::class)->autowire()->autoconfigure()->public();
        $container->services()->set(\SchefferWebdesign\FormManagerPlus\EventListener\FormEditorCategories::class)
            ->autowire()->autoconfigure()->tag('event.listener', [
                'identifier' => 'form-manager-plus/editor-categories',
                'event' => \TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent::class,
            ]);
    }
};
