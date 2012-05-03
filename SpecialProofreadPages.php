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
 * @ingroup SpecialPage
 */

class ProofreadPages extends QueryPage {
	protected $searchTerm, $searchList, $suppressSqlOffset;

	public function __construct( $name = 'IndexPages' ) {
		parent::__construct( $name );
	}

	public function execute( $parameters ) {
		global $wgDisableTextSearch, $wgScript;

		$this->setHeaders();
		list( $limit, $offset ) = wfCheckLimits();
		$output = $this->getOutput();
		$request = $this->getRequest();
		$output->addWikiText( wfMsgForContentNoTrans( 'proofreadpage_specialpage_text' ) );
		$this->searchList = null;
		$this->searchTerm = $request->getText( 'key' );
		$this->suppressSqlOffset = false;
		if( !$wgDisableTextSearch ) {
			$output->addHTML(
				Xml::openElement( 'form', array( 'action' => $wgScript ) ) .
				Html::hidden( 'title', $this->getTitle()->getPrefixedText() ) .
				Xml::input( 'limit', false, $limit, array( 'type' => 'hidden' ) ) .
				Xml::openElement( 'fieldset' ) .
				Xml::element( 'legend', null, wfMsg( 'proofreadpage_specialpage_legend' ) ) .
				Xml::input( 'key', 20, $this->searchTerm ) . ' ' .
				Xml::submitButton( wfMsg( 'ilsubmit' ) ) .
				Xml::closeElement( 'fieldset' ) .
				Xml::closeElement( 'form' )
			);
			if( $this->searchTerm ) {
				$searchEngine = SearchEngine::create();
				$searchEngine->setLimitOffset( $limit, $offset );
				$searchEngine->setNamespaces( array( ProofreadPage::getIndexNamespaceId() ) );
				$searchEngine->showRedirects = false;
				$textMatches = $searchEngine->searchText( $this->searchTerm );
				$this->searchList = array();
				while( $result = $textMatches->next() ) {
					$title = $result->getTitle();
					if ( $title->getNamespace() == ProofreadPage::getIndexNamespaceId() ) {
						array_push( $this->searchList, $title->getDBkey() );
					}
				}
				$this->suppressSqlOffset = true;
			}
		}
		parent::execute( $parameters );
	}

	function reallyDoQuery( $limit, $offset = false ) {
		if ( $this->suppressSqlOffset ) {
			// Bug #27678: Do not use offset here, because it was already used in
			// search perfomed by execute method
			return parent::reallyDoQuery( $limit, false );
		} else {
			return parent::reallyDoQuery( $limit, $offset );
		}
	}

	function isExpensive() {
		// FIXME: the query does filesort, so we're kinda lying here right now
		return false;
	}

	function isSyndicated() {
		return false;
	}

	function isCacheable() {
		// The page is not cacheable due to its search capabilities
		return false;
	}

	function linkParameters() {
		return array( 'key' => $this->searchTerm );
	}

	public function getQueryInfo() {
		$conds = array();
		if ( $this->searchTerm ) {
			if ( $this->searchList !== null ) {
				$conds = array( 'page_namespace' => ProofreadPage::getIndexNamespaceId() );
				if ( $this->searchList ) {
					$conds['page_title'] = $this->searchList;
				} else {
					// If not pages were found do not return results
					$conds[] = 'false';
				}
			} else {
				$conds = null;
			}
		}
		return array(
			'tables' => array( 'pr_index', 'page' ),
			'fields' => array( 'page_namespace AS namespace', 'page_title AS title', '2*pr_q4+pr_q3 AS value', 'pr_count',
			'pr_q0', 'pr_q1', 'pr_q2' ,'pr_q3', 'pr_q4' ),
			'conds' => $conds,
			'options' => array(),
			'join_conds' => array( 'page' => array( 'LEFT JOIN', 'page_id=pr_page_id' ) )
		);
	}

	function sortDescending() {
		return true;
	}

	function formatResult( $skin, $result ) {
		$title = Title::makeTitleSafe( $result->namespace, $result->title );
		if ( !$title ) {
			return '<!-- Invalid title ' .  htmlspecialchars( "{$result->namespace}:{$result->title}" ) . '-->';
		}
		$plink = $this->isCached()
			? Linker::link( $title , htmlspecialchars( $title->getText() ) )
			: Linker::linkKnown( $title , htmlspecialchars( $title->getText() ) );

		if ( !$title->exists() ) {
			return "<del>{$plink}</del>";
		}

		$size = $result->pr_count;
		$q0 = $result->pr_q0;
		$q1 = $result->pr_q1;
		$q2 = $result->pr_q2;
		$q3 = $result->pr_q3;
		$q4 = $result->pr_q4;
		$num_void = $size-$q1-$q2-$q3-$q4-$q0;
		$void_cell = $num_void ? "<td align=center style='border-style:dotted;background:#ffffff;border-width:1px;' width=\"{$num_void}\"></td>" : '';

		$lang = $this->getLanguage();
		$dirmark = $lang->getDirMark();
		$pages = wfMsgExt( 'proofreadpage_pages', 'parsemag', $size, $lang->formatNum( $size ) );

		$output = "<table style=\"line-height:70%;\" border=0 cellpadding=5 cellspacing=0 >
<tr valign=\"bottom\">
<td style=\"white-space:nowrap;overflow:hidden;\">{$plink} {$dirmark}[$pages]</td>
<td>
<table style=\"line-height:70%;\" border=0 cellpadding=0 cellspacing=0 >
<tr>
<td width=\"2\">&#160;</td>
<td align=center class='quality4' width=\"$q4\"></td>
<td align=center class='quality3' width=\"$q3\"></td>
<td align=center class='quality2' width=\"$q2\"></td>
<td align=center class='quality1' width=\"$q1\"></td>
<td align=center class='quality0' width=\"$q0\"></td>
$void_cell
</tr></table>
</td>
</tr></table>";

		return $output;
	}
}
