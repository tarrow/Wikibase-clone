<?php

namespace Wikibase\Client\DataAccess\Scribunto;

use InvalidArgumentException;
use MalformedTitleException;
use Title;
use TitleFormatter;
use TitleParser;
use Wikibase\Client\Usage\UsageAccumulator;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\MaxReferencedEntityVisitsExhaustedException;
use Wikibase\DataModel\Services\Lookup\MaxReferenceDepthExhaustedException;
use Wikibase\DataModel\Services\Lookup\ReferencedEntityIdLookup;
use Wikibase\DataModel\Services\Lookup\TermLookup;
use Wikibase\DataModel\Services\Lookup\TermLookupException;
use Wikibase\DataModel\SiteLink;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\SettingsArray;
use Wikibase\Lib\Store\EntityIdLookup;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\SiteLinkLookup;

/**
 * Actual implementations of various functions to access Wikibase functionality
 * through Scribunto.
 * All functions in here are independent from the target language, meaning that
 * this class can be instantiated without knowing the target language.
 *
 * @license GPL-2.0-or-later
 * @author Jens Ohlig < jens.ohlig@wikimedia.de >
 * @author Marius Hoch < hoo@online.de >
 */
class WikibaseLanguageIndependentLuaBindings {

	/**
	 * @var SiteLinkLookup
	 */
	private $siteLinkLookup;

	/**
	 * @var EntityIdLookup
	 */
	private $entityIdLookup;

	/**
	 * @var SettingsArray
	 */
	private $settings;

	/**
	 * @var UsageAccumulator
	 */
	private $usageAccumulator;

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var TermLookup
	 */
	private $termLookup;

	/**
	 * @var ContentLanguages
	 */
	private $termsLanguages;

	/**
	 * @var ReferencedEntityIdLookup
	 */
	private $referencedEntityIdLookup;

	/**
	 * @var TitleFormatter
	 */
	private $titleFormatter;

	/**
	 * @var TitleParser
	 */
	private $titleParser;

	/**
	 * @var string
	 */
	private $siteId;

	/**
	 * @var EntityRevisionLookup
	 */
	private $entityRevisionLookup;

	public function __construct(
		SiteLinkLookup $siteLinkLookup,
		EntityIdLookup $entityIdLookup,
		SettingsArray $settings,
		UsageAccumulator $usageAccumulator,
		EntityIdParser $entityIdParser,
		TermLookup $termLookup,
		ContentLanguages $termsLanguages,
		ReferencedEntityIdLookup $referencedEntityIdLookup,
		TitleFormatter $titleFormatter,
		TitleParser $titleParser,
		string $siteId,
		EntityRevisionLookup $entityRevisionLookup
	) {
		$this->siteLinkLookup = $siteLinkLookup;
		$this->entityIdLookup = $entityIdLookup;
		$this->settings = $settings;
		$this->usageAccumulator = $usageAccumulator;
		$this->entityIdParser = $entityIdParser;
		$this->termLookup = $termLookup;
		$this->termsLanguages = $termsLanguages;
		$this->referencedEntityIdLookup = $referencedEntityIdLookup;
		$this->titleFormatter = $titleFormatter;
		$this->titleParser = $titleParser;
		$this->siteId = $siteId;
		$this->entityRevisionLookup = $entityRevisionLookup;
	}

	/**
	 * Get entity ID from page title and optionally global site ID.
	 *
	 * @param string $pageTitle
	 * @param string|null $globalSiteId
	 *
	 * @return string|null
	 */
	public function getEntityId( $pageTitle, $globalSiteId ) {
		$globalSiteId = $globalSiteId ?: $this->siteId;
		$itemId = null;

		if ( $globalSiteId === $this->siteId ) {
			$title = Title::newFromDBkey( $pageTitle );
			if ( $title !== null ) {
				$itemId = $this->entityIdLookup->getEntityIdForTitle( $title );
			}
		}

		if ( !$itemId ) {
			$itemId = $this->siteLinkLookup->getItemIdForLink( $globalSiteId, $pageTitle );
		}

		if ( !$itemId ) {
			try {
				$normalizedPageTitle = $this->normalizePageTitle( $pageTitle );
			} catch ( MalformedTitleException $e ) {
				return null;
			}

			if ( $normalizedPageTitle === $pageTitle ) {
				return null;
			}
			$itemId = $this->siteLinkLookup->getItemIdForLink( $globalSiteId, $normalizedPageTitle );
		}

		if ( !$itemId ) {
			return null;
		}

		$this->trackUsageForSitelink( $globalSiteId, $itemId );

		return $itemId->getSerialization();
	}

