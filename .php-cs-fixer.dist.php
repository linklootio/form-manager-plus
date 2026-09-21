<?php
declare(strict_types=1);
$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->setHeader('Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.', true);
$config->getFinder()->in(__DIR__ . '/Classes')->in(__DIR__ . '/Configuration')->in(__DIR__ . '/Tests')->exclude('Fixtures');
return $config;
