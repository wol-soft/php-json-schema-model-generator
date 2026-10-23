<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\ModelUpdate;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Restores the complete model state of a rejected update (setter, populate, additional property accessors). An update
 * may change the model state before it is accepted: compositions record their results and apply branch defaults, base
 * validators store additional and pattern properties, custom setter hooks may change anything.
 *
 * Restoring only the state known to be changed by specific validators (one rollback mechanism per validator) was
 * rejected: each validator needs its own bookkeeping, state changed by custom hooks isn't covered, and a validator
 * which succeeds before a later validator rejects the update isn't restored at all.
 */
class ModelStateRollback
{
    private const METHOD_TEMPLATES = [
        '_backupModelState' => 'BackupModelState',
        '_restoreModelState' => 'RestoreModelState',
    ];

    public function __construct(private readonly GeneratorConfiguration $generatorConfiguration)
    {}

    /**
     * Wraps the code of an update into a snapshot of the model state which is restored if the code throws. The
     * snapshot and restore methods are added to the schema on first use, a class without any such update doesn't
     * contain them.
     */
    public function wrap(Schema $schema, string $code): string
    {
        foreach (self::METHOD_TEMPLATES as $method => $template) {
            if (!$schema->hasMethod($method)) {
                $schema->addMethod(
                    $method,
                    new RenderedMethod(
                        $schema,
                        $this->generatorConfiguration,
                        join(DIRECTORY_SEPARATOR, ['ModelState', "$template.phptpl"]),
                    ),
                );
            }
        }

        $indentedCode = RenderHelper::indent($code, 1);

        return <<<PHP
            \$modelStateBackup = \$this->_backupModelState();

            try {
                $indentedCode
            } catch (\\Throwable \$exception) {
                \$this->_restoreModelState(\$modelStateBackup);

                throw \$exception;
            }
            PHP;
    }
}
