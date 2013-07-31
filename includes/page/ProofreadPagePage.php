<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup ProofreadPage
 */

/**
 * The content of a page page
 */
class ProofreadPagePage {

	/**
	 * @var Title
	 */
	protected $title;

	/**
	 * @var ProofreadPageContent content of the page
	 */
	protected $content;

	/**
	 * @var ProofreadIndexPage|null index related to the page
	 */
	protected $index;

	/**
	 * @var File|null image related to the page
	 */
	protected $image;

	/**
	 * Constructor
	 * @param $title Title Reference to a Title object.
	 * @param $content ProofreadPageContent content of the page. Warning: only done for EditProofreadPagePage use.
	 * @param $index ProofreadIndexPage index related to the page.
	 */
	public function __construct( Title $title, ProofreadPageContent $content = null, ProofreadIndexPage $index = null ) {
		$this->title = $title;
		$this->content = $content;
		$this->index = $index;
	}

	/**
	 * Create a new ProofreadPagePage from a Title object
	 * @param $title Title
	 * @return ProofreadPagePage
	 */
	public static function newFromTitle( Title $title ) {
		return new self( $title, null, null );
	}
	/**
	 * Returns Title of the index page
	 * @return Title
	 */
	public function getTitle() {
		return $this->title;
	}

	/**
	 * Returns number of the page in the file if it's a multi-page file or null
	 * @return integer|null
	 */
	public function getPageNumber() {
		global $wgContLang;
		$parts = explode( '/', $this->title->getText() );
		if ( count( $parts ) === 1 ) {
			return null;
		}
		$val = $wgContLang->parseFormattedNumber( $parts[count( $parts ) - 1] );
		return (int) $val;
	}

	/**
	 * Return index of the page if it exist or false.
	 * @return ProofreadIndexPage|false
	 */
	public function getIndex() {
		if( $this->index !== null ) {
			return $this->index;
		}

		$result = ProofreadIndexDbConnector::getRowsFromTitle( $this->title );

		foreach ( $result as $x ) {
			$refTitle = Title::makeTitle( $x->page_namespace, $x->page_title );
			if ( $refTitle !== null && $refTitle->inNamespace( ProofreadPage::getIndexNamespaceId() ) ) {
				$this->index = ProofreadIndexPage::newFromTitle( $refTitle );
				return $this->index;
			}
		}

		$m = explode( '/', $this->title->getText(), 2 );
		if ( isset( $m[1] ) ) {
			$imageTitle = Title::makeTitleSafe( NS_IMAGE, $m[0] );
			if ( $imageTitle !== null ) {
				$image = wfFindFile( $imageTitle );
				// if it is multipage, we use the page order of the file
				if ( $image && $image->exists() && $image->isMultipage() ) {
					$indexTitle = Title::makeTitle( ProofreadPage::getIndexNamespaceId(), $image->getTitle()->getText());
					if ( $indexTitle !== null ) {
						$this->index = ProofreadIndexPage::newFromTitle( $indexTitle );
						return $this->index;
					}
				}
			}
		}
		$this->index = false;
		return false;
	}

	/**
	 * Return image of the page if it exist or false.
	 * @return File|false
	 */
	public function getImage() {
		if ( $this->image !== null ) {
			return $this->image;
		}

		//try to get the file related to the index
		$index = $this->getIndex();
		if( $index ) {
			$this->image = $index->getImage();
			if ( $this->image ) {
				return $this->image;
			}
		}

		//try to get an image with the same name as the file
		$imageTitle = Title::makeTitle( NS_IMAGE, $this->title->getText() );
		$this->image = wfFindFile( $imageTitle );
		return $this->image;
	}

	/**
	 * Return content of the page
	 * @return ProofreadPageValue
	 */
	public function getContent() {
		if ( $this->content === null ) {
			$rev = Revision::newFromTitle( $this->title );
			if ( $rev === null ) {
				$this->content = ProofreadPageContent::newEmpty();
			} else {
				$this->content = ProofreadPageContent::newFromWikitext( $rev->getText() );
			}
		}
		return $this->content;
	}

