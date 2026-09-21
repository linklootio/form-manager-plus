<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\EventListener;

use SchefferWebdesign\FormManagerPlus\Service\{FormLifecycle};
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\{AfterFileDeletedEvent, AfterFileMovedEvent, AfterFileRenamedEvent, AfterFolderDeletedEvent, AfterFolderMovedEvent, AfterFolderRenamedEvent, BeforeFileDeletedEvent, BeforeFileMovedEvent, BeforeFileRenamedEvent, BeforeFolderDeletedEvent, BeforeFolderMovedEvent, BeforeFolderRenamedEvent};

/** Remember the old identifier before FAL mutates the object; write only after success. */
final class FormFileLifecycle
{
    private array $previous = [];
    public function __construct(private FormLifecycle $lifecycle) {}
    #[AsEventListener(identifier: 'fmp/before-rename', event: BeforeFileRenamedEvent::class)]
    #[AsEventListener(identifier: 'fmp/before-move', event: BeforeFileMovedEvent::class)]
    #[AsEventListener(identifier: 'fmp/before-delete', event: BeforeFileDeletedEvent::class)]
    public function before(BeforeFileRenamedEvent|BeforeFileMovedEvent|BeforeFileDeletedEvent $event): void
    {
        $file = $event->getFile();
        if ($file instanceof \TYPO3\CMS\Core\Resource\File && str_ends_with($file->getIdentifier(), '.form.yaml')) {
            $this->previous[spl_object_id($file)] = $file->getCombinedIdentifier();
        }
    }
    #[AsEventListener(identifier: 'fmp/after-rename', event: AfterFileRenamedEvent::class)]
    #[AsEventListener(identifier: 'fmp/after-move', event: AfterFileMovedEvent::class)]
    #[AsEventListener(identifier: 'fmp/after-delete', event: AfterFileDeletedEvent::class)]
    public function after(AfterFileRenamedEvent|AfterFileMovedEvent|AfterFileDeletedEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof \TYPO3\CMS\Core\Resource\File) {
            return;
        } $key = spl_object_id($file);
        $previous = $this->previous[$key] ?? null;
        unset($this->previous[$key]);
        if ($previous === null) {
            return;
        }
        if ($event instanceof AfterFileDeletedEvent || !str_ends_with($file->getIdentifier(), '.form.yaml')) {
            $this->lifecycle->forget($previous);
        } else {
            $this->lifecycle->relocate($previous, $file->getCombinedIdentifier());
        }
    }
    #[AsEventListener(identifier: 'fmp/before-folder-move', event: BeforeFolderMovedEvent::class)]
    #[AsEventListener(identifier: 'fmp/before-folder-rename', event: BeforeFolderRenamedEvent::class)]
    #[AsEventListener(identifier: 'fmp/before-folder-delete', event: BeforeFolderDeletedEvent::class)]
    public function beforeFolder(BeforeFolderMovedEvent|BeforeFolderRenamedEvent|BeforeFolderDeletedEvent $event): void
    {
        $folder = $event->getFolder();
        $prefix = $folder->getCombinedIdentifier();
        $files = [];
        foreach ($folder->getFiles(recursive: true) as $file) {
            if ($file instanceof \TYPO3\CMS\Core\Resource\File && str_ends_with($file->getIdentifier(), '.form.yaml')) {
                $identifier = $file->getCombinedIdentifier();
                if (str_starts_with($identifier, $prefix)) {
                    $files[$identifier] = substr($identifier, strlen($prefix));
                }
            }
        }
        $this->previous['folder-' . spl_object_id($folder)] = $files;
    }
    #[AsEventListener(identifier: 'fmp/after-folder-move', event: AfterFolderMovedEvent::class)]
    #[AsEventListener(identifier: 'fmp/after-folder-rename', event: AfterFolderRenamedEvent::class)]
    #[AsEventListener(identifier: 'fmp/after-folder-delete', event: AfterFolderDeletedEvent::class)]
    public function afterFolder(AfterFolderMovedEvent|AfterFolderRenamedEvent|AfterFolderDeletedEvent $event): void
    {
        $original = $event instanceof AfterFolderRenamedEvent ? $event->getSourceFolder() : $event->getFolder();
        $key = 'folder-' . spl_object_id($original);
        $files = $this->previous[$key] ?? [];
        unset($this->previous[$key]);
        $target = $event instanceof AfterFolderMovedEvent ? $event->getTargetFolder() : $event->getFolder();
        if (!$target instanceof \TYPO3\CMS\Core\Resource\Folder) {
            return;
        }
        foreach ($files as $previous => $relative) {
            if ($event instanceof AfterFolderDeletedEvent) {
                $this->lifecycle->forget($previous);
            } else {
                $this->lifecycle->relocate($previous, $target->getCombinedIdentifier() . $relative);
            }
        }
    }

}
