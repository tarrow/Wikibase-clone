<?php

declare( strict_types = 1 );

use DataValues\Deserializers\DataValueDeserializer;
use DataValues\Geo\Values\GlobeCoordinateValue;
use DataValues\MonolingualTextValue;
use DataValues\QuantityValue;
use DataValues\Serializers\DataValueSerializer;
use DataValues\StringValue;
use DataValues\TimeValue;
use DataValues\UnknownValue;
use Deserializers\Deserializer;
use Deserializers\DispatchableDeserializer;
use Deserializers\DispatchingDeserializer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Serializers\DispatchingSerializer;
use Serializers\Serializer;
use ValueParsers\NullParser;
use Wikibase\DataAccess\DataAccessSettings;
use Wikibase\DataAccess\EntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\DataAccess\EntitySourceDefinitionsConfigParser;
use Wikibase\DataAccess\GenericServices;
use Wikibase\DataAccess\MediaWiki\EntitySourceDocumentUrlProvider;
use Wikibase\DataAccess\MultipleEntitySourceServices;
use Wikibase\DataAccess\SingleEntitySourceServices;
use Wikibase\DataAccess\WikibaseServices;
use Wikibase\DataModel\DeserializerFactory;
use Wikibase\DataModel\Entity\DispatchingEntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\SerializerFactory;
use Wikibase\DataModel\Services\Diff\EntityDiffer;
use Wikibase\DataModel\Services\Diff\EntityPatcher;
use Wikibase\DataModel\Services\EntityId\EntityIdComposer;
use Wikibase\DataModel\Services\Statement\StatementGuidParser;
use Wikibase\DataModel\Services\Statement\StatementGuidValidator;
use Wikibase\Lib\Changes\EntityChangeFactory;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\DataTypeDefinitions;
use Wikibase\Lib\DataTypeFactory;
use Wikibase\Lib\DataValueFactory;
use Wikibase\Lib\EntityTypeDefinitions;
use Wikibase\Lib\Formatters\CachingKartographerEmbeddingHandler;
use Wikibase\Lib\Formatters\OutputFormatValueFormatterFactory;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\Modules\PropertyValueExpertsModule;
use Wikibase\Lib\SettingsArray;
use Wikibase\Lib\Store\EntityArticleIdLookup;
use Wikibase\Lib\Store\EntityExistenceChecker;
use Wikibase\Lib\Store\EntityIdLookup;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Lib\Store\EntityRedirectChecker;
use Wikibase\Lib\Store\EntityTermStoreWriter;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Store\EntityTitleTextLookup;
use Wikibase\Lib\Store\EntityUrlLookup;
use Wikibase\Lib\Store\ItemTermStoreWriterAdapter;
use Wikibase\Lib\Store\PropertyTermStoreWriterAdapter;
use Wikibase\Lib\Store\Sql\Terms\DatabaseTypeIdsStore;
use Wikibase\Lib\Store\Sql\Terms\TermStoreWriterFactory;
use Wikibase\Lib\Store\Sql\Terms\TypeIdsAcquirer;
use Wikibase\Lib\Store\Sql\Terms\TypeIdsLookup;
use Wikibase\Lib\Store\Sql\Terms\TypeIdsResolver;
use Wikibase\Lib\Store\ThrowingEntityTermStoreWriter;
use Wikibase\Lib\Store\TitleLookupBasedEntityArticleIdLookup;
use Wikibase\Lib\Store\TitleLookupBasedEntityExistenceChecker;
use Wikibase\Lib\Store\TitleLookupBasedEntityRedirectChecker;
use Wikibase\Lib\Store\TitleLookupBasedEntityTitleTextLookup;
use Wikibase\Lib\Store\TitleLookupBasedEntityUrlLookup;
use Wikibase\Lib\Store\TypeDispatchingArticleIdLookup;
use Wikibase\Lib\Store\TypeDispatchingExistenceChecker;
use Wikibase\Lib\Store\TypeDispatchingRedirectChecker;
use Wikibase\Lib\Store\TypeDispatchingTitleTextLookup;
use Wikibase\Lib\Store\TypeDispatchingUrlLookup;
use Wikibase\Lib\StringNormalizer;
use Wikibase\Lib\TermFallbackCache\TermFallbackCacheFacade;
use Wikibase\Lib\TermFallbackCache\TermFallbackCacheServiceFactory;
use Wikibase\Lib\TermFallbackCacheFactory;
use Wikibase\Lib\Units\UnitConverter;
use Wikibase\Lib\Units\UnitStorage;
use Wikibase\Lib\WikibaseContentLanguages;
use Wikibase\Lib\WikibaseSettings;
use Wikibase\Repo\ChangeOp\Deserialization\SiteLinkBadgeChangeOpSerializationValidator;
use Wikibase\Repo\ChangeOp\EntityChangeOpProvider;
use Wikibase\Repo\Content\EntityContentFactory;
use Wikibase\Repo\EntitySourceDefinitionsLegacyRepoSettingsParser;
use Wikibase\Repo\FederatedProperties\FederatedPropertiesEntitySourceDefinitionsConfigParser;
use Wikibase\Repo\Notifications\RepoEntityChange;
use Wikibase\Repo\Notifications\RepoItemChange;
use Wikibase\Repo\Rdf\EntityRdfBuilderFactory;
use Wikibase\Repo\Rdf\RdfVocabulary;
use Wikibase\Repo\Rdf\ValueSnakRdfBuilderFactory;
use Wikibase\Repo\Store\EntityTitleStoreLookup;
use Wikibase\Repo\Store\IdGenerator;
use Wikibase\Repo\Store\LoggingIdGenerator;
use Wikibase\Repo\Store\RateLimitingIdGenerator;
use Wikibase\Repo\Store\Sql\SqlIdGenerator;
use Wikibase\Repo\Store\Sql\UpsertSqlIdGenerator;
use Wikibase\Repo\Store\TermsCollisionDetector;
use Wikibase\Repo\Store\TermsCollisionDetectorFactory;
use Wikibase\Repo\Store\TypeDispatchingEntityTitleStoreLookup;
use Wikibase\Repo\ValueParserFactory;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\ObjectFactory;

