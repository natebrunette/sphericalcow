<?php
/**
 * Base code for update jobs that put some secondary data extracted
 * from article content into the database.
 *
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
 */

/**
 * Abstract base class for update jobs that put some secondary data extracted
 * from article content into the database.
 */
abstract class SqlDataUpdate extends DataUpdate {

	protected $mDb;            //!< Database connection reference
	protected $mOptions;       //!< SELECT options to be used (array)

	private   $mHasTransaction; //!< bool whether a transaction is open on this object (internal use only!)

	/**
	 * Constructor
	**/
	public function __construct( ) {
		global $wgAntiLockFlags;

		parent::__construct( );

		if ( $wgAntiLockFlags & ALF_NO_LINK_LOCK ) {
			$this->mOptions = array();
		} else {
			$this->mOptions = array( 'FOR UPDATE' );
		}

		$this->mHasTransaction = false;
	}

	/**
	 * Begin a database transaction.
	 *
	 * Because nested transactions are not supportred by the Database class, this implementation
	 * checkes Database::trxLevel() and only opens a transaction if none is yet active.
	 */
	public function beginTransaction() {
		$this->mDb = wfGetDB( DB_MASTER );

		// NOTE: nested transactions are not supported, only start a transaction if none is open
		if ( $this->mDb->trxLevel() === 0 ) {
			$this->mDb->begin( get_class( $this ) . '::beginTransaction'  );
			$this->mHasTransaction = true;
		}
	}

	/**
	 * Commit the database transaction started via beginTransaction (if any).
	 */
	public function commitTransaction() {
		if ( $this->mHasTransaction ) {
			$this->mDb->commit( get_class( $this ) . '::commitTransaction' );
		}
	}

	/**
	 * Abort the database transaction started via beginTransaction (if any).
	 */
	public function abortTransaction() {
		if ( $this->mHasTransaction ) { //XXX: actually... maybe always?
			$this->mDb->rollback( get_class( $this ) . '::abortTransaction' );
		}
	}

	/**
	 * Invalidate the cache of a list of pages from a single namespace.
	 * This is intended for use by subclasses.
	 *
	 * @param $namespace Integer
	 * @param $dbkeys Array
	 */
	protected function invalidatePages( $namespace, Array $dbkeys ) {
		if ( !count( $dbkeys ) ) {
			return;
		}

		/**
		 * Determine which pages need to be updated
		 * This is necessary to prevent the job queue from smashing the DB with
		 * large numbers of concurrent invalidations of the same page
		 */
		$now = $this->mDb->timestamp();
		$ids = array();
		$res = $this->mDb->select( 'page', array( 'page_id' ),
			array(
				'page_namespace' => $namespace,
				'page_title' => $dbkeys,
				'page_touched < ' . $this->mDb->addQuotes( $now )
			), __METHOD__
		);
		foreach ( $res as $row ) {
			$ids[] = $row->page_id;
		}
		if ( !count( $ids ) ) {
			return;
		}

		/**
		 * Do the update
		 * We still need the page_touched condition, in case the row has changed since
		 * the non-locking select above.
		 */
		$this->mDb->update( 'page', array( 'page_touched' => $now ),
			array(
				'page_id' => $ids,
				'page_touched < ' . $this->mDb->addQuotes( $now )
			), __METHOD__
		);
	}

}
