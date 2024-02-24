<?php declare(strict_types=1);

namespace Melv\ShopmonIntegration\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection;
use Shopware\Core\Framework\Api\Util\AccessKeyHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\Integration\IntegrationCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('melv:shopmon:create-integration', 'Create Shopmon integration')]
class ShopmonCreateIntegration extends Command
{
    private const SHOPMON_ACL_ROLE_NAME = 'SHOPMON_ACL_ROLE';
    private const SHOPMON_ACL_ROLE_DESCRIPTION = 'This role has the necessary permissions to use Shopmon';
    private const SHOPMON_ACL_ROLE_ID = '018dd6ae4c4072b1b5887fe8d3b9b95a';
    private const SHOPMON_ACL_INTEGRATION_ID = 'c7c2b5c9af44443ea3f3482e7fd71d21';

    /**
     * @param EntityRepository<AclRoleCollection> $aclRoleRepository
     * @param EntityRepository<IntegrationCollection> $integrationRepository
     * @param Connection $connection
     */
    public function __construct(
        private readonly EntityRepository $aclRoleRepository,
        private readonly EntityRepository $integrationRepository,
        private readonly Connection $connection
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Integration name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $integrationName = $input->getOption('name');
        if(!$integrationName) {
            $io->error('Integration name is required');
            return Command::FAILURE;
        }

        $existingData = $this->roleOrIntegrationExists();
        if($existingData !== null) {
            foreach($existingData as $exists) {
                $io->error(sprintf('%s already exists (id: %s)', $exists['type'] , $exists['id']));
            }
            return Command::FAILURE;
        }

        try {
            $this->createRole();
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('ACL role with the name `%s` has been created', self::SHOPMON_ACL_ROLE_NAME));

        try {
            $this->createIntegration($integrationName);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('Integration with the name `%s` has been created', $integrationName));

        try {
            $this->assignRoleToIntegration();
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success('ACL role has been assigned to the integration');
        $io->note('Credentials needs to be regenerated');

        return Command::SUCCESS;
    }

    private function createRole(): void
    {
        $aclPermissions = [
            'app:read',
            'product:read',
            'product:write',
            'system_config:read',
            'scheduled_task:read',
            'frosh_tools:read',
            'system:clear:cache',
            'system:cache:info'
        ];

        $aclRole = [
            'id' => self::SHOPMON_ACL_ROLE_ID,
            'name' => self::SHOPMON_ACL_ROLE_NAME,
            'description' => self::SHOPMON_ACL_ROLE_DESCRIPTION,
            'privileges' => $aclPermissions,
        ];

        $context = Context::createDefaultContext();
        $this->aclRoleRepository->create([$aclRole], $context);
    }

    private function createIntegration(string $integrationName): void
    {
        $context = Context::createDefaultContext();
        $integration = [
            'id' => self::SHOPMON_ACL_INTEGRATION_ID,
            'label' => $integrationName,
            'accessKey' => AccessKeyHelper::generateAccessKey('integration'),
            'secretAccessKey' => AccessKeyHelper::generateSecretAccessKey(),
            'admin' => false,
        ];

        $this->integrationRepository->create([$integration], $context);
    }

    private function assignRoleToIntegration(): void
    {
        $sql = <<<'SQL'
            INSERT INTO `integration_role` (`integration_id`, `acl_role_id`)
            VALUES (:integration_id, :acl_role_id)
        SQL;

        $data = [
            'integration_id' => Uuid::fromHexToBytes(self::SHOPMON_ACL_INTEGRATION_ID),
            'acl_role_id' => Uuid::fromHexToBytes(self::SHOPMON_ACL_ROLE_ID)
        ];

        $query = new RetryableQuery($this->connection, $this->connection->prepare($sql));
        $query->execute($data);
    }

    private function roleOrIntegrationExists(): ?array
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria([self::SHOPMON_ACL_ROLE_ID]);
        $existingData = [];
        if($this->aclRoleRepository->search($criteria, $context)->getTotal() > 0) {
            $existingData[self::SHOPMON_ACL_ROLE_ID] = [
                'id' => self::SHOPMON_ACL_ROLE_ID,
                'type' => 'acl_role'
            ];
        }

        $criteria = new Criteria([self::SHOPMON_ACL_INTEGRATION_ID]);
        if($this->integrationRepository->search($criteria, $context)->getTotal() > 0) {
            $existingData[self::SHOPMON_ACL_INTEGRATION_ID] = [
                'id' => self::SHOPMON_ACL_INTEGRATION_ID,
                'type' => 'integration'
            ];
        }

        if(count($existingData) > 0) {
            return $existingData;
        }

        return null;
    }
}
