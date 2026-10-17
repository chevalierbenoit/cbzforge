<?php

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqlitePragmasMiddleware implements Middleware
{
    public const PRAGMAS = [
        'PRAGMA busy_timeout=5000',
        'PRAGMA journal_mode=WAL',
        'PRAGMA synchronous=NORMAL',
        'PRAGMA foreign_keys=ON',
    ];

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            #[\Override]
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);

                if ('pdo_sqlite' === ($params['driver'] ?? '') && ':memory:' !== ($params['path'] ?? '')) {
                    foreach (SqlitePragmasMiddleware::PRAGMAS as $pragma) {
                        try {
                            // Best-effort: some pragmas are rejected inside an
                            // explicit transaction (e.g. DAMA test isolation).
                            $connection->exec($pragma);
                        } catch (Driver\Exception) {
                        }
                    }
                }

                return $connection;
            }
        };
    }
}