/** @phpcs-require-sorted-array */
return [

	'WikibaseRepo.AllTypesEntityDeserializer' => function ( MediaWikiServices $services ): DispatchableDeserializer {
		$deserializerFactoryCallbacks = WikibaseRepo::getEntityTypeDefinitions( $services )
			->get( EntityTypeDefinitions::DESERIALIZER_FACTORY_CALLBACK );
		$baseDeserializerFactory = WikibaseRepo::getBaseDataModelDeserializerFactory( $services );
		$deserializers = [];

		foreach ( $deserializerFactoryCallbacks as $callback ) {
			$deserializers[] = call_user_func( $callback, $baseDeserializerFactory );
		}

		return new DispatchingDeserializer( $deserializers );
	},

	'WikibaseRepo.AllTypesEntitySerializer' => function ( MediaWikiServices $services ): Serializer {
		$serializerFactoryCallbacks = WikibaseRepo::getEntityTypeDefinitions( $services )
			->get( EntityTypeDefinitions::SERIALIZER_FACTORY_CALLBACK );
		$baseSerializerFactory = WikibaseRepo::getBaseDataModelSerializerFactory( $services );
		$serializers = [];

		foreach ( $serializerFactoryCallbacks as $callback ) {
			$serializers[] = $callback( $baseSerializerFactory );
		}

		return new DispatchingSerializer( $serializers );
	},

	'WikibaseRepo.BaseDataModelDeserializerFactory' => function ( MediaWikiServices $services ): DeserializerFactory {
		return new DeserializerFactory(
			WikibaseRepo::getDataValueDeserializer( $services ),
			WikibaseRepo::getEntityIdParser( $services )
		);
	},

	'WikibaseRepo.BaseDataModelSerializerFactory' => function ( MediaWikiServices $services ): SerializerFactory {
		return new SerializerFactory( new DataValueSerializer(), SerializerFactory::OPTION_DEFAULT );
	},

	'WikibaseRepo.CompactBaseDataModelSerializerFactory' => function ( MediaWikiServices $services ): SerializerFactory {
		return new SerializerFactory(
			new DataValueSerializer(),
			SerializerFactory::OPTION_SERIALIZE_MAIN_SNAKS_WITHOUT_HASH +
			SerializerFactory::OPTION_SERIALIZE_REFERENCE_SNAKS_WITHOUT_HASH
		);
	},

	'WikibaseRepo.ContentModelMappings' => function ( MediaWikiServices $services ): array {
		$map = WikibaseRepo::getEntityTypeDefinitions( $services )
			->get( EntityTypeDefinitions::CONTENT_MODEL_ID );

		$services->getHookContainer()
			->run( 'WikibaseContentModelMapping', [ &$map ] );

		return $map;
	},

	'WikibaseRepo.DataAccessSettings' => function ( MediaWikiServices $services ): DataAccessSettings {
		return new DataAccessSettings(
			WikibaseRepo::getSettings( $services )->getSetting( 'maxSerializedEntitySize' )
		);
	},

	'WikibaseRepo.DatabaseTypeIdsStore' => function ( MediaWikiServices $services ): DatabaseTypeIdsStore {
		return new DatabaseTypeIdsStore(
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache()
		);
	},

	'WikibaseRepo.DataTypeDefinitions' => function ( MediaWikiServices $services ): DataTypeDefinitions {
		$baseDataTypes = require __DIR__ . '/../lib/WikibaseLib.datatypes.php';
		$repoDataTypes = require __DIR__ . '/WikibaseRepo.datatypes.php';

		$dataTypes = array_merge_recursive( $baseDataTypes, $repoDataTypes );

		$services->getHookContainer()->run( 'WikibaseRepoDataTypes', [ &$dataTypes ] );

		$settings = WikibaseRepo::getSettings( $services );

		return new DataTypeDefinitions(
			$dataTypes,
			$settings->getSetting( 'disabledDataTypes' )
		);
	},

	'WikibaseRepo.DataTypeFactory' => function ( MediaWikiServices $services ): DataTypeFactory {
		return new DataTypeFactory(
			WikibaseRepo::getDataTypeDefinitions( $services )->getValueTypes()
		);
	},

	'WikibaseRepo.DataValueDeserializer' => function ( MediaWikiServices $services ): DataValueDeserializer {
		return new DataValueDeserializer( [
			'string' => StringValue::class,
			'unknown' => UnknownValue::class,
			'globecoordinate' => GlobeCoordinateValue::class,
			'monolingualtext' => MonolingualTextValue::class,
			'quantity' => QuantityValue::class,
			'time' => TimeValue::class,
			'wikibase-entityid' => function ( $value ) use ( $services ) {
				// TODO this should perhaps be factored out into a class
				if ( isset( $value['id'] ) ) {
					try {
						return new EntityIdValue( WikibaseRepo::getEntityIdParser( $services )->parse( $value['id'] ) );
					} catch ( EntityIdParsingException $parsingException ) {
						throw new InvalidArgumentException(
							'Can not parse id \'' . $value['id'] . '\' to build EntityIdValue with',
							0,
							$parsingException
						);
					}
				} else {
					return EntityIdValue::newFromArray( $value );
				}
			},
		] );
	},

	'WikibaseRepo.DataValueFactory' => function ( MediaWikiServices $services ): DataValueFactory {
		return new DataValueFactory( WikibaseRepo::getDataValueDeserializer( $services ) );
	},

	'WikibaseRepo.EntityArticleIdLookup' => function ( MediaWikiServices $services ): EntityArticleIdLookup {
		return new TypeDispatchingArticleIdLookup(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::ARTICLE_ID_LOOKUP_CALLBACK ),
			new TitleLookupBasedEntityArticleIdLookup(
				WikibaseRepo::getEntityTitleLookup( $services )
			)
		);
	},

	'WikibaseRepo.EntityChangeFactory' => function ( MediaWikiServices $services ): EntityChangeFactory {
		//TODO: take this from a setting or registry.
		$changeClasses = [
			Item::ENTITY_TYPE => RepoItemChange::class,
			// Other types of entities will use EntityChange
		];

		return new EntityChangeFactory(
			WikibaseRepo::getEntityDiffer( $services ),
			WikibaseRepo::getEntityIdParser( $services ),
			$changeClasses,
			RepoEntityChange::class,
			WikibaseRepo::getLogger( $services )
		);
	},

	'WikibaseRepo.EntityChangeOpProvider' => function ( MediaWikiServices $services ): EntityChangeOpProvider {
		return new EntityChangeOpProvider(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::CHANGEOP_DESERIALIZER_CALLBACK )
		);
	},

	'WikibaseRepo.EntityContentFactory' => function ( MediaWikiServices $services ): EntityContentFactory {
		return new EntityContentFactory(
			WikibaseRepo::getContentModelMappings( $services ),
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::CONTENT_HANDLER_FACTORY_CALLBACK ),
			WikibaseRepo::getEntitySourceDefinitions( $services ),
			WikibaseRepo::getLocalEntitySource( $services ),
			$services->getInterwikiLookup()
		);
	},

	'WikibaseRepo.EntityDiffer' => function ( MediaWikiServices $services ): EntityDiffer {
		$entityDiffer = new EntityDiffer();
		$entityTypeDefinitions = WikibaseRepo::getEntityTypeDefinitions( $services );
		$builders = $entityTypeDefinitions->get( EntityTypeDefinitions::ENTITY_DIFFER_STRATEGY_BUILDER );
		foreach ( $builders as $builder ) {
			$entityDiffer->registerEntityDifferStrategy( $builder() );
		}
		return $entityDiffer;
	},

	'WikibaseRepo.EntityExistenceChecker' => function ( MediaWikiServices $services ): EntityExistenceChecker {
		return new TypeDispatchingExistenceChecker(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::EXISTENCE_CHECKER_CALLBACK ),
			new TitleLookupBasedEntityExistenceChecker(
				WikibaseRepo::getEntityTitleLookup( $services ),
				$services->getLinkBatchFactory()
			)
		);
	},

	'WikibaseRepo.EntityIdComposer' => function ( MediaWikiServices $services ): EntityIdComposer {
		return new EntityIdComposer(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::ENTITY_ID_COMPOSER_CALLBACK )
		);
	},

	'WikibaseRepo.EntityIdLookup' => function ( MediaWikiServices $services ): EntityIdLookup {
		return WikibaseRepo::getEntityContentFactory( $services );
	},

	'WikibaseRepo.EntityIdParser' => function ( MediaWikiServices $services ): EntityIdParser {
		return new DispatchingEntityIdParser(
			WikibaseRepo::getEntityTypeDefinitions( $services )->getEntityIdBuilders()
		);
	},

	'WikibaseRepo.EntityNamespaceLookup' => function ( MediaWikiServices $services ): EntityNamespaceLookup {
		return array_reduce(
			WikibaseRepo::getEntitySourceDefinitions( $services )->getSources(),
			function ( EntityNamespaceLookup $nsLookup, EntitySource $source ): EntityNamespaceLookup {
				return $nsLookup->merge( new EntityNamespaceLookup(
					$source->getEntityNamespaceIds(),
					$source->getEntitySlotNames()
				) );
			},
			new EntityNamespaceLookup( [], [] )
		);
	},

	'WikibaseRepo.EntityPatcher' => function ( MediaWikiServices $services ): EntityPatcher {
		$entityPatcher = new EntityPatcher();
		$entityTypeDefinitions = WikibaseRepo::getEntityTypeDefinitions( $services );
		$builders = $entityTypeDefinitions->get( EntityTypeDefinitions::ENTITY_PATCHER_STRATEGY_BUILDER );
		foreach ( $builders as $builder ) {
			$entityPatcher->registerEntityPatcherStrategy( $builder() );
		}
		return $entityPatcher;
	},

	'WikibaseRepo.EntityRdfBuilderFactory' => function ( MediaWikiServices $services ): EntityRdfBuilderFactory {
		$entityTypeDefinitions = WikibaseRepo::getEntityTypeDefinitions( $services );

		return new EntityRdfBuilderFactory(
			$entityTypeDefinitions->get( EntityTypeDefinitions::RDF_BUILDER_FACTORY_CALLBACK ),
			$entityTypeDefinitions->get( EntityTypeDefinitions::RDF_LABEL_PREDICATES )
		);
	},

	'WikibaseRepo.EntityRedirectChecker' => function ( MediaWikiServices $services ): EntityRedirectChecker {
		return new TypeDispatchingRedirectChecker(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::REDIRECT_CHECKER_CALLBACK ),
			new TitleLookupBasedEntityRedirectChecker(
				WikibaseRepo::getEntityTitleLookup( $services )
			)
		);
	},

	'WikibaseRepo.EntitySourceDefinitions' => function ( MediaWikiServices $services ): EntitySourceDefinitions {
		$settings = WikibaseRepo::getSettings( $services );
		$entityTypeDefinitions = WikibaseRepo::getEntityTypeDefinitions( $services );

		if ( $settings->hasSetting( 'entitySources' ) && !empty( $settings->getSetting( 'entitySources' ) ) ) {
			$configParser = new EntitySourceDefinitionsConfigParser();

			return $configParser->newDefinitionsFromConfigArray(
				$settings->getSetting( 'entitySources' ),
				$entityTypeDefinitions
			);
		}

		$parser = new EntitySourceDefinitionsLegacyRepoSettingsParser();

		if ( $settings->getSetting( 'federatedPropertiesEnabled' ) ) {
			$configParser = new FederatedPropertiesEntitySourceDefinitionsConfigParser( $settings );

			return $configParser->initializeDefaults(
				$parser->newDefinitionsFromSettings( $settings, $entityTypeDefinitions ),
				$entityTypeDefinitions
			);
		}

		return $parser->newDefinitionsFromSettings( $settings, $entityTypeDefinitions );
	},

	'WikibaseRepo.EntityTitleLookup' => function ( MediaWikiServices $services ): EntityTitleLookup {
		return WikibaseRepo::getEntityTitleStoreLookup( $services );
	},

	'WikibaseRepo.EntityTitleStoreLookup' => function ( MediaWikiServices $services ): EntityTitleStoreLookup {
		return new TypeDispatchingEntityTitleStoreLookup(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::ENTITY_TITLE_STORE_LOOKUP_FACTORY_CALLBACK ),
			WikibaseRepo::getEntityContentFactory( $services )
		);
	},

	'WikibaseRepo.EntityTitleTextLookup' => function ( MediaWikiServices $services ): EntityTitleTextLookup {
		return new TypeDispatchingTitleTextLookup(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::TITLE_TEXT_LOOKUP_CALLBACK ),
			new TitleLookupBasedEntityTitleTextLookup(
				WikibaseRepo::getEntityTitleLookup( $services )
			)
		);
	},

	'WikibaseRepo.EntityTypeDefinitions' => function ( MediaWikiServices $services ): EntityTypeDefinitions {
		$baseEntityTypes = require __DIR__ . '/../lib/WikibaseLib.entitytypes.php';
		$repoEntityTypes = require __DIR__ . '/WikibaseRepo.entitytypes.php';

		$entityTypes = array_merge_recursive( $baseEntityTypes, $repoEntityTypes );

		$services->getHookContainer()->run( 'WikibaseRepoEntityTypes', [ &$entityTypes ] );

		return new EntityTypeDefinitions( $entityTypes );
	},

	'WikibaseRepo.EntityUrlLookup' => function ( MediaWikiServices $services ): EntityUrlLookup {
		return new TypeDispatchingUrlLookup(
			WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::URL_LOOKUP_CALLBACK ),
			new TitleLookupBasedEntityUrlLookup(
				WikibaseRepo::getEntityTitleLookup( $services )
			)
		);
	},

	'WikibaseRepo.ExternalFormatStatementDeserializer' => function ( MediaWikiServices $services ): Deserializer {
		return WikibaseRepo::getBaseDataModelDeserializerFactory( $services )->newStatementDeserializer();
	},

	'WikibaseRepo.IdGenerator' => function ( MediaWikiServices $services ): IdGenerator {
		$settings = WikibaseRepo::getSettings( $services );

		switch ( $settings->getSetting( 'idGenerator' ) ) {
			case 'original':
				$idGenerator = new SqlIdGenerator(
					$services->getDBLoadBalancer(),
					$settings->getSetting( 'reservedIds' ),
					$settings->getSetting( 'idGeneratorSeparateDbConnection' )
				);
				break;
			case 'mysql-upsert':
				// We could make sure the 'upsert' generator is only being used with mysql dbs here,
				// but perhaps that is an unnecessary check? People will realize when the DB query for
				// ID selection fails anyway...
				$idGenerator = new UpsertSqlIdGenerator(
					$services->getDBLoadBalancer(),
					$settings->getSetting( 'reservedIds' ),
					$settings->getSetting( 'idGeneratorSeparateDbConnection' )
				);
				break;
			default:
				throw new InvalidArgumentException(
					'idGenerator config option must be either \'original\' or \'mysql-upsert\''
				);
		}

		if ( $settings->getSetting( 'idGeneratorRateLimiting' ) ) {
			$idGenerator = new RateLimitingIdGenerator(
				$idGenerator,
				RequestContext::getMain()
			);
		}

		if ( $settings->getSetting( 'idGeneratorLogging' ) ) {
			$idGenerator = new LoggingIdGenerator(
				$idGenerator,
				LoggerFactory::getInstance( 'Wikibase.IdGenerator' )
			);
		}

		return $idGenerator;
	},

	'WikibaseRepo.ItemTermsCollisionDetector' => function ( MediaWikiServices $services ): TermsCollisionDetector {
		return WikibaseRepo::getTermsCollisionDetectorFactory( $services )
			->getTermsCollisionDetector( Item::ENTITY_TYPE );
	},

	'WikibaseRepo.ItemTermStoreWriter' => function ( MediaWikiServices $services ): EntityTermStoreWriter {
		if ( !in_array(
			Item::ENTITY_TYPE,
			WikibaseRepo::getLocalEntitySource( $services )->getEntityTypes()
		) ) {
			return new ThrowingEntityTermStoreWriter();
		}

		return new ItemTermStoreWriterAdapter(
			WikibaseRepo::getTermStoreWriterFactory( $services )->newItemTermStoreWriter()
		);
	},

	'WikibaseRepo.KartographerEmbeddingHandler' => function ( MediaWikiServices $services ): ?CachingKartographerEmbeddingHandler {
		$settings = WikibaseRepo::getSettings( $services );
		$config = $services->getMainConfig();
		if (
			$settings->getSetting( 'useKartographerGlobeCoordinateFormatter' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'Kartographer' ) &&
			$config->has( 'KartographerEnableMapFrame' ) &&
			$config->get( 'KartographerEnableMapFrame' )
		) {
			return new CachingKartographerEmbeddingHandler(
				$services->getParserFactory()->create()
			);
		} else {
			return null;
		}
	},

	'WikibaseRepo.LanguageFallbackChainFactory' => function ( MediaWikiServices $services ): LanguageFallbackChainFactory {
		return new LanguageFallbackChainFactory(
			$services->getLanguageFactory(),
			$services->getLanguageConverterFactory(),
			$services->getLanguageFallback()
		);
	},

	'WikibaseRepo.LocalEntityNamespaceLookup' => function ( MediaWikiServices $services ): EntityNamespaceLookup {
		$localEntitySource = WikibaseRepo::getLocalEntitySource( $services );
		$nsIds = $localEntitySource->getEntityNamespaceIds();
		$entitySlots = $localEntitySource->getEntitySlotNames();

		return new EntityNamespaceLookup( $nsIds, $entitySlots );
	},

	'WikibaseRepo.LocalEntitySource' => function ( MediaWikiServices $services ): EntitySource {
		$localEntitySourceName = WikibaseRepo::getSettings( $services )->getSetting( 'localEntitySourceName' );
		$sources = WikibaseRepo::getEntitySourceDefinitions( $services )->getSources();
		foreach ( $sources as $source ) {
			if ( $source->getSourceName() === $localEntitySourceName ) {
				return $source;
			}
		}

		throw new LogicException( 'No source configured: ' . $localEntitySourceName );
	},

	'WikibaseRepo.LocalEntityTypes' => function ( MediaWikiServices $services ): array {
		$localSource = WikibaseRepo::getLocalEntitySource( $services );
		$subEntityTypes = WikibaseRepo::getEntityTypeDefinitions( $services )
				->get( EntityTypeDefinitions::SUB_ENTITY_TYPES );

		// Expands the array of local entity types with sub types
		return array_reduce(
			$localSource->getEntityTypes(),
			function ( $types, $localTypeName ) use ( $subEntityTypes ) {
				$types[] = $localTypeName;
				if ( array_key_exists( $localTypeName, $subEntityTypes ) ) {
					$types = array_merge( $types, $subEntityTypes[$localTypeName] );
				}
				return $types;
			},
			[]
		);
	},

	'WikibaseRepo.Logger' => function ( MediaWikiServices $services ): LoggerInterface {
		return LoggerFactory::getInstance( 'Wikibase' );
	},

	'WikibaseRepo.MonolingualTextLanguages' => function ( MediaWikiServices $services ): ContentLanguages {
		return WikibaseRepo::getWikibaseContentLanguages( $services )
			->getContentLanguages( WikibaseContentLanguages::CONTEXT_MONOLINGUAL_TEXT );
	},

	'WikibaseRepo.PropertyTermsCollisionDetector' => function ( MediaWikiServices $services ): TermsCollisionDetector {
		return WikibaseRepo::getTermsCollisionDetectorFactory( $services )
			->getTermsCollisionDetector( Property::ENTITY_TYPE );
	},

	'WikibaseRepo.PropertyTermStoreWriter' => function ( MediaWikiServices $services ): EntityTermStoreWriter {
		if ( !in_array(
			Property::ENTITY_TYPE,
			WikibaseRepo::getLocalEntitySource( $services )->getEntityTypes()
		) ) {
			return new ThrowingEntityTermStoreWriter();
		}

		return new PropertyTermStoreWriterAdapter(
			WikibaseRepo::getTermStoreWriterFactory( $services )->newPropertyTermStoreWriter()
		);
	},

	'WikibaseRepo.PropertyValueExpertsModule' => function ( MediaWikiServices $services ): PropertyValueExpertsModule {
		return new PropertyValueExpertsModule( WikibaseRepo::getDataTypeDefinitions( $services ) );
	},

	'WikibaseRepo.RdfVocabulary' => function ( MediaWikiServices $services ): RdfVocabulary {
		$repoSettings = WikibaseRepo::getSettings( $services );
		$languageCodes = array_merge(
			$services->getMainConfig()->get( 'DummyLanguageCodes' ),
			$repoSettings->getSetting( 'canonicalLanguageCodes' )
		);

		$entitySourceDefinitions = WikibaseRepo::getEntitySourceDefinitions( $services );
		$nodeNamespacePrefixes = $entitySourceDefinitions->getRdfNodeNamespacePrefixes();
		$predicateNamespacePrefixes = $entitySourceDefinitions->getRdfPredicateNamespacePrefixes();

		$urlProvider = new EntitySourceDocumentUrlProvider();
		$canonicalDocumentUrls = $urlProvider->getCanonicalDocumentsUrls( $entitySourceDefinitions );

		return new RdfVocabulary(
			$entitySourceDefinitions->getConceptBaseUris(),
			$canonicalDocumentUrls,
			$entitySourceDefinitions,
			$nodeNamespacePrefixes,
			$predicateNamespacePrefixes,
			$languageCodes,
			WikibaseRepo::getDataTypeDefinitions( $services )->getRdfTypeUris(),
			$repoSettings->getSetting( 'pagePropertiesRdf' ) ?: [],
			$repoSettings->getSetting( 'rdfDataRightsUrl' )
		);
	},

	'WikibaseRepo.Settings' => function ( MediaWikiServices $services ): SettingsArray {
		return WikibaseSettings::getRepoSettings();
	},

	'WikibaseRepo.SiteLinkBadgeChangeOpSerializationValidator' => function (
		MediaWikiServices $services
	): SiteLinkBadgeChangeOpSerializationValidator {
		return new SiteLinkBadgeChangeOpSerializationValidator(
			WikibaseRepo::getEntityTitleLookup( $services ),
			array_keys(
				WikibaseRepo::getSettings( $services )
					->getSetting( 'badgeItems' )
			)
		);
	},

	'WikibaseRepo.StatementGuidParser' => function ( MediaWikiServices $services ): StatementGuidParser {
		return new StatementGuidParser( WikibaseRepo::getEntityIdParser( $services ) );
	},

	'WikibaseRepo.StatementGuidValidator' => function ( MediaWikiServices $services ): StatementGuidValidator {
		return new StatementGuidValidator( WikibaseRepo::getEntityIdParser( $services ) );
	},

	'WikibaseRepo.StorageEntitySerializer' => function ( MediaWikiServices $services ): Serializer {
		$serializerFactoryCallbacks = WikibaseRepo::getEntityTypeDefinitions( $services )
			->get( EntityTypeDefinitions::STORAGE_SERIALIZER_FACTORY_CALLBACK );
		$baseSerializerFactory = WikibaseRepo::getBaseDataModelSerializerFactory( $services );
		$serializers = [];

		foreach ( $serializerFactoryCallbacks as $callback ) {
			$serializers[] = $callback( $baseSerializerFactory );
		}

		return new DispatchingSerializer( $serializers );
	},

	'WikibaseRepo.StringNormalizer' => function ( MediaWikiServices $services ): StringNormalizer {
		return new StringNormalizer();
	},

	'WikibaseRepo.TermFallbackCache' => function ( MediaWikiServices $services ): TermFallbackCacheFacade {
		return new TermFallbackCacheFacade(
			WikibaseRepo::getTermFallbackCacheFactory( $services )->getTermFallbackCache(),
			WikibaseRepo::getSettings( $services )->getSetting( 'sharedCacheDuration' )
		);
	},

	'WikibaseRepo.TermFallbackCacheFactory' => function ( MediaWikiServices $services ): TermFallbackCacheFactory {
		$settings = WikibaseRepo::getSettings( $services );
		return new TermFallbackCacheFactory(
			$settings->getSetting( 'sharedCacheType' ),
			WikibaseRepo::getLogger( $services ),
			$services->getStatsdDataFactory(),
			hash( 'sha256', $services->getMainConfig()->get( 'SecretKey' ) ),
			new TermFallbackCacheServiceFactory(),
			$settings->getSetting( 'termFallbackCacheVersion' )
		);
	},

	'WikibaseRepo.TermsCollisionDetectorFactory' => function ( MediaWikiServices $services ): TermsCollisionDetectorFactory {
		return new TermsCollisionDetectorFactory(
			$services->getDBLoadBalancer(),
			WikibaseRepo::getTypeIdsLookup( $services )
		);
	},

	'WikibaseRepo.TermsLanguages' => function ( MediaWikiServices $services ): ContentLanguages {
		return WikibaseRepo::getWikibaseContentLanguages( $services )
			->getContentLanguages( WikibaseContentLanguages::CONTEXT_TERM );
	},

	'WikibaseRepo.TermStoreWriterFactory' => function ( MediaWikiServices $services ): TermStoreWriterFactory {
		return new TermStoreWriterFactory(
			WikibaseRepo::getLocalEntitySource( $services ),
			WikibaseRepo::getStringNormalizer( $services ),
			WikibaseRepo::getTypeIdsAcquirer( $services ),
			WikibaseRepo::getTypeIdsLookup( $services ),
			WikibaseRepo::getTypeIdsResolver( $services ),
			$services->getDBLoadBalancerFactory(),
			$services->getMainWANObjectCache(),
			JobQueueGroup::singleton(),
			WikibaseRepo::getLogger( $services )
		);
	},

	'WikibaseRepo.TypeIdsAcquirer' => function ( MediaWikiServices $services ): TypeIdsAcquirer {
		return WikibaseRepo::getDatabaseTypeIdsStore( $services );
	},

	'WikibaseRepo.TypeIdsLookup' => function ( MediaWikiServices $services ): TypeIdsLookup {
		return WikibaseRepo::getDatabaseTypeIdsStore( $services );
	},

	'WikibaseRepo.TypeIdsResolver' => function ( MediaWikiServices $services ): TypeIdsResolver {
		return WikibaseRepo::getDatabaseTypeIdsStore( $services );
	},

	'WikibaseRepo.UnitConverter' => function ( MediaWikiServices $services ): ?UnitConverter {
		$settings = WikibaseRepo::getSettings( $services );
		if ( !$settings->hasSetting( 'unitStorage' ) ) {
			return null;
		}

		// Creates configured unit storage.
		$unitStorage = ObjectFactory::getObjectFromSpec( $settings->getSetting( 'unitStorage' ) );
		if ( !( $unitStorage instanceof UnitStorage ) ) {
			wfWarn( "Bad unit storage configuration, ignoring" );
			return null;
		}
		return new UnitConverter( $unitStorage, $settings->getSetting( 'conceptBaseUri' ) );
	},

	'WikibaseRepo.ValueFormatterFactory' => function ( MediaWikiServices $services ): OutputFormatValueFormatterFactory {
		$formatterFactoryCBs = WikibaseRepo::getDataTypeDefinitions( $services )
			->getFormatterFactoryCallbacks( DataTypeDefinitions::PREFIXED_MODE );

		return new OutputFormatValueFormatterFactory(
			$formatterFactoryCBs,
			$services->getContentLanguage(),
			WikibaseRepo::getLanguageFallbackChainFactory( $services )
		);
	},

	'WikibaseRepo.ValueParserFactory' => function ( MediaWikiServices $services ): ValueParserFactory {
		$dataTypeDefinitions = WikibaseRepo::getDataTypeDefinitions( $services );
		$callbacks = $dataTypeDefinitions->getParserFactoryCallbacks();

		// For backwards-compatibility, also register parsers under legacy names,
		// for use with the deprecated 'parser' parameter of the wbparsevalue API module.
		$prefixedCallbacks = $dataTypeDefinitions->getParserFactoryCallbacks(
			DataTypeDefinitions::PREFIXED_MODE
		);
		if ( isset( $prefixedCallbacks['VT:wikibase-entityid'] ) ) {
			$callbacks['wikibase-entityid'] = $prefixedCallbacks['VT:wikibase-entityid'];
		}
		if ( isset( $prefixedCallbacks['VT:globecoordinate'] ) ) {
			$callbacks['globecoordinate'] = $prefixedCallbacks['VT:globecoordinate'];
		}
		// 'null' is not a datatype. Kept for backwards compatibility.
		$callbacks['null'] = function() {
			return new NullParser();
		};

		return new ValueParserFactory( $callbacks );
	},

	'WikibaseRepo.ValueSnakRdfBuilderFactory' => function ( MediaWikiServices $services ): ValueSnakRdfBuilderFactory {
		return new ValueSnakRdfBuilderFactory(
			WikibaseRepo::getDataTypeDefinitions( $services )
				->getRdfBuilderFactoryCallbacks( DataTypeDefinitions::PREFIXED_MODE )
		);
	},

	'WikibaseRepo.WikibaseContentLanguages' => function ( MediaWikiServices $services ): WikibaseContentLanguages {
		return WikibaseContentLanguages::getDefaultInstance( $services->getHookContainer() );
	},

	'WikibaseRepo.WikibaseServices' => function ( MediaWikiServices $services ): WikibaseServices {
		$entityTypeDefinitions = WikibaseRepo::getEntityTypeDefinitions( $services );
		$entitySourceDefinitions = WikibaseRepo::getEntitySourceDefinitions( $services );
		$genericServices = new GenericServices( $entityTypeDefinitions );
		$entityIdParser = WikibaseRepo::getEntityIdParser( $services );
		$entityIdComposer = WikibaseRepo::getEntityIdComposer( $services );
		$dataValueDeserializer = WikibaseRepo::getDataValueDeserializer( $services );
		$nameTableStoreFactory = $services->getNameTableStoreFactory();
		$dataAccessSettings = WikibaseRepo::getDataAccessSettings( $services );
		$languageFallbackChainFactory = WikibaseRepo::getLanguageFallbackChainFactory( $services );
		$storageEntitySerializer = WikibaseRepo::getStorageEntitySerializer( $services );
		$deserializerFactoryCallbacks = $entityTypeDefinitions->get(
			EntityTypeDefinitions::DESERIALIZER_FACTORY_CALLBACK );
		$entityMetaDataAccessorCallbacks = $entityTypeDefinitions->get(
			EntityTypeDefinitions::ENTITY_METADATA_ACCESSOR_CALLBACK );
		$prefetchingTermLookupCallbacks = $entityTypeDefinitions->get(
			EntityTypeDefinitions::PREFETCHING_TERM_LOOKUP_CALLBACK );
		$entityRevisionFactoryLookupCallbacks = $entityTypeDefinitions->get(
			EntityTypeDefinitions::ENTITY_REVISION_LOOKUP_FACTORY_CALLBACK );

		$singleSourceServices = [];
		foreach ( $entitySourceDefinitions->getSources() as $source ) {
			$singleSourceServices[$source->getSourceName()] = new SingleEntitySourceServices(
				$genericServices,
				$entityIdParser,
				$entityIdComposer,
				$dataValueDeserializer,
				$nameTableStoreFactory->getSlotRoles( $source->getDatabaseName() ),
				$dataAccessSettings,
				$source,
				$languageFallbackChainFactory,
				$storageEntitySerializer,
				$deserializerFactoryCallbacks,
				$entityMetaDataAccessorCallbacks,
				$prefetchingTermLookupCallbacks,
				$entityRevisionFactoryLookupCallbacks
			);
		}
		return new MultipleEntitySourceServices(
			$entitySourceDefinitions,
			$genericServices,
			$singleSourceServices
		);
	},

];