	/**
	 * @param string $pageTitle
	 * @return string
	 */
	private function normalizePageTitle( $pageTitle ) {
		// This is not necessary the right thing for non-local titles, but it's
		// the best we can do (without the expensive MediaWikiPageNameNormalizer).
		$pageTitleValue = $this->titleParser->parseTitle( $pageTitle );
		return $this->titleFormatter->getPrefixedText( $pageTitleValue );
	}

	/**
	 * Is this a valid (parseable) entity id.
	 *
	 * @param string $entityIdSerialization
	 *
	 * @return bool
	 */
	public function isValidEntityId( $entityIdSerialization ) {
		try {
			$this->entityIdParser->parse( $entityIdSerialization );
		} catch ( EntityIdParsingException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $prefixedEntityId
	 * @param string $languageCode
	 *
	 * @return string|null Null if language code invalid or entity couldn't be found/ no label present.
	 */
	public function getLabelByLanguage( $prefixedEntityId, $languageCode ) {
		if ( !$this->termsLanguages->hasLanguage( $languageCode ) ) {
			// Directly abort: Only track label usages for valid languages
			return null;
		}

		try {
			$entityId = $this->entityIdParser->parse( $prefixedEntityId );
		} catch ( EntityIdParsingException $e ) {
			return null;
		}

		$this->usageAccumulator->addLabelUsage( $entityId, $languageCode );
		try {
			$label = $this->termLookup->getLabel( $entityId, $languageCode );
		} catch ( TermLookupException $ex ) {
			return null;
		}

		return $label;
	}

	/**
	 * @param string $setting
	 *
	 * @return mixed
	 */
	public function getSetting( $setting ) {
		return $this->settings->getSetting( $setting );
	}

	/**
	 * @param string $prefixedItemId
	 * @param string|null $globalSiteId
	 *
	 * @return string|null Null if no site link found.
	 */
	public function getSiteLinkPageName( $prefixedItemId, $globalSiteId ) {
		$globalSiteId = $globalSiteId ?: $this->siteId;

		try {
			$itemId = new ItemId( $prefixedItemId );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}

		$returnIdAsIs = function () use ( $itemId ) {
			return $itemId;
		};

		// Using an EntityRevisionLookup here is weird because we aren't interested in the revision.
		// It's an easy way to get to the redirect target though. Create an EntityRedirectLookup for client?
		$itemIdAfterRedirectResolution = $this->entityRevisionLookup->getLatestRevisionId( $itemId )
			->onConcreteRevision( $returnIdAsIs )
			->onRedirect( function ( $revisionId, $redirectTarget ) {
				return $redirectTarget;
			} )
			->onNonexistentEntity( $returnIdAsIs )
			->map();

		$this->trackUsageForSitelink( $globalSiteId, $itemIdAfterRedirectResolution );
		if ( !$itemId->equals( $itemIdAfterRedirectResolution ) ) {
			// it's a redirect. We want to know if anything happens to it.
			$this->usageAccumulator->addAllUsage( $itemId );
		}

		$siteLinkRows = $this->siteLinkLookup->getLinks(
			[ $itemIdAfterRedirectResolution->getNumericId() ],
			[ $globalSiteId ]
		);

		foreach ( $siteLinkRows as $siteLinkRow ) {
			$siteLink = new SiteLink( $siteLinkRow[0], $siteLinkRow[1] );
			return $siteLink->getPageName();
		}

		return null;
	}

	/**
	 * @param EntityId $fromId
	 * @param PropertyId $propertyId
	 * @param EntityId[] $toIds
	 *
	 * @return string|null|bool Serialization of the referenced entity id, if one could be found.
	 *  Null if none of the given entities is referenced.
	 *  False if the search for a referenced entity had to be aborted due to resource limits.
	 */
	public function getReferencedEntityId( EntityId $fromId, PropertyId $propertyId, array $toIds ) {
		try {
			$res = $this->referencedEntityIdLookup->getReferencedEntityId( $fromId, $propertyId, $toIds );
		} catch ( MaxReferenceDepthExhaustedException | MaxReferencedEntityVisitsExhaustedException $e ) {
			return false;
		}

		return $res ? $res->getSerialization() : null;
	}

	private function trackUsageForSitelink( string $globalSiteId, ItemId $itemId ): void {
		if ( $globalSiteId === $this->siteId ) {
			$this->usageAccumulator->addTitleUsage( $itemId );
		} else {
			$this->usageAccumulator->addSiteLinksUsage( $itemId );
		}
	}

}