	/**
	 * Return content of the page initialised for edition
	 * @return ProofreadPageContent
	 */
	public function getContentForEdition() {
		global $wgContLang;
		$content = $this->getContent();

		if ( $content->isEmpty() && !$this->title->exists() ) {
			$index = $this->getIndex();
			if ( $index ) {
				list( $header, $footer, $css, $editWidth ) = $index->getIndexDataForPage( $this->title );
				$content->setHeader( $header );
				$content->setFooter( $footer );

				//Extract text layer
				$image = $index->getImage();
				$pageNumber = $this->getPageNumber();
				if ( $image && $pageNumber !== null && $image->exists() ) {
					$text = $image->getHandler()->getPageText( $image, $pageNumber );
					if ( $text ) {
						$text = preg_replace( "/(\\\\n)/", "\n", $text );
						$text = preg_replace( "/(\\\\\d*)/", '', $text );
						$content->setBody( $text );
					}
				}
			}
		}
		return $content;
	}

	/**
	 * Return HTML for the image
	 * @param $options array
	 * @return string|null
	 */
	public function getImageHtml( $options ) {
		$image = $this->getImage();
		if ( !$image || !$image->exists() ) {
			return null;
		}
		$width = $image->getWidth();
		if ( isset( $options['max-width'] ) && $width > $options['max-width'] ) {
			$width = $options['max-width'];
		}
		$transformAttributes = array(
			'width' => $width
		);

		if ( $image->isMultipage() ) {
			$pageNumber = $this->getPageNumber();
			if ( $pageNumber !== null ) {
				$transformAttributes['page'] = $pageNumber;
			}
		}
		$thumbnail = $image->transform( $transformAttributes );
		return $thumbnail->toHtml( $options );
	}

	/**
	 * Output page content
	 * @param OutputPage $out
	 */
	public function outputPage( OutputPage $out ) {
		global $wgParser;

		$content = $this->getContent();
		$parserOptions = $this->createParserOptions( $out );

		//beggining
		$out->addHtml(
			Html::openElement( 'div', array( 'class' => 'prp-page-container' )  ) .
			Html::openElement( 'div', array( 'class' => 'prp-page-content' ) )
		);

		//page quality
		//TODO FIXME: display whether page has been proofread by the user or by someone else
		$out->addHtml(
			Html::openElement( 'div', array(
				'class' => 'prp-page-qualityheader quality' . $content->getProofreadingLevel()
			) )  .
			wfMessage( 'proofreadpage_quality' . $content->getProofreadingLevel() . '_message' )->inContentLanguage()->text() .
			Html::closeElement( 'div' )
		);

		$headerParserOutput = $wgParser->parse( $content->getHeader(), $this->title, $parserOptions );
		$out->addParserOutput( $headerParserOutput );

		$bodyParserOutput = $wgParser->parse( $content->getBody(), $this->title, $parserOptions );
		$bodyParserOutput->addCategory(
			wfMessage( 'proofreadpage_quality' . $content->getProofreadingLevel() . '_category' )->inContentLanguage()->text(),
			$this->title->getText()
		);
		$out->addParserOutput( $bodyParserOutput );

		$footerParserOutput = $wgParser->parse( $content->getFooter(), $this->title, $parserOptions );
		$out->addParserOutput( $footerParserOutput );

		//end
		$out->addHtml(
			Html::closeElement( 'div' ) .
			Html::openElement( 'div', array( 'class' => 'prp-page-image' ) ) .
			$this->getImageHtml( array( 'max-width' => 800 ) ) .
			Html::closeElement( 'div' ) .
			Html::closeElement( 'div' )
		);
	}

	protected function createParserOptions( OutputPage $out ) {
		global $wgUser;

		$page = new WikiPage( $this->title );
		$parserOptions = $page->makeParserOptions( $wgUser );
		$parserOptions->setEditSection( false );

		if ( $out->isPrintable() ) {
			$parserOptions->setIsPrintable( true );
		}

		return $parserOptions;
	}
}