<?php

namespace Wikibase\DataModel\Services\Lookup;

use OutOfBoundsException;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Term\FingerprintProvider;

/**
 * @since 1.1
 *
 * @licence GNU GPL v2+
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Adam Shorland
 */
class EntityRetrievingTermLookup implements TermLookup {

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var Fingerprint[]
	 */
	private $fingerprints;

	/**
	 * @param EntityLookup $entityLookup
	 */
	public function __construct( EntityLookup $entityLookup ) {
		$this->entityLookup = $entityLookup;
	}

	/**
	 * @see TermLookup::getLabel()
	 *
	 * @param EntityId $entityId
	 * @param string $languageCode
	 *
	 * @return string|null
	 * @throws TermLookupException
	 */
	public function getLabel( EntityId $entityId, $languageCode ) {
		$fingerprint = $this->getFingerprint( $entityId, array( $languageCode ) );

		/** @var Fingerprint $fingerprint */
		$labels = $fingerprint->getLabels();

		try{
			return $labels->getByLanguage( $languageCode )->getText();
		} catch( OutOfBoundsException $ex ) {
			return null;
		}
	}

	/**
	 * @see TermLookup::getLabels()
	 *
	 * @param EntityId $entityId
	 * @param string[] $languages
	 *
	 * @throws TermLookupException
	 * @return string[]
	 */
	public function getLabels( EntityId $entityId, array $languages ) {
		$fingerprint = $this->getFingerprint( $entityId, $languages );

		/** @var Fingerprint $fingerprint */
		$labels = $fingerprint->getLabels()->toTextArray();

		return array_intersect_key( $labels, array_flip( $languages ) );
	}

	/**
	 * @see TermLookup::getDescription()
	 *
	 * @param EntityId $entityId
	 * @param string $languageCode
	 *
	 * @throws TermLookupException
	 * @return string|null
	 */
	public function getDescription( EntityId $entityId, $languageCode ) {
		$fingerprint = $this->getFingerprint( $entityId, array( $languageCode ) );

		/** @var Fingerprint $fingerprint */
		$descriptions = $fingerprint->getDescriptions();

		try{
			return $descriptions->getByLanguage( $languageCode )->getText();
		} catch( OutOfBoundsException $ex ) {
			return null;
		}
	}

	/**
	 * @see TermLookup::getDescriptions()
	 *
	 * @param EntityId $entityId
	 * @param string[] $languages
	 *
	 * @throws TermLookupException
	 * @return string[]
	 */
	public function getDescriptions( EntityId $entityId, array $languages ) {
		$fingerprint = $this->getFingerprint( $entityId, $languages );


		/** @var Fingerprint $fingerprint */
		$descriptions = $fingerprint->getDescriptions()->toTextArray();

		return array_intersect_key( $descriptions, array_flip( $languages ) );
	}

	/**
	 * @param EntityId $entityId
	 * @param array $languages used in thrown exceptions
	 *
	 * @throws TermLookupException
	 * @return Fingerprint
	 */
	private function getFingerprint( EntityId $entityId, array $languages ) {
		$idSerialization = $entityId->getSerialization();

		if ( !isset( $this->fingerprints[$idSerialization] ) ) {
			$this->fingerprints[$idSerialization] = $this->fetchFingerprint( $entityId, $languages );
		}

		return $this->fingerprints[$idSerialization];
	}

	/**
	 * @param EntityId $entityId
	 * @param array $languages used in thrown exceptions
	 *
	 * @throws TermLookupException
	 * @return Fingerprint
	 */
	private function fetchFingerprint( EntityId $entityId, array $languages ) {
		$entity = $this->entityLookup->getEntity( $entityId );

		if( $entity === null ) {
			throw new TermLookupException( $entityId, $languages, 'The entity could not be loaded' );
		}

		return $entity instanceof FingerprintProvider ? $entity->getFingerprint() : new Fingerprint();
	}

}
