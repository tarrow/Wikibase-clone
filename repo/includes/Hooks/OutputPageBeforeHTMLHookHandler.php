<?php

namespace Wikibase\Repo\Hooks;

use MediaWiki\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\EntityFactory;
use Wikibase\Lib\LanguageNameLookup;
use Wikibase\Lib\Store\EntityRevision;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\UserLanguageLookup;
use Wikibase\Repo\BabelUserLanguageLookup;
use Wikibase\Repo\Hooks\Helpers\OutputPageEditability;
use Wikibase\Repo\Hooks\Helpers\OutputPageRevisionIdReader;
use Wikibase\Repo\Hooks\Helpers\UserPreferredContentLanguagesLookup;
use Wikibase\Repo\MediaWikiLanguageDirectionalityLookup;
use Wikibase\Repo\MediaWikiLocalizedTextProvider;
use Wikibase\Repo\ParserOutput\PlaceholderExpander\EntityViewPlaceholderExpander;
use Wikibase\Repo\ParserOutput\PlaceholderExpander\ExternallyRenderedEntityViewPlaceholderExpander;
use Wikibase\Repo\ParserOutput\PlaceholderExpander\PlaceholderExpander;
use Wikibase\Repo\ParserOutput\PlaceholderExpander\TermboxRequestInspector;
use Wikibase\Repo\ParserOutput\TermboxFlag;
use Wikibase\Repo\ParserOutput\TextInjector;
use Wikibase\Repo\View\RepoSpecialPageLinker;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\View\Template\TemplateFactory;
use Wikibase\View\Termbox\Renderer\TermboxRemoteRenderer;
use Wikibase\View\ToolbarEditSectionGenerator;

/**
 * Handler for the "OutputPageBeforeHTML" hook.
 *
 * @license GPL-2.0-or-later
 * @author Marius Hoch < hoo@online.de >
 */
class OutputPageBeforeHTMLHookHandler implements OutputPageBeforeHTMLHook {

	/**
	 * @var TemplateFactory
	 */
	private $templateFactory;

	/**
	 * @var UserLanguageLookup
	 */
	private $userLanguageLookup;

	/**
	 * @var ContentLanguages
	 */
	private $termsLanguages;

	/**
	 * @var EntityRevisionLookup
	 */
	private $entityRevisionLookup;

	/**
	 * @var LanguageNameLookup
	 */
	private $languageNameLookup;

	/**
	 * @var OutputPageEntityIdReader
	 */
	private $outputPageEntityIdReader;

	/**
	 * @var EntityFactory
	 */
	private $entityFactory;

	/**
	 * @var string
	 */
	private $cookiePrefix;

	/**
	 * @var OutputPageEditability
	 */
	private $editability;

	/**
	 * @var bool
	 */
	private $isExternallyRendered;

	/**
	 * @var UserPreferredContentLanguagesLookup
	 */
	private $userPreferredTermsLanguages;

	public function __construct(
		TemplateFactory $templateFactory,
		UserLanguageLookup $userLanguageLookup,
		ContentLanguages $termsLanguages,
		EntityRevisionLookup $entityRevisionLookup,
		LanguageNameLookup $languageNameLookup,
		OutputPageEntityIdReader $outputPageEntityIdReader,
		EntityFactory $entityFactory,
		$cookiePrefix,
		OutputPageEditability $editability,
		$isExternallyRendered,
		UserPreferredContentLanguagesLookup $userPreferredTermsLanguages
	) {
		$this->templateFactory = $templateFactory;
		$this->userLanguageLookup = $userLanguageLookup;
		$this->termsLanguages = $termsLanguages;
		$this->entityRevisionLookup = $entityRevisionLookup;
		$this->languageNameLookup = $languageNameLookup;
		$this->outputPageEntityIdReader = $outputPageEntityIdReader;
		$this->entityFactory = $entityFactory;
		$this->cookiePrefix = $cookiePrefix;
		$this->isExternallyRendered = $isExternallyRendered;
		$this->editability = $editability;
		$this->userPreferredTermsLanguages = $userPreferredTermsLanguages;
	}

	/**
	 * @return self
	 */
	public static function factory(): self {
		global $wgLang, $wgCookiePrefix;

		$wikibaseRepo = WikibaseRepo::getDefaultInstance();
		$termLanguages = $wikibaseRepo->getTermsLanguages();
		$babelUserLanguageLookup = new BabelUserLanguageLookup();

		return new self(
			TemplateFactory::getDefaultInstance(),
			$babelUserLanguageLookup,
			$termLanguages,
			$wikibaseRepo->getEntityRevisionLookup(),
			new LanguageNameLookup( $wgLang->getCode() ),
			new OutputPageEntityIdReader(
				$wikibaseRepo->getEntityContentFactory(),
				$wikibaseRepo->getEntityIdParser()
			),
			$wikibaseRepo->getEntityFactory(),
			$wgCookiePrefix,
			new OutputPageEditability(),
			TermboxFlag::getInstance()->shouldRenderTermbox(),
			new UserPreferredContentLanguagesLookup(
				$termLanguages,
				$babelUserLanguageLookup,
				MediaWikiServices::getInstance()->getContentLanguage()->getCode()
			)
		);
	}

