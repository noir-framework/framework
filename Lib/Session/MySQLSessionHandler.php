<?php

declare(strict_types=1);

namespace Noirapi\Lib\Session;

use Override;
use PDO;

class MySQLSessionHandler extends AbstractSessionHandler
{
    private PDO $pdo;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->pdo = new PDO('mysql:' . $dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
    }

    /**
     * @param string $id
     * @return string|null
     */
    #[Override]
    protected function doRead(string $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT data, last_activity FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $maxLifetime = (int)ini_get('session.gc_maxlifetime');
        if ($row->last_activity < time() - $maxLifetime) {
            $this->doDestroy($id);
            return null;
        }

        return (string)$row->data;
    }

    /**
     * @param string $id
     * @param string $data
     * @return bool
     */
    #[Override]
    protected function doWrite(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)'
        );
        return $stmt->execute([$id, $data, time()]);
    }

    /**
     * @param string $id
     * @return bool
     */
    #[Override]
    protected function doDestroy(string $id): bool
    {
        return $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
    }

    /**
     * @param int $maxLifetime
     * @return int|false
     */
    #[Override]
    protected function doGc(int $maxLifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < ?');
        $stmt->execute([time() - $maxLifetime]);
        return $stmt->rowCount();
    }
}
