<?php

declare( strict_types=1 );

namespace Wikibase\Lib\Store;

use Psr\Log\LoggerInterface;
use WANObjectCache;
use Wikibase\DataModel\Services\EntityId\EntityIdComposer;
use Wikibase\Lib\Store\Sql\Terms\DatabaseMatchingTermsLookup;
use Wikibase\Lib\Store\Sql\Terms\DatabaseTypeIdsStore;
use Wikimedia\Rdbms\ILBFactory;

/**
 * @license GPL-2.0-or-later
 */
class MatchingTermsLookupFactory {

	/**
	 * @var EntityIdComposer
	 */
	private $entityIdComposer;

	/**
	 * @var ILBFactory
	 */
	private $loadBalancerFactory;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var WANObjectCache
	 */
	private $objectCache;

	/**
	 * @param EntityIdComposer $entityIdComposer
	 * @param ILBFactory $loadBalancerFactory
	 * @param LoggerInterface $logger
	 * @param WANObjectCache $objectCache
	 */
	public function __construct(
		EntityIdComposer $entityIdComposer,
		ILBFactory $loadBalancerFactory,
		LoggerInterface $logger,
		WANObjectCache $objectCache
	) {
		$this->entityIdComposer = $entityIdComposer;
		$this->loadBalancerFactory = $loadBalancerFactory;
		$this->logger = $logger;
		$this->objectCache = $objectCache;
	}

	/**
	 * @param string|false $dbName The name of the database to use (use false for the local db)
	 */
	public function getLookupForDatabase( $dbName ): MatchingTermsLookup {
		$loadBalancer = $this->loadBalancerFactory->getMainLB( $dbName );

		$databaseTypeIdsStore = new DatabaseTypeIdsStore(
			$loadBalancer,
			$this->objectCache,
			$dbName,
			$this->logger
		);

		return new DatabaseMatchingTermsLookup(
			$loadBalancer,
			$databaseTypeIdsStore,
			$databaseTypeIdsStore,
			$this->entityIdComposer,
			$this->logger
		);
	}
}