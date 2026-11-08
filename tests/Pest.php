<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Tests Feature (tests/Feature et app/Modules/<Module>/Tests/Feature) : application Laravel
| complète, base SQLite en mémoire remise à zéro à chaque test.
| Tests unitaires des modules (app/Modules/<Module>/Tests/Unit) : calculs purs, sans base.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', '../app/Modules/*/Tests/Feature');