	/**
	 * Called when pushing HTML from the ParserOutput into OutputPage.
	 * Used to expand any placeholders in the OutputPage's 'wb-placeholders' property
	 * in the HTML.
	 *
	 * @param OutputPage $out
	 * @param string &$html the HTML to mangle
	 */
	public function onOutputPageBeforeHTML( $out, &$html ): void {
		if ( !$out->isArticle() ) {
			return;
		}

		$html = $this->replacePlaceholders( $out, $html );
		$this->addJsUserLanguages( $out );
		$html = $this->showOrHideEditLinks( $out, $html );
	}

	/**
	 * @param OutputPage $out
	 * @param string $html
	 *
	 * @return string
	 */
	private function replacePlaceholders( OutputPage $out, $html ) {
		$placeholders = $out->getProperty( 'wikibase-view-chunks' );
		if ( !$placeholders ) {
			return $html;
		}

		$injector = new TextInjector( $placeholders );
		$getHtmlCallback = function() {
			return '';
		};

		$entity = $this->getEntity( $out );
		if ( $entity instanceof EntityDocument ) {
			$getHtmlCallback = [ $this->getPlaceholderExpander( $entity, $out ), 'getHtmlForPlaceholder' ];
		}

		return $injector->inject( $html, $getHtmlCallback );
	}

	private function addJsUserLanguages( OutputPage $out ) {
		$out->addJsConfigVars(
			'wbUserSpecifiedLanguages',
			// All user-specified languages, that are valid term languages
			// Reindex the keys so that JavaScript still works if an unknown
			// language code in the babel box causes an index to miss
			array_values( array_intersect(
				$this->userLanguageLookup->getUserSpecifiedLanguages( $out->getUser() ),
				$this->termsLanguages->getLanguages()
			) )
		);
	}

	/**
	 * @param OutputPage $out
	 *
	 * @return EntityDocument|null
	 */
	private function getEntity( OutputPage $out ) {
		$entityId = $this->getEntityId( $out );

		if ( !$entityId ) {
			return null;
		}

		if ( $this->needsRealEntity( $out ) ) {
			// The parser cache content is too old to contain the terms list items
			// Pass the correct entity to generate terms list items on the fly
			$entityRev = $this->entityRevisionLookup->getEntityRevision( $entityId, $out->getRevisionId() );
			if ( !( $entityRev instanceof EntityRevision ) ) {
				return null;
			}

			return $entityRev->getEntity();
		}

		return $this->entityFactory->newEmpty( $entityId->getEntityType() );
	}

	private function needsRealEntity( OutputPage $out ) {
		return !$this->isExternallyRendered && !$this->getEntityTermsListHtml( $out );
	}

	private function getPlaceholderExpander(
		EntityDocument $entity,
		OutputPage $out
	): PlaceholderExpander {
		return $this->isExternallyRendered
			? $this->getExternallyRenderedEntityViewPlaceholderExpander( $out )
			: $this->getLocallyRenderedEntityViewPlaceholderExpander(
				$entity,
				$out
			);
	}

	/**
	 * @param EntityDocument $entity
	 * @param OutputPage $out
	 *
	 * @return EntityViewPlaceholderExpander
	 */
	private function getLocallyRenderedEntityViewPlaceholderExpander(
		EntityDocument $entity,
		OutputPage $out
	) {
		$language = $out->getLanguage();
		$user = $out->getUser();

		return new EntityViewPlaceholderExpander(
			$this->templateFactory,
			$user,
			$entity,
			$this->userPreferredTermsLanguages->getLanguages( $language->getCode(), $user ),
			new MediaWikiLanguageDirectionalityLookup(),
			$this->languageNameLookup,
			new MediaWikiLocalizedTextProvider( $language ),
			$this->cookiePrefix,
			$this->getEntityTermsListHtml( $out ) ?: []
		);
	}

	private function getExternallyRenderedEntityViewPlaceholderExpander( OutputPage $out ) {
		$repo = WikibaseRepo::getDefaultInstance();
		$languageFallbackChainFactory = $repo->getLanguageFallbackChainFactory();

		return new ExternallyRenderedEntityViewPlaceholderExpander(
			$out,
			new TermboxRequestInspector( $languageFallbackChainFactory ),
			new TermboxRemoteRenderer(
				MediaWikiServices::getInstance()->getHttpRequestFactory(),
				$repo->getSettings()->getSetting( 'ssrServerUrl' ),
				$repo->getSettings()->getSetting( 'ssrServerTimeout' ),
				$repo->getLogger(),
				MediaWikiServices::getInstance()->getStatsdDataFactory()
			),
			$this->outputPageEntityIdReader,
			new RepoSpecialPageLinker(),
			$languageFallbackChainFactory,
			new OutputPageRevisionIdReader(),
			$repo->getSettings()->getSetting( 'termboxUserSpecificSsrEnabled' )
		);
	}

	private function getEntityTermsListHtml( OutputPage $out ) {
		return $out->getProperty( 'wikibase-terms-list-items' );
	}

	private function showOrHideEditLinks( OutputPage $out, $html ) {
		return ToolbarEditSectionGenerator::enableSectionEditLinks(
			$html,
			$this->editability->validate( $out )
		);
	}

	/**
	 * @param OutputPage $out
	 *
	 * @return EntityId|null
	 */
	private function getEntityId( OutputPage $out ) {
		return $this->outputPageEntityIdReader->getEntityIdFromOutputPage( $out );
	}

}
