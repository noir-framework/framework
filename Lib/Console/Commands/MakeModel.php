<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Override;

/**
 * make:model Name: app/models/Name.php extending Noirapi\Lib\Model. A controller with the same
 * short name gets it as $this->model.
 *
 * @psalm-api
 */
final class MakeModel implements Command
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'make:model';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return 'Create app/models/<Name>.php';
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        $name = Generator::className($args, $output, 'noirapi make:model <Name>');
        if ($name === null) {
            return 1;
        }

        [$namespace, $class] = Generator::split($name);
        $table = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        $code = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Models$namespace;

            use Noirapi\\Lib\\Model;

            final class $class extends Model
            {
                public function find(int \$id): ?object
                {
                    \$row = \$this->db->from('$table')->where('id')->is(\$id)->select()->first();

                    return \$row === false ? null : \$row;
                }
            }

            PHP;

        return Generator::write('models', $name, $code, $output) === null ? 1 : 0;
    }
}
