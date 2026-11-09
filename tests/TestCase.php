<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') !== 'mysql'
            || $app['config']->get('database.connections.mysql.database') !== 'personal_budget_testing'
            || $app['config']->get('database.connections.mysql.url')) {
            throw new LogicException('Тести дозволені лише на MySQL-базі personal_budget_testing. Очистіть кеш конфігурації через Sail.');
        }

        return $app;
    }
}
