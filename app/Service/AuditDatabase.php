<?php

namespace App\Service;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuditDatabase
{
    public function Connection(): ConnectionInterface
    {
        $audit = DB::connection('audit');
        $primary = DB::connection();
        if ($audit === $primary) {
            throw new LogicException('The audit connection must be separate from the application connection.');
        }
        $auditDriver = $audit->getDriverName();
        $primaryDriver = $primary->getDriverName();
        if ($auditDriver === 'sqlite' && $primaryDriver === 'sqlite') {
            $auditPath = $audit->getDatabaseName();
            $primaryPath = $primary->getDatabaseName();
            if ($auditPath !== ':memory:' && $primaryPath !== ':memory:'
                && $this->NormalizePath($auditPath) === $this->NormalizePath($primaryPath)) {
                throw new LogicException('The audit database must use a different SQLite file.');
            }
        } elseif (in_array($auditDriver, ['mysql', 'mariadb'], true) && in_array($primaryDriver, ['mysql', 'mariadb'], true)) {
            if ($audit->getDatabaseName() === $primary->getDatabaseName()
                && $audit->getConfig('host') === $primary->getConfig('host')
                && (string) $audit->getConfig('port') === (string) $primary->getConfig('port')
                && $audit->getConfig('unix_socket') === $primary->getConfig('unix_socket')) {
                throw new LogicException('The audit database must use a different database from the application.');
            }
        }

        return $audit;
    }

    private function NormalizePath(string $path): string
    {
        $path = realpath($path) ?: $path;

        return PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path;
    }
}
