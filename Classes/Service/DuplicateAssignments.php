<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Service;

/** Operations over the authorized catalogue, never arbitrary persistence paths. */
class DuplicateAssignments
{
    public function __construct(protected FormAccess $access, protected WorkspaceData $data, protected CategoryActions $categories) {}

    protected function assignment(string $id, array $choices): array
    {
        $form = $this->access->find($id, true);
        $profile = $this->data->profile($id);
        if (ctype_digit($id)) {
            if (!$GLOBALS['BE_USER']->check('tables_select', 'sys_category')) {
                throw new \DomainException('access');
            }
            return ['selected' => array_values(array_intersect(json_decode($profile['categories_json'] ?? '[]', true) ?: [], array_column($choices, 'uid'))), 'version' => '', 'revision' => (int)$profile['revision']];
        }
        $assignment = $this->categories->fileAssignment($form, $choices);
        if (!$assignment['editable']) {
            throw new \DomainException('access');
        }
        return $assignment + ['revision' => (int)$profile['revision']];
    }

    protected function saveAssignment(string $id, array $choices, array $selected, array $previous): void
    {
        $form = $this->access->find($id, true);
        if (ctype_digit($id)) {
            $this->data->saveCategories($id, $selected, $previous['revision']);
        } else {
            $this->categories->saveFileAssignment($form, $choices, $selected, $previous['version']);
        }
    }

    public function duplicateData(string $source, bool $copyCategories, bool $copyMetadata): array
    {
        $form = $this->access->find($source);
        $profile = $this->data->profile($source);
        $choices = $this->data->categories();
        $selected = ctype_digit($source) ? (json_decode($profile['categories_json'] ?? '[]', true) ?: []) : $this->categories->fileAssignment($form, $choices)['selected'];
        return ['categories' => $copyCategories ? array_values(array_map('intval', array_intersect($selected, array_column($choices, 'uid')))) : [],
            // A new copy belongs to its creator, not the source's responsible user.
            'profile' => $copyMetadata ? array_intersect_key($profile, array_flip(Edition::pro() ? ['purpose', 'responsible', 'notes'] : ['purpose'])) : []];
    }

    public function applyDuplicateData(string $destination, array $copy): void
    {
        $this->access->clearCatalog();
        $this->access->find($destination, true);
        if ($copy['profile']) {
            $profile = $this->data->profile($destination);
            $this->data->saveProfile($destination, array_replace($profile, $copy['profile']));
        }
        if ($copy['categories']) {
            $choices = $this->data->categories();
            $this->saveAssignment($destination, $choices, $copy['categories'], $this->assignment($destination, $choices));
        }
        $this->access->clearCatalog();
    }

}
