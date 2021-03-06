<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propel\Generator\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Propel\Generator\Manager\MigrationManager;
use Propel\Generator\Util\SqlParser;

/**
 * @author William Durand <william.durand1@gmail.com>
 */
class MigrationDownCommand extends AbstractCommand
{
    const DEFAULT_MIGRATION_TABLE   = 'propel_migration';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->addOption('output-dir',       null, InputOption::VALUE_REQUIRED,  'The output directory')
            ->addOption('migration-table',  null, InputOption::VALUE_REQUIRED,  'Migration table name', self::DEFAULT_MIGRATION_TABLE)
            ->addOption('connection',       null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Connection to use', array())
            ->setName('migration:down')
            ->setAliases(array('down'))
            ->setDescription('Execute migrations down')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configOptions = array();

        if ($this->hasInputOption('output-dir', $input)) {
            $configOptions['propel']['paths']['migrationDir'] = $input->getOption('output-dir');
        }

        $generatorConfig = $this->getGeneratorConfig($configOptions, $input);

        $this->createDirectory($generatorConfig->getSection('paths')['migrationDir']);

        $manager = new MigrationManager();
        $manager->setGeneratorConfig($generatorConfig);

        $connections = array();
        $optionConnections = $input->getOption('connection');
        if (!$optionConnections) {
            $connections = $generatorConfig->getBuildConnections();
        } else {
            foreach ($optionConnections as $connection) {
                list($name, $dsn, $infos) = $this->parseConnection($connection);
                $connections[$name] = array_merge(array('dsn' => $dsn), $infos);
            }
        }

        $manager->setConnections($connections);
        $manager->setMigrationTable($input->getOption('migration-table'));
        $manager->setWorkingDirectory($generatorConfig->getSection('paths')['migrationDir']);

        $previousTimestamps = $manager->getAlreadyExecutedMigrationTimestamps();
        if (!$nextMigrationTimestamp = array_pop($previousTimestamps)) {
            $output->writeln('No migration were ever executed on this database - nothing to reverse.');

            return false;
        }

        $output->writeln(sprintf(
            'Executing migration %s down',
            $manager->getMigrationClassName($nextMigrationTimestamp)
        ));

        if ($nbPreviousTimestamps = count($previousTimestamps)) {
            $previousTimestamp = array_pop($previousTimestamps);
        } else {
            $previousTimestamp = 0;
        }

        $migration = $manager->getMigrationObject($nextMigrationTimestamp);
        if (false === $migration->preDown($manager)) {
            $output->writeln('<error>preDown() returned false. Aborting migration.</error>');

            return false;
        }

        foreach ($migration->getDownSQL() as $datasource => $sql) {
            $connection = $manager->getConnection($datasource);

            if ($input->getOption('verbose')) {
                $output->writeln(sprintf(
                    'Connecting to database "%s" using DSN "%s"',
                    $datasource,
                    $connection['dsn']
                ));
            }

            $conn = $manager->getAdapterConnection($datasource);
            $res = 0;
            $statements = SqlParser::parseString($sql);

            foreach ($statements as $statement) {
                try {
                    if ($input->getOption('verbose')) {
                        $output->writeln(sprintf('Executing statement "%s"', $statement));
                    }

                    $conn->exec($statement);
                    $res++;
                } catch (\PDOException $e) {
                    $output->writeln(sprintf('<error>Failed to execute SQL "%s"</error>', $statement));
                }
            }
            if (!$res) {
                $output->writeln('No statement was executed. The version was not updated.');
                $output->writeln(sprintf(
                    'Please review the code in "%s"',
                    $manager->getMigrationDir() . DIRECTORY_SEPARATOR . $manager->getMigrationClassName($nextMigrationTimestamp)
                ));
                $output->writeln('<error>Migration aborted</error>');

                return false;
            }

            $output->writeln(sprintf(
                '%d of %d SQL statements executed successfully on datasource "%s"',
                $res,
                count($statements),
                $datasource
            ));

            $manager->removeMigrationTimestamp($datasource, $nextMigrationTimestamp);

            if ($input->getOption('verbose')) {
                $output->writeln(sprintf(
                    'Downgraded migration date to %d for datasource "%s"',
                    $previousTimestamp,
                    $datasource
                ));
            }
        }

        $migration->postDown($manager);

        if ($nbPreviousTimestamps) {
            $output->writeln(sprintf('Reverse migration complete. %d more migrations available for reverse.', $nbPreviousTimestamps));
        } else {
            $output->writeln('Reverse migration complete. No more migration available for reverse');
        }
    }
}
