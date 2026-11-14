<?php

declare(strict_types=1);

enum EnvironmentConfigurationMode: string
{
    case CONFIG = 'config';
    case MIXED = 'mixed';
    case ENVIRONMENT = 'environment';
}
